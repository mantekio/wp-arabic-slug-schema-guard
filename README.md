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
- **Stops new slugs truncating** (Layer 2): replaces `sanitize_title_with_dashes()` with a byte-for-byte copy whose **only** difference from core is the byte budget. The copy **self-tests against core** on each update: it diffs the fork against core's live function on short inputs (where neither cap fires), so if core rewrites that function, or the fork falls behind it, the drift is reported instead of shipping silently.
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

> ⚠️ **On MySQL 8 in strict mode this `ALTER` fails**, with `Invalid default value for 'post_date'`. Altering the table makes MySQL re-validate WordPress's legacy zero-date column defaults, which strict mode rejects. Clear the mode for the session (`SET SESSION sql_mode = '';`) or fix those defaults first. **dbDelta-driven widening is unaffected**, so on a strict host you can also just let the next core upgrade widen the column for you, losslessly, since Layer 1 rewrites the desired schema.

> ⚠️ **Widen both columns.** Core ships `wp_terms.slug` at `VARCHAR(200)` too, so widening only `post_name` is the most common mistake, and Layer 2 generates slugs for terms as well. On a narrow column MySQL truncates them (in strict mode the insert fails outright). Until the plugin has read the live width, **Layer 2 clamps its budget to what the narrowest column can actually hold**, so a half-widened site degrades to shorter slugs rather than corrupted ones. Widen both anyway.
>
> Because Layer 1 rewrites dbDelta's *desired* schema, the **next core upgrade will widen a narrow column for you**, losslessly. So treat the manual `ALTER` as "do it now, because Layer 2 is already generating slugs," not "or it never happens."

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
| `ASG_SLUG_BYTES` | `1000` | Ceiling for a generated slug. The effective budget is `min(ASG_SLUG_BYTES, live_column_width - ASG_SUFFIX_HEADROOM)` |
| `ASG_SUFFIX_HEADROOM` | `24` | Bytes kept free for a `-2` collision suffix, so a colliding slug cannot overflow the column |
| `ASG_ALERT_EMAIL` | *(unset)* | If defined, the tripwire (and the Layer-2 self-test) email this address on a column revert or fork drift |
| `ASG_L2_FAILSAFE` | *(unset)* | On Layer-2 drift, hand generation back to core. ⚠️ **Reintroduces silent 200-byte truncation.** Read the warning below |

> ⚠️ **`ASG_L2_FAILSAFE` is not the safe option, despite its name.**
> On drift it hands slug generation back to core, which caps at 200 bytes on a *character* boundary. The result is a slug that is perfectly valid and merely **wrong**: it serves `200`, never `404`, never `400`, and never reaches a log. It is the one failure class nothing can see, on a site that installed this plugin to prevent exactly it.
>
> Running a **drifted fork** instead yields full-length slugs under stale cleanup rules, which is a cosmetic problem, not a data one. So the default on drift is to **keep the fork and shout**. If you do enable the fail-safe, the plugin records that it engaged, emails once, and `wp asg verify` keeps failing until you re-sync the fork and unset the constant.

### Switching layers off

Each layer is independent and **on by default** (Layer 3 is opt-in). Switch one off with a **constant in `wp-config.php`**:

| Switch | Default | Layer |
|---|---|---|
| `ASG_LAYER1` | on | Prevention: hold both columns at `VARCHAR(1024)` and monitor them |
| `ASG_LAYER2` | on | Generation: the long-slug `sanitize_title_with_dashes()` fork |
| `ASG_LAYER3` | off | Collision de-duplication: reserved (handler pending) |

```php
// wp-config.php: keep the wide column, but let core (or another plugin) make slugs
define( 'ASG_LAYER2', false );
```

**Constants, not filters.** There are matching `asg_layer{N}_enabled` filters, but they resolve *while this mu-plugin loads*, so only code that runs earlier (another mu-plugin) can use them. A theme's `functions.php` or a regular plugin is far too late. More decisively: during a database upgrade WordPress loads **neither** plugins nor themes, because `wp_get_active_and_valid_plugins()` and `wp_get_active_and_valid_themes()` both return early on `wp_installing()`. Layer 1 matters precisely during that upgrade, so a filter could never reach it. Use the constant.

**Dependency:** Layers 2 and 3 emit slugs longer than 200 bytes, which only survive because Layer 1 widened the column, so they require Layer 1. Turning Layer 1 off while Layer 2 is requested leaves Layer 2 off (and logs once) rather than truncating. Layer 1 on its own is safe: the column stays wide and core's 200-byte generator stays in place.

## Verifying

```bash
wp asg verify
```

It exits **non-zero** on anything that is silently damaging slugs, so cron can simply react to the exit code:

