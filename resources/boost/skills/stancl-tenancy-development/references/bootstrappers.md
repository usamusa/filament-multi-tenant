# Bootstrappers

A bootstrapper implements `Stancl\Tenancy\Contracts\TenancyBootstrapper` (`bootstrap(Tenant $tenant)` and `revert()`). The package registers every class in `tenancy.bootstrappers` as a **singleton**, runs `bootstrap()` in config order when tenancy starts, and runs `revert()` **in the same order** when it ends. Write each `revert()` so it doesn't depend on another bootstrapper having reverted first.

## The built-in bootstrappers

### DatabaseTenancyBootstrapper

Builds the `tenant` connection from the tenant's database config, makes it the default, and switches back to `tenancy.database.central_connection` on revert. Explicit connections (`DB::connection('x')`, a model's `getConnectionName()`, the `CentralConnection` trait) are untouched. See [databases-migrations](databases-migrations.md).

### CacheTenancyBootstrapper

Replaces the `cache` binding with a manager that tags every call with `tenant_base` + tenant key. It needs a store with tags (Redis, Memcached); on the database, file or array store each tenant-context call throws "This cache store does not support tagging". Tags allow `php artisan cache:clear --tags=tenant<key>`. `global_cache()` returns an untagged manager for central entries.

For stores without tags, use the prefix bootstrapper below instead.

### FilesystemTenancyBootstrapper

