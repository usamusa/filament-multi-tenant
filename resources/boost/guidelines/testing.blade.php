## Testing standard

- A feature is done when tests prove it works the way a user uses it. Green service-level tests alone are not enough: they have hidden buttons that end in a 403, guards bypassed through another page and steps a user could skip.
- For every feature cover the happy path; every role that may act and every role that may not; validation and failure modes; edge cases (empty states, boundaries, duplicates); and side effects (persisted state, audit or log entries, notifications, queued jobs).
- Every user-facing flow also gets a browser test that drives the real UI: sign in, navigate, click, fill in, assert what the user sees.
- Name tests by behaviour in domain language, one behaviour per test. When a test exposes a bug or an unfinished feature, say so explicitly.
- Under `RefreshDatabase`, Laravel's testing transaction manager treats transaction level 1 as the outermost: after-commit work (`DB::afterCommit()`, jobs, listeners and notifications marked `afterCommit`) runs as soon as a nested transaction commits back to level 1, even while an application transaction on that level is still open. A test can't show that such work waits for an outer transaction. Code that wraps a long run in one transaction (a seeder, an import) must not expect queued after-commit jobs to have run before it ends: run that work inline, or outside the transaction.
@if($assist->hasPackage('pestphp/pest'))
- Pest invokes closures in a dataset before passing them, except to a parameter typed `Closure`, which receives the closure itself. Don't wrap it in a second closure.
- Pest loads every test file into one process. Two files declaring a global helper function of the same name each pass alone and break the whole suite with a fatal redeclaration error. Give file-local helpers distinctive names and keep shared ones in `tests/Pest.php`; `grep -rhoE "^function \w+" tests | sort | uniq -d` lists clashes.
- `expect($object)->value` is the expectation's own `value` property (the object itself), not the object's `value`. Write `expect($object->value)`.
@endif
@if($assist->hasPackage('filament/filament', '^5.0'))

@scoped(['tests/**'])
## Filament tests

- Filament silently ignores a request to mount or call an action the user can't see (hidden, unauthorized or not found): no 403, no exception. A test that calls a forbidden action and checks that nothing changed also passes when the action's name is wrong, so also assert the action hidden for that user and visible for one who may use it. The test helpers can't resolve a hidden action attached to a schema component (a section header, an entry) at all; assert that its label is missing from the page.
- A field's test key is its absolute key: a parent layout component's `key()` becomes part of it unless set with `isInheritable: false`, and field assertions such as `assertFormFieldExists()` then miss the field.
@endscoped
@endif
@if($assist->hasPackage('pestphp/pest-plugin-browser'))

@scoped(['tests/**'])
## Browser tests (pest-plugin-browser)

- The in-process server shares the test's application, so the session store, auth guards, tenancy and Filament's navigation carry over from one request to the next unless the base test case resets them per request. `actingAs()` then signs in every browser context: don't mix it with signing in through the login form. Base test case and helpers: `filament-browser-testing` skill.
- A click doesn't wait for a Livewire redirect. Assert something the next page shows before the next step.
- Never travel back in time in a browser test: the browser drops session cookies that expired in the travelled past.
- Reach tenants as `http://<subdomain>.localhost:<port>` (Chromium resolves every `*.localhost` to loopback) and set the tenant base domain to `localhost` before service providers boot.
- Select Filament fields by id: `[id="form.email"]`, `[id="mountedActionSchema0.name"]`. A dotted name is otherwise parsed as CSS. Searchable selects are a button plus an option list, not a native select.
- A Builder or Repeater is a field itself, wrapping its items' fields: a selector by label such as `.fi-fo-field:has(label:has-text("…"))` also matches the builder around the field, and so every field nested in it. Select nested fields by id (`[id*=".conditions."][id$=".field"]`). `nth=` waits only for a position that doesn't exist yet: a block inserted before others, or into a nested builder, is found at once at the old block's position, so select it by an id pattern too (`filament-browser-testing` skill).
- `click()`, `fill()` and the other element methods take a string that starts with `#`, `.` or `[`, or contains one of `: ( ) , = > + ~ * | ^`, or a dot before a letter ("z.B."), as a CSS selector, and anything else as visible text. Click such a text through Playwright's text engine, quoted for an exact match: `text="Temperature: 9.5 °C"`. Unquoted, it also matches every longer text containing it, and strict mode fails on two matches. `assertSee()` always searches text.
- Playwright dismisses the browser's own dialogs, so a button with a confirm dialog (Livewire's `wire:confirm`) silently does nothing. Set `window.confirm` to answer true before the click (`filament-browser-testing` skill).
- The in-process server hands the app a multipart request's raw body but none of its files (an open TODO in pest-plugin-browser), so every Livewire or Filament upload arrives empty and fails. Parse multipart bodies in the base test case into `Illuminate\Http\UploadedFile` objects created with `test: true` (a Symfony `UploadedFile` is rebuilt without its test flag, and validation then rejects a file PHP didn't upload), and create Livewire's test upload disk in `setUp()` with `FileUploadConfiguration::storage()`. Code and upload helpers: `filament-browser-testing` skill.
- Filament renders on the server for every round trip; raise the browser timeout above the 5-second default.
- Run WebAuthn/passkey flows with a software authenticator so the server verifies real signatures; never stub the verification.
@endscoped
@endif
