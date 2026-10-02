---
name: stancl-tenancy-development
description: "Builds and debugs database-per-tenant Laravel apps on stancl/tenancy 3 with Filament 5 panels on tenant (sub)domains and Livewire 4. Use when setting up or changing tenancy (identification middleware, bootstrappers, config/tenancy.php, TenancyServiceProvider, routes/tenant.php, tenant migrations, provisioning, tenants:* commands); when deciding whether state is central or per tenant (queue, cache, rate limiter, session, filesystem, spatie permission cache); when debugging 419s, unstyled panels, UrlGenerationException or cross-tenant leaks on tenant domains; and when writing tenancy isolation tests."
metadata:
  author: usamusa
---

# Database-per-tenant apps with stancl/tenancy 3

Each tenant has its own database and is identified by its (sub)domain. Initializing tenancy switches the default connection to the tenant's database and runs the bootstrappers; ending it reverts them in reverse order. Isolation therefore lives in the connection and the bootstrappers, not in `tenant_id` columns, and it is only as complete as the list of things those bootstrappers cover.

## The two planes

| | Central plane | Tenant plane |
| --- | --- | --- |
| Database | the central connection (`tenancy.database.central_connection`) | one database per tenant (prefix + tenant key + suffix) |
| Domains | `tenancy.central_domains` | each tenant's rows in `domains` |
| Holds | tenants, domains, platform staff, shared catalogs, and the **jobs and cache tables** | the tenant's users, sessions, roles and business data |
| Filament | its own panel on the central domain | a panel on `{tenant}.<base domain>`, tenancy middleware first |

Code running in tenant context that needs central data uses a model whose `getConnectionName()` returns the central connection, or `DB::connection(config('tenancy.database.central_connection'))`. Never the default connection.

## Setup checklist

Use it for a new app and to audit an existing one. Each item prevents a failure listed under "Symptoms".

1. **`config/tenancy.php`**: bootstrappers for the database, the cache by key prefix, the permission cache, `FilesystemTenancyBootstrapper` and `QueueTenancyBootstrapper` (not stancl's tag-based `CacheTenancyBootstrapper`); `database.prefix` from an env variable (`TENANT_DB_PREFIX`) plus a suffix; `filesystem.asset_helper_tenancy => false`; `features => [UniversalRoutes::class]`; `migration_parameters['--path'] => [database_path('migrations/tenant')]`.
2. **`bootstrap/app.php`**: `$middleware->group('universal', [])`, `redirectGuestsTo()` per plane, and `shouldRenderJsonWhen()` including `$request->expectsJson()`.
3. **`TenancyServiceProvider`**: re-register the Livewire update route as universal with the identification and route-defaults middleware; keep `makeTenancyMiddlewareHighestPriority()`.
4. **`config/queue.php`, `config/cache.php`**: the `database` queue and cache stores on the central connection.
5. **Central models**: `getConnectionName()` returns the central connection.
6. **Filament**: the tenant panel uses `->domain('{tenant}.'.config('app.tenant_base_domain'))` and middleware that starts with `InitializeTenancyByDomain`, `PreventAccessFromCentralDomains`, any tenant status gate and the route-defaults middleware, then the session stack. Don't use Filament's `->tenant()`. The central panel lives on a path of the central domain.
7. **spatie/laravel-permission**: migrate its tables into the central database and every tenant database (copy the vendor migration into `database/migrations/tenant`), add the permission-cache bootstrapper, don't use teams.
8. **`phpunit.xml`**: test database names, a test-only `TENANT_DB_PREFIX`, and `APP_URL` plus the tenant base domain pinned.
9. **Isolation tests**: [references/isolation-tests.md](references/isolation-tests.md).

Code for items 1–6: [references/bootstrappers.md](references/bootstrappers.md) and [references/request-plumbing.md](references/request-plumbing.md).

## Working in tenant context

- For scoped work, `$tenant->run(fn () => ...)` restores the previous context afterwards. Prefer it to `tenancy()->initialize()` / `tenancy()->end()` pairs outside tests.
- Commands: `tenants:migrate` (plain `migrate` touches only the central database), `tenants:run`, `tenants:seed`. Custom fleet commands iterate the tenants and call `$tenant->run()`; the bootstrappers reset per-tenant caches on every switch.
- A job dispatched in tenant context carries the tenant (`QueueTenancyBootstrapper`) and runs in it again. Start `handle()` with an assertion that tenancy is initialized.
- Schedule one command that iterates the tenants, not one schedule entry per tenant.
- Provisioning runs as a `JobPipeline` on `TenantCreated` (create the database, migrate, seed). Make each step idempotent so a failed provisioning can be retried.

## Least-privilege tenant databases (PostgreSQL, optional)

When each tenant connection authenticates as its own role (CONNECT revoked from PUBLIC and granted only to that role, the role owning nothing):

- Provisioning and migrations run as a separate privileged migrator role. `tenants:migrate` initializes tenancy first, so elevate the connection in a `MigratingDatabase` listener and re-apply the grants in a `DatabaseMigrated` listener.
- Set `ALTER DEFAULT PRIVILEGES` for the migrator so tables it creates are usable by the app role.
- `tenants:rollback` and `tenants:migrate-fresh` run as the app role and fail on table ownership. Keep it that way: otherwise they are a way to destroy data, or an audit trail, from inside the app.
- Prove it in tests: role A cannot connect to database B. `has_database_privilege(role, db, 'CONNECT')` is false, and the attempt fails with SQLSTATE `08006`.

## Symptoms

| Symptom | Cause | Fix |
| --- | --- | --- |
| Every Livewire request on a tenant domain fails with 419, login included | Livewire's update route isn't tenant-aware, so the session is read from the central database | Universal Livewire route with the identification middleware |
| `Target class [universal] does not exist` | The `UniversalRoutes` marker group isn't registered | `$middleware->group('universal', [])` |
| `UrlGenerationException` (missing parameter `tenant`) on save | The route-defaults middleware doesn't run on the Livewire route | Add it there, guarded by `tenancy()->initialized` |
| Panel unstyled on tenant domains; Filament and Livewire assets 404 | `asset_helper_tenancy` rewrites `asset()` to tenant storage | Turn it off; use `tenant_asset()` for tenant files |
| "This cache store does not support tagging" | stancl's tag-based cache bootstrapper on a database or file store | Cache-by-prefix bootstrapper |
| Jobs dispatched inside a tenant never run | The queue table is on the tenant connection | Pin the queue connection to central |
| Another tenant's permissions, or roles without permissions after a switch | Permission cache not scoped per tenant, or its in-memory copy kept | Permission-cache bootstrapper |
| Rate limits or lockouts count across tenants | `RateLimiter` still holds the central cache store | Rebuild it in the cache bootstrapper |
| 500 "Route [login] not defined" | `auth` redirects guests to a route named `login` | `redirectGuestsTo()` per plane |
| A test run dropped dev tenant databases | Tests and dev share a database prefix | Env-driven prefix; test prefix pinned in `phpunit.xml` |
