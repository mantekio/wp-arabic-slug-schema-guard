# Changelog

All notable changes to **Arabic Slug Schema Guard** are documented here. The
format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0]

### Added
- **Layer-1 drift guard.** The canonical-schema rewrite now tolerates backticks,
  spacing, case and any current width, then asserts the *outcome* rather than the
  attempt. Previously, if core ever changed its `CREATE TABLE` string the rewrite
  failed **silently**, dbDelta shrank the column, and every long slug was
  truncated. It now logs, records `asg_l1_status`, alerts, and surfaces in
  `wp asg verify`. Until now only Layer 2, the recoverable layer, self-tested.
- **Safety clamp on slug generation.** Layer 2 could mint a slug longer than the
  live column, manufacturing the very truncation this plugin prevents. Core ships
  `wp_terms.slug` at `VARCHAR(200)`, so any site that widened only `post_name`
  hit this by default. The budget is now
  `min( ASG_SLUG_BYTES, live_width - ASG_SUFFIX_HEADROOM )`, resolved lazily on
  first use and cached in an autoloaded option, falling back to core's 200 only
  when the width cannot be read.
- `ASG_SUFFIX_HEADROOM` (default `24`), bytes reserved for a collision suffix.
- **A test suite** (`php tests/run.php`): the Layer-2 fork diffed against core's real
  function, the slug classifier, and the `Data OK` / blindness gate. No WordPress, no
  database, no dependencies. Each suite extracts the functions from the plugin file
  rather than copying them, so a copied gate cannot re-encode the blindness it exists to
  catch and pass itself. The fixtures are Arabic, and model a short column and a capped
  generator together, because every bug this plugin shipped stayed green until those
  conditions co-existed. `ASG_PLUGIN=<file>` points the suite at another build.
  The gate's isolation is established by a **purity walk** over its transitive call
  graph, tokenized rather than regexed, since a back door needs no parameter (so arity
  proves nothing), can hide one call deep (so scanning the gate's own body proves
  nothing), and `/\bglobal\b/` matches the word "global" in a comment. The walk
  **requires the good rather than enumerating the bad**: all nine superglobals are
  rejected (a closed set), any construct that cannot be resolved statically is a
  violation by virtue of being unverifiable (`$f()`, `$m['g']()`, `new`, `::`, `->`,
  `static`), and the only permitted calls are plugin functions, which it follows, plus a
  short explicit allow-list of pure builtins. Bare constant reads are covered by the same
  argument: once calls and object syntax are flagged, a bare identifier is `true`/`false`
  /`null`, a call name, or a constant, so an allow-list over that closed set of roles is
  complete. `PHP_INT_MAX` passes; a bare `if ( ASG_KILL )` does not. Earlier drafts denied `$GLOBALS` but not
  `$_ENV`, denied `getenv` by name while `$f = 'getenv'; $f()` walked past, and admitted
  every PHP builtin through `isInternal()`, which lets `getenv`, `constant`, `ini_get`
  and `file_get_contents` through. The walk runs before the first call, so a back door
  that happens to fatal cannot pre-empt the assertion meant to catch it, and the suite's
  **extraction list is derived from the walk**, so "add the helper to `asg_test_load()`"
  is not an available repair for a suite that a delegating door has broken.
- `asg_row_is_unprovable( $slug_length, $ceiling )`, lifted out of the `$wpdb`-bound scan
  loop so a test can pin the invariant it carries: `unprovable` keys on the **slug**
  length, not the suffix-stripped stem, which is what makes the two blindness clauses
  interlock. Re-keying it on the stem, the obvious-looking improvement, would quietly
  make the ceiling clause load-bearing again with no test going red.
- **`wp asg verify --data`.** The tripwire watches the column, never the slugs, so it
  inferred a data conclusion from a schema observation. Measured against a real stack,
  only a cut *inside* an escape fails loudly (nginx returns 400 before WordPress runs);
  a cut *on* an escape boundary and a cut on a character boundary both serve **200**,
  because routing byte-matches `post_name` and never decodes it. An encoding-only scan
  therefore finds the class that already screams and misses both silent ones. The scan
  now reports **corrupt** slugs (broken escape or invalid UTF-8) and **truncation
  candidates** (the stored slug is a strict prefix of the slug its title regenerates to,
  gated on length). CLI only, exits non-zero on any finding.
  Candidates are not verdicts: a hand-shortened slug is indistinguishable from a
  truncated one, and rows whose titles changed never match, so the count is a lower
  bound. Repairing candidates **moves live URLs**, so it must go through
  `wp_update_post()` for `_wp_old_slug` 301 redirects, never a raw `$wpdb->update()`.

### Changed
- **All constants are overridable from `wp-config.php`.** `ASG_COLUMN_LEN`,
  `ASG_SLUG_BYTES` and `ASG_SCHEMA_SIG` were `const`, so a `define()` collided
  instead of taking effect. They now use `defined() || define()`.
