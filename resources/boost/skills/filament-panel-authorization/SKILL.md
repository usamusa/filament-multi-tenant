---
name: filament-panel-authorization
description: "Authorizes Filament 5 panels with permissions (spatie/laravel-permission or policies) so every visible button leads somewhere the user may go and every guard runs where Filament actually routes the request. Use when creating or changing a Filament resource, page, relation manager, widget or action that should be restricted; when users see buttons that end in a 403 or reach pages they shouldn't; when adding record-level rules or guards for critical actions (confirmations, WebAuthn step-up); and when writing the per-role authorization tests for a panel."
metadata:
  author: usamusa
---

# Filament 5 panel authorization

## How Filament 5 decides

- **Resources** ask the model policy through the resource's `get*AuthorizationResponse()` methods (`viewAny`, `view`, `create`, `edit`, `delete`, `deleteAny`, `forceDelete`, `forceDeleteAny`, `restore`, `restoreAny`, `replicate`, `reorder`). The same responses open the pages and decide whether the create, edit, view and delete buttons and actions render.
- With no policy and no overridden responses, everything is allowed. Overriding only `can*()` does not hide the buttons: users see "New" and "Edit" that end in a 403, and modal create actions don't check at all.
- **Custom pages**: `canAccess()`. **Widgets**: `canView()`. **Relation managers**: `canViewForRecord($ownerRecord, $pageClass)`, with their create, edit and delete actions following the related model's policy. **Custom actions**: `->authorize('ability')` (plus `->authorizationTooltip()` or `->authorizationNotification()` when the user should see why) or `->visible()`. Filament authorizes only its built-in CRUD operations; anything custom is yours.
- **Bulk actions** check `deleteAny()` (and friends), not `delete()` per record. Use `->authorizeIndividualRecords()` when record rules matter.
- Authorization re-runs on every Livewire request, so a permission change takes effect at the user's next interaction. Livewire restores properties and runs `boot()` and hydrate hooks before that check: keep side effects out of them.

## Choose one wiring per panel

1. **Policies** (one per model). Use them when the same rules apply outside Filament: API, jobs, other UIs.
2. **A permission map on the resource**, when the panel is the only consumer and permissions are named `<module>.<entity>.<action>`. The trait in [references/permission-map-trait.md](references/permission-map-trait.md) answers the `get*AuthorizationResponse()` methods from a map, adds record-level rules and denies bulk, restore and force-delete abilities.

Never rely on `can*()` overrides alone.

## Record rules, visibility and guards

- **Record rules** (a released record is no longer editable, someone else's record needs an extra permission): `canEditRecord()` / `canDeleteRecord()` in the trait, or the policy's `update()` / `delete()`.
- **Visibility of rows**: scope in the resource's `getEloquentQuery()` (for example "staff see only their own requests"). Hiding buttons does not hide data.
- **Guards for critical actions** run where Filament really routes the request. A table's edit action links to the edit page, so a guard in that action's `->using()` never runs. Put it in the page:

```php
protected function handleRecordUpdate(Model $record, array $data): Model
{
    if (! $this->mayChange($record, $data)) {
        Notification::make()->danger()->title(__('This change needs a confirmation.'))->send();

        $this->halt();
    }

    $record->update($data);

    return $record;
}
```

  Better still, enforce the rule again in the domain service the page calls. For WebAuthn step-up, verify the assertion on the server, bound to exactly this record and action, never only in JavaScript.

## Tests (required for every restricted resource)

- **Button invariant per resource and role**: the list opens exactly for roles with the view permission, and "New" and "Edit" appear exactly when the pages behind them open. One dataset row per resource: [references/button-invariant-test.md](references/button-invariant-test.md).
- **Direct access**: GET each page URL as each role and assert 200 or 403. Hidden buttons don't stop a typed URL.
- **Livewire actions**: call the action or `save` as a user who may not act and assert it is refused and nothing changed; the same for each guard's refusal path.
- **Browser test** for the happy path of every critical flow (`filament-browser-testing` skill).

Name the roles in each test. A test that only checks the role that may act proves half the rule.
