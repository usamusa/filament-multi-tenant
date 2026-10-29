# Tenancy isolation tests

Required whenever a change touches identification, bootstrappers, connections, database grants, caches, sessions, storage or anything stateful. These tests create real tenants with real databases.

## Traps in tests

- **`Event::fake()` without a list** swallows `TenancyInitialized` and the other tenancy events: tenancy never bootstraps and queries silently hit the central database. Fake only what the test asserts: `Event::fake([OrderShipped::class])`.
- **`Queue::fake()` / `Bus::fake()`** skip stancl's payload hook, so faked jobs carry no `tenant_id`. Assert that a job was dispatched with the fakes; test the tenant round trip with a real database queue (below).
- **`RefreshDatabase`** wraps the central connection in a transaction. Tenant databases are created and dropped for real and survive the rollback: delete the tenants after each test. In-memory SQLite can't hold the tenant databases.
- **Leaked context.** A failing assertion inside `$tenant->run()` skips the restore, and the next test starts inside that tenant. End tenancy in `afterEach`.
- **Open connections block `DROP DATABASE`.** Purge every extra connection you opened to a tenant database before deleting the tenant.

## Helpers and cleanup

`tests/Pest.php`:

```php
/** A provisioned tenant reachable at <subdomain>.<tenant base domain>. */
function makeTenant(string $subdomain = 'acme'): Tenant
{
    $tenant = Tenant::create();
    $tenant->domains()->create(['domain' => $subdomain.'.'.config('app.tenant_base_domain')]);

    return $tenant;
}

/**
 * Delete every tenant created in this test (drops their databases), only
 * through an application a test boot accepted: afterEach runs after a refused
 * boot too (see "Test databases on a shared server").
 */
function deleteAllTenants(): void
{
    TestApplications::assertApproved(app());

    Tenant::query()->get()->each->delete();
}
```

In each tenancy test file:

```php
uses(RefreshDatabase::class);

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }
    // Purge any extra connections the test opened to tenant databases first.
    deleteAllTenants();
});
```

For tests that run entirely inside one tenant, a base class or a `beforeEach` that creates a tenant and calls `tenancy()->initialize($tenant)` keeps the test bodies plain.

## The permission cache does not leak within one process

Both tenants use the same role and permission names, so an unscoped cache answers for the wrong tenant.

```php
it('does not leak the permission cache across tenants in one process', function () {
    $alpha = makeTenant('alpha');
    $bravo = makeTenant('bravo');

    tenancy()->initialize($alpha);
    Permission::create(['name' => 'probe.leak.check', 'guard_name' => 'web']);
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    $role->givePermissionTo('probe.leak.check');
    $userA = User::factory()->create();
    $userA->assignRole($role);
    expect($userA->checkPermissionTo('probe.leak.check'))->toBeTrue();
    tenancy()->end();

    tenancy()->initialize($bravo);
    Permission::create(['name' => 'probe.leak.check', 'guard_name' => 'web']);
    Role::create(['name' => 'editor', 'guard_name' => 'web']); // no grant here
    $userB = User::factory()->create();
    $userB->assignRole('editor');
    expect($userB->checkPermissionTo('probe.leak.check'))->toBeFalse();
    tenancy()->end();

    tenancy()->initialize($alpha);
    expect(User::find($userA->id)->checkPermissionTo('probe.leak.check'))->toBeTrue();
});

it('scopes the permission cache key per tenant and restores it', function () {
    $tenant = makeTenant();
    $registrar = app(PermissionRegistrar::class);

    tenancy()->initialize($tenant);
    expect($registrar->cacheKey)->toBe('spatie.permission.cache.tenant.'.$tenant->getTenantKey());

    tenancy()->end();
    expect($registrar->cacheKey)->toBe('spatie.permission.cache');
});
```

## Cache and rate limits are per tenant

