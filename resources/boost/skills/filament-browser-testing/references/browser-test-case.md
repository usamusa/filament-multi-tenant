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
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Events\Routing;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

abstract class BrowserTestCase extends TestCase
{
    public const TENANT_BASE_DOMAIN = 'localhost';

    /** @var array{0: Authenticatable, 1: string|null}|null the user actingAs() signed in, for every browser context */
    private ?array $actingAsUser = null;

    /** @var list<string> temporary files holding the current request's uploads */
    private array $uploadedTempFiles = [];

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

        $this->app['events']->listen(Routing::class, function (Routing $event): void {
            // The plugin pins generated URLs to http://127.0.0.1:<port>. A tenant
            // lives on <sub>.localhost; an absolute Livewire update URL pointing
            // at 127.0.0.1 would leave the tenant host (no tenant, no session
            // cookie). Generate URLs from the host each request came in on.
            URL::useOrigin(null);
            URL::useAssetOrigin(null);

            $this->parseMultipartBody($event->request);

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

        // Under tests Livewire keeps uploads on a fake disk of its own
        // (tmp-for-tests), created on first use by Livewire::test(). A browser
        // upload request expects it to exist already.
        FileUploadConfiguration::storage();

        // Tenancy initialized by one request must not leak into the next one
        // (or into a central panel), nor Filament's navigation, which is built
        // once per request for the signed-in user.
        $this->app->terminating(function (): void {
            if (tenancy()->initialized) {
                tenancy()->end();
            }

            $this->app->forgetInstance(NavigationManager::class);

            foreach ($this->uploadedTempFiles as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $this->uploadedTempFiles = [];
        });
    }

    /**
     * The in-process server hands the app a multipart request's raw body but
     * none of its files (an open TODO in pest-plugin-browser), so a Livewire
     * upload arrives empty. Parse the parts into files and fields as PHP's SAPI
     * would, for the `name` and `name[]` fields browsers send.
     */
    private function parseMultipartBody(Request $request): void
    {
        $contentType = (string) $request->headers->get('Content-Type');

        if (! str_starts_with(strtolower($contentType), 'multipart/form-data')
            || $request->files->count() > 0
            || preg_match('/boundary=(?:"([^"]+)"|([^;\s]+))/i', $contentType, $boundary) !== 1) {
            return;
        }

        $files = [];
        $fields = [];

        foreach (explode('--'.($boundary[1] !== '' ? $boundary[1] : $boundary[2]), $request->getContent()) as $part) {
            if (str_starts_with($part, "\r\n")) {
                $part = substr($part, 2);
            }

            if ($part === '' || str_starts_with($part, '--') || ! str_contains($part, "\r\n\r\n")) {
                continue;
            }

            [$headers, $body] = explode("\r\n\r\n", $part, 2);
            $body = str_ends_with($body, "\r\n") ? substr($body, 0, -2) : $body;

            if (preg_match('/name="([^"]*)"/i', $headers, $name) !== 1) {
                continue;
            }

            [$key, $isList] = str_ends_with($name[1], '[]') ? [substr($name[1], 0, -2), true] : [$name[1], false];

            if (preg_match('/filename="([^"]*)"/i', $headers, $filename) === 1) {
                $path = (string) tempnam(sys_get_temp_dir(), 'browser-upload-');
                file_put_contents($path, $body);
                $this->uploadedTempFiles[] = $path;
                preg_match('/Content-Type:\s*([^\r\n]+)/i', $headers, $type);

                // Laravel's own class with test: true. A Symfony UploadedFile is
                // rebuilt without its test flag, and validation then rejects a
                // file PHP didn't upload ("failed to upload").
                $value = new UploadedFile($path, $filename[1], $type[1] ?? null, UPLOAD_ERR_OK, test: true);
                $isList ? $files[$key][] = $value : $files[$key] = $value;
            } else {
                $isList ? $fields[$key][] = $body : $fields[$key] = $body;
            }
        }

        foreach ($files as $key => $value) {
            $request->files->set($key, $value);
        }

        $request->request->add($fields);
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

Without stancl/tenancy, drop the tenancy lines, the base-domain switch and the tenant cleanup; keep the session, guard, URL-origin and navigation resets and the upload handling.

The multipart parser covers what Livewire's upload endpoint and plain HTML forms send (single and `[]` fields, quoted or bare boundaries). It does not handle nested names such as `a[b][c]`. Remove it once pest-plugin-browser forwards uploaded files itself; until then, an upload test without it fails before reaching your code.
