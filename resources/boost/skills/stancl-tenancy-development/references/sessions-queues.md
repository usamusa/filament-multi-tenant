# Sessions, queues, Horizon and the scheduler

## Sessions

User ids repeat across tenant databases: user 5 exists in tenant A and in tenant B. If tenant B can read a session written at tenant A, that session authenticates as tenant B's user 5. Two things prevent it:

1. **Host-only session cookies.** Leave `SESSION_DOMAIN` unset (`null`). A wildcard like `.app.example` sends one session cookie to every tenant subdomain.
2. **Session storage that is scoped to the tenant**, one of:
   - **Database driver** with the `sessions` table in the tenant migrations. The identification middleware must run before `StartSession`, so the session is read from the tenant database.
   - **Redis driver** with `RedisTenancyBootstrapper` prefixing the session's Redis connection (`session.connection`, listed in `tenancy.redis.prefixed_connections`). Needs phpredis.
   - **`ScopeSessions` middleware** on tenant routes, after `StartSession`. It stores the tenant key in the session and answers 403 when a session from another tenant shows up. It works with any driver but relies on one domain per tenant.

The **file** driver's folder is resolved at boot and is shared by all tenants. Don't use it with multiple tenants unless `ScopeSessions` runs on every tenant route.

## Queues

- Jobs dispatched in tenant context carry `tenant_id`; the worker initializes that tenant before the job and ends tenancy after it (always, since 3.8.5).
- **Database driver:** set `queue.connections.database.connection` to the central connection. Otherwise a job dispatched in tenant context is written to the tenant database's `jobs` table, which no worker polls.
- **Redis driver:** the queue's Redis connection must not be in `tenancy.redis.prefixed_connections`.
- Keep `queue.batching.database` and `queue.failed.database` on the central connection name too, so batches and failed jobs land centrally.
- **Central-only jobs:** dispatch them on a connection whose config has `'central' => true`; they never carry a tenant. Don't mix central and tenant jobs on the same queue connection: a long-running worker can carry state from one job into the next.
- **Models in job payloads** are restored after tenancy is initialized, so tenant models (connection `tenant`) and central models (pinned connection) both come back from the right database.
- **Assert the context** at the top of `handle()` in jobs that touch tenant data:

```php
public function handle(): void
{
    if (! tenancy()->initialized) {
        throw new LogicException(static::class.' must run inside a tenant.');
    }

    // ...
}
```

- **Deleted tenants:** a job whose tenant no longer exists fails with `TenantCouldNotBeIdentifiedById`. Drain or delete a tenant's jobs before deleting it.
- **No request in the worker:** URLs default to `app.url`. Pass URLs built during the request, or build them from the tenant's domain (see [identification-routing](identification-routing.md)).
- **Tests:** `Queue::fake()` and `Bus::fake()` skip the payload hook, so faked jobs carry no `tenant_id`. Test the tenant round trip with a real database queue (see [isolation-tests](isolation-tests.md)).

## Horizon

- Horizon's dashboard belongs to the central app; put it on a central domain.
- Neither Horizon's Redis connection (`horizon.use`) nor the queue connections may be prefixed per tenant.
- Tag jobs with their tenant to filter them in the dashboard:

```php
public function tags(): array
{
    return ['tenant:'.tenant('id')];
}
```

`tags()` runs at dispatch time, in the dispatching context.

## The scheduler

The scheduler runs centrally. Schedule central commands that fan out to the tenants: a tenant-aware command, `tenants:run`, or a central command that dispatches one queued job per tenant from inside that tenant (`$tenant->run(fn () => SomeJob::dispatch())`, so the payload carries the tenant). Locks for `withoutOverlapping()` and `onOneServer()` live in the cache, which is central in the scheduler process.

## Redis for cache and sessions

- Cache on Redis: stancl's tag-based bootstrapper works there, or use the prefix bootstrapper from [bootstrappers](bootstrappers.md) for one approach across all stores.
- Direct `Redis::` calls in tenant code are shared unless their connection is in `tenancy.redis.prefixed_connections`.
- The prefix is `prefix_base` + tenant key with no separator (`tenant42`), applied as phpredis `OPT_PREFIX`.
