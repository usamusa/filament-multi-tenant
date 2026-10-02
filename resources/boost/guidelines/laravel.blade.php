## Laravel in this stack

- Store timestamps in UTC and pin the database session time zone to UTC. Convert user-local input (e.g. Europe/Berlin) to UTC instants before comparing it with stored values, and filter a local calendar day by its UTC bounds; mixing a local wall time with a stored UTC value is off by the offset (one to two hours in Berlin).
@if($assist->hasPackage('spatie/laravel-permission'))
- Seeders using `WithoutModelEvents` (Laravel's default `DatabaseSeeder` does) mute the events spatie relies on to flush its permission cache. Code that creates roles or permissions during seeding resets the cache itself (`app(PermissionRegistrar::class)->forgetCachedPermissions()`), or freshly seeded roles miss their permissions.
@endif
