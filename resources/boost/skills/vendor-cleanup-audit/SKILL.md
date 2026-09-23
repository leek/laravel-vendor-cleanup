---
name: vendor-cleanup-audit
description: Audit a Laravel app's published vendor files (config, migrations, views, lang) against their vendor originals, verify each finding, then guide the user through cleaning up drift, unchanged cruft and orphans.
when_to_use: Use when the user asks to audit vendor files, find vendor cruft or leftover files from removed packages, check published configs, migrations, views or lang files for drift, or see what they customized before a Laravel or package upgrade. Pass the file types to audit as arguments (config, migration, lang, view); no arguments audits all four.
argument-hint: "[config] [migration] [lang] [view]"
compatibility: Requires a Laravel application with the leek/laravel-vendor-cleanup Composer package installed.
allowed-tools:
  - Read
  - Grep
  - Glob
  - Bash(php artisan vendor-cleanup:config --json)
  - Bash(php artisan vendor-cleanup:config --json --normalize)
  - Bash(php artisan vendor-cleanup:migration --json)
  - Bash(php artisan vendor-cleanup:migration --json --normalize)
  - Bash(php artisan vendor-cleanup:lang --json)
  - Bash(php artisan vendor-cleanup:lang --json --normalize)
  - Bash(php artisan vendor-cleanup:view --json)
  - Bash(php artisan vendor-cleanup:view --json --normalize)
  - Bash(diff -u *)
  - Bash(composer show *)
  - Bash(git log --oneline -- *)
---

# Vendor Cleanup Audit

Audit the published vendor files in this Laravel application, verify every finding, then help the user decide what to clean up. The `leek/laravel-vendor-cleanup` package finds the candidates; nothing reaches the user unverified, and nothing is deleted without their explicit choice.

Requested scope: $ARGUMENTS

If the scope above is empty or still reads as a placeholder, audit all four types: config, migration, lang and view.

## Rules

- The audit is read-only. Never pass `--delete` or `--force` while auditing, and never edit or delete a file until the user has chosen it in step 5.
- Treat every category from the commands as a lead, not a verdict. Only verified findings go in the report.
- Report paths relative to the project root, never absolute.

## Steps

1. Check prerequisites. The project root must contain `artisan`, and `composer show leek/laravel-vendor-cleanup` must succeed. If either fails, stop and tell the user what is missing, including the install command `composer require leek/laravel-vendor-cleanup --dev`.

2. Gather leads. For each requested type, run the matching command and parse its JSON:

   ```bash
   php artisan vendor-cleanup:config --json
   php artisan vendor-cleanup:migration --json
   php artisan vendor-cleanup:lang --json
   php artisan vendor-cleanup:view --json
   ```

   Each report has five keys:

   | Key | Contents |
   |---|---|
   | `modified` | Published files that differ from vendor, each with `path` and `diff` (percentage of lines that differ) |
   | `unchanged` | Published files identical to vendor once PHP comments are ignored |
   | `orphaned` | Local files no installed vendor file publishes to |
   | `missing` | Vendor files that are not published locally (absolute vendor paths) |
   | `deleted` | Always empty during the audit |

   For low-percentage `modified` entries, run the same command with `--json --normalize`. Files that move to `unchanged` differ only in whitespace.

3. Verify each lead with the checks in [references/verification.md](references/verification.md), found at `${CLAUDE_SKILL_DIR}/references/verification.md` in Claude Code.

   Verification means reading and diffing many files. If you can hand work to a subagent (in Claude Code, the Agent tool), delegate this step to one running a faster model (pass `model: sonnet`). Give it the full path to the verification guide, the JSON leads, the rules above and the report format below, and ask it to return only the report. Otherwise, verify the leads yourself.

4. Present the report in the format below.

5. Guide the user through the decisions. Skip this step if the report has nothing to act on, or if the user only asked for a report.

## Report format

Use short Markdown sections and bullets. Do not paste the commands' raw output or wide tables.

```markdown

## Vendor cleanup audit: config, view

### Unchanged (safe to delete)

- config/cache.php: identical to vendor/laravel/framework/config/cache.php

### Modified (review)

- config/app.php, 38%: custom providers and locale. Intentional, keep.
- config/database.php, 9.8%: whitespace only (unchanged under --normalize). Safe to delete.
- config/queue.php, 6%: custom `background` connection. Keep, and consider adding the upstream `overflow` block by hand.
- config/cors.php, 12%: stale copy with no local changes. Safe to republish.

### Orphaned (confirmed)

- config/old-package.php: package not installed, no references, added by a vendor:publish commit.

### Not confirmed

- config/billing.php: reported orphaned, but app/Billing reads it. App-owned, keep.
```

Omit empty sections. Summarize the `missing` list as a count per package unless the user asked about it.

## Guiding the user

If you have a tool that asks the user structured multiple-choice questions (in Claude Code, `AskUserQuestion`), use it. Otherwise ask in plain text, one decision at a time, with numbered options.

Ask only about verified findings, grouped so each question is one decision:

- **Unchanged and whitespace-only files:** which to delete. Offer "all of them", "none" and, when there are only a few, each file as its own choice.
- **Confirmed orphans:** which to remove.
- **Stale copies** (no local changes, only missing upstream updates): keep as is, or republish with `php artisan vendor:publish`. Republishing overwrites the file, so offer it one file at a time.
- **Customized files missing upstream keys:** keep as is, or add the missing keys by hand. Never offer to republish these, since it would drop the local changes.

Recommend the safe choice first, and put the consequence of each option in its description. Never offer to delete migrations. If any are unchanged, explain instead that the package never deletes them, because removing a published migration can stop fresh installs from creating the package's tables.

Before changing anything, check `git status`. If the working tree has uncommitted changes, say so and ask whether to continue, since a clean tree makes every deletion revertible.

Then act on exactly what the user chose:

- Delete the chosen files with `git rm -- <path> ...`, or `rm -- <path> ...` for untracked files. Do not use `vendor-cleanup:* --delete`: it removes every file the command reports as unchanged, including files your verification rejected.
- Finish with a short summary of what changed and how to undo it (`git restore --staged --worktree -- <path>`).
