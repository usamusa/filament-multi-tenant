@if($assist->hasPackage('stancl/tenancy', '^3.0'))
## Multi-database tenancy (stancl/tenancy 3)

One database per tenant, identified by its (sub)domain. Setup checklist, bootstrapper code and isolation tests: `stancl-tenancy-development` skill.

### Requests on tenant domains

- Run the tenant identification middleware (`InitializeTenancyByDomain`, then `PreventAccessFromCentralDomains`) first in a tenant panel's middleware, before `StartSession`, so sessions and auth use the tenant database. Don't combine stancl's database-per-tenant isolation with Filament's own `->tenant()` (row-level) tenancy.
- Livewire's update route lives outside the panels. Register it as a universal route with the identification middleware; otherwise Livewire requests on tenant domains read the session from the central database and every interaction, login included, fails with 419. This needs the `UniversalRoutes` feature and a registered, empty `universal` middleware group ("Target class [universal] does not exist" otherwise).
- Middleware that seeds the `{tenant}` domain parameter through `URL::defaults()` must also run on the Livewire update route, guarded by `tenancy()->initialized`. Otherwise saves throw `UrlGenerationException` while Filament builds redirect and navigation URLs.
- Keep `tenancy.filesystem.asset_helper_tenancy` off. When on, every `asset()` call in tenant context points at tenant storage, so Filament and Livewire assets 404 and panels render unstyled. Use `tenant_asset()` for tenant files.
- Panels have no route named `login`, so `auth` sends guests into a 500. Configure `redirectGuestsTo()` per plane, passing the domain parameter explicitly (`auth` runs before the route defaults are set).
- JSON callers outside `api/*` (passkey ceremonies, `fetch()` endpoints) need `shouldRenderJsonWhen()` to also check `$request->expectsJson()`, or they get redirects instead of 401 and 422.

### State that must not cross tenants

- Pin the database queue connection to the central connection. In tenant context the default connection is the tenant's database, so jobs would land in a jobs table no worker polls. `QueueTenancyBootstrapper` still restores the tenant for each job.
- stancl's `CacheTenancyBootstrapper` tags every cache call and throws on stores without tags (database, file). Isolate the cache by key prefix in a custom bootstrapper that rebuilds the stores, `cache.store`, the `RateLimiter` and spatie's permission registrar on every switch, and pin the database cache store to the central connection.
- spatie/laravel-permission caches every role and permission under one key and in memory. A bootstrapper sets `permission.cache.key` per tenant and calls `PermissionRegistrar::initializeCache()` on bootstrap and revert; without it, any process that switches tenants (queue workers, `tenants:*` commands, tests) authorizes one tenant with another tenant's permissions. Don't use spatie's teams feature for tenant isolation.
- Central models read from tenant context return `config('tenancy.database.central_connection')` from `getConnectionName()`.
- A queued job that touches tenant data asserts the tenant context at the top of `handle()`.
- Every new stateful service (cache, session, rate limiter, disk, static or singleton state) is deliberately central or per tenant, proven by a test that switches tenants within one process.

### Databases, environments and tests

- Name tenant databases through `tenancy.database.prefix`/`suffix` with an environment-specific prefix, and pin a test-only prefix in `phpunit.xml`; otherwise tests that provision and drop real databases drop dev tenants on a shared server. Parallel runs need a per-process prefix as well (`ParallelTesting::token()`), since each process numbers its tenants from 1.
- Pin `APP_URL` and the tenant base domain in `phpunit.xml`, so URL assertions don't depend on a developer's `.env`.
- `RefreshDatabase` doesn't roll back provisioned tenant databases. End tenancy, purge tenant connections and delete the tenants in `afterEach` or `tearDown`.
- Changes to bootstrappers, connections, grants or caches need isolation tests: tenant A cannot reach tenant B's database or data; caches and rate limits are per tenant; the permission cache does not leak within one process; queued jobs run in the tenant that dispatched them.
- PostgreSQL words errors in the server's locale, and pdo_pgsql reports every failed connection as SQLSTATE `08006`. Assert SQLSTATE codes and catalog queries (`has_database_privilege()`), never English error text.
@endif
