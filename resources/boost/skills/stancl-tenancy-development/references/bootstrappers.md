# Bootstrappers and central pins

## Registration

`config/tenancy.php`:

```php
'bootstrappers' => [
    Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper::class, // or your own (per-tenant roles)
    App\Tenancy\Bootstrappers\CachePrefixBootstrapper::class,
    App\Tenancy\Bootstrappers\PermissionCacheBootstrapper::class,
    Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper::class,
    Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper::class,
],

'database' => [
    'central_connection' => env('DB_CONNECTION', 'central'),
    'prefix' => env('TENANT_DB_PREFIX', 'tenant_'),
    'suffix' => env('TENANT_DB_SUFFIX', ''),
    // ...
],
```

stancl reverts bootstrappers in reverse order. A bootstrapper whose revert depends on another one's state must not assume it still holds; the cache bootstrapper below re-initializes spatie's registrar itself for that reason.

## Cache isolated by key prefix

Replaces stancl's tag-based `CacheTenancyBootstrapper`, which throws "This cache store does not support tagging" on the database and file stores. It works on any store (database in dev, Redis in production).

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

Drop the `PermissionRegistrar` line if the app doesn't use spatie/laravel-permission.

## Permission cache per tenant

spatie caches the whole role and permission set under one fixed key and keeps an in-memory copy in the registrar. Without this bootstrapper, tenant B is authorized against tenant A's cached permissions in any process that switches tenants.

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

## Queue and cache tables on the central connection

In tenant context the default connection is the tenant's database. A `database` queue or cache store that follows the default connection writes jobs nobody polls, and binds stores to a connection tenancy is about to purge.

`config/queue.php`:

```php
'database' => [
    'driver' => 'database',
    // A job dispatched inside a tenant must land where the central worker polls;
    // QueueTenancyBootstrapper re-initializes the tenant for each job.
    'connection' => env('DB_QUEUE_CONNECTION', env('DB_CONNECTION', 'central')),
    'table' => env('DB_QUEUE_TABLE', 'jobs'),
    'queue' => env('DB_QUEUE', 'default'),
    'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
],
```

`config/cache.php`:

```php
'database' => [
    'driver' => 'database',
    // Tenant entries are isolated by key prefix (CachePrefixBootstrapper).
    'connection' => env('DB_CACHE_CONNECTION', env('DB_CONNECTION', 'central')),
    'table' => env('DB_CACHE_TABLE', 'cache'),
    'lock_connection' => env('DB_CACHE_LOCK_CONNECTION', env('DB_CONNECTION', 'central')),
    'lock_table' => env('DB_CACHE_LOCK_TABLE'),
],
```

## Central models used from tenant context

```php
public function getConnectionName(): ?string
{
    return config('tenancy.database.central_connection');
}
```

Use this for anything the tenant plane reads from the central plane (shared catalogs, content libraries, the tenant registry itself).
