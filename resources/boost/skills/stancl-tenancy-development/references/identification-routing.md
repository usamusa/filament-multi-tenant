# Identification and routing

## Choosing the middleware

| Middleware | `domains.domain` stores | Resolves |
| --- | --- | --- |
| `InitializeTenancyByDomain` | Full hostnames: `acme.app.example`, `acme.de` | The exact request host |
| `InitializeTenancyBySubdomain` | Labels only: `acme` | The first label of a host under any central domain |
| `InitializeTenancyByDomainOrSubdomain` | Both | Subdomain logic when the host ends with a central domain, domain logic otherwise |

Path (`InitializeTenancyByPath`, routes prefixed with `/{tenant}`) and request-data identification (`X-Tenant` header or `tenant` query parameter) also exist. They suit single-domain products and APIs and are out of scope here.

Details from the 3.10 source:

- Domains are lowercased when saved, and a domain that already belongs to another tenant throws `DomainOccupiedByOtherTenantException`.
- The subdomain middleware throws `NotASubdomainException` for a central domain itself, `localhost`, an IP address, or a host outside every central domain. `InitializeTenancyBySubdomain::$subdomainIndex` (default `0`) selects which label is the tenant.
- Its central-domain check is a plain suffix match (`Str::endsWith`). With central domain `app.example`, the host `evilapp.example` counts as a subdomain. Use dotted central domains you control, or validate hosts upstream.
- The resolved domain model is in `DomainTenantResolver::$currentDomain` and can be injected as `Stancl\Tenancy\Contracts\Domain`.

## Central and tenant routes

Tenant routes (`routes/tenant.php`, mapped by `TenancyServiceProvider`) are registered after the central ones and take precedence on equal paths. Two rules keep the planes apart:

1. **Every tenant route group gets `PreventAccessFromCentralDomains`.** Without it, a tenant route on a central domain throws `TenantCouldNotBeIdentifiedOnDomainException`. The middleware aborts with 404 instead; customize with `PreventAccessFromCentralDomains::$abortRequest = fn ($request, $next) => …`.
2. **Central routes are bound to the central domains.** Since Laravel 11 there is no `RouteServiceProvider`, so wrap them in the route file:

```php
// routes/web.php
foreach (config('tenancy.central_domains') as $domain) {
    Route::domain($domain)->group(function () {
        Route::get('/', LandingController::class);
        // ...
    });
}
```

With more than one central domain the same route is registered once per domain, so route names repeat: Laravel's `route:cache` refuses duplicate names. Use one central domain per environment, or skip names on these routes.

Bind central **Filament panels** the same way: `->domains(config('tenancy.central_domains'))` (or `->domain()` for one). A panel registered only with `->path('admin')` also answers on every tenant host.

Keep `makeTenancyMiddlewareHighestPriority()` in `TenancyServiceProvider`: it moves the identification middleware to the top of Laravel's middleware priority, so tenancy is initialized before sessions, auth and bindings even when the route lists them in another order.

## When identification fails

Each identification middleware has its own static `$onFail`, called with `($exception, $request, $next)`:

```php
InitializeTenancyByDomain::$onFail = function ($exception, $request, $next) {
    return redirect('https://'.config('tenancy.central_domains')[0]);
};
```

The `UniversalRoutes` feature overwrites `$onFail` on `InitializeTenancyByDomain` and `InitializeTenancyBySubdomain` (see below). With universal routes enabled, custom failure handling must reimplement the universal check itself: a route carrying the `universal` group continues centrally, anything else fails.

## Universal routes

A route that works on both planes: tenancy is initialized when the host belongs to a tenant, otherwise it runs centrally.

```php
// config/tenancy.php
'features' => [Stancl\Tenancy\Features\UniversalRoutes::class],

// bootstrap/app.php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->group('universal', []); // a marker; Laravel still has to resolve it
})

Route::get('/health', HealthController::class)
    ->middleware(['universal', InitializeTenancyByDomain::class]);
```

The feature only handles the middleware classes in `UniversalRoutes::$identificationMiddlewares` (domain and subdomain by default). Use universal routes for package endpoints that must serve both planes (Livewire, Sanctum's CSRF cookie, Passport). For your own features prefer separate central and tenant routes: shared controllers break down as soon as the planes need different behaviour.

## Controller constructors run before tenancy

Route middleware runs after the controller is constructed. Services injected into a controller constructor are resolved in central context: the central connection, the central cache. Inject them into the action method, or resolve them lazily:

```php
public function store(Request $request, InvoiceService $invoices) { /* tenant context */ }
```

Livewire components and Filament pages are fine: they are resolved after the route middleware has run.

## Links to another domain

- `redirect()->route('dashboard')->domain($domain)` with the `CrossDomainRedirect` feature enabled.
- `tenant_route($domain, 'dashboard', $parameters)` builds the URL of a named route on another host.
- The `PrimaryDomain` trait on the Tenant model: implement `getPrimaryDomainHostnameAttribute(): string`, then `$tenant->route('dashboard')`.

## URLs outside a request

Queued jobs, scheduled commands and console commands have no request, so `url()`, `route()` and `asset()` use `app.url`: the central domain. Links in queued notifications then point at the central app. Either build the URL during the request and pass it into the job or notification, or build it from the tenant's domain with `tenant_route()` or `$tenant->route()`.

## Custom domains and trusted hosts

Laravel's `trustHosts()` middleware rejects hosts it doesn't know. If it is enabled, tenant domains must be trusted: `$middleware->trustHosts(at: ['app.example'], subdomains: true)` covers subdomains of a central domain; custom domains need their own entries or a callback that checks the `domains` table. Custom domains also need TLS certificates and DNS validation before you save them as a tenant's domain.

## Local development

`*.localhost` resolves to loopback in browsers and most systems: `acme.localhost`. With Herd or Valet, use a central domain like `app.test` and tenants as `acme.app.test`.

## Cached lookup

Identification queries the central database on every request. Cache the resolver result:

```php
// TenancyServiceProvider::boot()
DomainTenantResolver::$shouldCache = true; // also used by subdomain identification
DomainTenantResolver::$cacheTTL = 3600;
DomainTenantResolver::$cacheStore = 'central'; // a store with a fixed prefix, see below
```

- The cache is invalidated when the tenant is saved or deleted and when one of its domains is saved or deleted. A manual invalidation is `app(DomainTenantResolver::class)->invalidateCache($tenant)`. The cache key contains the domain, so invalidate before you rename a domain.
- With cache isolation by key prefix, a tenant saved inside tenant context invalidates the key under the tenant's prefix and the central entry survives. Point `$cacheStore` at a store whose config sets its own `prefix`: Laravel uses a store's `prefix` before the global `cache.prefix`, so that store stays central in every context.
- A cached tenant is a serialized model. Anything that must take effect immediately (suspension, status) must save the tenant, so the cache is invalidated.
