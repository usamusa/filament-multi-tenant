# Tenant lifecycle

## The Tenant and Domain models

```php
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    public static function getCustomColumns(): array
    {
        return ['id', 'name', 'status', 'trial_ends_at']; // keep 'id'
    }
}
```

The base model already brings:

| Trait | Effect |
| --- | --- |
| `CentralConnection` | Always queries the central database, also in tenant context |
| `GeneratesIds` | Generates a UUID when no id is given, unless `tenancy.id_generator` is `null` |
| `HasDataColumn` | Attributes without a column of their own are stored in the `data` JSON column |
| `HasInternalKeys` | `getInternal()` / `setInternal()` for `tenancy_*` attributes |
| `TenantRun` | `$tenant->run($callback)` |
| `InvalidatesResolverCache` | Clears cached identification on save and delete |

The `Domain` model brings `CentralConnection`, lowercasing, the occupied-domain check and resolver-cache invalidation. `HasDomains` adds `domains()` (foreign key `tenant_id`) and `createDomain('acme.example')`.

## Ids

- UUID strings are the default. For integers: set `tenancy.id_generator` to `null`, change `tenants.id` to `bigIncrements` (or `id()`), and give `domains.tenant_id` the same type. `getIncrementing()` then returns `true`.
- Integer ids are enumerable. Never expose them where guessing one grants anything; keep an opaque handle (a UUID column) for external references.
- spatie/laravel-activitylog stores subjects with integer ids: logging the Tenant model needs integer tenant ids.

## The data column and internal keys

- Attributes without a column go into `data` and are encoded only on retrieval and save. Query them as `where('data->plan', 'pro')`. Anything you filter or sort by deserves a real column, listed in `getCustomColumns()`.
- Keys starting with `tenancy_` belong to the package: `tenancy_db_name`, `tenancy_db_username`, `tenancy_db_password`, `tenancy_db_connection`, `tenancy_create_database` and any other `tenancy_db_*` connection override. Don't name your own attributes with that prefix.
- Secrets in `data` (database passwords, API keys) are stored as plain JSON. Give them custom columns (`string(…, 512)->nullable()`) and an `encrypted` cast.

## Provisioning

`TenancyServiceProvider` maps `TenantCreated` to a job pipeline:

```php
Events\TenantCreated::class => [
    JobPipeline::make([
        Jobs\CreateDatabase::class,  // name + credentials, then CREATE DATABASE
        Jobs\MigrateDatabase::class, // tenants:migrate --tenants=<id>
        Jobs\SeedDatabase::class,    // tenants:seed --tenants=<id>
        // your jobs: create the first user, default settings, storage folders
    ])->send(fn (Events\TenantCreated $event) => $event->tenant)
      ->shouldBeQueued(false),
],
```

- Separate listeners give no ordering guarantee once they are queued and can't stop each other; the pipeline runs its jobs in sequence and stops at the first exception (or a job returning `false`).
- `CreateDatabase` calls `makeCredentials()`: it stores the database name (and generated credentials, for managers that create users) on the tenant and saves it. It throws `TenantDatabaseAlreadyExistsException` when the database exists, so a naive retry fails. Make your own pipeline jobs idempotent and give the tenant a status (provisioning, active, failed) the UI can show.
- `->shouldBeQueued(true)` keeps signup requests fast, but the tenant row then exists before its database. Gate access on the status, never on the row's existence. `JobPipeline::$shouldBeQueuedByDefault = true` changes the default for every pipeline.
- `Tenant::create(['tenancy_create_database' => false])` adopts a database that already exists: `CreateDatabase` returns `false`, which stops the **whole** pipeline, migrations included.
- Domains are usually created after the tenant, so pipeline jobs can't rely on them. Pass what they need through the tenant's attributes.
- `SeedDatabase` runs `tenants:seed`, which, like `db:seed`, needs `--force` in production. The default config ships that line commented out: put `'--force' => true` into `tenancy.seeder_parameters`. It seeds the class in `'--class'`, by default the central `DatabaseSeeder`: point it at a root seeder for tenants.

## Deletion

`TenantDeleted` runs `DeleteDatabase`, which drops the database.

- PostgreSQL's `DROP DATABASE` fails while any session is connected to that database. End tenancy and `DB::purge()` every connection you opened to it (in tests too) before deleting the tenant, and stop the workers that might still be connected.
- Jobs still queued for the tenant fail with `TenantCouldNotBeIdentifiedById` once it is gone. Drain or delete them first.
- Only the database is dropped. The tenant's storage folder (`storage/tenant<key>`), its bucket prefix, cache entries and search indexes stay: add jobs for them to the `TenantDeleted` pipeline.
- Deleting is irreversible. Suspend first (a status the identification middleware respects), export or back up, and delete later in a deliberate step.

## Events

All in `Stancl\Tenancy\Events`:

| Group | Events |
| --- | --- |
| Tenancy | `InitializingTenancy`, `TenancyInitialized`, `BootstrappingTenancy`, `TenancyBootstrapped`, `EndingTenancy`, `TenancyEnded`, `RevertingToCentralContext`, `RevertedToCentralContext` |
| Tenant | `CreatingTenant`, `TenantCreated`, `SavingTenant`, `TenantSaved`, `UpdatingTenant`, `TenantUpdated`, `DeletingTenant`, `TenantDeleted` |
| Domain | `CreatingDomain`, `DomainCreated`, `SavingDomain`, `DomainSaved`, `UpdatingDomain`, `DomainUpdated`, `DeletingDomain`, `DomainDeleted` |
| Database | `CreatingDatabase`, `DatabaseCreated`, `MigratingDatabase`, `DatabaseMigrated`, `SeedingDatabase`, `DatabaseSeeded`, `RollingBackDatabase`, `DatabaseRolledBack`, `DeletingDatabase`, `DatabaseDeleted` |
| Resource syncing | `SyncedResourceSaved`, `SyncedResourceChangedInForeignDatabase` |

- Listen to `TenancyBootstrapped` (not `TenancyInitialized`) for work that needs the tenant context to be in place.
- `MigratingDatabase`, `DatabaseMigrated`, `SeedingDatabase` and `DatabaseSeeded` fire **inside** the tenant context: their listeners must address the central database explicitly.
- Listeners can be registered anywhere; the arrays in `TenancyServiceProvider::events()` are only a convenience.

## Maintenance mode per tenant

```php
use Stancl\Tenancy\Database\Concerns\MaintenanceMode;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains, MaintenanceMode;
}

$tenant->putDownForMaintenance(['message' => 'Back at 6', 'retry' => 60, 'allowed' => ['203.0.113.10']]);
$tenant->update(['maintenance_mode' => null]); // back up
```

Add `CheckTenantForMaintenanceMode` to the tenant routes after identification. It answers 503 (with `Retry-After` when set) unless the client IP is in `allowed`.

## Tenant config

The `TenantConfig` feature copies tenant attributes into Laravel config when the tenant is bootstrapped and restores the originals when tenancy ends:

```php
// TenancyServiceProvider::boot()
TenantConfig::$storageToConfigMap = [
    'mail_from_address' => 'mail.from.address',
    'locale' => ['app.locale', 'translatable.fallback_locale'],
];
```

Application code then reads `config('mail.from.address')` and stays unaware of tenancy. `null` attributes leave the default untouched. Queue workers re-initialize tenancy for every job, so changed values apply to the next job.

## Status and access

stancl has no notion of a suspended or expired tenant. Add a status column and a middleware after identification that refuses inactive tenants (and still lets support reach a status page). Remember that cached identification keeps serving the old tenant object until the tenant is saved.
