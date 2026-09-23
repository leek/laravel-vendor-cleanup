# Verifying audit leads

The package's categories come from path matching and text comparison. Confirm each lead with the checks below before it goes in the report. If a check contradicts the package, trust the check and list the file under "Not confirmed" with the reason.

## Finding the vendor original

The `missing` list gives absolute vendor paths. For `unchanged` and `modified` entries the JSON only has the local path, so locate the source:

- Config: usually `vendor/laravel/framework/config/<name>.php` or `vendor/<vendor>/<package>/config/<name>.php`. Search with Glob `vendor/*/*/config/<name>.php`.
- Views: `resources/views/vendor/<namespace>/...` comes from the package that registers that view namespace. Search the vendor tree for the relative path after the namespace.
- Lang: `lang/vendor/<namespace>/...` works the same way. Top-level files such as `lang/en/validation.php` come from `vendor/laravel/framework/src/Illuminate/Translation/lang`.
- Migrations: published names carry a new timestamp. Match on the part after the timestamp, for example `create_jobs_table.php`, which may exist in vendor as `.php` or `.php.stub`.

## Unchanged

1. Read both the local file and the vendor original.
2. Confirm they match apart from comments. The package ignores PHP comments, so a local file whose only change is an added comment is still reported as unchanged. Mention that the comment would be lost.
3. Flag files where matching the current vendor default looks deliberate, for example a value pinned to guard against a future upstream change. Those belong under "Not confirmed" with the reason.

## Modified

1. Produce a real diff: `diff -u <vendor-original> <local-file>`.
2. Classify the drift. Look at both directions of the diff: lines only in vendor (`-`) and lines only in the local file (`+`).
   - Customized: the local file has changes of its own, such as custom values, extra keys, env wiring or app-specific logic. Keep. If vendor also added keys since it was published, list those keys so the user can add them by hand. Never suggest republishing a customized file: it would drop the local changes, which can include security hardening.
   - Stale copy: the local file has no changes of its own, and every difference is vendor code added or changed since it was published. Republishing is safe.
   - Formatting only: whitespace, import style or reordering with no change in behaviour. Say so.
3. For entries under about 10%, run the command again with `--json --normalize`. If the file moves to `unchanged`, the difference is whitespace only.

## Orphaned

A file is a confirmed orphan only when all three checks agree:

1. The package that published it is gone. Compare against `composer show --name-only`.
2. Nothing references it. Grep the file's key or name across `app/`, `config/`, `routes/`, `bootstrap/`, `database/` and `resources/`. For a config file `config/foo.php`, search for `config('foo` and `config("foo`.
3. It arrived via `vendor:publish`, not written by hand. Check `git log --oneline -- <path>`.

Otherwise it is app-owned. List it under "Not confirmed" and keep it.

Migrations with no vendor counterpart are almost always the application's own. Only mention one when its table name clearly belongs to a package that is no longer installed.

## Missing

These are vendor files available for publishing that were never published. They need no action, so summarize them as a count per package. Only list individual files when the user asked about publishing something specific.
