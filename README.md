# Arabic Slug Schema Guard

[![Packagist Version](https://img.shields.io/packagist/v/mantekio/wp-arabic-slug-schema-guard)](https://packagist.org/packages/mantekio/wp-arabic-slug-schema-guard) [![License: GPL v2](https://img.shields.io/badge/License-GPLv2-blue.svg)](LICENSE)

A WordPress **must-use plugin** that stops core updates from silently truncating long Arabic (and other non-Latin) URLs.

> 📖 **Full write-up:** [The 200-byte trap: why WordPress core updates break Arabic URLs](https://www.mantek.io/insights/wordpress-arabic-slug-truncation)

## The problem

WordPress stores post and term slugs **percent-encoded** in `VARCHAR(200)` columns (`wp_posts.post_name`, `wp_terms.slug`). Each Arabic character costs about **six bytes** once URL-encoded, so a `VARCHAR(200)` column holds only ~33 Arabic characters, and Arabic publishers widen the columns to `VARCHAR(1024)`.

The trap: on every major core update, `dbDelta()` reconciles the live schema against WordPress's canonical schema and **shrinks `VARCHAR(1024)` back to `VARCHAR(200)`**. Unlike `TEXT`/`BLOB`, `VARCHAR` has no downsize protection, so the truncation is silent and **unrecoverable**, and your long-headline URLs start returning 404.

Widening the column alone isn't enough, either: WordPress hard-codes `200` in **three** independent places: storage, slug **generation** (`sanitize_title_with_dashes()`), and collision **de-duplication** (`_truncate_post_slug()`).

## What it does

- **Prevents the shrink** (Layer 1): filters `dbdelta_create_queries` so dbDelta's *desired* schema already says `1024`; it never emits a destructive `CHANGE COLUMN`. Covers the admin DB-upgrade screen, background auto-updates, and `wp core update-db`.
- **Stops new slugs truncating** (Layer 2): replaces `sanitize_title_with_dashes()` with a byte-for-byte copy that raises the generation cap. The copy **self-tests against core** on each update: it diffs the fork against core's live function on short inputs (where neither cap fires), so if core ever rewrites that function it alerts (and can fall back to core's generator) instead of drifting silently.
- **Verifies + alerts** (tripwire): after every core update it checks the real column widths, logs and (optionally) emails on a revert, and exposes a `wp asg verify` CLI command for cron.

Layer 3 (collision de-dup) only fires on slug clashes and is left optional (see the write-up).

## Installation

This is a must-use plugin, so it loads before the upgrade routine runs and can't be deactivated by accident.

**Manual**
```bash
mkdir -p wp-content/mu-plugins
cp wp-arabic-slug-schema-guard.php wp-content/mu-plugins/
```

**Composer**
```bash
composer require mantekio/wp-arabic-slug-schema-guard
```

Composer installs it as a [`wordpress-muplugin`](https://github.com/composer/installers), which has two consequences worth knowing:

- **Allow the installer plugin.** On a fresh project Composer blocks `composer/installers` until you permit it. Add this to your root `composer.json` (most WordPress-Composer stacks already have it):
  ```json
  { "config": { "allow-plugins": { "composer/installers": true } } }
  ```
- **Make sure it actually loads.** It installs into `wp-content/mu-plugins/wp-arabic-slug-schema-guard/` (a subfolder). Vanilla WordPress only auto-loads `*.php` placed *directly* in `mu-plugins/`, not in subfolders, so either rely on a mu-plugins autoloader (Bedrock and similar stacks ship one) or use the **Manual** method above to drop the single file straight into `mu-plugins/`.

### One-time: widen the columns

The plugin *keeps* the columns wide; you still widen them once. On a small site:

```sql
ALTER TABLE wp_posts MODIFY post_name VARCHAR(1024) NOT NULL DEFAULT '';
ALTER TABLE wp_terms MODIFY slug      VARCHAR(1024) NOT NULL DEFAULT '';
```

On a large `wp_posts` (millions of rows) the `200 → 1024` change crosses InnoDB's VARCHAR length-byte boundary and forces a full table rebuild, so use an online schema-change tool instead of a raw `ALTER`:

```bash
pt-online-schema-change \
  --alter "MODIFY post_name VARCHAR(1024) NOT NULL DEFAULT ''" \
  --execute D=wordpress,t=wp_posts
```

## Configuration

Define before the plugin loads (e.g. in `wp-config.php`), or edit the constants at the top of the file:

| Constant | Default | Meaning |
|---|---|---|
| `ASG_COLUMN_LEN` | `1024` | Physical column width (bytes) |
| `ASG_SLUG_BYTES` | `1000` | Max generated slug length, under the column, leaving room for a `-2` collision suffix |
| `ASG_ALERT_EMAIL` | *(unset)* | If defined, the tripwire (and the Layer-2 self-test) email this address on a column revert or fork drift |
| `ASG_L2_FAILSAFE` | *(unset)* | If defined truthy, on Layer-2 drift fall back to core's generator (slugs cap at 200) until you re-sync the copy |

### Switching layers off

Each layer is independent and **on by default** (Layer 3 is opt-in). Turn one off with a constant, or with a filter from a theme or plugin that already owns slug generation:

| Switch | Default | Layer |
|---|---|---|
| `ASG_LAYER1` | on | Prevention: hold the column at `VARCHAR(1024)` and monitor it |
| `ASG_LAYER2` | on | Generation: the long-slug `sanitize_title_with_dashes()` fork |
| `ASG_LAYER3` | off | De-duplication: reserved (handler ships on demand) |

```php
// wp-config.php: keep the wide column, but let core (or another plugin) make slugs
define( 'ASG_LAYER2', false );

// or, from a theme/plugin that sanitises titles itself:
add_filter( 'asg_layer2_enabled', '__return_false' );
```

**Dependency:** Layers 2 and 3 emit slugs longer than 200 bytes, which only survive because Layer 1 widened the column, so they require Layer 1. Turning Layer 1 off while Layer 2 is requested leaves Layer 2 off (and logs once) rather than truncating. Layer 1 on its own is safe: the column stays wide and core's 200-byte generator stays in place.

## Verifying

```bash
wp asg verify
```

Nightly cron, alert if either column ever reverts:

```bash
0 3 * * *  cd /var/www/site && wp asg verify | grep -q REVERTED \
           && wp asg verify | mail -s "WP slug schema reverted on $(hostname)" ops@example.com
```

## Important caveats

- The tripwire restores the **column definition**, never bytes already truncated. Treat any revert as an incident: restore from backup and check your 404 logs.
- **Never import a SQL dump taken *before* you widened the columns**: the old `CREATE TABLE` puts you back at 200. A dump of the *current* database is fine.

## How it works (and why `VARCHAR(1024)` is safe)

The full root-cause analysis (the `dbDelta` chain, why the fixed **191-character index prefix** means widening the column carries no index / InnoDB / utf8mb4 risk, and the production rollout for multi-million-row sites) is in the write-up:

**→ [The 200-byte trap: why WordPress core updates break Arabic URLs](https://www.mantek.io/insights/wordpress-arabic-slug-truncation)**

## License

[GPL-2.0-or-later](LICENSE): same as WordPress.

---

Built and maintained by **[ManTek Technologies](https://www.mantek.io)**: WordPress + AWS at scale, for Arabic newsrooms and beyond.
