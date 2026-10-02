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
```

The CSS classes (`fi-modal-open`, `fi-select-input-btn`, …) are Filament 5 internals. If a selector stops matching after a Filament upgrade, inspect the rendered HTML with `$page->debug()` and update the helper in one place.

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
