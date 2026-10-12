# Integrations

The rule for any package: decide whether its tables, routes, files and caches belong to the central app, to the tenants, or to both. Then put its migrations in the matching tree, give its routes the matching middleware, and make sure its storage and cache follow the context. Horizon is covered in [sessions-queues](sessions-queues.md).

## spatie/laravel-permission

Migrations into `database/migrations/tenant` (and the central tree too if the central app has roles); a bootstrapper that scopes the permission cache per tenant and calls `initializeCache()` on every switch. Code and reasons: [bootstrappers](bootstrappers.md). No teams feature for tenant isolation. Seeders that create roles must refresh spatie's cache themselves when model events are muted.

## spatie/laravel-activitylog

- **Tenant app:** publish the migration into `database/migrations/tenant`. The default `Activity` model uses the default connection, which is the tenant's inside tenant context. (Before v5 the config had a `database_connection` key; set it to `null` there.)
- **Central app:** a central-only log needs a custom activity model (config `activity_model`) whose `getConnectionName()` returns the central connection, so entries written from tenant context still land centrally.
- Subjects are stored with integer ids: models with UUID keys, including a UUID tenant model, can't be logged without changing the migration.

## spatie/laravel-medialibrary

- The `media` table goes into the tenant tree; conversions run as queued jobs and stay tenant-aware through the queue bootstrapper.
- **Local public disk:** the disk's `url` is the central `/storage` URL, but the files live in the tenant's storage folder. Generate URLs through the tenant asset route:

```php
use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator;

final class TenantAwareUrlGenerator extends DefaultUrlGenerator
{
    public function getUrl(): string
    {
        return $this->versionUrl(tenant_asset($this->getPathRelativeToRoot()));
    }
}
```

  Set it as `url_generator` in `config/media-library.php`. (The stancl docs use `asset()`, which only works with `asset_helper_tenancy` on.)
- **S3 disks:** list the disk in `tenancy.filesystem.disks`; each tenant gets a key prefix and the disk's own URLs work. No custom generator.

## spatie/laravel-login-link

Add the identification middleware to the package's `middleware` config (`web`, `InitializeTenancyByDomain::class`, `PreventAccessFromCentralDomains::class`), so the login link signs in a tenant user on the tenant host.

## Laravel Sanctum

- Tokens belong to tenant users: move the `personal_access_tokens` migration (published by `php artisan install:api`) into the tenant tree. Keep a copy centrally only if the central app issues tokens.
- The `/sanctum/csrf-cookie` route is registered with plain `web` middleware. For SPA authentication on tenant hosts, set `'routes' => false` in `config/sanctum.php` and register it yourself:

```php
// routes/tenant.php
use Laravel\Sanctum\Http\Controllers\CsrfCookieController;

Route::prefix(config('sanctum.prefix', 'sanctum'))->group(function () {
    Route::get('/csrf-cookie', [CsrfCookieController::class, 'show'])
        ->middleware(['web', InitializeTenancyByDomain::class]) // add 'universal' to serve both planes
        ->name('sanctum.csrf-cookie');
});
```

- Stateful domains: `Sanctum::currentRequestHost()` in `sanctum.stateful` marks every request's own host as stateful, so tenant domains don't need to be listed.
- Sanctum's SPA guide suggests a wildcard session domain (`.app.example`) for an SPA on a sibling subdomain. With tenants on subdomains, that sends one session cookie to every tenant. Serve the SPA from the tenant's own host instead.
- The `sanctum` guard isn't stateful: user impersonation needs a stateful guard such as `web`.

## Laravel Passport

Install with `php artisan install:api --passport`, then decide where OAuth lives.

**In the tenant app:**

1. Move Passport's migrations (tag `passport-migrations`) into `database/migrations/tenant` and run `tenants:migrate`.
2. Call `Passport::ignoreRoutes()` in a service provider's `register()`, copy Passport's `routes/web.php` into your routes, and give that group the identification middleware plus `PreventAccessFromCentralDomains` (or `universal` and the identification middleware for both planes).
3. **Keys:** one key pair for the whole app (`php artisan passport:keys`). Passport reads them through `Passport::keyPath()`, which falls back to `storage_path()` at runtime: the tenant-suffixed path. Call `Passport::loadKeysFrom(storage_path())` in `boot()` (resolved before tenancy, so it stays central), or use the `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY` environment variables.
4. **Clients** live in each tenant's database. Create them during provisioning, for example in the tenant seeder: `Artisan::call('passport:client', ['--personal' => true, '--name' => 'Personal Access Client', '--no-interaction' => true])` (other grant types: `--password`, `--client`, `--public`, `--device`).

Per-tenant key pairs (through the `TenantConfig` feature) add little security over shared keys and are untested upstream; prefer shared keys.

## Laravel Telescope

Telescope belongs to the central app: its migrations go in the central tree, its dashboard on a central domain. Its storage connection (`telescope.storage.database.connection`) must name the central connection, not follow the default, or entries recorded during tenant requests land in tenant databases. Enable the `TelescopeTags` feature to tag entries with `tenant:<id>`.

## Vite

With `asset_helper_tenancy` off (recommended), Vite works unchanged. With it on, enable the `ViteBundler` feature so Vite builds asset URLs with `global_asset()`.

## Other packages

Nova, Orchid and Sail have their own pages in the stancl docs. The same pattern applies: tenant migrations into the tenant tree, the identification middleware on the package's routes, and central-only parts on central domains.
