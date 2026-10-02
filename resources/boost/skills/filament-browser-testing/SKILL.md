---
name: filament-browser-testing
description: "Writes Pest 5 browser tests (pest-plugin-browser, Playwright/Chromium) for Filament 5 panels, including multi-tenant apps on tenant subdomains and WebAuthn/passkey flows. Use when adding or fixing a test in tests/Browser, setting up browser testing in a project, choosing selectors for Filament forms, modals, searchable selects and table row actions, or debugging browser tests that leak sessions or tenants between requests, miss Livewire redirects or time out."
metadata:
  author: usamusa
---

# Browser tests for Filament panels

A browser test drives the real UI in Chromium against an in-process server that shares the test's application and database transaction. It catches what feature tests miss: buttons that lead to a 403, guards bypassed through another page, JavaScript ceremonies that never reach the server.

## Setup (once per project)

1. `composer require pestphp/pest-plugin-browser --dev`, then `npm install playwright@latest` and `npx playwright install`. Add `tests/Browser/Screenshots` and `tests/Browser/Traces` to `.gitignore`.
2. `tests/Pest.php`:

```php
pest()->extend(BrowserTestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Browser');

// Filament renders on the server for every round trip.
pest()->browser()->timeout(15_000);
```

3. A base test case that makes the shared application behave like one fresh process per request: [references/browser-test-case.md](references/browser-test-case.md).
4. Selector and sign-in helpers: [references/helpers.md](references/helpers.md).

## Writing a test

```php
it('saves a manager\'s correction of a colleague\'s time entry', function () {
    $tenant = makeTenant('acme');
    [$manager, $entry] = $tenant->run(fn (): array => [
        userWithRole('manager'),
        TimeEntry::factory()->create(),
    ]);
    $this->actingAs($manager);

    visit(tenantUrl($tenant, '/time-entries'))
        ->click('Edit')
        ->assertPathIs("/time-entries/{$entry->id}/edit")   // wait for the page, not the click
        ->fill(field('ended_at'), '2026-03-02T18:00')
        ->press('Save')
        ->assertSee('Saved')
        ->assertNoJavaScriptErrors();

    // Side effects, read inside the tenant; times are stored in UTC.
    expect($tenant->run(fn () => $entry->refresh()->ended_at->toDateTimeString()))
        ->toBe('2026-03-02 17:00:00');
});
```

- Arrange with factories inside `$tenant->run()`.
- Sign in either with `actingAs()` (signs every browser context in) or through the login form with a helper that waits for the dashboard. Not both in one test.
- After every click that triggers a Livewire redirect, save or notification, assert something the next state shows before the next step.
- End with `->assertNoJavaScriptErrors()`, then assert persisted side effects in the database.
- Test the refusal paths too: the role that may not act, the guard that blocks, the validation that fails.

## Traps

- **Shared application**: without the base test case, the session, auth guards, tenancy and Filament's navigation carry over between requests, so tests pass for the wrong reason or leak a signed-in user into another context.
- **Livewire redirects**: `click()` and `press()` don't wait for them; the next `fill()` then runs on the old page.
- **Time travel**: never travel into the past in a browser test; the browser drops session cookies that expired in the travelled past. Create data with explicit timestamps instead.
- **Tenant hosts**: Chromium resolves every `*.localhost` to loopback, so tenants are `http://<subdomain>.localhost:<port>`. The panel's domain pattern is fixed when the panel registers, so the base domain must be `localhost` before providers boot. `withHost()` is the alternative for a single host.
- **Selectors**: Filament field ids contain dots (`form.email`), which CSS reads as classes; use attribute selectors. Searchable selects are a button plus an option list, not a native `<select>`. Modal fields are `mountedActionSchema0.<name>`.
- **Server-side rendering**: raise the timeout above the 5-second default; the first paint of a panel page is slow.
- **Debugging**: `./vendor/bin/pest --debug` (headed, pauses on failure), `$page->screenshot()`, `--trace` for flaky tests.

## Passkeys and WebAuthn step-up

Run the real ceremony: a software authenticator answers `navigator.credentials` in Chromium and the server verifies real signatures, counters and origins. Code and helpers: [references/passkeys.md](references/passkeys.md).
