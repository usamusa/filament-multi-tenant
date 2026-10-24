# Base test case for browser tests

pest-plugin-browser serves the application in-process on `127.0.0.1:<port>`, sharing the test's application and database transaction. In production every request is a fresh process; this base class restores that per request.

```php
<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Tenant;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Http\Events\RequestHandled;
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

    /** How many milliseconds late Filament's `x-load` components start (delayLateComponents()). */
    private ?int $lateComponentDelay = null;

    public function be(Authenticatable $user, $guard = null)
    {
        $this->actingAsUser = [$user, $guard];

        return parent::be($user, $guard);
    }

    /**
     * Starts every component Filament loads after the page (`x-load`) this
     * much later than its script arrives, on every page from now on, as on a
     * slow CI runner. For a test of the load guard.
     */
    public function delayLateComponents(int $milliseconds): void
    {
        $this->lateComponentDelay = $milliseconds;
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

            // Sessions in the database, as in production: a tenant's in its
            // own table, where a list of signed-in devices reads them. Built
            // per request, after tenancy — see setUp().
            $app['config']->set('session.driver', 'database');
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
            // the last request's sign-in into another context. Forget both
            // before StartSession runs: the store is then built anew for this
            // request, after tenancy — as a fresh process builds it — in the
            // tenant's own sessions table and with its lifetime; the guards
            // find it through the forgotten 'session.store' singleton. Only an
            // explicit actingAs() signs every context in.
            // Livewire keeps per-request state in its manager, among it the
            // persistent-middleware bookkeeping, which skips a page's middleware
            // for a snapshot of the route it last ran them for (since 4.4.8;
            // before, of any route it had run them for). A request is a process
            // in production, not here, so a later Livewire request for the same
            // page would skip SetUpPanel and Authenticate: flush the state as
            // Livewire's own test harness does between renders.
            app('livewire')->flushState();

            // Filament keeps the current panel in its shared manager; a request
            // to one panel after a request to another would start as the
            // other's until the panel middleware runs, and since Filament 5.10
            // a lazy widget is authorized on hydrate, where that shows (the
            // guard and the user it sees). Start each request without a
            // panel, as a fresh process does.
            Filament::setCurrentPanel(null);

            $this->app['session']->forgetDrivers();
            $this->app->forgetInstance('session.store');
            $this->app['auth']->forgetGuards();
            // Laravel keeps the default guard as the 'auth.driver' singleton
            // too (what Illuminate\Contracts\Auth\Guard resolves to): built
            // in one request, it would answer every later one — the database
            // session handler asks it for the user id and would save every
            // session row without one.
            $this->app->forgetInstance('auth.driver');

            if ($this->actingAsUser !== null) {
                [$user, $guard] = $this->actingAsUser;
                $this->app['auth']->guard($guard)->setUser($user);
                $this->app['auth']->shouldUse($guard);
            }
        });

        // Playwright acts as soon as an element exists, and text assertions pass
        // while a page is still loading: a field filled before Livewire starts
        // is emptied when it starts, a button clicked then does nothing, and the
        // plugin ends navigate() on any page's `load` event, also the late one of
        // the page being left. Show the test each page only once it has loaded.
        $this->app['events']->listen(RequestHandled::class, function (RequestHandled $event): void {
            $content = $event->response->getContent();

            if (is_string($content) && str_contains($content, '<head>')) {
                $scripts = '<script>'.self::LOAD_GUARD.'</script>';

                if ($this->lateComponentDelay !== null) {
                    $scripts .= '<script>'.str_replace('LATE_COMPONENT_DELAY', (string) $this->lateComponentDelay, self::LATE_COMPONENTS).'</script>';
                }

                $event->response->setContent(str_replace('<head>', '<head>'.$scripts, $content));
            }
        });

        // Under tests Livewire keeps uploads on a fake disk of its own
        // (tmp-for-tests), created on first use by Livewire::test(). A browser
        // upload request expects it to exist already.
        FileUploadConfiguration::storage();

        // Tenancy initialized by one request must not leak into the next one
        // (or into a central panel), nor Filament's navigation, which is built
        // once per request for the signed-in user, nor the scoped request it
        // marks the current page by (kept, the menu marks the first page).
        $this->app->terminating(function (): void {
            if (tenancy()->initialized) {
                tenancy()->end();
            }

            $this->app->forgetInstance(NavigationManager::class);
            $this->app->forgetInstance('originalRequest');

            foreach ($this->uploadedTempFiles as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $this->uploadedTempFiles = [];
        });
    }

    /**
     * The in-process server hands the app a multipart request's files only as
     * the plugin parses them: since pest-plugin-browser 5.1 it does, but a
     * nested name such as Livewire's `files[]` stays a flat key where PHP
     * would nest it, so an upload still arrives empty (before 5.1 the files
     * were dropped altogether). Parse the parts into files and fields as
     * PHP's SAPI would, in place of what the plugin found, for the `name`
     * and `name[]` fields browsers send.
     */
    private function parseMultipartBody(Request $request): void
    {
        $contentType = (string) $request->headers->get('Content-Type');

        if (! str_starts_with(strtolower($contentType), 'multipart/form-data')
            || preg_match('/boundary=(?:"([^"]+)"|([^;\s]+))/i', $contentType, $boundary) !== 1) {
            return;
        }

        $request->files->replace([]);

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

    /**
     * Keeps the page hidden until its `load` event (10 s at most): Playwright
     * sees no element of a hidden page. Then keeps out of reach what Alpine
     * has not bound yet, from the moment it is added (with the page, in a
     * window opened later, in a part a Livewire response adds), for ten
     * seconds at most each:
     *
     * - a field bound with wire:model, until Alpine has bound it: a value
     *   typed before is dropped when the binding sets the field from the
     *   component's data. Read-only, or disabled where read-only means
     *   nothing (selects, checkboxes); Playwright waits for an editable field.
     * - a component Filament starts only once its script has arrived
     *   (`x-load`: toggle buttons, the password reveal, tabs, the wizard,
     *   builders, selects, pickers, editors), until Alpine has started it: its
     *   buttons do nothing before. `aria-disabled` on the component disables
     *   everything inside for Playwright, which waits.
     *
     * A MutationObserver marks each element as it is added, before
     * Playwright's next command can reach it; a poll leaves a gap in which a
     * window's new field is filled. Not by hiding the page longer: Filament's
     * upload field defers its own set-up while hidden and never resumes.
     */
    private const LOAD_GUARD = <<<'JS'
        (function () {
            var root = document.documentElement;
            root.style.setProperty('visibility', 'hidden', 'important');
            var reveal = function () { root.style.removeProperty('visibility'); };
            window.addEventListener('load', reveal);
            setTimeout(reveal, 10000);

            var MARK = 'data-load-guard';
            var held = [];
            var timer = null;
            var hasModel = function (element) {
                for (var i = 0; i < element.attributes.length; i++) {
                    if (element.attributes[i].name.indexOf('wire:model') === 0) { return true; }
                }
                return false;
            };
            // What Alpine binds itself when it starts the element is left to Alpine.
            var bindsItself = function (element, attribute) {
                return element.hasAttribute('x-bind:' + attribute) || element.hasAttribute(':' + attribute);
            };
            var howToHold = function (element) {
                if (element.hasAttribute(MARK)) { return null; }
                if (element.hasAttribute('x-load')) {
                    return element._x_dataStack || element.hasAttribute('aria-disabled') || bindsItself(element, 'aria-disabled') ? null : 'component';
                }
                if (['INPUT', 'TEXTAREA', 'SELECT'].indexOf(element.tagName) === -1 || element._x_model || ! hasModel(element) || element.readOnly || element.disabled) { return null; }
                return element.tagName === 'SELECT' || ['checkbox', 'radio', 'file', 'hidden'].indexOf(element.type) !== -1 ? 'disabled' : 'readonly';
            };
            var isBound = function (entry) {
                return entry.how === 'component' ? !! entry.element._x_dataStack : !! entry.element._x_model;
            };
            var release = function (entry) {
                var element = entry.element;
                element.removeAttribute(MARK);
                if (entry.how === 'component') {
                    element.removeAttribute('aria-disabled');
                } else if (entry.how === 'disabled') {
                    if (! bindsItself(element, 'disabled')) { element.disabled = false; }
                } else if (! bindsItself(element, 'readonly')) {
                    element.readOnly = false;
                }
            };
            var tick = function () {
                timer = null;
                var now = Date.now();
                held = held.filter(function (entry) {
                    if (entry.element.isConnected && ! isBound(entry) && now - entry.since < 10000) { return true; }
                    release(entry);
                    return false;
                });
                if (held.length) { timer = setTimeout(tick, 50); }
            };
            var hold = function (element) {
                var how = howToHold(element);
                if (! how) { return; }
                element.setAttribute(MARK, how);
                if (how === 'component') {
                    element.setAttribute('aria-disabled', 'true');
                } else if (how === 'disabled') {
                    element.disabled = true;
                } else {
                    element.readOnly = true;
                }
                held.push({ element: element, how: how, since: Date.now() });
                if (! timer) { timer = setTimeout(tick, 50); }
            };
            var holdWithin = function (node) {
                if (node.nodeType !== 1) { return; }
                hold(node);
                var inside = node.querySelectorAll('input, textarea, select, [x-load]');
                for (var i = 0; i < inside.length; i++) { hold(inside[i]); }
            };
            new MutationObserver(function (records) {
                for (var i = 0; i < records.length; i++) {
                    for (var j = 0; j < records[i].addedNodes.length; j++) { holdWithin(records[i].addedNodes[j]); }
                }
            }).observe(document, { childList: true, subtree: true });
            holdWithin(root);
        })();
        JS;

    /**
     * Delays the start of every component Filament loads after the page
     * (delayLateComponents()). async-alpine starts such a component through
     * `Alpine.initTree()` once its script has arrived; this waits before
     * passing it on. The page's own start and Livewire's updates use Alpine
     * internally and stay as fast as before.
     */
    private const LATE_COMPONENTS = <<<'JS'
        document.addEventListener('alpine:init', function () {
            var initTree = window.Alpine.initTree;
            window.Alpine.initTree = function (element) {
                var alpine = this;
                var args = arguments;
                if (element && element.nodeType === 1 && element.hasAttribute('x-load') && ! element.__startedLate) {
                    element.__startedLate = true;
                    setTimeout(function () { initTree.apply(alpine, args); }, LATE_COMPONENT_DELAY);
                    return;
                }
                return initTree.apply(alpine, args);
            };
        });
        JS;
}
```

