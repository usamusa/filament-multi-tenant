# Request plumbing for Filament and Livewire on tenant domains

Livewire registers its endpoints outside your panels and their middleware, under an APP_KEY-derived prefix (`/livewire-<8 hex chars>`): `/update`, `/upload-file`, `/preview-file/{filename}` and the script route. On a tenant host each of these needs tenancy too, or it reads the session, the database and the disks of the central app. The stancl docs show Livewire 3 code with a hard-coded `/livewire/update`; on Livewire 4 that route never matches.

## The update route

```php
// app/Providers/TenancyServiceProvider.php
use App\Http\Middleware\ApplyTenantRouteDefaults;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;

public function boot(): void
{
    $this->bootEvents();
    $this->mapRoutes();
    $this->makeTenancyMiddlewareHighestPriority();
    $this->configureLivewireForTenants();
}

protected function configureLivewireForTenants(): void
{
    // Livewire 4 hands the hashed path to this callback. Use it; a hard-coded
    // URI stops matching what the client posts to.
    Livewire::setUpdateRoute(function ($handle, $path) {
        return Route::post($path, $handle)->middleware([
            'web',
            'universal',
            InitializeTenancyByDomain::class,
            ApplyTenantRouteDefaults::class,
        ]);
    });
}
```

Without it every Livewire interaction on a tenant host, login included, fails with 419: the session written by the tenant panel lives in the tenant database, and the update request reads the central one.

Requirements: the `UniversalRoutes` feature and `$middleware->group('universal', [])` in `bootstrap/app.php` (see [identification-routing](identification-routing.md)). Tests resolve the URI with `Livewire::getUpdateUri()` and find the route by its `livewire.update` name suffix, never by a literal path.

## File uploads and previews

Filament's `FileUpload` (and any `WithFileUploads` component) first posts the file to Livewire's upload route, which stores it on `livewire.temporary_file_upload.disk` (the default disk unless set) under `livewire-tmp`. The form's save, a later update request, then picks it up. When the upload route runs without tenancy, the temporary file lands in the central storage and the tenant-context save can't find it.

```php
// config/livewire.php
'temporary_file_upload' => [
    // ...
    'middleware' => ['throttle:60,1', 'universal', InitializeTenancyByDomain::class],
],

// TenancyServiceProvider::configureLivewireForTenants()
use Livewire\Features\SupportFileUploads\FilePreviewController;

FilePreviewController::$middleware = ['web', 'universal', InitializeTenancyByDomain::class];
```

- Livewire prepends `web` to the upload middleware itself; the default is only `throttle:60,1`, so writing the key replaces the throttle unless you keep it.
- The temporary disk must be one of `tenancy.filesystem.disks`, so the temporary file lands in the tenant's storage.
- Test it in the browser: upload on a tenant host, save, and assert the file exists on the tenant's disk and not on the central one.

## Route defaults for a `{tenant}` domain parameter

A tenant panel on `->domain('{tenant}.'.$baseDomain)` needs the `tenant` parameter for every `route()` call, including those Filament makes during a Livewire save.

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Seeds the {tenant} domain parameter from the current host. Guarded on
 * tenancy, so it is a no-op on universal routes serving the central panel.
 */
final class ApplyTenantRouteDefaults
{
    public function handle(Request $request, Closure $next): Response
    {
        if (tenancy()->initialized) {
            URL::defaults(['tenant' => explode('.', $request->getHost())[0]]);
        }

        return $next($request);
    }
}
```

It belongs in the panel's middleware and on the Livewire update route; without it a save throws `UrlGenerationException` (missing parameter `tenant`).

## `bootstrap/app.php`

```php
->withMiddleware(function (Middleware $middleware): void {
    // Marker group for stancl's UniversalRoutes. Laravel still resolves it.
    $middleware->group('universal', []);

    // There is no route named "login": each panel has its own. `auth` runs
    // before ApplyTenantRouteDefaults, so pass the domain parameter explicitly.
    $middleware->redirectGuestsTo(fn (Request $request): string => tenancy()->initialized
        ? route('filament.app.auth.login', ['tenant' => Str::before($request->getHost(), '.')])
        : route('filament.admin.auth.login'));
})
->withExceptions(function (Exceptions $exceptions): void {
    // fetch() callers (passkey ceremonies, JSON endpoints) get 401/422, not redirects.
    $exceptions->shouldRenderJsonWhen(
        fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
    );
})
```

Replace `filament.app` / `filament.admin` with your panel ids.

## The tenant panel

```php
return $panel
    ->id('app')
    ->path('')
    ->domain('{tenant}.'.config('app.tenant_base_domain'))
    ->login()
    ->middleware([
        // Tenancy first: the session and everything after it run on the tenant connection.
        InitializeTenancyByDomain::class,
        PreventAccessFromCentralDomains::class,
        EnsureTenantIsActive::class, // your status gate, if any
        ApplyTenantRouteDefaults::class,
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        AuthenticateSession::class,
        ShareErrorsFromSession::class,
        // the CSRF middleware, SubstituteBindings, DisableBladeIconComponents, DispatchServingFilamentEvent
    ])
    ->authMiddleware([
        Authenticate::class,
    ]);
```

Copy the CSRF and remaining middleware names from the panel stub your Filament version generates; don't retype them from memory. Add `ScopeSessions` after `StartSession` if sessions aren't stored per tenant (see [sessions-queues](sessions-queues.md)). Don't add Filament's `->tenant()`: that is row-level scoping inside one database.

The central panel gets `->domains(config('tenancy.central_domains'))`, so it doesn't answer on tenant hosts.

## Assets

```php
// config/tenancy.php
'filesystem' => [
    // ...
    'asset_helper_tenancy' => false, // Filament, Livewire and Vite assets live in public/
],

// TenancyServiceProvider::boot()
TenantAssetsController::$tenancyMiddleware = InitializeTenancyByDomain::class;
```

Serve tenant files with `tenant_asset($path)` (the `/tenancy/assets/{path}` route, reading the tenant's `storage/app/public`). Keep `asset_helper_tenancy` on only if the app's own `asset()` calls must resolve to tenant files, and then enable the `ViteBundler` feature so Vite keeps using global asset URLs.
