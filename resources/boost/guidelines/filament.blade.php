@if($assist->hasPackage('filament/filament', '^5.0'))
@scoped(['app/Filament/**', 'app/Livewire/**', 'app/Policies/**', 'app/Providers/Filament/**', 'resources/views/filament/**', 'resources/views/livewire/**'])
## Filament 5: authorization

- Filament 5 opens resource pages and renders their create, edit, view and delete buttons according to the resource's `get*AuthorizationResponse()` methods, which ask the model policy. Overriding only `can*()` leaves buttons on screen that end in a 403, and modal create actions skip the check entirely. With neither a policy nor overridden responses, everything is allowed. Give every resource one or the other (recipe and per-role test: `filament-panel-authorization` skill).
- Filament authorizes only its built-in CRUD operations. Authorize everything else yourself: custom pages (`canAccess()`), widgets (`canView()`), relation managers (`canViewForRecord()`), custom actions (`->authorize()` or `->visible()`) and custom Livewire components.
- Bulk actions check `deleteAny()`-style abilities, not `delete()` per record. Use `->authorizeIndividualRecords()` when record rules matter, or deny the bulk ability.
- A table action that links to a resource page (edit, view) never runs its own `->using()` or `->action()` closure. Put guards (record rules, step-up confirmation, who may act) in the page (`handleRecordUpdate()`, `handleRecordCreation()`) or in the domain service it calls.
- Resource queries return every row. Restrict what a user may see in the resource's `getEloquentQuery()`, not by hiding buttons.
- Livewire restores properties and runs `boot()` and per-property hydrate hooks before Filament authorizes the request. Don't write data, dispatch events or call services from those hooks.
@endscoped

@scoped(['app/Filament/**', 'app/Enums/**', 'app/Providers/**', 'lang/**', 'resources/views/**'])
## Filament 5: forms and output

- A closure may receive an enum-backed field's state as the enum case or as its backing value, depending on the field and the model's casts. Compare through a helper that accepts both; a bare comparison with the case's `value` silently never matches the enum.
- RichEditor HTML is not sanitized on the server. Never print it unescaped in a Blade view: sanitize at render time with `Str::sanitizeHtml()` (Filament's macro over symfony/html-sanitizer). Table columns and infolist entries using `->html()` already sanitize.
- Enums shown in tables, filters, badges or selects implement `HasLabel` (and `HasColor` where useful) with translated labels. Raw enum values never reach the UI.
- For a German (or other noun-capitalizing) UI: Filament lower-cases a label's first letter in validation messages. Keep labels as written by setting `validationAttribute()` in `Field::configureUsing()`. Publish and translate Laravel's `validation`, `auth`, `passwords` and `pagination` lang files, or errors and error pages show in English.
@endscoped
@endif
