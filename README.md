# filament-multi-tenant

Shared AI guidelines and agent skills for building multi-tenant Laravel apps with **Filament 5, Livewire 4, stancl/tenancy 3 (database per tenant), spatie/laravel-permission and Pest browser tests**.

It is a Composer package without runtime code. [Laravel Boost](https://github.com/laravel/boost) picks it up and writes its guidelines and skills into each project's agent files (`CLAUDE.md`, `AGENTS.md`, `.claude/skills/`, …) for every agent the project configures. Because those files are committed, the rules reach every machine, cloud agent session and collaborator working on the project.

## What's inside

**Guidelines** (`resources/boost/guidelines/`) are short must-follow rules, loaded in every agent session. Each file renders only when the project uses the matching package:

| File | Covers | Rendered when the project has |
| --- | --- | --- |
| `filament.blade.php` | Authorization (responses vs `can*()`, guards on pages), forms, sanitizing, labels | `filament/filament ^5.0` |
| `livewire.blade.php` | APP_KEY-hashed update endpoint | `livewire/livewire ^4.0` |
| `tenancy.blade.php` | Tenant-domain requests, state that must not cross tenants, databases and tests | `stancl/tenancy ^3.0` |
| `laravel.blade.php` | Time zones; spatie cache after seeding | always |
| `testing.blade.php` | Testing standard; Pest and pest-plugin-browser traps | always (parts gated) |

**Skills** (`resources/boost/skills/`) are loaded on demand:

| Skill | Use it for |
| --- | --- |
| `stancl-tenancy-development` | Setting up or auditing tenancy: checklist, bootstrapper code, request plumbing, isolation tests, symptom table |
| `filament-panel-authorization` | Permission-gated resources, record rules, guards for critical actions, the per-role button invariant test |
| `filament-browser-testing` | Browser tests for Filament panels on tenant subdomains, selectors, passkey ceremonies with a software authenticator |
| `record-stack-rule` | Adding or changing a rule in this package |

## Install in a project

The project needs Laravel Boost (`laravel/boost` ^2.10, set up with `php artisan boost:install`).

1. Make the package resolvable. Until it is on Packagist, add the repository to the project's `composer.json`:

   ```json
   "repositories": [
       { "type": "vcs", "url": "https://github.com/usamusa/filament-multi-tenant" }
   ]
   ```

2. Require it as a dev dependency. It must be a direct dependency; Boost ignores transitive ones.

   ```bash
   composer require --dev usamusa/filament-multi-tenant:dev-main
   ```

3. Enable it in `boost.json` by adding `"usamusa/filament-multi-tenant"` to `packages`. (Alternatively `php artisan boost:update` asks about newly discovered packages when run interactively.)

4. Generate the agent files and commit them:

   ```bash
   php artisan boost:update --no-discover
   ```

   The guidelines appear in `CLAUDE.md` / `AGENTS.md` as `=== usamusa/filament-multi-tenant/<file> rules ===` sections, and the skills in each agent's skills folder.

Optional: with `BOOST_RULES_SCOPED_GUIDELINES=true`, Boost moves the path-scoped parts (the `@scoped` blocks) out of `CLAUDE.md` into `.ai/rules/boost/`, where agents read them when they work on matching files. This applies to all of Boost's guidelines in that project, not just these.

## Update a project

```bash
composer update usamusa/filament-multi-tenant
php artisan boost:update --no-discover
```

Commit the regenerated agent files.

## Change a rule

Ask the agent to "make that a stack rule"; the `record-stack-rule` skill walks through it. By hand:

1. Decide whether the lesson holds for any project on this stack. Project-specific decisions stay in that project. This repository is public: no project or company names, customer data, people's names, internal hosts or credentials, in rules or in examples.
2. Short constraints go into a guideline file as one bullet that names the failure it prevents. Procedures and code go into a skill's `references/`.
3. Guidelines are Blade templates: no double curly braces, and no `@` followed by a word other than the `@if` / `@scoped` lines. A Blade error drops the whole file on `boost:update` without a message; `boost:install` reports it.
4. Verify in a project (step 4 above), commit with a conventional commit, then update the projects.

For local work on this package, point a project at the checkout with a path repository instead of the VCS one:

```json
{ "type": "path", "url": "../filament-multi-tenant", "options": { "versions": { "usamusa/filament-multi-tenant": "dev-main" } } }
```