```php
it('reads back a value written in tenant context', function (string $store) {
    config(['cache.default' => $store]);
    $tenant = makeTenant();

    $value = $tenant->run(function (): mixed {
        Cache::put('probe', 'value', 60);

        return Cache::get('probe');
    });

    expect($value)->toBe('value');
})->with(['database store' => 'database', 'array store' => 'array']);

it('keeps one tenant\'s cache entries invisible to another', function () {
    config(['cache.default' => 'database']);
    $alpha = makeTenant('alpha');
    $bravo = makeTenant('bravo');

    $alpha->run(fn () => Cache::put('shared-key', 'alpha', 60));
    $bravo->run(fn () => Cache::put('shared-key', 'bravo', 60));

    expect($alpha->run(fn () => Cache::get('shared-key')))->toBe('alpha')
        ->and($bravo->run(fn () => Cache::get('shared-key')))->toBe('bravo');
});

it('keeps memoized cache reads per tenant', function () {
    config(['cache.default' => 'database']);
    $alpha = makeTenant('alpha');
    $bravo = makeTenant('bravo');

    $alpha->run(fn () => Cache::put('shared-key', 'alpha', 60));
    $bravo->run(fn () => Cache::put('shared-key', 'bravo', 60));

    // Cache::memo() is scoped per store: without the bootstrapper forgetting it,
    // bravo reads alpha's value through the memo alpha resolved first.
    expect($alpha->run(fn () => Cache::memo()->get('shared-key')))->toBe('alpha')
        ->and($bravo->run(fn () => Cache::memo()->get('shared-key')))->toBe('bravo')
        ->and(Cache::memo()->get('shared-key'))->toBeNull();
});

it('separates tenant entries from the central cache', function () {
    config(['cache.default' => 'database']);
    $tenant = makeTenant();
    Cache::put('shared-key', 'central', 60);

    $seenInTenant = $tenant->run(fn (): mixed => Cache::get('shared-key'));

    expect($seenInTenant)->toBeNull()
        ->and(Cache::get('shared-key'))->toBe('central');
});

it('counts rate limits per tenant', function () {
    config(['cache.default' => 'database']);
    $alpha = makeTenant('alpha');
    $bravo = makeTenant('bravo');

    $alpha->run(function (): void {
        foreach (range(1, 5) as $attempt) {
            RateLimiter::hit('login:1', 600);
        }
    });

    expect($alpha->run(fn () => RateLimiter::tooManyAttempts('login:1', 5)))->toBeTrue()
        ->and($bravo->run(fn () => RateLimiter::tooManyAttempts('login:1', 5)))->toBeFalse();
});
```

## Queued jobs land centrally and run in their tenant

```php
final class RemembersItsTenantJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Cache::put('ran-in', tenant()?->getTenantKey() ?? 'central', 60);
    }
}

it('queues a job dispatched inside a tenant where the central worker polls', function () {
    $tenant = makeTenant();

    $tenant->run(fn () => Queue::connection('database')->push(new RemembersItsTenantJob));

    $job = DB::connection(config('tenancy.database.central_connection'))->table('jobs')->sole();

    expect(json_decode($job->payload, true)['tenant_id'] ?? null)->toBe($tenant->getTenantKey());
});

it('runs the queued job inside the tenant that dispatched it', function () {
    config(['cache.default' => 'database']);
    $tenant = makeTenant();

    $tenant->run(fn () => Queue::connection('database')->push(new RemembersItsTenantJob));
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);

    expect($tenant->run(fn () => Cache::get('ran-in')))->toBe($tenant->getTenantKey());
});
```

## Routes stay on their plane

```php
it('answers 404 for a tenant route on the central domain', function () {
    $this->get('http://'.config('tenancy.central_domains')[0].'/dashboard')->assertNotFound();
});

it('does not serve the central admin panel on a tenant host', function () {
    makeTenant('acme');

    $this->get('http://acme.'.config('app.tenant_base_domain').'/admin/login')->assertNotFound();
});
```

## Storage and sessions are per tenant