- a **short column**,
- **Layer-1 drift** (core changed its `CREATE TABLE` string, so the rewrite no longer applies),
- **Layer-2 drift** (the fork no longer matches core's generator),
- the **fail-safe engaged** (slugs are capping at 200 bytes and truncating silently).

The last two matter without `--data`, because a fail-safe whose alarm only sounds when you pass an optional flag is not an alarm. `verify` also **evaluates the Layer-2 drift oracle itself** rather than trusting a cached option, since that oracle is otherwise bound to `admin_init` and a headless site (WP-CLI, REST, no `wp-admin` visits) would never run it. That is precisely how a drifted fork can ride along unnoticed for releases at a time.

```bash
0 3 * * *  cd /var/www/site && wp asg verify >/dev/null 2>&1 \
           || wp asg verify 2>&1 | mail -s "WP slug schema alert on $(hostname)" ops@example.com
```

### Check the data, not just the schema

The schema check watches the **column**. It cannot tell you whether slugs were *already* truncated, and the obvious detector turns out to be the wrong one. There are three cut classes:

| Cut lands | Example tail | Serves | Noticed by |
|---|---|---|---|
| inside an escape | `…%d9%85%d` | **400**, nginx rejects it | already in your access log |
| on an escape boundary | `…%d9%85%d8` | **200** | only code that *decodes* it (REST/JSON) |
| on a character boundary | `…%d8%aa` | **200** | nothing at all |

Routing never decodes the slug, it byte-matches `post_name` against the request segment, so the last two resolve normally and quietly serve the **wrong** URL forever. An encoding-only scan finds the class that already screams and misses both silent ones.

So the real detector compares the stored slug against what its title regenerates to:

```bash
wp asg verify --data
wp asg verify --data --min-length=190   # tune the length gate
```

Full scan, CLI only, never `admin_init`. Every scanned row lands in one bucket:

| Bucket | Meaning | Fails the command |
|---|---|---|
| **corrupt** | bytes do not decode **and** differ from the regeneration: data is gone | yes |
| **truncation candidate** | stored slug is a strict prefix of the regenerated one | yes |
| **generator artifact** | bytes do not decode but are *identical* to the regeneration: nothing lost | no |
| **trashed** | `wp_trash_post()` cut it to 191 bytes, one-way | no |
| **clean** | matches, or matches plus a genuine `-<n>` uniqueness suffix | no |

The **artifact** bucket exists because Arabic writes the percent sign *before* the numeral, so a title opening `%80 …` makes core's "preserve escaped octets" step keep `%80` as an octet. The slug then opens on an orphan continuation byte and fails to decode, yet it is exactly what the generator produces, it serves `200`, and nothing was lost. Only a slug that is *both* undecodable *and* different from its own regeneration has actually lost bytes. (Anything that **decodes** such a slug, REST or `json_encode()`, will still choke on it. It is just not a truncation, and rewriting it repairs nothing.)

Comparison order matters: **compare first, strip last.** Testing a stripped `-<digits>` stem up front flags every title that legitimately ends in a year.

Regeneration runs through the **live `sanitize_title` filter chain**, whatever actually minted these slugs: core, this fork, or your theme's own copy. Forcing a full budget lifts *this plugin's* clamp; it never swaps the generator, because diffing two different generators would report their disagreement as truncation.

`--min-length` is a lower bound, not the ceiling. Truncation lands at whatever the column was, so a cut in a `VARCHAR(512)` `wp_terms.slug` sits at 512, not 200. Read the histogram: a pile of slugs at exactly one length *is* the ceiling that cut them.

> **Candidates, not verdicts.** A hand-shortened slug is indistinguishable from a truncated one, and a row whose title changed after publication never matches at all. The count is a **lower bound**.
>
> ⚠️ **The scan refuses to pass from a blind spot.** The prefix test only works if the generator can out-run the stored slug, so the command first **probes** the live generator with an over-long title and measures what it actually emits. Any slug at or above that ceiling can be neither proved nor disproved: the command prints `SCAN IS BLIND` (or `PARTIALLY BLIND`) and exits non-zero instead of "Data OK". Blindness is anchored to the measured ceiling, never to `--min-length`, and never to whether `ASG_L2_FAILSAFE` engaged, because a theme's own fallback or a future core change reaches the same capped state by a different road.
>
> ⚠️ **Repairing candidates moves live URLs.** They serve `200` today. Change slugs through `wp_update_post()`, which records `_wp_old_slug` so `wp_old_slug_redirect()` 301s the old URL. A raw `$wpdb->update()` bypasses that and silently breaks links that work right now.

### Tests

```bash
php tests/run.php
```

No WordPress, no database, no dependencies. Three suites: the Layer-2 fork diffed against core's real `sanitize_title_with_dashes()`, the slug classifier, and the `Data OK` / blindness gate. Each one **extracts the functions from the plugin file** rather than copying them, because a copied gate re-encodes the blindness it is supposed to catch and then passes itself.

The fixtures are deliberately Arabic, and deliberately model a short column and a capped generator at the same time. Every bug this plugin has shipped lived in the gap between a Latin fixture and a real corpus, and stayed green until those conditions existed together. Point the suite at an older build to see it: `ASG_PLUGIN=/path/to/old.php php tests/run.php`.

## Known limits

Three truncations live in core and are outside this plugin's current reach:

- **Post collisions.** `wp_unique_post_slug()` rebuilds a colliding slug with `_truncate_post_slug( $slug, 200 - … )`. That cap is UTF-8 boundary-safe, so the slug is merely **shortened** to about 200 bytes, never corrupted, and it happens regardless of column width.
- **Term collisions.** `wp_unique_term_slug()` appends `-2` (or an unbounded parent slug) with **no truncation at all**. A slug near the ceiling then overflows the column and MySQL cuts it mid-escape, which is the broken-URL bug. `ASG_SUFFIX_HEADROOM` covers numeric suffixes; the parent-slug case needs a terms-aware Layer 3. That layer is genuinely hard: posts expose a real short-circuit (`pre_wp_unique_post_slug`), terms expose only a post-hoc result filter (`wp_unique_term_slug`), so a handler cannot intercept. Nor can it simply truncate, because boundary-truncating two distinct composed slugs can collide them back together. It must truncate on a UTF-8 boundary **and** re-run uniqueness within budget.
- **Trashing.** `wp_trash_post()` permanently rewrites the slug as `_truncate_post_slug( $post_name, 191 ) . '__trashed'`, and untrashing does not restore it. Trashing a post is a one-way haircut for a long slug.

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