- **The tripwire no longer spams.** It never cached "ok" while a column was short,
  so it re-ran the `information_schema` query and re-sent the alert email on
  *every* admin page load. Checks are throttled to hourly, emails to daily.
- **The tripwire classifies instead of crying breach.** A column that was never
  widened is a setup task, and raising `ASG_COLUMN_LEN` is a configuration change.
  Only a column narrower than a previously *verified* width is reported as a revert.
- **`wp asg verify` exits non-zero** on failure. It only ever logged, so it always
  exited 0 and no cron monitor could fire.
- The cached column width is now invalidated on **Layer-1 drift**, not just on a core
  update. A stale-high width would otherwise let Layer 2 mint oversized slugs for an
  entire throttle window, precisely when dbDelta is about to shrink the column.
- The live width is resolved only for `'save'` context. Display-context `sanitize_title()`
  calls (CSS classes, ids) are never stored, so they no longer trigger an
  `information_schema` query on a cold cache.

### Fixed
- **The Layer-2 fork had itself drifted from core, since 1.0.0.** It was described as
  "core with a bigger byte budget," but it dropped core's entire *"strip these
  characters entirely"* block and the `×`→`x` mapping, keeping only the nbsp / en-dash /
  em-dash / slash conversions. So slugs kept `%c2%ab`/`%c2%bb` (**guillemets, the
  standard Arabic quotation marks**), `%e2%80%8e`/`%e2%80%8f` (**LRM/RLM**), curly
  quotes, `©`, `®`, `°`, `…`, `™`, combining accents, zero-width characters and the BOM,
  none of which core keeps. The character filter cannot clean them up afterwards, because
  `%c2%ab` is made entirely of permitted characters. The block is restored verbatim, and
  the drift fixtures now cover every branch of it. The Layer-2 oracle was sound and
  flagged this the first time it ran, which means its `admin_init` check had never
  executed against core on a real install.
- **`ASG_L2_FAILSAFE` was the least safe option available.** On drift it handed
  generation back to core, whose 200-byte cut lands on a *character* boundary and yields
  a valid, wrong slug that serves HTTP 200 and never reaches a log: the one truncation
  class nothing detects. A drifted fork merely applies stale cleanup rules to
  full-length slugs. The default on drift is now to keep the fork and shout. The
  fail-safe stays opt-in, records `asg_l2_failsafe_active`, emails once (deferred to
  `init`, since `wp_mail()` does not exist at mu-plugin load and the alert was being
  swallowed), and fails `wp asg verify` until it is unset.
- **`--data` flagged any title ending in a number as truncated.** The stem was stripped of
  a trailing `-<digits>` *before* the comparison, so `…-1985` (a year in the title) or
  `…-2` became a strict prefix of its own faithful regeneration. On a real Arabic corpus
  this read 8268 candidates against ~280 real ones, an 82% false-positive rate on the
  sampled rows, and it distorted the length histogram, which recorded post-strip lengths.
  The classifier now compares first and strips last: exact match, then match-plus-genuine-
  suffix, then unstripped strict prefix, and only then the truncated-and-suffixed case, at
  lower confidence. Histogram lengths are unstripped.
- **`--data` called Arabic percentage titles corrupt.** Arabic writes the percent sign
  before the numeral, so a title opening `%80 …` makes core's "preserve escaped octets"
  step keep `%80` as an octet. The slug opens on an orphan continuation byte and fails to
  decode, yet it is the generator's faithful, reproducible output and has lost nothing.
  Undecodable bytes are now only **corrupt** when they also differ from the regeneration;
  when they match it, they are reported as a **generator artifact** and do not fail the
  command. The malformed-bytes test is also unanchored now, so it catches a broken escape
  hiding before a suffix (`…%d9%8-2`), which an end-anchored regex misses.
