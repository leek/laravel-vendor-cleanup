---
name: vendor-cleanup-audit
description: Audit a Laravel app for vendor-published file cruft - orphaned files from uninstalled packages, drift between local copies and vendor originals, and unchanged published files that can be deleted. Use when the user asks to "audit vendor files", "find vendor cruft", "check for orphaned configs/migrations/views/lang files", "detect vendor drift", "find leftover package files", "clean up published files", or mentions the `leek/laravel-vendor-cleanup` package.
---

# Vendor Cleanup Audit

Help the user inspect published vendor files (`config/`, `database/migrations/`, `lang/vendor/`, `resources/views/vendor/`) against the originals in `vendor/`. Identifies four categories: **MODIFIED** (drift), **UNCHANGED** (cruft), **ORPHANED** (leftovers from uninstalled packages), and **MISSING** (vendor files not published locally).

Backed by the `leek/laravel-vendor-cleanup` Composer package.

## Treat command output as a lead, not a verdict

The `vendor-cleanup:*` commands are **file finders**, not auditors. Their categorization is heuristic (basename matching, content hashing with comments stripped, `similar_text` similarity). **Always verify each finding yourself** before recommending action to the user. Use the command output as a worklist of file paths to investigate, then independently confirm with `Read`, `git log`, `composer show`, and `grep`.

Mandatory verification per category:

- **UNCHANGED** — re-read both the local file and the vendor original. Confirm they are functionally equivalent. Watch for: locale-specific lang files where basename collides but content is intentional; configs where the published copy intentionally pins values that happen to match current vendor defaults but should not regress if vendor changes upstream.
- **MODIFIED** — read both files and produce a real diff (`diff -u vendor/.../file local/file`). Decide if drift is intentional (custom values, env wiring) or accidental (stale copy from older package version, partial merge). Low % drift is often whitespace/comment artifacts the package already strips — re-run with `--normalize` to rule those out.
- **ORPHANED** — never trust the orphan label alone. Cross-check:
  1. `composer show --name-only` — is the originating package actually gone?
  2. `grep -r` the filename/key across `app/`, `config/`, `routes/`, `bootstrap/` — is the file still referenced?
  3. `git log -- <path>` — was it user-authored or `vendor:publish`-generated?
  An "orphan" may be a deliberately app-owned file that lives in `config/` or a sibling of a package that publishes under a different basename.
- **MISSING** — confirm the vendor source file actually belongs to a publish group the user intends to consume. Some vendor files in `vendor/*/config/` are internal and never meant to be published.

If any verification step contradicts the package's category, trust your verification — report the discrepancy to the user and adjust the recommendation.

## When to use this skill

Invoke when the user wants to:

- Find files left behind after a package was removed (orphans).
- Detect drift between locally published config/migration/view/lang files and the upstream vendor copy.
- Identify unchanged published files that can be safely deleted to fall back to vendor defaults.
- Audit before a Laravel/package upgrade so they know exactly what they have customized.
- Reduce repository "cruft" from `vendor:publish` operations.

Do **not** invoke for general dependency cleanup (`composer remove`, `composer prune`) - this skill is scoped to **published** vendor files only.

## Prerequisites

Before running any command, verify:

1. The package is installed: `grep leek/laravel-vendor-cleanup composer.json` (or check `composer.lock`). If absent, suggest:
   ```bash
   composer require leek/laravel-vendor-cleanup --dev
   ```
2. The user is in a Laravel project root (`artisan` file exists, Laravel 11+ / PHP 8.2+).
3. The `vendor/` directory is present and populated (`composer install` has been run).

## Commands

All four commands share the same flags and output four categories. Pick the command that matches what the user is auditing.

| Audit target | Command | What it scans |
|---|---|---|
| Config files | `php artisan vendor-cleanup:config` | `config/*.php` vs `vendor/*/config/*.php` |
| Migrations | `php artisan vendor-cleanup:migration` | `database/migrations/*.php` vs vendor migrations (timestamp-stripped match) |
| Lang files | `php artisan vendor-cleanup:lang` | `lang/vendor/**/*` vs vendor lang files |
| Blade views | `php artisan vendor-cleanup:view` | `resources/views/vendor/**/*.blade.php` vs vendor views |

### Shared flags

- `--normalize` - Also normalizes whitespace and line endings before comparison. PHP comments are **always** stripped. Use when whitespace-only diffs (line endings, indentation tweaks) are showing files as modified that the user considers identical.
- `--delete` - Prompts to delete the **UNCHANGED** files after displaying results. Destructive - see safety section below.

## Workflow

Run commands **read-only first** (no `--delete`). Treat output as a list of paths to investigate. Verify each path independently. Only after verification, propose actions.

1. **Gather leads** — run relevant audit(s) with no flags:
   ```bash
   php artisan vendor-cleanup:config
   php artisan vendor-cleanup:migration
   php artisan vendor-cleanup:lang
   php artisan vendor-cleanup:view
   ```
   If user did not specify a target, run all four. Capture the file paths under each category. Do **not** relay the package's verdict to the user yet.

