# Helpers for Filament browser tests

Put these in `tests/Pest.php`. Translate the button texts ("Sign in", "Actions") to the panel's language.

```php
/** The port the browser plugin bound its in-process server to (it rewrites app.url). */
function browserServerPort(): int
{
    return (int) parse_url((string) config('app.url'), PHP_URL_PORT);
}

/**
 * Absolute URL of a tenant page: http://<subdomain>.<base domain>[:port]<path>.
 * Visiting the tenant host directly keeps cookies, Livewire round trips and
 * panel links on one origin.
 */
function tenantUrl(Tenant $tenant, string $path = '/'): string
{
    $port = browserServerPort();

    return 'http://'.$tenant->domains()->value('domain').($port > 0 ? ':'.$port : '').$path;
}

/** Absolute URL of the central panel on the in-process server. */
function centralUrl(string $path = '/admin'): string
{
    return 'http://localhost:'.browserServerPort().$path;
}

/**
 * Submits the tenant login form without waiting for the outcome (for logins
 * that must be refused). Each browser context keeps its own session; don't
 * combine with actingAs(), which signs every context in.
 */
function attemptSignIn(Tenant $tenant, string $email, string $password = 'password'): mixed
{
    return visit(tenantUrl($tenant, '/login'))
        ->fill(field('email'), $email)
        ->fill(field('password'), $password)
        ->press('Sign in');
}

/** Signs in through the login form and waits for the dashboard: a click doesn't wait for the Livewire redirect. */
function signIn(Tenant $tenant, string $email, string $password = 'password'): mixed
{
    return attemptSignIn($tenant, $email, $password)->assertSee('Dashboard');
}

/**
 * A field of a page form (id="form.<name>"). An attribute selector, because a
 * dotted name like form.email is read as CSS ("a <form> with class email").
 */
function field(string $name): string
{
    return '[id="form.'.$name.'"]';
}

/** A field of the open action modal (id="mountedActionSchema0.<name>"). */
function modalField(string $name): string
{
    return '[id="mountedActionSchema0.'.$name.'"]';
}

/** The page form's submit button; its label often repeats the page heading, so it is not found by text. */
function formSubmit(): string
{
    return 'form [type="submit"]:visible';
}

/**
 * The main button of the open action modal: the footer's one coloured button.
 * Its label often repeats the button that opened the modal, so find it by position.
 */
function modalSubmit(): string
{
    return '.fi-modal-open .fi-modal-footer-actions > .fi-btn.fi-color';
}

/** The button that opens a searchable select (no native <select>), by the field's label. */
function searchableSelect(string $label): string
{
    return '.fi-fo-field:has(label:has-text("'.$label.'")) .fi-select-input-btn';
}

/** An entry of the open searchable select's list. */
function selectOption(string $option): string
{
    return '.fi-select-input-option:has-text("'.$option.'")';
}

/** The row-actions menu of the table row naming this record. */
function rowActionsOf(string $name): string
{
    return 'tr:has-text("'.$name.'") [aria-label="Actions"]';
}

/**
 * A field of a Builder block, by the block's position (1 = the first block)
 * and the field's name. `nth=` counts only the blocks; `:nth-child()` would
 * also count the controls for inserting a block in between. Playwright waits
 * until a newly added block exists, so fill right after adding it.
 */
function builderField(int $position, string $field): string
{
    return '.fi-fo-builder-item >> nth='.($position - 1).' >> [id$=".data.'.$field.'"]';
}

/**
 * Answers the browser's own confirm dialogs (wire:confirm) with OK for the
 * rest of the page's life. Playwright dismisses dialogs nobody handles, so
 * the confirmed action would never run.
 */
function confirmDialogs(mixed $page): mixed
{
    $page->script('void (window.confirm = () => true)');

    return $page;
}
```

