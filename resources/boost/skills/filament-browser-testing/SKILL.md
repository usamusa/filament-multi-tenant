---
name: filament-browser-testing
description: "Writes Pest 5 browser tests (pest-plugin-browser, Playwright/Chromium) for Filament 5 panels, including multi-tenant apps on tenant subdomains and WebAuthn/passkey flows. Use when adding or fixing a test in tests/Browser, setting up browser testing in a project, choosing selectors for Filament forms, modals, searchable selects, file uploads and table row actions, or debugging browser tests that leak sessions or tenants between requests, lose uploaded files, miss Livewire redirects, time out, or pass locally and fail at random on CI."
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
4. Selector, sign-in and upload helpers: [references/helpers.md](references/helpers.md).

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
        ->assertPathIs("/time-entries/{$entry->id}/edit")   // only the address; fill() waits for the loaded page (load guard)
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

- **Shared application**: without the base test case, the session, auth guards, tenancy and Filament's navigation carry over between requests, so tests pass for the wrong reason or leak a signed-in user into another context; and Filament's scoped `originalRequest`, unless forgotten per request, makes the menu mark the first page of the session as the current one. The session store is built on first use and kept for the process: touched before tenancy, it is central and serves every tenant — the base test case forgets the session drivers and the `session.store` singleton per request, so the store is built after tenancy like a fresh process's (database sessions then land in the tenant's own table, with its lifetime).
- **Reading after a navigation**: `script()` returns the browser's error text, a string, when a navigation replaces the page under it. Read values from a page just opened with `readAfterNavigation()` (references/helpers.md).
- **Livewire redirects**: `click()` and `press()` don't wait for them; the next `fill()` then runs on the old page.
- **Half-loaded pages**: `assertSee()` and `assertPathIs()` pass while the next page is still loading, and pest-plugin-browser ends `navigate()` on any page's `load` event, also the late one of the page being left. A field filled before Livewire starts is emptied when it starts; a button clicked then does nothing. The load guard in the base test case hides each page until `load`, so every step waits. Without it, the first field filled after a page change comes up empty at random, mostly on CI. It also holds what Filament binds later, from the moment it is added: a `wire:model` field until Alpine has bound it, and an `x-load` component (toggle buttons, the password reveal, tabs, selects …) until Alpine has started it. Otherwise a toggle option clicked too early stays unchosen, and a window's field filled too early is emptied again.
- **Text assertions**: `assertSee()` matches any text containing the string, case ignored. Wait for a state with an exact match (`assertVisible('text="Confirmed"')`), or it passes on a prompt that merely mentions the word.
- **Time travel**: never travel into the past in a browser test; the browser drops session cookies that expired in the travelled past. Create data with explicit timestamps instead.
- **Tenant hosts**: Chromium resolves every `*.localhost` to loopback, so tenants are `http://<subdomain>.localhost:<port>`. The panel's domain pattern is fixed when the panel registers, so the base domain must be `localhost` before providers boot. `withHost()` is the alternative for a single host.
- **Selectors**: Filament field ids contain dots (`form.email`), which CSS reads as classes; use attribute selectors. Searchable selects are a button plus an option list, not a native `<select>`. Modal fields are `mountedActionSchema0.<name>`. A Builder block's fields go by the block's position (`builderField()`), not `:nth-child()`: the controls for inserting a block between two others are siblings of the blocks. A block inserted before others, or into a nested builder, takes a position that already exists, and Playwright fills the old block's field at once: select it by an id pattern. A Builder or Repeater is a field itself, so a selector by label (`searchableSelect()`) also matches the builder around a nested field and every field in it: select nested fields by id. `click('Temperature: 9.5 °C')` is read as CSS because of the colon; write `click('text="Temperature: 9.5 °C"')` (quoted: an exact match; unquoted, a longer text containing it matches too, and two matches fail).
- **Confirm dialogs**: Playwright dismisses native dialogs, so a `wire:confirm` button does nothing and the test fails later, far from the cause. Call `confirmDialogs($page)` before the click.
- **Uploads**: the in-process server passed no files up to pest-plugin-browser 5.0 and keeps `files[]` flat since 5.1, so without the parsing in the base test case every upload fails (Livewire reports `Undefined array key 0`, or validation says the file failed to upload). Attach to FilePond's own input, `input.filepond--browser` (`uploadField()`; an upload field's label is no `<label>` element): it exists only once the field has started, and a file attached to the field's original `<input type="file">` before then never uploads, which shows as a flaky timeout under load. Fields in modals start when the modal opens. Then wait until FilePond has sent every file (`waitForUploads()`) before saving: a save during an upload goes out without the file.
- **Server-side rendering**: raise the timeout above the 5-second default; the first paint of a panel page is slow.
- **Debugging**: `./vendor/bin/pest --debug` (headed, pauses on failure), `$page->screenshot()`, `--trace` for flaky tests.
- **Failures only on CI**: a delay added on the server can't reproduce them. The test, the in-process server and the application share one PHP thread, so a server-side sleep pauses the test too. Instead, log every request in the base test case (method, path, `Sec-Fetch-Mode`, each Livewire component with its calls and updated keys), and inject a page script that reports load events, typed inputs and fields that lose their value. Compare a failing CI run with a passing one. A late component start can be reproduced in the browser instead: `delayLateComponents()` in the base test case delays Filament's `x-load` components, which loses a too-early click on a fast machine too.

## Passkeys and WebAuthn step-up

Run the real ceremony: a software authenticator answers `navigator.credentials` in Chromium and the server verifies real signatures, counters and origins. Code and helpers: [references/passkeys.md](references/passkeys.md).
