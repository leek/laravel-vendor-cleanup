# Laravel Vendor Cleanup

> Detect and clean up unchanged vendor-published files in your Laravel application.

<img width="1208" height="962" alt="image" src="https://github.com/user-attachments/assets/671ac9a9-4a1b-4b11-bd08-463f48dea1d1" />

---

This package provides Artisan commands to compare published vendor files (configs, migrations, views, lang files) with their originals in the vendor directory. Find out which files you've modified, which are identical to vendor (cruft), and which files are orphaned from removed packages.

## Features

- 🎯 **Accurate Matching** - Uses the publish paths packages register with Laravel, so every file is compared with the exact vendor file it was published from
- 🔍 **Smart Comparison** - Automatically strips PHP comments and optionally normalizes whitespace
- 📊 **Diff Percentages** - See what share of lines differ from the vendor original
- 🎨 **Color-Coded Output** - Green/yellow/magenta/red based on difference percentage
- 🗑️ **Safe Cleanup** - Optionally delete unchanged files with confirmation
- 🏷️ **Orphan Detection** - Find files from uninstalled packages
- 📦 **Handles Timestamps** - Smart migration filename matching (strips timestamps)
- 🔧 **Stub Support** - Detects both `.php` and `.php.stub` vendor files
- 🤖 **CI Friendly** - JSON output and a failing exit code when unchanged files remain

## Installation

```bash
composer require leek/laravel-vendor-cleanup --dev
```

The package will auto-register via Laravel's package discovery.

## Usage

### Config Files

Compare published config files with vendor originals:

```bash
php artisan vendor-cleanup:config
```

With options:

```bash
# Delete unchanged config files after confirmation
php artisan vendor-cleanup:config --delete

# Normalize whitespace in addition to stripping comments
php artisan vendor-cleanup:config --normalize
```

### Migration Files

Compare published migrations with vendor originals (handles timestamped filenames):

```bash
php artisan vendor-cleanup:migration

# List local migrations with no vendor counterpart (hidden by default,
# since most are your application's own migrations)
php artisan vendor-cleanup:migration --orphans
```

Unchanged migrations are never deleted, even with `--delete`. A deleted config, view or lang file falls back to the vendor copy, but a deleted migration does not: fresh installs would silently skip creating the package's tables.

### Lang Files

Compare published language files, including the framework's own (`php artisan lang:publish`), namespaced package translations in `lang/vendor/`, and JSON files:

```bash
php artisan vendor-cleanup:lang
```

### View Files

Compare published view files, such as `resources/views/vendor/` overrides, including the framework's pagination, mail and notification views:

```bash
php artisan vendor-cleanup:view
```

## Output Categories

Each command categorizes files into four groups:

### MODIFIED (color-coded by % different)

Files you've customized, sorted by difference percentage:
- 🟢 Green (< 5%) - Very minor changes
- 🟡 Yellow (< 15%) - Small changes
- 🟣 Magenta (< 30%) - Moderate changes
- 🔴 Red (≥ 30%) - Significant changes

### UNCHANGED

Files identical to vendor - potential candidates for deletion to reduce cruft.

### ORPHANED

Files with no vendor counterpart - either from removed packages or your own application-specific files. For lang files only `lang/vendor/` is checked, and for migrations the list is only shown with `--orphans`.

### MISSING

Vendor files not yet published locally - available if you need them.

## How It Works

1. **Finds vendor files** from the paths packages register with `ServiceProvider::publishes()`, then fills gaps from registered view and translation namespaces, the framework's own config and lang files, and common vendor directory layouts
2. **Strips PHP comments** from both vendor and local files for comparison
3. **Optionally normalizes** whitespace with `--normalize` flag
4. **Compares** file contents as text. PHP files are never executed, so a config that hardcodes a value your `.env` happens to provide is still reported as modified. JSON files are compared as data, so key order is ignored
5. **Measures drift** as the share of lines that differ (line-based, like `diff`)
6. **Categorizes** results and displays with color-coded diff percentages

For migrations, the command intelligently strips timestamps from filenames before matching (e.g., `2024_01_15_123456_create_jobs_table.php` matches `create_jobs_table.php`).

## Options

All commands support these options:

- `--delete` - Interactively delete unchanged files after showing results (not supported for migrations)
- `--force` - Delete without a confirmation prompt; required with `--delete` when running non-interactively
- `--normalize` - Also normalize whitespace, blank lines and line endings (comments are always ignored)
- `--json` - Print the report as JSON (`modified`, `unchanged`, `orphaned`, `missing`, `deleted`)
- `--fail-on-unchanged` - Exit with status 1 when unchanged files remain, e.g. to keep cruft out in CI

```bash
# Fail the build if any published config is identical to its vendor copy
php artisan vendor-cleanup:config --fail-on-unchanged
```

## AI Agent Skill (Laravel Boost)

The package ships a `vendor-cleanup-audit` skill for [Laravel Boost](https://laravel.com/docs/boost). It runs the commands, then checks every finding itself (reading both files, diffing, grepping for references, checking git history) and returns one verified report.

To install it, run Boost's installer and **select `leek/laravel-vendor-cleanup` when it asks which third-party guidelines and skills to install**. Boost never adds third-party skills on its own.

```bash
php artisan boost:install
```

Boost copies the skill to each agent it supports (Claude Code, Cursor, Codex and others). Then ask your agent to audit your vendor files, or in Claude Code run it directly:

```text
/vendor-cleanup-audit
/vendor-cleanup-audit config view
```

In Claude Code, the skill hands the file-by-file verification to a subagent on Sonnet, so the checks stay out of your main conversation. It then walks you through the decisions (which files to delete, which orphans to remove, whether to republish stale copies) with multiple-choice questions, or plain-text questions in agents without a question tool.

The audit is read-only: the skill only pre-approves the audit commands with `--json` and a few read-only tools. It deletes only the files you pick, with `git rm`, never with `--delete`, and never offers to delete migrations.

Boost 2.8 or later is recommended. Older versions reformat headings inside code blocks, which this skill avoids but other skills may not.

## Why Use This?

- **Reduce Cruft** - Delete unchanged published files and rely on vendor defaults
- **Track Customizations** - Quickly see which vendor files you've modified
- **Find Orphans** - Identify leftover files from removed packages
- **Upgrade Confidence** - Know exactly what you've changed before upgrading packages

## Example Output

```text
MODIFIED
+----------------------+------------+
| File                 | Difference |
+----------------------+------------+
| config/services.php  | 65.3%      |
| config/app.php       | 38%        |
| config/database.php  | 9.8%       |
+----------------------+------------+

UNCHANGED (matches vendor)
+------------------------+--------------------+
| File                   | File               |
+------------------------+--------------------+
| config/filesystems.php | config/mail.php    |
| config/cache.php       | config/session.php |
+------------------------+--------------------+

ORPHANED (no vendor counterpart)
+---------------------------+------------------------------+
| File                      | File                         |
+---------------------------+------------------------------+
| config/custom-package.php | config/old-dependency.php    |
+---------------------------+------------------------------+

MISSING (not published locally)
+------------------------------------+-------------------------------------+
| File                               | File                                |
+------------------------------------+-------------------------------------+
| vendor/package/config/optional.php | vendor/another/config/settings.php  |
+------------------------------------+-------------------------------------+

Done.
```

## Requirements

- PHP 8.2+
- Laravel 11.x, 12.x, or 13.x

## License

MIT
