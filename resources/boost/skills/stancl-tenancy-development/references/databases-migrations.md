# Tenant databases, migrations and commands

## Database managers

`tenancy.database.managers` maps the template connection's driver to a manager that creates and drops tenant databases:

| Driver | Manager | Notes |
| --- | --- | --- |
| `pgsql` | `PostgreSQLDatabaseManager` | `CREATE DATABASE "<name>" WITH TEMPLATE=template0`; `DROP DATABASE "<name>"` (fails while sessions are connected) |
| `mysql`, `mariadb` | `MySQLDatabaseManager` | One shared database user |
| `mysql` | `PermissionControlledMySQLDatabaseManager` | Creates a user per tenant with `PermissionControlledMySQLDatabaseManager::$grants`; never include `GRANT OPTION` |
| `sqlite` | `SQLiteDatabaseManager` | One file per tenant |
| `pgsql` | `PostgreSQLSchemaManager` | A schema per tenant in one database: a different isolation model, not covered here |

## Database names

- The name is `tenancy.database.prefix` + tenant key + `tenancy.database.suffix`, or whatever `DatabaseConfig::generateDatabaseNamesUsing(fn (Tenant $tenant) => …)` returns. `Tenant::create(['tenancy_db_name' => 'acme'])` sets it explicitly.
- `CreateDatabase` stores the name on the tenant as `tenancy_db_name`. From then on the stored name wins: changing the prefix later affects only new tenants.
- Make the prefix environment-specific (`'prefix' => env('TENANT_DB_PREFIX', 'tenant_')`) and pin a test-only prefix in `phpunit.xml`, so a test run that drops its tenants doesn't drop dev databases on the same server. A cached configuration replaces phpunit.xml's values in tests, so pin `APP_CONFIG_CACHE` there too and check the names at boot (`isolation-tests.md`, "Test databases on a shared server").

## The `tenant` connection and templates

- The package writes and purges `database.connections.tenant` itself and resets it when tenancy ends. **Never define a connection named `tenant`** in `config/database.php`; name a template `tenant_template`.
- The template for a tenant's connection is the first that is set: the tenant's `tenancy_db_connection`, `tenancy.database.template_tenant_connection`, or the central connection.
- Every other `tenancy_db_*` attribute overrides a key of the template: `tenancy_db_host`, `tenancy_db_port`, `tenancy_db_username`, `tenancy_db_password`. This is how one tenant moves to a dedicated database server: dump and restore its database there, then update those attributes (and invalidate cached identification).
- In the `local` environment the database bootstrapper checks that the database exists and throws `TenantDatabaseDoesNotExistException`; elsewhere a missing database fails on the first query.

## Credentials

- Database credentials are generated only for managers that create users: `DatabaseConfig::generateUsernamesUsing()` / `generatePasswordsUsing()` customize them. They are stored on the tenant, by default inside the `data` JSON in plain text: store them in custom columns with an `encrypted` cast (see [tenant-lifecycle](tenant-lifecycle.md)), or in a secret store and inject them through a custom bootstrapper.
- A tenant's database user must not be able to grant itself more rights or reach other tenants' databases. Test that it can't (see [isolation-tests](isolation-tests.md)).

## Which tables live where

| Central database (`database/migrations`) | Tenant databases (`database/migrations/tenant`) |
| --- | --- |
| `tenants`, `domains` | `users`, `password_reset_tokens` |
| `jobs`, `job_batches`, `failed_jobs` | `sessions` (database session driver) |
| `cache`, `cache_locks` (when the cache store is pinned to central) | spatie permission tables (also central if the central app uses roles) |
| `tenant_user_impersonation_tokens` | `personal_access_tokens`, Passport's tables, passkeys |
| Telescope, Pulse | media, notifications, activity log (when the tenant app logs) |
| Platform staff, plans, shared catalogs | Everything the tenant's business owns |

