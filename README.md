# Laravel Vendor Cleanup

> Detect and clean up unchanged vendor-published files in your Laravel application.

<img width="1208" height="962" alt="image" src="https://github.com/user-attachments/assets/671ac9a9-4a1b-4b11-bd08-463f48dea1d1" />

---

This package provides Artisan commands to compare published vendor files (configs, migrations, views, lang files) with their originals in the vendor directory. Find out which files you've modified, which are identical to vendor (cruft), and which files are orphaned from removed packages.

## Features

- 🔍 **Smart Comparison** - Automatically strips PHP comments and optionally normalizes whitespace
- 📊 **Diff Percentages** - See exactly how different your files are from vendor originals
- 🎨 **Color-Coded Output** - Green/yellow/magenta/red based on difference percentage
- 🗑️ **Safe Cleanup** - Optionally delete unchanged files with confirmation
- 🏷️ **Orphan Detection** - Find files from uninstalled packages
- 📦 **Handles Timestamps** - Smart migration filename matching (strips timestamps)
- 🔧 **Stub Support** - Detects both `.php` and `.php.stub` vendor files

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
```

### Lang Files

Compare published language files (supports nested directories and JSON files):

```bash
php artisan vendor-cleanup:lang
```

### View Files

Compare published view files in `resources/views/vendor/`:

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

Files with no vendor counterpart - either from removed packages or your own application-specific files.

### MISSING

Vendor files not yet published locally - available if you need them.

## How It Works

1. **Finds all vendor files** matching the file type (configs, migrations, etc.)
2. **Strips PHP comments** from both vendor and local files for comparison
3. **Optionally normalizes** whitespace with `--normalize` flag
4. **Compares** files using SHA256 hashing and similarity algorithms
5. **Categorizes** results and displays with color-coded diff percentages

For migrations, the command intelligently strips timestamps from filenames before matching (e.g., `2024_01_15_123456_create_jobs_table.php` matches `create_jobs_table.php`).

## Options

All commands support these options:

- `--delete` - Interactively delete unchanged files after showing results
- `--normalize` - Also normalize whitespace and line endings (comments are always ignored)

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
- Laravel 11.x or 12.x

## License

MIT
