---
name: record-stack-rule
description: "Records a reusable lesson about the Filament 5 / Livewire 4 / stancl/tenancy 3 / spatie-permission / Pest stack in the shared usamusa/filament-multi-tenant package, so every project that uses the stack gets it through Laravel Boost. Use only when the user explicitly asks to record, add, change or remove a stack rule, gotcha or skill (for example \"make that a stack rule\"). Not for decisions that belong to one project."
metadata:
  author: usamusa
---

# Record a stack rule

The package `usamusa/filament-multi-tenant` is the single source of these rules and skills. Projects receive copies through `php artisan boost:update`; the copies in a project's `vendor/`, `CLAUDE.md`, `AGENTS.md` and agent skill folders are generated and are overwritten on the next update. Never edit them.

## 1. Is it a stack rule?

- **Stack rule**: true in any project using these packages, independent of its domain. "Filament 5 renders buttons from `get*AuthorizationResponse()`."
- **Project rule**: a decision or convention of one app. "Users are deactivated, never deleted." It goes to that project's own instructions (its `CLAUDE.md`, its memory, or Boost's `record-rule`), not here.

If it is unclear, ask the user.

## 2. Rule or skill?

- **Rule** (a guideline in `resources/boost/guidelines/`): a short, must-follow constraint that is loaded in every session. One bullet: imperative, names the failure it prevents ("…, or saves throw `UrlGenerationException`").
- **Skill content** (in `resources/boost/skills/<skill>/`): procedures, checklists and code that are read on demand. Link it from the rule when both are needed.

| Guideline file | Topic | Rendered when the project has |
| --- | --- | --- |
| `filament.blade.php` | Filament authorization, forms, output | `filament/filament ^5.0` |
| `livewire.blade.php` | Livewire | `livewire/livewire ^4.0` |
| `tenancy.blade.php` | stancl tenancy, cross-tenant state, tenant tests | `stancl/tenancy ^3.0` |
| `laravel.blade.php` | General Laravel lessons | always (spatie part: `spatie/laravel-permission`) |
| `testing.blade.php` | Testing standard, Pest, browser tests | always (Pest and browser parts gated) |

A rule that only holds for one major version goes inside an `@if($assist->hasPackage('vendor/package', '^N.0'))` block. When a new major version changes the answer, add a block for it rather than editing the old one in place.

## 3. Find the package checkout

1. `composer show usamusa/filament-multi-tenant` in the current project shows where it is installed. When the project uses a path repository, that path is the checkout.
2. Otherwise look for a sibling checkout (for example `~/dev/filament-multi-tenant`) whose `git remote -v` points at `github.com/usamusa/filament-multi-tenant`.
3. Otherwise ask the user where it is, or whether to clone it.

Run `git pull` in the checkout before editing.

## 4. Check for duplicates and conflicts

Search the guidelines and skills for the topic (for example `grep -rin "rate limiter" resources/boost`). Update an existing rule rather than adding a near-duplicate. If the new lesson contradicts an existing rule, show both to the user and ask which holds.

## 5. Write it

- Guidelines are Blade templates. Write no double curly braces, and no `@` followed by a word except the file's own `@if`, `@endif`, `@scoped` and `@endscoped` lines. Describe unescaped output in words rather than writing the Blade syntax. A Blade error silently drops the whole file on `boost:update`.
- Use backticks for code identifiers; put longer code in a skill's reference file, not in a guideline.
- The repository is public. Keep every rule and example general: no project or company names, domain terms, customer data, people's names, internal hosts, credentials or details of how a particular product works. Rewrite an example from a real project with neutral names before adding it.
- A skill's `SKILL.md` needs `name` and `description` frontmatter, or Boost skips it. Keep `SKILL.md` short and put long material in `references/`.

## 6. Verify

In a project that uses the package through a path repository (or after pushing and running `composer update usamusa/filament-multi-tenant`), run `php artisan boost:update --no-discover` and check that the rule appears in `CLAUDE.md` under `=== usamusa/filament-multi-tenant/<file> rules ===`. `php artisan boost:install` reports Blade render failures; `boost:update` does not.

## 7. Commit and roll out

1. Commit in the package checkout with a conventional commit (`docs(rules): …` for guidelines, `feat(skills): …` for skills). Push only when the user says so.
2. In each project: `composer update usamusa/filament-multi-tenant`, then `php artisan boost:update --no-discover`, and commit the regenerated agent files.
