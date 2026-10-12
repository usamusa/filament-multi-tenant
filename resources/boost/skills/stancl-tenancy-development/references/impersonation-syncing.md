# User impersonation and resource syncing

## User impersonation

Lets someone in the central app (support, an admin) sign in as a user of a tenant, on the tenant's host.

### Setup

```bash
# config/tenancy.php: add Stancl\Tenancy\Features\UserImpersonation::class to 'features'
php artisan vendor:publish --tag=impersonation-migrations   # central migration
php artisan migrate
```

A tenant route consumes the token:

```php
// routes/tenant.php, inside the identification + PreventAccessFromCentralDomains group
Route::get('/impersonate/{token}', ImpersonateController::class);
```

The central side creates the token and redirects:

```php
$token = tenancy()->impersonate($tenant, (string) $user->getKey(), '/dashboard', 'web');

return redirect('https://'.$tenant->domains()->value('domain').'/impersonate/'.$token->token);
```

### What the package does

- The token is a random 128-character string in the central table `tenant_user_impersonation_tokens`, bound to the tenant, the user id, the guard and the redirect URL.
- `UserImpersonation::makeResponse($token)` checks that the token belongs to the current tenant and is younger than `UserImpersonation::$ttl` (60 seconds), signs in with `loginUsingId()` on the token's guard, deletes the token and redirects. A token for another tenant or an expired one gets a 403; an unknown token a 404. An expired token stays in the table: prune old rows on a schedule.
- The guard must be stateful (`web`, not `sanctum`).
- `impersonate()` declares `string $userId`: in a file with `strict_types`, cast integer ids.

### What it doesn't do

The result is an ordinary login as that user. Nothing records who is behind it or why, nothing ends it, nothing shows it. Before using it for support access, wrap it:

- **Reason and authorization in the central app.** Only a permission may start it, with a stated reason (a ticket reference). Record the start in the central log and in the tenant's own log, so the tenant can see that support accessed their data.
- **Mark the session.** Use your own controller instead of a bare `makeResponse()` call: after it signs in, put `impersonated_by` (the staff user) and an expiry time into the session, show a banner on every page while it is set, and attribute actions to the staff member in your audit records.
- **Time-box it.** A middleware that signs the user out when the expiry passes.
- **Limit it.** Refuse destructive or security-relevant actions (passwords, roles, deletions, exports) while impersonating, unless a second person has approved.
- **End it.** A "stop impersonating" action that signs out and records the end.
- The token is a bearer secret in a URL: HTTPS only, and keep these URLs out of logs.

## Resource syncing

Syncs selected columns of a resource, usually users, between the central database and the tenant databases in which it exists, for products where one person has one account across several tenants. Skip it when each tenant has its own independent users.

### The pieces

- A **central model** implementing `Stancl\Tenancy\Contracts\SyncMaster` with the `CentralConnection` and `ResourceSyncing` traits, and a `tenants()` relation through a pivot table using `Stancl\Tenancy\Database\Models\TenantPivot`.
- A **tenant model** implementing `Syncable` with `ResourceSyncing`.
- Both share a global identifier (`getGlobalIdentifierKeyName()`, for example `global_id`) and list the synced attributes in `getSyncedAttributeNames()`; all other columns stay independent per tenant.
- `UpdateSyncedResource` listens to `SyncedResourceSaved` (mapped in `TenancyServiceProvider`).

### Behaviour

- Saving the resource in a tenant updates the central record and every other tenant that has it. Saving it centrally updates every attached tenant.
- Creating it in a tenant copies **all** attributes to a new central record (unsynced ones act as defaults) and attaches the tenant. Attaching a tenant to a central record (`$centralUser->tenants()->attach($tenant)`) creates the tenant copy the same way, through `TenantPivot`.
- The copies are written with `withoutEvents()`: observers and model events on the synced models don't fire for synced writes.
- Every save touches several databases. Queue the listener in production: `UpdateSyncedResource::$shouldQueue = true;`.
- When the resource is created in a tenant, the global id comes from the id generator: with incrementing tenant ids (`id_generator => null`) you must supply it yourself.
- Synced passwords mean one credential across tenants. Decide deliberately whether that matches your security model.