- **The blindness gate was dead.** The prefix test needs a generator that can out-run the
  stored slug, but blindness was anchored to `--min-length`: with core's 200-byte cap and
  the 190-byte gate, `200 <= 190` is false, so the guard never fired while every 200-byte
  row was unprovable. It is now anchored to a **probe** of the live generator (an over-long
  title, measured output), and refuses "Data OK" while any scanned slug sits at or above
  that ceiling. Anchoring it to the corpus maximum would have been wrong too, since on a
  healthy site the longest slug equals its own regeneration. The gate no longer keys on
  `asg_l2_failsafe_active` either: that flag is only one of several routes to a capped
  generator (a theme's own fallback, a future core change). Regeneration deliberately runs
  through the live `sanitize_title` filter chain, so a Layer-2-off site is compared against
  the generator that actually minted its slugs, not against this fork.
- **`--data` printed "Data OK" directly beneath its own findings.** The success line was
  gated on the command's accumulated `$failed` compared against a sentinel captured after
  the schema check. Once a short column had already set `$failed`, that comparison read
  `true === true` whatever the scan found, so "Data OK: nothing corrupt, no truncation
  candidates, no blind spots" printed under 81 corrupt rows, 3949 truncation candidates
  and a partial blind spot. The exit code was right throughout; only the text lied, and
  the text is the half a human reads. The gate is now `asg_data_findings( $scan, $min )`,
  derived from the scan's own buckets and wholly independent of the schema verdict.
  Artifacts and trashed rows remain informational.
- **`--data` now also reports** how many rows it scanned, the generator's measured ceiling,
  and how many trashed slugs it skipped.
- **Docs:** the `asg_layer{N}_enabled` filters cannot be used from a theme or a
  plugin. They resolve while the mu-plugin loads, and during a database upgrade
  WordPress loads neither, since `wp_get_active_and_valid_plugins()` and
  `wp_get_active_and_valid_themes()` both return early on `wp_installing()`. The
  `wp-config.php` constants are the only reliable switch.
- **Docs:** both columns must be widened, and the collision caveats are now stated.
  Posts are shortened safely by `_truncate_post_slug()`; terms are overflowed
  unsafely by `wp_unique_term_slug()`; trashing a post cuts its slug to 191 bytes
  one-way. A real Layer 3 must therefore cover **terms**, not just posts.

## [1.2.0]

### Added
- Per-layer switches: `ASG_LAYER1`, `ASG_LAYER2`, `ASG_LAYER3` constants and
  matching `asg_layer{N}_enabled` filters, so any layer can be turned off
  independently (for example, keep Layer 1's wide column but let another plugin or
  theme own slug generation). Layers 2 and 3 depend on Layer 1 and fail safe (stay
  off, and log once) if requested without it. Layer 3 stays reserved: its switch
  resolves, but the de-duplication handler is not shipped yet.

### Changed
- Renamed the repository, plugin file, and Composer package to
  **`wp-arabic-slug-schema-guard`** to make its WordPress scope explicit. Install
  with `composer require mantekio/wp-arabic-slug-schema-guard`; the old
  `mantekio/arabic-slug-schema-guard` package is superseded. Internal `ASG_` /
  `asg_` names are unchanged, so existing `wp-config.php` constants keep working.

## [1.1.2]

### Changed
- README: documented the Composer install requirements. Allowing the
  `composer/installers` plugin, and the note that a `wordpress-muplugin` package
  installs into a `mu-plugins/` subfolder, which vanilla WordPress does not
  auto-load without a mu-plugins autoloader. No functional changes.

## [1.1.1]

### Changed
- Documentation and inline-comment cleanup in the README and the plugin source. No functional changes.

## [1.1.0]

### Added
- Layer-2 self-test: the slug-generation fork now diffs itself against core's
  live `sanitize_title_with_dashes()` on short fixtures (once per core version),
  so a future core rewrite of that function is caught immediately instead of
  drifting silently.
- `ASG_L2_FAILSAFE` constant: on detected drift, fall back to core's generator
  (slugs cap at 200) until the fork is re-synced, rather than run a copy that is
  known to have diverged.

### Changed
- The Layer-2 install is now conditional on the drift status, and the
  `asg_l2_status` option is autoloaded so the fail-safe is read on every request.

## [1.0.0]

### Added
- Initial release. A WordPress must-use plugin that stops core database upgrades
  from truncating long Arabic (and other non-Latin) slugs:
  - **Layer 1 (prevention):** a `dbdelta_create_queries` filter that keeps
    `wp_posts.post_name` and `wp_terms.slug` at `VARCHAR(1024)`, so dbDelta never
    emits a destructive `CHANGE COLUMN`.
  - **Layer 2 (generation):** a copy of `sanitize_title_with_dashes()` that
    raises the byte cap, so new slugs are not cut at 200 bytes.
  - **Tripwire:** post-update verification of the real column widths (logs and
    optionally emails on a revert) plus a `wp asg verify` WP-CLI command.

[1.3.0]: https://github.com/mantekio/wp-arabic-slug-schema-guard/releases/tag/v1.3.0
[1.2.0]: https://github.com/mantekio/wp-arabic-slug-schema-guard/releases/tag/v1.2.0
[1.1.2]: https://github.com/mantekio/wp-arabic-slug-schema-guard/releases/tag/v1.1.2
[1.1.1]: https://github.com/mantekio/wp-arabic-slug-schema-guard/releases/tag/v1.1.1
[1.1.0]: https://github.com/mantekio/wp-arabic-slug-schema-guard/releases/tag/v1.1.0
[1.0.0]: https://github.com/mantekio/wp-arabic-slug-schema-guard/releases/tag/v1.0.0
