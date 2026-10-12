---
name: stancl-tenancy-development
description: "Builds, debugs and tests database-per-tenant Laravel apps on stancl/tenancy 3 (checked against 3.10) with Filament 5 panels on tenant (sub)domains and Livewire 4. Use for anything touching tenancy: identification middleware and central vs tenant routes; the Tenant and Domain models and their lifecycle (provisioning pipelines, deletion, maintenance mode, tenant config); tenant databases and tenants:* commands; bootstrappers (database, cache, filesystem, queue, Redis, spatie permission cache); sessions, queues, Horizon and the scheduler; Livewire uploads; integrations (spatie packages, Sanctum, Passport, Telescope, Vite); user impersonation; resource syncing; and the isolation tests. Also use when debugging 419s, unstyled panels, UrlGenerationException, failed uploads, links that point at the central domain, or data, sessions or permissions leaking between tenants."
metadata:
  author: usamusa
---

# Database-per-tenant apps with stancl/tenancy 3

Written for stancl/tenancy **3.10** with multi-database tenancy, domain or subdomain identification, Filament 5 and Livewire 4. Run `composer show stancl/tenancy` first: v4 renames and restructures much of this. The official v3 docs are thin or outdated in places (the Livewire page hard-codes `/livewire/update`, the testing page is a stub). Where this skill and the docs disagree, this skill was checked against the 3.10 source.

## How it works

1. An identification middleware resolves the tenant from the request host and calls `tenancy()->initialize($tenant)`.
2. `TenancyInitialized` fires. The `BootstrapTenancy` listener runs the classes in `tenancy.bootstrappers` in config order, then fires `TenancyBootstrapped`.
3. The database bootstrapper builds a connection named `tenant` from the tenant and makes it the **default**. Only the default changes: a model or query that names a connection keeps it.
4. `tenancy()->end()` fires `TenancyEnded`. `RevertToCentralContext` reverts the bootstrappers **in the same order** (not reverse), and the default connection returns to the central one.
5. Queued jobs carry `tenant_id` in their payload. The worker initializes that tenant before each job and ends tenancy after it.

Isolation therefore lives in the connection and the bootstrappers. Anything they don't cover is shared between tenants: a shared session store, a cache without a tenant prefix, a disk missing from `tenancy.filesystem.disks`, static or singleton state.

## The two planes

| | Central plane | Tenant plane |
| --- | --- | --- |
| Database | the central connection (`tenancy.database.central_connection`) | one database per tenant, connection `tenant` |
| Domains | `tenancy.central_domains` | each tenant's rows in `domains` |
| Holds | tenants, domains, platform staff, shared catalogs, jobs, failed jobs, job batches, cache, impersonation tokens, Telescope | the tenant's users, sessions, roles and permissions, tokens, media and business data |
| Filament | its own panel, restricted to the central domains | a panel on `{tenant}.<base domain>` with the tenancy middleware first |

