## Laravel in this stack

- Store timestamps in UTC and pin the database session time zone to UTC. Convert user-local input (e.g. Europe/Berlin) to UTC instants before comparing it with stored values, and filter a local calendar day by its UTC bounds; mixing a local wall time with a stored UTC value is off by the offset (one to two hours in Berlin).
- Blade compiles echoes in a component tag's attribute values (`<x-…>`), but no directives: a directive written there, such as the `js` directive, reaches the component as literal text, and in `wire:click` or an Alpine attribute the browser then fails with "Invalid or unexpected token". Print `Illuminate\Support\Js::from(...)` with an escaped echo instead (it is `Htmlable`, so it isn't escaped twice); directives compile only in plain HTML attributes.
@if($assist->hasPackage('spatie/laravel-permission'))
- Seeders using `WithoutModelEvents` (Laravel's default `DatabaseSeeder` does) mute the events spatie relies on to flush its permission cache. Code that creates roles or permissions during seeding resets the cache itself (`app(PermissionRegistrar::class)->forgetCachedPermissions()`), or freshly seeded roles miss their permissions.
@endif