```php
it('keeps one tenant\'s files out of another tenant\'s disk', function () {
    $alpha = makeTenant('alpha');
    $bravo = makeTenant('bravo');

    $alpha->run(fn () => Storage::disk('local')->put('probe.txt', 'alpha'));

    expect($bravo->run(fn () => Storage::disk('local')->exists('probe.txt')))->toBeFalse()
        ->and(Storage::disk('local')->exists('probe.txt'))->toBeFalse();

    // Tenant storage is real and survives the tenant: clean up.
    $alpha->run(fn () => Storage::disk('local')->delete('probe.txt'));
});
```

If sessions are not stored in the tenant database, prove that a session cookie from tenant A doesn't authenticate at tenant B: sign in at A, replay its cookie against B, and expect a guest (or a 403 from `ScopeSessions`).

## Cached lookup is invalidated

With `DomainTenantResolver::$shouldCache` on: resolve a tenant, rename or delete its domain (or suspend the tenant), and assert the next request sees the change. Run the update once in central and once in tenant context, which catches a resolver cache that lives under a tenant prefix.

## Links in queued mail point at the tenant

Send the notification through a real (or `sync`) queue from tenant context and assert the link's host is the tenant's domain, not `app.url`.

## A tenant's database role cannot reach another tenant (PostgreSQL)

PostgreSQL words errors in the server's locale, and pdo_pgsql reports every failed connection as SQLSTATE `08006`, so assert the catalog and the code, not the message.

```php
it('denies tenant role A any connection to tenant B\'s database', function () {
    // $roleA, $passwordA: tenant A's credentials; $databaseB: tenant B's database name.
    expect(DB::connection('tenant_instance')->selectOne(
        "SELECT has_database_privilege(?, ?, 'CONNECT') AS allowed", [$roleA, $databaseB]
    )->allowed)->toBeFalse();

    config(['database.connections.as_a' => array_merge(config('database.connections.tenant_instance'), [
        'database' => $databaseB,
        'username' => $roleA,
        'password' => $passwordA,
    ])]);
    DB::purge('as_a');

    try {
        DB::connection('as_a')->select('SELECT 1');
    } catch (QueryException $e) {
        expect($e->errorInfo[0] ?? null)->toBe('08006');

        return;
    } finally {
        DB::purge('as_a');
    }

    test()->fail("Expected the connection to tenant B's database to be rejected.");
});
```

A permission denial inside a database (for example an `UPDATE` on an append-only table) is SQLSTATE `42501`.

## Test databases on a shared server

`phpunit.xml`:

```xml
<env name="APP_URL" value="http://myapp.test"/>
<env name="TENANT_BASE_DOMAIN" value="myapp.test"/>
<env name="DB_DATABASE" value="myapp_central_test"/>
<env name="TENANT_DB_PREFIX" value="testing_tenant_"/>
<!-- A cached configuration (php artisan optimize, config:cache) would replace every value above. -->
<env name="APP_CONFIG_CACHE" value="bootstrap/cache/config.testing.php"/>
<env name="APP_ROUTES_CACHE" value="bootstrap/cache/routes.testing.php"/>
<env name="APP_EVENTS_CACHE" value="bootstrap/cache/events.testing.php"/>
```

Laravel loads a configuration cache in tests too: `bootstrap/cache/config.php`, unless `APP_CONFIG_CACHE` names another file. The test application then ignores phpunit.xml's `<env>` values and `.env.testing` and runs on the development databases. `php artisan optimize` also caches routes and events, which test boots load as well (routes with the development domains), so the three pinned paths keep a developer's caches out of the tests. Package caches, such as Filament's component cache under `bootstrap/cache/filament`, still need `php artisan optimize:clear`. A variable exported in the shell also beats phpunit.xml, even with `force="true"`, and a connection's `url` (`DB_URL` in `.env`) overrides its `database` key: check the configuration at every boot as well.

Parallel runs give each process its own central database, so tenant keys restart at 1 in every process while tenant database names are server-wide. Add a per-process prefix in the base test case:

```php
protected function setUp(): void
{
    parent::setUp();

    $token = ParallelTesting::token();

    if ($token !== false && $token !== null) {
        config(['tenancy.database.prefix' => config('tenancy.database.prefix')."p{$token}_"]);
    }
}
```

