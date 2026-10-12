## Laravel in this stack

- Store timestamps in UTC and pin the database session time zone to UTC. Convert user-local input (e.g. Europe/Berlin) to UTC instants before comparing it with stored values, and filter a local calendar day by its UTC bounds; mixing a local wall time with a stored UTC value is off by the offset (one to two hours in Berlin).
- Blade compiles echoes in a component tag's attribute values (`<x-…>`), but no directives: a directive written there, such as the `js` directive, reaches the component as literal text, and in `wire:click` or an Alpine attribute the browser then fails with "Invalid or unexpected token". Print `Illuminate\Support\Js::from(...)` with an escaped echo instead (it is `Htmlable`, so it isn't escaped twice); directives compile only in plain HTML attributes.
@if($assist->hasPackage('league/flysystem-aws-s3-v3'))
- An S3-compatible store other than AWS itself (MinIO, a hosting provider's object storage) may reject the checksum headers the AWS SDK adds to every request since early 2025. Set `request_checksum_calculation` and `response_checksum_validation` to `when_required` in such a disk's configuration.
@endif
@if($assist->hasPackage('phpoffice/phpword'))
- The Word2007 reader of phpoffice/phpword loads every picture a document links to outside the file (a relationship with `TargetMode="External"`): it fetches an http or https target from the network and reads a local path from the server's disk. Before loading an uploaded `.docx`, remove external relationships other than hyperlinks from its `.rels` parts, and cap its number of entries and its unpacked size: the file is a zip.
@endif
@if($assist->hasPackage('barryvdh/laravel-dompdf'))
- barryvdh/laravel-dompdf's default configuration embeds whole fonts (`enable_font_subsetting` off, so every PDF grows by hundreds of kilobytes), writes inline scripts into the PDF (`enable_javascript` on) and loads `file://` resources from anywhere under the project root (`chroot`). For PDFs that hold user content, turn subsetting on and JavaScript off, allow only `data://` in `allowed_protocols`, and embed images as data URIs.
@endif
@if($assist->hasPackage('spatie/laravel-permission'))
- Seeders using `WithoutModelEvents` (Laravel's default `DatabaseSeeder` does) mute the events spatie relies on to flush its permission cache. Code that creates roles or permissions during seeding resets the cache itself (`app(PermissionRegistrar::class)->forgetCachedPermissions()`), or freshly seeded roles miss their permissions.
@endif
