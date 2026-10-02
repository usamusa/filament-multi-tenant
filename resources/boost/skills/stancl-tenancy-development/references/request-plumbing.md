# Request plumbing for Filament and Livewire on tenant domains

## Livewire's update route serves both planes

Livewire posts to its update endpoint, which is registered outside the panels and their middleware. Without tenancy on it, a tenant-domain request reads the session from the central database and every interaction, login included, fails with 419.

`app/Providers/TenancyServiceProvider.php`:

```php
use App\Http\Middleware\ApplyTenantRouteDefaults;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Stancl\Tenancy\Middleware;

public function boot(): void
{
    $this->bootEvents();
    $this->mapRoutes();
    $this->makeTenancyMiddlewareHighestPriority();
    $this->makeLivewireUpdateRouteUniversal();
}

protected function makeLivewireUpdateRouteUniversal(): void
{
    // Livewire 4 derives the path from APP_KEY (/livewire-<hash>/update) and
    // hands it to this callback. Use $path; a hard-coded URI stops matching.
    Livewire::setUpdateRoute(function ($handle, $path) {
        return Route::post($path, $handle)->middleware([
            'web',
            'universal',
            Middleware\InitializeTenancyByDomain::class,
            ApplyTenantRouteDefaults::class,
        ]);
    });
}
```

`config/tenancy.php`:

```php
'features' => [
    Stancl\Tenancy\Features\UniversalRoutes::class,
],
```

## Domain parameter defaults

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

## Tenant panel

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
        EnsureTenantIsActive::class, // your status / trial gate, if any
        ApplyTenantRouteDefaults::class,
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        AuthenticateSession::class,
        ShareErrorsFromSession::class,
        // CSRF middleware, SubstituteBindings, DisableBladeIconComponents, DispatchServingFilamentEvent
    ])
    ->authMiddleware([
        Authenticate::class,
    ]);
```

Copy the CSRF and remaining middleware names from the panel stub your Filament version generates; don't retype them from memory. Don't add Filament's `->tenant()`: that is row-level scoping inside one database.

## Assets

```php
'filesystem' => [
    // ...
    'asset_helper_tenancy' => false, // Filament and Livewire assets live in public/
],
```

Serve tenant-specific files with `tenant_asset()` explicitly.