Refuse anything but the test configuration at every boot, in every process, and let a cleanup delete tenants only through an application that check accepted. PHPUnit runs `tearDown()`, and with it Pest's `afterEach()`, also when the boot threw; `$this->app` is null then, but `app()`, the facades, `tenancy()` and Eloquent still reach the refused application, so an unguarded `deleteAllTenants()` deletes the tenants its configuration names (with a cached development configuration, the development tenants and their databases). The check belongs in `createApplication()`, right after the bootstrap: `RefreshDatabase` runs `migrate:fresh` during `setUp()`, before any later check. Don't check the names again at cleanup: a parallel worker's central database becomes `<name>_test_<token>` after the boot, and a test may switch the environment.

`tests/Support/TestApplications.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Application;
use Illuminate\Support\ConfigurationUrlParser;
use RuntimeException;
use WeakMap;

/** The applications that booted on the test configuration: a cleanup acts only through one of them. */
final class TestApplications
{
    /** @var WeakMap<Application, true>|null keyed by the object: an id could be reused by a later one */
    private static ?WeakMap $approved = null;

    /** Right after the bootstrap, before RefreshDatabase migrates anything. */
    public static function approve(Application $app): void
    {
        $config = $app['config'];
        $problems = [];

        if ($app->environment() !== 'testing') {
            $problems[] = "the environment is \"{$app->environment()}\"";
        }

        // The connection RefreshDatabase migrates and the one the tenants live on (central models),
        // each as Laravel connects to it: a connection's url overrides its database key.
        $connections = array_filter(array_unique([
            $config->get('database.default'),
            $config->get('tenancy.database.central_connection'),
        ]));

        foreach ($connections as $connection) {
            $database = (string) ((new ConfigurationUrlParser)->parseConfiguration(
                (array) $config->get("database.connections.{$connection}"),
            )['database'] ?? '');

            // Under WithCachedConfig a parallel worker boots on the name its first test switched to.
            if (preg_match('/_test(_test_\d+)?$/', $database) !== 1) {
                $problems[] = "the database \"{$database}\" of the connection \"{$connection}\" does not end in _test";
            }
        }

        // Without stancl/tenancy there is no tenant prefix to check.
        $prefix = (string) $config->get('tenancy.database.prefix');

        if ($config->has('tenancy') && ! str_starts_with($prefix, 'testing_')) {
            $problems[] = "the tenant database prefix \"{$prefix}\" does not start with testing_";
        }

        if ($problems !== []) {
            // configurationIsCached() is also true under WithCachedConfig, which reads no file.
            throw new RuntimeException('Refusing the test configuration: '.implode('; ', $problems)
                .(is_file($app->getCachedConfigPath()) ? '. The configuration is cached: run php artisan optimize:clear.' : '. Check phpunit.xml.'));
        }

        self::$approved ??= new WeakMap;
        self::$approved[$app] = true;
    }

    /** First thing in every cleanup that deletes tenants, databases or files. */
    public static function assertApproved(Application $app): void
    {
        if (! isset(self::$approved[$app])) {
            throw new RuntimeException('Refusing to clean up through an application no test boot accepted.');
        }
    }
}
```

`tests/TestCase.php` (a browser base test case that builds its own application calls `approve()` the same way):

```php
public function createApplication(): Application
{
    $app = parent::createApplication();

    TestApplications::approve($app);

    return $app;
}
```

A cleanup in a `tearDown()` override checks first and reaches Laravel's own teardown in a `finally`, so it still runs when the cleanup refuses or fails: it rolls back and flushes an approved application, and after a refused boot removes the error handlers that boot installed (`filament-browser-testing` skill, `references/browser-test-case.md`).

Drop what the run created when the owning process exits (in a parallel run, the main process): register that drop in the boot check, after it passed and with the names it checked, so a refused boot registers none.

## Browser tests

Uploads, sign-in, panels and passkey ceremonies on tenant hosts need browser tests: see the `filament-browser-testing` skill.