- **`storage_path()`** becomes `storage/<suffix_base><key>` (for example `storage/tenant42`) when `suffix_storage_path` is on. Paths Laravel resolved at boot (logs, compiled views, file sessions) keep the central path, but anything that calls `storage_path()` at runtime writes into the tenant folder: real-time facades (`storage/tenant42/framework/cache`), packages that keep keys or files under storage (Passport). Create the folders a tenant needs in the provisioning pipeline, or give those packages absolute paths at boot.
- **Disks** listed in `tenancy.filesystem.disks` get a tenant root: the `root_override` entry with `%storage_path%` (the suffixed storage path) and `%tenant%` (the tenant key) replaced, or the original root plus `/<suffix>`. For S3 that means a key prefix: a bucket disk with root `files` becomes `files/tenant42`. A disk missing from the list is shared by every tenant.
- **`asset()`** points at the tenant asset route (`/tenancy/assets/...`, served by `TenantAssetsController` from the tenant's `app/public`) or, when `ASSET_URL` is set, at that URL plus the suffix. That breaks every package that loads its JS and CSS through `asset()`, Filament and Livewire included. Set `asset_helper_tenancy` to `false`, use `tenant_asset()` for tenant files and `global_asset()` when you need the central URL explicitly.
- `TenantAssetsController::$tenancyMiddleware` defaults to `InitializeTenancyByDomain`; set it to the identification middleware you use. `tenancy.routes` set to `false` removes the asset route (custom controller or external storage).

### QueueTenancyBootstrapper

Adds `tenant_id` to the payload of jobs dispatched in tenant context. The worker initializes that tenant before each job and, since 3.8.5, ends tenancy after every job. `QueueTenancyBootstrapper::$forceRefresh` is deprecated and does nothing. A queue connection with `'central' => true` in its config never gets a `tenant_id`. See [sessions-queues](sessions-queues.md).

### RedisTenancyBootstrapper

For direct `Redis::` calls: sets the phpredis `OPT_PREFIX` of each connection in `tenancy.redis.prefixed_connections` to `prefix_base` + tenant key (no separator). It needs phpredis; predis is not supported. Never prefix the connection your queues or Horizon use: the worker would no longer see the jobs.

## Writing your own

```php
final class SearchIndexBootstrapper implements TenancyBootstrapper
{
    private ?string $originalIndex = null;

    public function bootstrap(Tenant $tenant): void
    {
        $this->originalIndex ??= config('scout.prefix');
        config(['scout.prefix' => $this->originalIndex.'tenant_'.$tenant->getTenantKey().'_']);
    }

    public function revert(): void
    {
        if ($this->originalIndex === null) {
            return;
        }

        config(['scout.prefix' => $this->originalIndex]);
        $this->originalIndex = null;
    }
}
```

- Register it in `tenancy.bootstrappers`. As a singleton it may keep the original values on itself.
- Changing config is not enough for services that are already resolved. Forget the instance (`app()->forgetInstance(...)`, a facade's `clearResolvedInstances()`) so the next use rebuilds it.
- A static `__constructStatic(Application $app)` method runs when the package registers its bootstrappers; the queue bootstrapper uses it to hook into the worker before any tenant exists.
- Every bootstrapper needs a test that switches tenants within one process (see [isolation-tests](isolation-tests.md)).

## Cache isolated by key prefix

Works on any store (database in dev, Redis in production) and replaces stancl's tag-based bootstrapper.

```php
<?php

declare(strict_types=1);

namespace App\Tenancy\Bootstrappers;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Scopes the cache to the current tenant by key prefix. On every switch the
 * resolved stores are forgotten and rebuilt with the new prefix, together with
 * everything that holds a store instance: the cache.store singleton, the
 * RateLimiter (lockouts must not count across tenants) and spatie's registrar.
 */
final class CachePrefixBootstrapper implements TenancyBootstrapper
{
    private ?string $centralPrefix = null;

    public function __construct(private Application $app) {}

    public function bootstrap(Tenant $tenant): void
    {
        $this->centralPrefix ??= (string) config('cache.prefix');

        $this->usePrefix($this->centralPrefix.'tenant_'.$tenant->getTenantKey().'_');
    }

    public function revert(): void
    {
        if ($this->centralPrefix === null) {
            return;
        }

        $this->usePrefix($this->centralPrefix);
        $this->centralPrefix = null;
    }

    private function usePrefix(string $prefix): void
    {
        config(['cache.prefix' => $prefix]);

        /** @var CacheManager $cache */
        $cache = $this->app->make('cache');
        $cache->forgetDriver(array_keys((array) config('cache.stores', [])));

        $this->app->forgetInstance('cache.store');
        $this->app->forgetInstance(RateLimiter::class);
        Cache::clearResolvedInstances();

        $this->app->make(PermissionRegistrar::class)->initializeCache();
    }
}
```

- Pin the `database` cache store to the central connection (`'connection' => env('DB_CACHE_CONNECTION', env('DB_CONNECTION', 'central'))`, the same for `lock_connection`), so no store binds to a tenant connection that tenancy is about to purge.
- Laravel uses a store's own `prefix` key before the global `cache.prefix`. A store with an explicit `prefix` therefore stays central in every context: use one for central data read from tenant context and for cached tenant lookup (see [identification-routing](identification-routing.md)).
- `global_cache()` builds a fresh manager from the current config, so with this bootstrapper it is **not** central inside a tenant. Use the fixed-prefix store or `tenancy()->central(fn () => Cache::get(...))` instead.
- Drop the `PermissionRegistrar` line if the app doesn't use spatie/laravel-permission.

## spatie/laravel-permission cache per tenant

spatie caches every role and permission under one key and keeps an in-memory copy in the registrar. The official docs only switch `cacheKey`; that leaves the in-memory copy of the previous tenant in place, which matters in every process that switches tenants (queue workers, `tenants:*` commands, tests).

```php
<?php

declare(strict_types=1);

namespace App\Tenancy\Bootstrappers;

use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

final class PermissionCacheBootstrapper implements TenancyBootstrapper
{
    public const CACHE_KEY_BASE = 'spatie.permission.cache';

    public function __construct(private PermissionRegistrar $registrar) {}

    public function bootstrap(Tenant $tenant): void
    {
        config(['permission.cache.key' => self::CACHE_KEY_BASE.'.tenant.'.$tenant->getTenantKey()]);

        // Re-reads the key and discards the in-memory collection.
        $this->registrar->initializeCache();
    }

    public function revert(): void
    {
        config(['permission.cache.key' => self::CACHE_KEY_BASE]);

        $this->registrar->initializeCache();
    }
}
```

Migrate spatie's tables into the tenant databases (and the central one if the central app has roles), and don't use spatie's teams feature for tenant isolation.

## Registration and central pins

```php
// config/tenancy.php
'bootstrappers' => [
    Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper::class, // or your own (per-tenant roles)
    App\Tenancy\Bootstrappers\CachePrefixBootstrapper::class,
    App\Tenancy\Bootstrappers\PermissionCacheBootstrapper::class,
    Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper::class,
    Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper::class,
],
```

Models the tenant plane reads from the central database:

```php
use Stancl\Tenancy\Database\Concerns\CentralConnection; // getConnectionName() returns the central connection

class Plan extends Model
{
    use CentralConnection;
}
```

`TenantConnection` is the counterpart for manual mode. With the database bootstrapper active, tenant models need neither.