2. **Verify every flagged file yourself.** For each path the package reported:

   - **UNCHANGED candidates** — `Read` both `vendor/<source>` and the local copy. Confirm content equivalence beyond what the hash check sees (e.g., logically equivalent arrays, intentional value-pinning). Only files that pass your own check are real cruft.
   - **MODIFIED candidates** — produce a real diff:
     ```bash
     diff -u <vendor-source-path> <local-path>
     ```
     Read the diff. Classify drift as intentional (env wiring, custom values, app logic) or accidental (stale copy, partial merge, formatting). For low-% items, re-run the relevant command with `--normalize` and see if it drops out — confirms whitespace-only noise.
   - **ORPHANED candidates** — verify via three checks before treating as deletable:
     ```bash
     composer show --name-only | sort
     grep -rn "<filename-without-ext>" app/ config/ routes/ bootstrap/ database/ resources/
     git log --oneline -- <path>
     ```
     File is a true orphan only if: originating package absent from `composer show`, no live references in app code, and git history shows it arrived via `vendor:publish` (not user-authored). Otherwise treat as app-owned and leave alone.
   - **MISSING candidates** — open `vendor/<package>/composer.json` and inspect `extra.laravel.providers` + the service provider's `boot()` to confirm the file is actually in a publish group the user would want. Skip files that are package-internal.

3. **Synthesize verified findings.** Drop anything that failed verification. Report only verified results to the user, with the evidence you collected (diff snippets, grep counts, git log lines). Example:
   > Verified 4 UNCHANGED configs against vendor sources: `cache.php`, `mail.php`, `session.php`, `filesystems.php` — content-equivalent. `config/old-dependency.php` flagged ORPHANED, confirmed: package absent from `composer show`, zero references in app code, last touched by `vendor:publish` commit `abc1234`.

4. **Then** propose actions. For UNCHANGED-and-verified, suggest the relevant `--delete` invocation. For ORPHANED-and-verified, propose explicit `rm <path>` (or `git rm`). For MODIFIED, surface the diff so the user decides.

## Safety

- **Never run `--delete` without first running the read-only command and showing the user the UNCHANGED list.**
- `--delete` only removes UNCHANGED files (never MODIFIED or ORPHANED). Orphan removal is the user's call - if requested, delete with explicit `rm` after confirming each path.
- Migrations: deleting an UNCHANGED published migration is safe **only if it has not yet run in production**, or the vendor package will continue to provide it via auto-loaded migrations. Warn the user before deleting any migration file. Check `php artisan migrate:status` if uncertain.
- Always recommend the user has a clean git working tree (`git status`) before any deletion so changes are revertable.

## Worked example

User: *"Audit my vendor configs and tell me what's drifted."*

Step 1 — gather leads:

```bash
php artisan vendor-cleanup:config
```

Sample raw output (treat as worklist only):

```text
MODIFIED
| config/services.php  | 65.3%      |
| config/app.php       | 38%        |
| config/database.php  | 9.8%       |

UNCHANGED (matches vendor)
| config/filesystems.php | config/mail.php    |
| config/cache.php       | config/session.php |

ORPHANED (no vendor counterpart)
| config/old-dependency.php |
```

Step 2 — verify each path independently:

```bash
# MODIFIED: real diffs, not similarity scores
diff -u vendor/laravel/framework/config/services.php config/services.php
diff -u vendor/laravel/framework/config/app.php config/app.php
diff -u vendor/laravel/framework/config/database.php config/database.php
# Whitespace-only? confirm by re-running normalized:
php artisan vendor-cleanup:config --normalize

# UNCHANGED: re-read and confirm equivalence
# (Read tool on each pair)

# ORPHANED: confirm package is truly gone and file unreferenced
composer show --name-only | grep -i old-dependency || echo "package absent"
grep -rn "old-dependency" app/ config/ routes/ bootstrap/ database/ resources/
git log --oneline -- config/old-dependency.php
```

Step 3 — only verified findings reach the user:

- `services.php` 65% drift confirmed by `diff -u`: custom Stripe/Mailgun keys + new `pusher` block. Real customization, keep.
- `app.php` 38% drift confirmed: provider list + locale customized. Keep.
- `database.php` 9.8% drift drops to 0 under `--normalize`: whitespace-only. Treat as UNCHANGED.
- `filesystems.php`, `mail.php`, `cache.php`, `session.php`: read both sides — content-equivalent. Real cruft.
- `old-dependency.php`: `composer show` empty for that vendor, zero `grep` hits, last commit was `vendor:publish` 2 years ago. Real orphan.

Step 4 — propose actions:

- `php artisan vendor-cleanup:config --delete` for the 4 UNCHANGED + `database.php`.
- `git rm config/old-dependency.php` for the verified orphan.
- Leave `services.php` and `app.php` alone — document drift in upgrade notes.

## Limitations

- Comparison is content-based (SHA256 + `similar_text`). Rename-only refactors in vendor packages may show as MODIFIED even when semantically identical.
- Migrations match by basename with timestamp stripped (`2024_01_15_123456_create_jobs_table.php` → `create_jobs_table.php`). Custom-named migrations that shadow vendor ones may misclassify - inspect manually.
- The package only inspects files under the four supported roots. It does not detect orphans under arbitrary paths (e.g. `resources/js/vendor/`).
- Requires PHP 8.2+ and Laravel 11.x / 12.x.
