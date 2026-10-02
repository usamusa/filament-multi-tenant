# Tenancy isolation tests

Required whenever a change touches bootstrappers, connections, database grants, caches or anything stateful. Each test creates real tenants (real databases), so clean up explicitly: `RefreshDatabase` rolls back only the central transaction.

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

/** Delete every tenant created in this test (drops their databases). */
function deleteAllTenants(): void
{
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
    // Purge any extra connections the test opened to tenant databases first,
    // or DROP DATABASE is blocked by the open session.
    deleteAllTenants();
});
```

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

## A tenant's database role cannot reach another tenant (PostgreSQL)

Postgres words errors in the server's locale, and pdo_pgsql reports every failed connection as SQLSTATE `08006`, so assert the catalog and the code, not the message.

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

A permission denial inside a database (for example UPDATE on an append-only table) is SQLSTATE `42501`.

## Test databases on a shared server

`phpunit.xml`:

```xml
<env name="APP_URL" value="http://myapp.test"/>
<env name="TENANT_BASE_DOMAIN" value="myapp.test"/>
<env name="DB_DATABASE" value="myapp_central_test"/>
<env name="TENANT_DB_PREFIX" value="testing_tenant_"/>
```

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

Have the suite refuse database names that don't look like test databases before it creates or drops anything, and drop what it created when the owning process exits (in a parallel run, the main process).