Without stancl/tenancy, drop the tenancy lines, the base-domain switch and the tenant cleanup; keep the session, guard, URL-origin and navigation resets, the load guard and the upload handling.

The load guard makes every step wait for a fully loaded page, as a user sees it; `load` fires after Livewire has started. Without it, a suite passes on a fast machine and fails at random on slower CI runners: the first field filled after a page change comes up empty ("Please fill out this field" on submit), or a click opens nothing. It also holds what Filament binds after `load`, from the moment it is added: every `wire:model` field read-only (selects and checkboxes disabled) until it carries Alpine's `_x_model`, and every `x-load` element `aria-disabled` until it carries `_x_dataStack`. Filament starts such components (toggle buttons, the password reveal, tabs, the wizard, builders, selects, pickers, editors) only once their script has arrived: a value typed before is dropped when the binding starts, seen as a password field emptied while the other fields kept theirs, and a click on a single-choice toggle option does nothing, its button having no `wire:model`. Playwright counts an `aria-disabled` ancestor in its enabled and editable checks, so it waits for the component. The guard marks elements from a `MutationObserver`, whose callback runs right after the insertion and before Playwright's next command; a poll every 50 ms let Playwright fill a window's new password field before the guard saw it. Each element is held for ten seconds at most from when it was added, so a window opened late in a test is covered too. Hiding the page until then instead stalls Filament's upload field, which defers its own set-up while an ancestor is hidden and resumes only on a resize. It needs the layout's `<head>` tag written without attributes, as Filament's is.

`delayLateComponents()` reproduces a slow runner on a fast machine, where these races are won by luck: async-alpine starts each `x-load` component through `Alpine.initTree()`, and the injected script delays that call. With `$this->delayLateComponents(1500)` before signing in, a toggle option clicked under a guard that holds only `wire:model` fields stays unchosen; under this guard the click waits and lands. Keep such a test next to the base test case, so the guard cannot quietly lose a hole again.

The multipart parser covers what Livewire's upload endpoint and plain HTML forms send (single and `[]` fields, quoted or bare boundaries). It does not handle nested names such as `a[b][c]`. pest-plugin-browser 5.1 parses the parts itself but keeps a nested name such as `files[]` flat, so the parser stays and replaces what the plugin found; an upload test without it fails before reaching your code.
