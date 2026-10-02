# Base test case for browser tests

pest-plugin-browser serves the application in-process on `127.0.0.1:<port>`, sharing the test's application and database transaction. In production every request is a fresh process; this base class restores that per request.

```php
<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Tenant;
use Filament\Navigation\NavigationManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Routing\Events\Routing;
use Illuminate\Support\Facades\URL;

abstract class BrowserTestCase extends TestCase
{
    public const TENANT_BASE_DOMAIN = 'localhost';

    /** @var array{0: Authenticatable, 1: string|null}|null the user actingAs() signed in, for every browser context */
    private ?array $actingAsUser = null;

    public function be(Authenticatable $user, $guard = null)
    {
        $this->actingAsUser = [$user, $guard];

        return parent::be($user, $guard);
    }

    public function createApplication(): Application
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        // The tenant panel's domain pattern is fixed when the panel registers:
        // switch the base domain right after the configuration loads, before
        // any service provider boots. Chromium resolves *.localhost to loopback.
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $app['config']->set('app.tenant_base_domain', self::TENANT_BASE_DOMAIN);

            // State that must survive from one browser request to the next
            // (step-up challenges, lockouts) lives in the cache. The array store
            // is rebuilt on every tenancy switch; the database store persists
            // inside the test transaction.
            $app['config']->set('cache.default', 'database');
        });

        $this->traitsUsedByTest = class_uses_recursive(static::class);

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['events']->listen(Routing::class, function (): void {
            // The plugin pins generated URLs to http://127.0.0.1:<port>. A tenant
            // lives on <sub>.localhost; an absolute Livewire update URL pointing
            // at 127.0.0.1 would leave the tenant host (no tenant, no session
            // cookie). Generate URLs from the host each request came in on.
            URL::useOrigin(null);
            URL::useAssetOrigin(null);

            // Each browser context is its own visitor. The shared application
            // keeps one session store and one set of guards, which would carry
            // the last request's sign-in into another context. Start every
            // request empty; only an explicit actingAs() signs every context in.
            $this->app['session']->driver()->flush();
            $this->app['auth']->forgetGuards();

            if ($this->actingAsUser !== null) {
                [$user, $guard] = $this->actingAsUser;
                $this->app['auth']->guard($guard)->setUser($user);
                $this->app['auth']->shouldUse($guard);
            }
        });

        // Tenancy initialized by one request must not leak into the next one
        // (or into a central panel), nor Filament's navigation, which is built
        // once per request for the signed-in user.
        $this->app->terminating(function (): void {
            if (tenancy()->initialized) {
                tenancy()->end();
            }

            $this->app->forgetInstance(NavigationManager::class);
        });
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        // Provisioned tenant databases live outside the test transaction.
        Tenant::query()->get()->each->delete();

        parent::tearDown();
    }
}
```

Without stancl/tenancy, drop the tenancy lines, the base-domain switch and the tenant cleanup; keep the session, guard, URL-origin and navigation resets.