```php
// A Builder: add blocks, then fill them by position.
$page->click('Add step')
    ->click('.fi-dropdown-list-item:visible:has-text("Measurement")')
    ->fill(builderField(1, 'label'), 'Fridge temperature')
    ->click('Add step')
    ->click('.fi-dropdown-list-item:visible:has-text("Check")')
    ->fill(builderField(2, 'label'), 'Door closes');

// A button with wire:confirm.
confirmDialogs($page)->click('Restore the template')->assertSee('Restored');

// Phone width: nothing may scroll sideways.
$page->resize(390, 844);
expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
```

The CSS classes (`fi-modal-open`, `fi-select-input-btn`, …) are Filament 5 internals. If a selector stops matching after a Filament upgrade, inspect the rendered HTML with `$page->debug()` and update the helper in one place.

## Uploads

They need the multipart handling in the base test case ([browser-test-case.md](browser-test-case.md)); without it every upload fails.

```php
/** A file on disk for a browser upload: a file input needs a path. */
function uploadFixture(string $name, string $contents): string
{
    $directory = storage_path('framework/testing/uploads/'.Str::random(8));
    mkdir($directory, recursive: true);
    file_put_contents($path = $directory.'/'.$name, $contents);

    return $path;
}

/**
 * The file input of a Filament upload field, by the field's label. An upload
 * field's label is no <label> element, so the field is matched by its text.
 * It is FilePond's own input, which exists only once the field has started:
 * a file attached to the field's original input before then lands on an
 * element FilePond replaces, and the upload never begins. Playwright waits
 * for this one to appear.
 */
function uploadField(string $label): string
{
    return '.fi-fo-field:has-text("'.$label.'") input.filepond--browser';
}

/**
 * Waits until a JavaScript expression on the page is true, for states no text
 * announces (a finished upload, a loaded image).
 */
function waitUntil(mixed $page, string $expression, int $timeoutMs = 15_000): mixed
{
    $description = json_encode($expression, JSON_THROW_ON_ERROR);

    $page->script(<<<JS
        new Promise((resolve, reject) => {
            const started = Date.now();
            (function check() {
                let done = false;
                try { done = Boolean({$expression}); } catch (error) {}
                if (done) { resolve(true); return; }
                if (Date.now() - started > {$timeoutMs}) { reject(new Error('Timed out waiting for ' + {$description})); return; }
                setTimeout(check, 100);
            })();
        })
    JS);

    return $page;
}

/** Waits until FilePond has sent every file of the page's upload fields to the server. */
function waitForUploads(mixed $page): mixed
{
    return waitUntil($page, "[...document.querySelectorAll('.filepond--item')].length > 0 && [...document.querySelectorAll('.filepond--item')].every((item) => ['processing-complete', 'idle'].includes(item.dataset.filepondItemState))");
}
```

```php
$page = visit(tenantUrl($tenant, '/reports/create'))
    ->fill(field('title'), 'Monthly report')
    ->attach(uploadField('Attachments'), uploadFixture('report.pdf', "%PDF-1.4\n%%EOF\n"));

waitForUploads($page)            // a save during the upload goes out without the file
    ->click(formSubmit())
    ->assertSee('Created');

// An upload field in a modal (the rich editor's "Attach files", an action's
// form) starts when the modal opens: attach to its FilePond input too.
$page->click('[aria-label="Attach files"]')
    ->attach('.fi-modal-open input.filepond--browser', uploadFixture('photo.png', $png));
waitForUploads($page)->click(modalSubmit());

// An image in the page: wait until it has actually loaded.
waitUntil($page, "(() => { const img = document.querySelector('.fi-in-entry img'); return img && img.complete && img.naturalWidth > 0; })()");
```

Keep fixtures small: a few bytes of a real format are enough for the type checks (a one-pixel PNG, a minimal PDF).

Reading a Filament notification in a feature test: Livewire's dehydrate moves notifications to the session's `filament.claimed_notifications` key, and `assertNotified()` compares the title only.

```php
/** @return array<string, mixed> */
function sentNotification(string $title): array
{
    return collect([
        ...session('filament.claimed_notifications', []),
        ...session('filament.notifications', []),
    ])->firstWhere('title', $title)
        ?? throw new RuntimeException("No notification titled \"{$title}\" was sent.");
}
```
