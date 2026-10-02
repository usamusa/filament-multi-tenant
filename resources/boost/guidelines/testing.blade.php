## Testing standard

- A feature is done when tests prove it works the way a user uses it. Green service-level tests alone are not enough: they have hidden buttons that end in a 403, guards bypassed through another page and steps a user could skip.
- For every feature cover the happy path; every role that may act and every role that may not; validation and failure modes; edge cases (empty states, boundaries, duplicates); and side effects (persisted state, audit or log entries, notifications, queued jobs).
- Every user-facing flow also gets a browser test that drives the real UI: sign in, navigate, click, fill in, assert what the user sees.
- Name tests by behaviour in domain language, one behaviour per test. When a test exposes a bug or an unfinished feature, say so explicitly.
@if($assist->hasPackage('pestphp/pest'))
- Pest invokes closures in a dataset before passing them, except to a parameter typed `Closure`, which receives the closure itself. Don't wrap it in a second closure.
@endif
@if($assist->hasPackage('pestphp/pest-plugin-browser'))

@scoped(['tests/**'])
## Browser tests (pest-plugin-browser)

- The in-process server shares the test's application, so the session store, auth guards, tenancy and Filament's navigation carry over from one request to the next unless the base test case resets them per request. `actingAs()` then signs in every browser context: don't mix it with signing in through the login form. Base test case and helpers: `filament-browser-testing` skill.
- A click doesn't wait for a Livewire redirect. Assert something the next page shows before the next step.
- Never travel back in time in a browser test: the browser drops session cookies that expired in the travelled past.
- Reach tenants as `http://<subdomain>.localhost:<port>` (Chromium resolves every `*.localhost` to loopback) and set the tenant base domain to `localhost` before service providers boot.
- Select Filament fields by id: `[id="form.email"]`, `[id="mountedActionSchema0.name"]`. A dotted name is otherwise parsed as CSS. Searchable selects are a button plus an option list, not a native select.
- Filament renders on the server for every round trip; raise the browser timeout above the 5-second default.
- Run WebAuthn/passkey flows with a software authenticator so the server verifies real signatures; never stub the verification.
@endscoped
@endif