Move a vendor migration by publishing it and moving the file into `database/migrations/tenant`. Use anonymous migration classes (`return new class extends Migration`); the docs' warning about colliding class names applies only to named classes.

## Migrating tenants

```bash
php artisan migrate                       # central database only
php artisan tenants:migrate               # every tenant
php artisan tenants:migrate --tenants=1 --tenants=7
```

- `tenancy.migration_parameters` supplies the defaults (`--force`, `--path` = `database/migrations/tenant`, `--realpath`); options given on the command line win. From code, `--tenants` must be an array: `Artisan::call('tenants:migrate', ['--tenants' => [$tenant->getTenantKey()]])`.
- For each tenant the command fires `MigratingDatabase`, runs Laravel's migrator on the tenant connection, then fires `DatabaseMigrated`. These events are the hook for switching to a privileged role and back (see below).
- The tenants are migrated one after another. An exception stops the run, and the remaining tenants stay unmigrated. Keep migrations safe to re-run, watch the output, and run `tenants:migrate` again after fixing the failure.
- Deploy order: central `migrate` first, then `tenants:migrate`. Code that reads a new tenant column must tolerate tenants that aren't migrated yet, or ship after the migrations.
- Large fleets: migrate in batches (`--tenants`) or dispatch a queued job per tenant that calls `tenants:migrate` for that one tenant.

## Rolling back, wiping and seeding

- `tenants:rollback` and `tenants:migrate-fresh` (which runs `db:wipe` on the tenant connection, then migrates) destroy tenant data across the fleet. Keep them out of production. If tenants connect with least-privilege roles, these commands fail on ownership, which is the point.
- `tenants:seed` runs the root seeder named in `tenancy.seeder_parameters['--class']` inside every tenant (plus `'--force' => true`, which production needs). The shipped default is the central `DatabaseSeeder`: give the tenants a root seeder of their own (a fully qualified class name, for example `Database\Seeders\Tenant\TenantDatabaseSeeder`) that calls only tenant seeders, or every tenant receives the central seed data — or the command fails where central seeding refuses tenant context.

## Running your own commands for tenants

- One-off: `php artisan tenants:run my:command --tenants=7 --option="queue=1" --argument="name=foo"`. Without `--tenants` it runs for every tenant.
- Built in: make the command tenant-aware.

```php
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Stancl\Tenancy\Concerns\TenantAwareCommand;

class PruneDrafts extends Command
{
    use TenantAwareCommand, HasATenantsOption; // or HasATenantArgument for exactly one

    protected $signature = 'drafts:prune';

    public function handle(): int
    {
        // runs once per tenant, inside that tenant
        return self::SUCCESS;
    }
}
```

`TenantAwareCommand` runs `handle()` inside `$tenant->run()` for each tenant; the exit code is the last non-zero one. The option and argument traits add their parameters in the constructor: a command with its own constructor must call `$this->specifyParameters()` at its end. `tenants:list` lists the tenants with their domains.

## Least-privilege tenant databases (PostgreSQL)

stancl's PostgreSQL manager connects every tenant with the central credentials. For isolation enforced by the database itself:

- Provision a role per tenant that owns nothing, revoke `CONNECT` on each tenant database from `PUBLIC` and grant it only to that tenant's role. Cross-tenant access then fails at connection time.
- A custom database bootstrapper connects with that role (credentials from a secret store), and a custom provisioning job creates the database, the role and the grants (replacing `CreateDatabase`).
- Migrations need a privileged migrator role, but `tenants:migrate` runs on the unprivileged tenant connection. Switch the connection to the migrator in a `MigratingDatabase` listener and re-apply the grants in a `DatabaseMigrated` listener. Set `ALTER DEFAULT PRIVILEGES` for the migrator so the app role can use tables the migrator creates.
- `tenants:rollback` and `tenants:migrate-fresh` keep running as the app role and fail on ownership. Don't add elevation for them.
- Test it: role A cannot connect to database B, and protected tables reject writes the app role must not make (see [isolation-tests](isolation-tests.md)).
