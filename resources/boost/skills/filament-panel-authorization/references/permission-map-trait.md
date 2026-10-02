# Permission map trait for Filament 5 resources

Answers the responses Filament 5 consults from a per-resource map of abilities to permissions. Any one permission in a list suffices; an ability without an entry is denied. Works with spatie/laravel-permission (`canAny()`) or any gate-backed `can()`.

```php
<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Permission-based authorization for a panel resource, expressed through the
 * responses Filament 5 consults: get*AuthorizationResponse() guards the pages
 * AND decides whether the create / edit / view / delete buttons render.
 */
trait AuthorizesWithPermissions
{
    /**
     * @return array<'viewAny'|'create'|'update'|'delete', string|list<string>>
     */
    abstract protected static function abilityPermissions(): array;

    public static function getViewAnyAuthorizationResponse(): Response
    {
        return static::allowWhen(static::userHoldsAbility('viewAny'));
    }

    public static function getViewAuthorizationResponse(Model $record): Response
    {
        return static::getViewAnyAuthorizationResponse();
    }

    public static function getCreateAuthorizationResponse(): Response
    {
        return static::allowWhen(static::userHoldsAbility('create'));
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return static::allowWhen(static::userHoldsAbility('update') && static::canEditRecord($record));
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return static::allowWhen(static::userHoldsAbility('delete') && static::canDeleteRecord($record));
    }

    public static function getDeleteAnyAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    public static function getForceDeleteAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
    }

    public static function getForceDeleteAnyAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    public static function getRestoreAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
    }

    public static function getRestoreAnyAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    public static function getReplicateAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
    }

    public static function getReorderAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    /** Record-level rule on top of the update permission. */
    protected static function canEditRecord(Model $record): bool
    {
        return true;
    }

    /** Record-level rule on top of the delete permission. */
    protected static function canDeleteRecord(Model $record): bool
    {
        return true;
    }

    protected static function userHoldsAbility(string $ability): bool
    {
        $permissions = (array) (static::abilityPermissions()[$ability] ?? []);
        $user = auth()->user();

        return $user !== null && $permissions !== [] && $user->canAny($permissions);
    }

    private static function allowWhen(bool $allowed): Response
    {
        return $allowed ? Response::allow() : Response::deny();
    }
}
```

Usage:

```php
class DeviceResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static function abilityPermissions(): array
    {
        return [
            'viewAny' => 'inventory.device.view',
            'create' => 'inventory.device.manage',
            'update' => 'inventory.device.manage',
            'delete' => 'inventory.device.manage',
        ];
    }

    /** A disposed device is history: it can no longer be edited. */
    protected static function canEditRecord(Model $record): bool
    {
        return $record->disposed_at === null;
    }
}
```

When migrating a resource that overrides `can*()`, remove those overrides in the same change so there is one source of truth, and add the resource to the button invariant test.
