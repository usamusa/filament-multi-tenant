# Button invariant test

A button a user can see must lead somewhere they may go, and a page they may open must not hide its button. For every resource and every role, the list opens exactly for roles with the view permission, and the "New" and "Edit" links appear exactly when the pages behind them open. One run of this test found ten dead buttons on a single resource.

Adapt the helpers to the app: `rolePermissions()` returns each built-in role with its permissions; `userWithRole()` creates a user holding that role; `panelUrl()` builds an absolute URL of the panel (tenant domain included in multi-tenant apps). Match the link text to the panel's language.

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Everything one role sees wrong on one resource, as readable lines.
 *
 * @param  list<string>  $permissions  the role's permissions
 * @param  list<string>|string  $view
 * @param  list<string>|string  $manage
 * @return list<string>
 */
function buttonProblems(string $role, array $permissions, string $slug, array|string $view, array|string $manage, ?Model $record): array
{
    $holds = fn (array|string $required): bool => array_intersect((array) $required, $permissions) !== [];
    $expected = fn (bool $allowed): int => $allowed ? 200 : 403;

    $list = test()->get(panelUrl("/{$slug}"));

    if ($list->status() !== $expected($holds($view))) {
        return ["{$role}: list answers {$list->status()}"];
    }

    if (! $holds($view)) {
        return [];
    }

    $mayManage = $holds($manage);
    $links = [panelUrl("/{$slug}/create") => 'New'];

    if ($record !== null) {
        $links[panelUrl("/{$slug}/{$record->getKey()}/edit")] = 'Edit';
    }

    $problems = [];

    foreach ($links as $url => $button) {
        $shown = str_contains($list->getContent(), "href=\"{$url}\"");
        $status = test()->get($url)->status();

        if ($shown !== $mayManage || $status !== $expected($mayManage)) {
            $problems[] = sprintf('%s: "%s" %s, page answers %d', $role, $button, $shown ? 'shown' : 'hidden', $status);
        }
    }

    return $problems;
}

it('shows "New" and "Edit" exactly to the roles that may open the pages behind them', function (string $slug, array|string $view, array|string $manage, ?Closure $makeRecord) {
    // Multi-tenant apps: create the tenant and initialize tenancy here.
    $record = $makeRecord?->__invoke();
    $problems = [];

    foreach (rolePermissions() as $role => $permissions) {
        session()->flush();
        $this->actingAs(userWithRole($role));

        array_push($problems, ...buttonProblems($role, $permissions, $slug, $view, $manage, $record));
    }

    expect($problems)->toBe([]);
})->with([
    'devices' => ['devices', 'inventory.device.view', 'inventory.device.manage', fn () => Device::factory()->create()],
    'documents' => ['documents', 'docs.document.view', 'docs.document.manage', null],
]);
```

The `?Closure $makeRecord` parameter receives the dataset closure itself, uninvoked, because it is typed `Closure`; that is why the test calls it.

Collecting every problem before asserting reports all dead buttons of a resource in one failure, instead of stopping at the first role.