Code running in tenant context that needs central data uses a model whose `getConnectionName()` returns the central connection (stancl's `CentralConnection` trait), or `DB::connection(config('tenancy.database.central_connection'))`. Never rely on the default connection for central data.

## Switching context in code

| Call | What it does | Watch out |
| --- | --- | --- |
| `$tenant->run(fn ($tenant) => …)` | Runs in the tenant, then restores the previous context | If the callback throws, the restore never happens |
| `tenancy()->central(fn ($previous) => …)` | Ends tenancy, runs centrally, re-initializes the previous tenant | Same |
| `tenancy()->runForMultiple($tenants, fn ($tenant) => …)`, `Tenant::all()->runForEach(…)` | Loops over tenants, then restores | `null` **or an empty list** means every tenant; one exception stops the loop |
| `tenancy()->initialize($tenant)`, `tenancy()->end()` | Manual switching | Initializing the current tenant again is a no-op; a different tenant ends the current one first |
| `tenant()`, `tenant('id')`, type-hint `Stancl\Tenancy\Contracts\Tenant` | The current tenant | `null` in central context |

For per-tenant work that may throw, restore the context yourself. In a central-context loop:

```php
foreach (Tenant::cursor() as $tenant) {
    try {
        $tenant->run(fn () => $this->syncCatalog());
    } catch (Throwable $e) {
        report($e);
    } finally {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }
}
```

Never call `runForMultiple()` with a list that may be empty unless "all tenants" is what you want.

## Decisions to make once per app

- **Identification.** `InitializeTenancyByDomain` when `domains.domain` stores full hostnames (custom domains possible); `InitializeTenancyBySubdomain` when it stores only the label (`acme`, valid on every central domain); `InitializeTenancyByDomainOrSubdomain` for both.
- **Tenant keys.** UUID strings (the default) or auto-increment integers (`id_generator => null`, `bigIncrements` on `tenants.id`, the same type on `domains.tenant_id`).
- **Database users.** One privileged connection for every tenant (the default), or one user per tenant: MySQL's `PermissionControlledMySQLDatabaseManager`, or PostgreSQL roles through your own bootstrapper and provisioning.
- **Cache isolation.** stancl's tag-based bootstrapper needs a tagging store (Redis, Memcached); a key-prefix bootstrapper works on any store.
- **Sessions.** In the tenant database, in Redis with a per-tenant prefix, or behind `ScopeSessions`.
- **Assets.** Keep `asset_helper_tenancy` off and use `tenant_asset()` for tenant files.

## Setup checklist

1. `composer require stancl/tenancy`, `php artisan tenancy:install`, register `App\Providers\TenancyServiceProvider` in `bootstrap/providers.php`, `php artisan migrate`.
2. Tenant model: extends `Stancl\Tenancy\Database\Models\Tenant`, implements `TenantWithDatabase`, uses `HasDatabase` and `HasDomains`; set `tenancy.tenant_model`. See [tenant-lifecycle](references/tenant-lifecycle.md).
3. `config/tenancy.php`: central domains, bootstrappers, database (central connection, env-driven prefix, managers), filesystem (disks, `root_override`, `asset_helper_tenancy => false`), features (`UniversalRoutes`), migration parameters, and `'--force' => true` in the seeder parameters. See [databases-migrations](references/databases-migrations.md) and [bootstrappers](references/bootstrappers.md).
4. Routes: central routes wrapped per central domain, tenant routes behind identification plus `PreventAccessFromCentralDomains`, central Filament panels restricted with `->domains(config('tenancy.central_domains'))`. See [identification-routing](references/identification-routing.md).
5. `bootstrap/app.php`: the `universal` middleware group, `redirectGuestsTo()`, `shouldRenderJsonWhen()`; `trustHosts()` only with tenant domains included. See [request-plumbing](references/request-plumbing.md).
6. `TenancyServiceProvider`: provisioning pipelines, the Livewire update, upload and preview routes, `TenantAssetsController::$tenancyMiddleware`, resolver cache settings. See [request-plumbing](references/request-plumbing.md).
7. Queue, cache and session configuration. See [sessions-queues](references/sessions-queues.md) and [bootstrappers](references/bootstrappers.md).
8. Tenant migrations in `database/migrations/tenant`, including the vendor tables that belong to tenants. See [databases-migrations](references/databases-migrations.md).
9. Integrations. See [integrations](references/integrations.md).
10. `phpunit.xml` and the isolation tests. See [isolation-tests](references/isolation-tests.md).

## Topic files

| Read | When |
| --- | --- |
| [identification-routing](references/identification-routing.md) | Choosing middleware, central vs tenant routes, `$onFail`, universal routes, cross-domain links, URLs outside requests, cached lookup, custom domains |
| [tenant-lifecycle](references/tenant-lifecycle.md) | Tenant and Domain models, the data column, ids, provisioning pipelines, deletion, events, maintenance mode, tenant config, encrypted attributes |
| [databases-migrations](references/databases-migrations.md) | Database managers, names, template connections, credentials, which tables live where, `tenants:*` and tenant-aware commands, fleet loops, least-privilege roles |
| [bootstrappers](references/bootstrappers.md) | The built-in bootstrappers, writing your own, cache by prefix, the permission cache, filesystem and assets |
| [sessions-queues](references/sessions-queues.md) | Session isolation, queues, Horizon, the scheduler, job batches |
| [request-plumbing](references/request-plumbing.md) | Livewire 4 and Filament 5 on tenant domains: update route, uploads, previews, route defaults, assets |
| [integrations](references/integrations.md) | spatie packages, Sanctum, Passport, Telescope, Vite |
| [impersonation-syncing](references/impersonation-syncing.md) | User impersonation, resource syncing |
| [isolation-tests](references/isolation-tests.md) | Test setup, the required isolation tests, traps in tests |

## Symptoms

| Symptom | Cause | Fix |
| --- | --- | --- |
| Every Livewire request on a tenant domain fails with 419, login included | Livewire's update route isn't tenant-aware, so the session is read from the central database | Universal Livewire update route with the identification middleware |
| `Target class [universal] does not exist` | The `UniversalRoutes` marker group isn't registered | `$middleware->group('universal', [])` |
| `UrlGenerationException` (missing parameter `tenant`) when saving | The route-defaults middleware doesn't run on the Livewire route | Add it there, guarded by `tenancy()->initialized` |
| Uploads fail or the file is gone when the form is saved | Livewire's upload and preview routes run without tenancy, so the temporary file lands centrally | Identification middleware on both routes |
| Panel unstyled on tenant domains; Filament and Livewire assets 404 | `asset_helper_tenancy` rewrites `asset()` to tenant storage | Turn it off; use `tenant_asset()` for tenant files |
| "This cache store does not support tagging" | stancl's tag-based cache bootstrapper on a database or file store | Cache-by-prefix bootstrapper |
| Jobs dispatched inside a tenant never run | The queue table is on the tenant connection | Pin the queue connection to central |
| A job for a deleted tenant fails with `TenantCouldNotBeIdentifiedById` | Its payload names a tenant that no longer exists | Delete or fail such jobs deliberately; drain queues before deleting tenants |
| Another tenant's permissions, or roles without permissions after a switch | Permission cache not scoped per tenant, or its in-memory copy kept | Permission-cache bootstrapper |
| Rate limits or lockouts count across tenants | `RateLimiter` still holds the central cache store | Rebuild it in the cache bootstrapper |
| Signed in at tenant A, also signed in at tenant B | Shared session store (file, unprefixed Redis) and a wildcard session domain | Host-only session cookies plus tenant-scoped session storage or `ScopeSessions` |
| Links in queued mail point at the central domain | No request in the worker, so URLs use `app.url` | Build tenant URLs at dispatch time or from the tenant's domain |
| 500 "Route [login] not defined" | `auth` redirects guests to a route named `login` | `redirectGuestsTo()` per plane |
| `TenantCouldNotBeIdentifiedOnDomainException` on the central domain | A tenant route without `PreventAccessFromCentralDomains` | Add it to every tenant route group |
| `TenantDatabaseDoesNotExistException` (local only) | The tenant row exists but its database doesn't (failed or queued provisioning) | Re-run provisioning; check the pipeline |
| `DROP DATABASE` fails: "being accessed by other users" | A connection to the tenant database is still open | End tenancy and `DB::purge()` the connection before deleting the tenant |
| The central admin panel answers on tenant hosts | The panel's routes have no domain | `->domains(config('tenancy.central_domains'))` on the panel |
| Passport: key file not found | `Passport::keyPath()` falls back to `storage_path()`, which is tenant-suffixed | `Passport::loadKeysFrom()` with an absolute path, or env keys |
| Tests: tenancy never initializes, or the database never switches | `Event::fake()` without a list swallows the tenancy events | Fake only the events the test asserts |
| A test run dropped dev tenant databases | Tests and dev share a database prefix | Env-driven prefix, test prefix pinned in `phpunit.xml` |
