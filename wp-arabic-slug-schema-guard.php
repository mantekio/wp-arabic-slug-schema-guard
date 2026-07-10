<?php
/**
 * Plugin Name: Arabic Slug Schema Guard
 * Plugin URI:  https://github.com/mantekio/wp-arabic-slug-schema-guard
 * Description: Keeps wp_posts.post_name and wp_terms.slug at VARCHAR(1024) across
 *              WordPress core database upgrades, so long URL-encoded Arabic slugs
 *              are never truncated. Prevention-first: dbDelta never truncates,
 *              because the canonical schema it diffs against already says 1024.
 *              Both the schema rewrite and the slug fork self-test for drift.
 * Version:     1.3.0
 * Author:      ManTek Technologies
 * Author URI:  https://www.mantek.io
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Full write-up: https://www.mantek.io/insights/wordpress-arabic-slug-truncation
 *
 * Install as a MUST-USE plugin: drop this file in wp-content/mu-plugins/ so it
 * always loads, can't be deactivated by accident, and is in place before the
 * core DB-upgrade routine runs.
 *
 * SWITCHING LAYERS OFF: use the ASG_LAYER{1,2,3} constants in wp-config.php.
 * The asg_layer{N}_enabled filters resolve while this mu-plugin loads, so only
 * code that runs even earlier (another mu-plugin) can use them. Themes and
 * regular plugins are far too late, and during a database upgrade WordPress
 * loads neither of them at all: wp_get_active_and_valid_plugins() and
 * wp_get_active_and_valid_themes() both bail out on wp_installing(). Layer 1
 * matters precisely during that upgrade, so a constant is the only real switch.
 */

defined( 'ABSPATH' ) || exit;

/* CONFIGURATION
 * Every value is overridable: define it in wp-config.php (which loads before
 * mu-plugins) and the fallback below is skipped. */
defined( 'ASG_COLUMN_LEN' )      || define( 'ASG_COLUMN_LEN', 1024 );  // target column width (bytes)
defined( 'ASG_SLUG_BYTES' )      || define( 'ASG_SLUG_BYTES', 1000 );  // ceiling for a generated slug
defined( 'ASG_SUFFIX_HEADROOM' ) || define( 'ASG_SUFFIX_HEADROOM', 24 ); // bytes kept free for a "-2" collision suffix
defined( 'ASG_SCHEMA_SIG' )      || define( 'ASG_SCHEMA_SIG', 'post_name:' . ASG_COLUMN_LEN . ';slug:' . ASG_COLUMN_LEN );
// define( 'ASG_ALERT_EMAIL', 'ops@example.com' );  // optional, set in wp-config.php
// define( 'ASG_L2_FAILSAFE', true );               // optional: on Layer-2 drift, fall back to core until you re-sync
// define( 'ASG_LAYER2', false );                   // optional: skip the long-slug generator, keep Layer 1's wide column

/* PER-LAYER SWITCHES
 * Every layer is ON by default (Layer 3 is opt-in), so existing installs are
 * unchanged. Turn one off with a constant in wp-config.php:
 *
 *   define( 'ASG_LAYER2', false );
 *
 * Dependency: Layers 2 and 3 generate slugs longer than 200 bytes, which only
 * survive because Layer 1 widened the column, so they REQUIRE Layer 1. Asking for
 * either with Layer 1 off leaves it off (and logs once) instead of generating a
 * slug that would truncate. Layer 1 on its own is safe: the wide column stays and
 * core's 200-byte generator is left untouched. */
function asg_layer_enabled( $n, $default = true ) {
    $const = 'ASG_LAYER' . $n;
    $on    = defined( $const ) ? (bool) constant( $const ) : $default;
    return (bool) apply_filters( "asg_layer{$n}_enabled", $on );
}

$asg_l1 = asg_layer_enabled( 1, true );
$asg_l2 = $asg_l1 && asg_layer_enabled( 2, true );
// Layer 3 (collision de-duplication) is reserved. See its note near the bottom.

// Long-slug generation asked for without its Layer-1 foundation? Hold it, once.
if ( ! $asg_l1 && asg_layer_enabled( 2, true ) ) {
    add_action( 'admin_init', 'asg_layer_dependency_notice' );
} elseif ( get_option( 'asg_dep_warned' ) ) {
    delete_option( 'asg_dep_warned' );  // config resolved, re-arm the notice
}

function asg_layer_dependency_notice() {
    if ( get_option( 'asg_dep_warned' ) ) {
        return;
    }
    error_log( '[Arabic Slug Schema Guard] Layer 2 needs Layer 1 (the VARCHAR(' . ASG_COLUMN_LEN . ') column), '
             . 'but Layer 1 is disabled, so long-slug generation is held off to avoid truncation.' );
    update_option( 'asg_dep_warned', 1, false );
}

/* The two columns this plugin guards. */
function asg_guarded_columns() {
    global $wpdb;
    return array( $wpdb->posts => 'post_name', $wpdb->terms => 'slug' );
}

/* LAYER 1: PREVENTION
 * Rewrite the canonical CREATE TABLE dbDelta diffs against, so "desired" already
 * equals a 1024-wide column and no destructive CHANGE COLUMN is emitted. Because
 * "desired" wins, dbDelta will also WIDEN a too-narrow column on the next upgrade,
 * losslessly. That is a safety net, not a substitute for the one-time ALTER: until
 * you run it, Layer 2 is clamped (see asg_slug_budget()).
 *
 * LAYER 1 DRIFT GUARD: the old code assumed core's exact string and failed SILENTLY
 * if it ever changed, which is this plugin's one catastrophic failure mode. Now the
 * rewrite tolerates backticks, spacing and any current width, and then asserts the
 * OUTCOME (a match is not proof we wrote the width we meant to). A miss shouts. */
if ( $asg_l1 ) {
    add_filter( 'dbdelta_create_queries', 'asg_widen_canonical_schema' );
}

function asg_widen_canonical_schema( $queries ) {
    $missed  = array();
    $checked = 0;

    foreach ( asg_guarded_columns() as $table => $column ) {
        if ( ! isset( $queries[ $table ] ) ) {
            continue;  // dbDelta was called for a subset; nothing to assert here
        }
        $checked++;
        $col   = preg_quote( $column, '/' );
        $count = 0;

        $queries[ $table ] = preg_replace(
            '/(`?\b' . $col . '`?\s+varchar\(\s*)\d+(\s*\))/i',
            '${1}' . ASG_COLUMN_LEN . '${2}',
            $queries[ $table ],
            -1,
            $count
        );

        $written = preg_match(
            '/`?\b' . $col . '`?\s+varchar\(\s*' . ASG_COLUMN_LEN . '\s*\)/i',
            (string) $queries[ $table ]
        );

        if ( ! $count || ! $written ) {
            $missed[] = "{$table}.{$column}";
        }
    }

    if ( $missed ) {
        asg_layer1_drift( $missed );
    } elseif ( $checked && get_option( 'asg_l1_status' ) ) {
        delete_option( 'asg_l1_status' );  // the rewrite applies again; stop shouting
    }
    return $queries;
}

function asg_layer1_drift( array $missed ) {
    $msg = '[Arabic Slug Schema Guard] LAYER-1 DRIFT on WP ' . $GLOBALS['wp_version'] . ': could not rewrite '
         . implode( ', ', $missed ) . ' to VARCHAR(' . ASG_COLUMN_LEN . ') in the canonical schema. dbDelta will '
         . 'shrink the column(s) and truncate long slugs. Core changed its CREATE TABLE string; re-sync the pattern.';
    error_log( $msg );
    update_option( 'asg_l1_status', 'drift:' . $GLOBALS['wp_version'], true );  // autoload: read by verify + CLI
    // A drifted rewrite means dbDelta is about to shrink the column. A stale-high
    // cached width would let Layer 2 keep minting oversized slugs for a whole
    // throttle window, exactly when that is most dangerous. Drop it, and re-verify.
    delete_option( 'asg_observed_width' );
    delete_option( 'asg_schema_sig' );
    asg_alert( 'WP slug Layer-1 drift', $msg );
}

/* LAYER 2: GENERATION
 * Core caps new slugs at 200 bytes via utf8_uri_encode($title, 200). Swap in a
 * copy of sanitize_title_with_dashes() whose only change is the byte budget.
 *
 * ASG_L2_FAILSAFE IS NOT SAFE, despite the name it shipped with.
 * On drift it reverts to core's generator, which caps at 200 bytes on a CHARACTER
 * boundary. That yields a valid slug that is merely WRONG: it serves HTTP 200, never
 * 404, never 400, and leaves no trace in an access log. It is the exact failure this
 * plugin exists to prevent, and the only class nothing can see.
 *
 * Running a drifted fork, by contrast, produces FULL-LENGTH slugs under stale cleanup
 * rules. That is a cosmetic problem. Silent truncation is a data problem. So the
 * DEFAULT on drift is to keep the fork and shout. The fail-safe stays opt-in, records
 * that it engaged, alerts once, and `wp asg verify` reports it as a failure. */
$asg_l2_drifted  = ( get_option( 'asg_l2_status' ) === 'drift:' . $GLOBALS['wp_version'] );
$asg_l2_failsafe = $asg_l2 && $asg_l2_drifted && defined( 'ASG_L2_FAILSAFE' ) && ASG_L2_FAILSAFE;

if ( $asg_l2_failsafe && ! get_option( 'asg_l2_failsafe_active' ) ) {
    update_option( 'asg_l2_failsafe_active', 1, true );
    error_log( asg_failsafe_notice() );
    // wp_mail() does not exist yet at mu-plugin load, so asg_alert() would return
    // silently. Defer the email until pluggable.php is in.
    add_action( 'init', 'asg_failsafe_alert' );
} elseif ( ! $asg_l2_failsafe && get_option( 'asg_l2_failsafe_active' ) ) {
    delete_option( 'asg_l2_failsafe_active' );
}

function asg_failsafe_notice() {
    return "[Arabic Slug Schema Guard] Layer 2 fell back to core's generator on WP {$GLOBALS['wp_version']}. "
        . 'New slugs now cap at 200 bytes and are truncated SILENTLY: they serve HTTP 200, never 404, and '
        . 'never reach an error log. Re-sync the fork, then unset ASG_L2_FAILSAFE. '
        . 'Audit what has already been cut with: wp asg verify --data';
}

function asg_failsafe_alert() {
    asg_alert( 'WP slug Layer-2 failed safe to core', asg_failsafe_notice() );
}

if ( ! $asg_l2 ) {
    // Layer 2 off (disabled, or Layer 1 is off) → leave core's generator in place.
} elseif ( $asg_l2_failsafe ) {
    // Opt-in fallback to core. Slugs cap at 200 bytes from here on. See the alert above.
} else {
    remove_filter( 'sanitize_title', 'sanitize_title_with_dashes' );
    add_filter( 'sanitize_title', 'asg_sanitize_title_with_dashes', 10, 3 );
}

/* SAFETY CLAMP
 * Layer 2 must never mint a slug the live column cannot hold, or it manufactures
 * the very truncation this plugin prevents. (Core ships wp_terms.slug at
 * VARCHAR(200), so a site that widened only post_name is the DEFAULT broken case.)
 * Posts are shortened safely on collision by _truncate_post_slug(); terms are not,
 * wp_unique_term_slug() appends its suffix with no truncation, so MySQL cuts it
 * mid-escape and the URL breaks. Hence the headroom.
 *
 * The width is resolved lazily on the first slug generation, then cached in an
 * autoloaded option. Unknown means pessimistic, never optimistic: if the lookup
 * genuinely fails we fall back to core's own 200. We do NOT wait for admin_init,
 * because WP-CLI and REST never fire it, and a bulk import is exactly when long
 * slugs matter most. */
/** Pin the budget for the duration of a scan, so regeneration is not clamped. CLI only. */
function asg_force_slug_budget( $bytes = null ) {
    static $forced = null;
    if ( func_num_args() ) {
        $forced = $bytes;
    }
    return $forced;
}

function asg_slug_budget() {
    $forced = asg_force_slug_budget();
    if ( null !== $forced ) {
        return (int) $forced;
    }
    static $budget = null;
    if ( null !== $budget ) {
        return $budget;
    }
    $width  = asg_observed_width();
    $budget = $width > 0
        ? max( 32, min( ASG_SLUG_BYTES, $width - ASG_SUFFIX_HEADROOM ) )
        : 200;  // width unknown: behave exactly like core
    return $budget;
}

function asg_observed_width() {
    $width = (int) get_option( 'asg_observed_width', 0 );
    if ( $width > 0 ) {
        return $width;
    }
    $width = asg_min_column_width();
    if ( $width > 0 ) {
        update_option( 'asg_observed_width', $width, true );  // autoload: no query next request
    }
    return $width;
}

function asg_sanitize_title_with_dashes( $title, $raw_title = '', $context = 'display' ) {
    $title = strip_tags( $title );
    // Preserve already-encoded octets through the cleanup below.
    $title = preg_replace( '|%([a-fA-F0-9][a-fA-F0-9])|', '---$1---', $title );
    $title = str_replace( '%', '', $title );
    $title = preg_replace( '|---([a-fA-F0-9][a-fA-F0-9])---|', '%$1', $title );

    if ( seems_utf8( $title ) ) {
        if ( function_exists( 'mb_strtolower' ) ) {
            $title = mb_strtolower( $title, 'UTF-8' );
        }
        // Only a 'save' produces a slug that gets stored, so only that path needs the
        // live column width, and only it may pay for one information_schema query on
        // a cold cache. Display-context calls (CSS classes, ids) never hit the DB.
        $budget = ( 'save' === $context ) ? asg_slug_budget() : ASG_SLUG_BYTES;
        $title  = utf8_uri_encode( $title, $budget );  // core hard-codes 200 here
    }

    $title = strtolower( $title );

    if ( 'save' === $context ) {
        // EVERYTHING in this block is a verbatim copy of core's sanitize_title_with_dashes().
        // The ONLY sanctioned difference between this fork and core is the byte budget
        // above. Dropping any line here is drift, and the Layer-2 oracle will say so.
        // (v1.0.0 through v1.2.0 shipped without the strip block below, which meant Arabic
        // guillemets, RTL marks, curly quotes and © survived as %-escapes. The character
        // filter cannot remove them: "%c2%ab" is made only of permitted characters.)

        // Turn non-breaking spaces, en/em dashes and slashes into hyphens.
        $title = str_replace( array( '%c2%a0', '%e2%80%93', '%e2%80%94' ), '-', $title );
        $title = str_replace( array( '&nbsp;', '&#160;', '&ndash;', '&#8211;', '&mdash;', '&#8212;' ), '-', $title );
        $title = str_replace( '/', '-', $title );

        // Strip these characters entirely.
        $title = str_replace(
            array(
                '%c2%ad',                                            // soft hyphen
                '%c2%a1', '%c2%bf',                                  // iexcl, iquest
                '%c2%ab', '%c2%bb', '%e2%80%b9', '%e2%80%ba',        // angle quotes
                '%e2%80%98', '%e2%80%99', '%e2%80%9c', '%e2%80%9d',  // curly quotes
                '%e2%80%9a', '%e2%80%9b', '%e2%80%9e', '%e2%80%9f',
                '%e2%80%a2',                                         // bullet
                '%c2%a9', '%c2%ae', '%c2%b0', '%e2%80%a6', '%e2%84%a2', // copy, reg, deg, hellip, trade
                '%c2%b4', '%cb%8a', '%cc%81', '%cd%81',              // acute accents
                '%cc%80', '%cc%84', '%cc%8c',                        // grave, macron, caron
                '%e2%80%8b', '%e2%80%8c', '%e2%80%8d',               // zero-width space/non-joiner/joiner
                '%e2%80%8e', '%e2%80%8f',                            // LRM, RLM
                '%e2%80%aa', '%e2%80%ab', '%e2%80%ac',               // LRE, RLE, PDF
                '%e2%80%ad', '%e2%80%ae',                            // LRO, RLO
                '%ef%bb%bf',                                         // byte order mark
                '%ef%bf%bc',                                         // object replacement character
            ),
            '',
            $title
        );

        // Convert &times to 'x'.
        $title = str_replace( '%c3%97', 'x', $title );
    }

    $title = preg_replace( '/&.+?;/', '', $title );          // strip remaining entities
    $title = str_replace( '.', '-', $title );
    $title = preg_replace( '/[^%a-z0-9 _-]/', '', $title );  // keep only slug-safe chars
    $title = preg_replace( '/\s+/', '-', $title );
    $title = preg_replace( '|-+|', '-', $title );
    return trim( $title, '-' );
}

/* LAYER 2 DRIFT GUARD: self-test the fork against core's live implementation.
 * remove_filter() only unhooks sanitize_title_with_dashes(); the function stays
 * callable, so we use core's CURRENT code as a live oracle and assert our fork
 * still agrees on SHORT inputs (where neither byte cap engages, the only thing
 * the fork changed). Any divergence == core altered the cleanup logic and our
 * copy has drifted. Runs once per core version; shouts, and can fail safe.
 * Only registered while Layer 2 is active: nothing to self-test otherwise. */
if ( $asg_l2 ) {
    add_action( 'admin_init', 'asg_guard_layer2_drift' );
}

function asg_guard_layer2_drift() {
    $ok      = 'ok:' . $GLOBALS['wp_version'];
    $drifted = 'drift:' . $GLOBALS['wp_version'];
    if ( in_array( get_option( 'asg_l2_status' ), array( $ok, $drifted ), true ) ) {
        return;  // already settled for this core version
    }
    if ( ! function_exists( 'sanitize_title_with_dashes' ) ) {
        return;  // core refactored it away, no oracle to diff against; the fork
                 // still runs as a standalone copy (it needs no removed function).
    }

    // Deliberately SHORT fixtures so neither length cap fires, any difference is
    // pure cleanup-logic drift, not the byte budget. Every branch of core's 'save'
    // block gets a fixture, because v1.0.0-v1.2.0 shipped a fork missing the whole
    // "strip these characters entirely" section and no fixture would have caught it.
    $fixtures = array(
        'الذكاء الاصطناعي',
        'عاجل: تطورات «مهمة» اليوم',          // angle quotes (Arabic quotation marks)
        'حقوق © 2017 الأخبار',                 // copyright, and ® ° … ™ alongside it
        'قياس 3 × 4 متر',                      // times sign maps to "x"
        "علامة\xE2\x80\x8F اتجاه",             // right-to-left mark, zero-width
        'مقال “مقتبس” و‘مفرد’',                // curly quotes
        'Mixed عربي + English — dash',
        'foo/bar &mdash; baz',
        'UPPER   multiple   spaces',
        '%d8%a7 pre-encoded octet',
    );

    $drift = array();
    foreach ( $fixtures as $f ) {
        $core = sanitize_title_with_dashes( $f, $f, 'save' );
        $ours = asg_sanitize_title_with_dashes( $f, $f, 'save' );
        if ( $core !== $ours ) {
            $drift[] = "in=[{$f}] core=[{$core}] ours=[{$ours}]";
        }
    }

    if ( $drift ) {
        $msg = "[Arabic Slug Schema Guard] Layer-2 DRIFT on WP {$GLOBALS['wp_version']}: core "
             . "sanitize_title_with_dashes() no longer matches our fork: re-sync the copy.\n"
             . implode( "\n", $drift );
        error_log( $msg );
        asg_alert( 'WP slug Layer-2 drift', $msg );
        update_option( 'asg_l2_status', $drifted, true );  // autoload: the fail-safe reads it every request
        return;  // do NOT cache "ok" while drifted
    }
    update_option( 'asg_l2_status', $ok, true );
}

/* LAYER 3: COLLISION DE-DUPLICATION (reserved, opt-in)
 * Two distinct problems live here, and they are NOT equally severe:
 *
 *   Posts: wp_unique_post_slug() rebuilds a colliding slug as
 *          _truncate_post_slug( $slug, 200 - strlen($suffix) - 1 ) . "-$suffix".
 *          That cap is boundary-safe, so the slug is merely SHORTENED to ~200,
 *          never corrupted. Cosmetic, and independent of column width.
 *
 *   Terms: wp_unique_term_slug() appends "-2" (or a parent slug, which is
 *          UNBOUNDED) with no truncation at all. A slug near the ceiling then
 *          overflows the column, MySQL cuts it mid-escape, and the URL breaks.
 *          This is the corrupting path, and headroom alone cannot close the
 *          parent-slug case.
 *
 * The seams are not symmetric, which makes the terms handler much harder:
 *
 *   Posts expose a genuine short-circuit. pre_wp_unique_post_slug returns an
 *          override BEFORE core composes anything, so a handler can own uniqueness
 *          outright.
 *
 *   Terms expose only a post-hoc result filter, wp_unique_term_slug( $slug, $term,
 *          $original_slug ). A handler cannot intercept; it can only rewrite the
 *          slug core has already composed. And it must NOT merely truncate, because
 *          boundary-truncating two distinct composed slugs can collide them back
 *          together. It has to truncate on a UTF-8 boundary AND then run its own
 *          uniqueness loop, within budget, against $wpdb->terms.
 *          (wp_unique_term_slug_is_bad_slug only toggles whether a suffix is sought;
 *          it does nothing about length.)
 *
 * So a real Layer 3 covers TERMS first, and it is a materially different, harder
 * shape than the post-path handler this file once reserved. The ASG_LAYER3 constant
 * and asg_layer3_enabled filter already resolve through asg_layer_enabled(), and the
 * layer inherits Layer 1's dependency. Only the handler is pending.
 *
 * Also note, outside this plugin's reach: wp_trash_post() permanently rewrites the
 * slug as _truncate_post_slug( $post_name, 191 ) . '__trashed', and untrashing does
 * not restore it. Trashing a post is a one-way haircut for a long slug.
 */

/* TRIPWIRE: verify + alert
 * Detects and alerts only: it restores the COLUMN definition, never bytes already
 * truncated. Bound to Layer 1: with the wide column off, there is nothing to watch.
 *
 * Two fixes over the naive version: the check is throttled (it used to re-run the
 * information_schema query and re-send the email on EVERY admin page load while a
 * column was short), and it classifies. A column that was never widened is a setup
 * task, not a breach, and raising ASG_COLUMN_LEN is a config change, not a revert. */
if ( $asg_l1 ) {
    add_action( 'upgrader_process_complete', function ( $upgrader, $extra ) {
        if ( isset( $extra['type'] ) && 'core' === $extra['type'] ) {
            delete_option( 'asg_schema_sig' );      // force a re-check on the next admin_init
            delete_option( 'asg_observed_width' );  // the column may have moved under us
        }
    }, 10, 2 );

    add_action( 'admin_init', 'asg_verify_schema' );
}

function asg_verify_schema() {
    if ( get_option( 'asg_schema_sig' ) === ASG_SCHEMA_SIG && ! get_option( 'asg_l1_status' ) ) {
        return;  // fast path: verified since the last update, and Layer 1 is not drifted
    }

    // Throttle: at most one information_schema check an hour while something is wrong.
    $last = (int) get_option( 'asg_check_last', 0 );
    if ( time() - $last < HOUR_IN_SECONDS ) {
        return;
    }
    update_option( 'asg_check_last', time(), false );

    $widths = array_filter( asg_column_widths() );  // drop columns we could not read
    if ( ! $widths ) {
        return;  // cannot tell; say nothing rather than cry wolf
    }
    $min = min( $widths );
    update_option( 'asg_observed_width', $min, true );  // keeps the Layer-2 clamp honest

    $short = asg_short_columns( $widths );
    if ( ! $short ) {
        update_option( 'asg_schema_sig', ASG_SCHEMA_SIG, false );
        update_option( 'asg_verified_width', ASG_COLUMN_LEN, false );
        return;
    }

    // Classify before alarming anyone.
    $verified = (int) get_option( 'asg_verified_width', 0 );
    if ( ! $verified ) {
        $msg = '[Arabic Slug Schema Guard] Column(s) not yet widened: ' . implode( ', ', $short )
             . '. Nothing was truncated. Widen BOTH columns to VARCHAR(' . ASG_COLUMN_LEN . '); until then Layer 2 is '
             . 'clamped to ' . asg_slug_budget() . ' bytes so it cannot mint a slug the column will not hold.';
    } elseif ( $min >= $verified ) {
        $msg = '[Arabic Slug Schema Guard] Target width raised to ' . ASG_COLUMN_LEN . ', column(s) still at the '
             . 'previously verified width: ' . implode( ', ', $short ) . '. Widen them. This is a configuration '
             . 'change, not a revert; nothing was truncated.';
    } else {
        $msg = '[Arabic Slug Schema Guard] Column(s) REVERTED: ' . implode( ', ', $short )
             . '. Long slugs were truncated: restore from backup and check 404 logs.';
    }
    error_log( $msg );
    asg_alert( 'WP slug schema', $msg );
    // Do NOT cache "ok" while a column is short.
}

/* Alerts are rate-limited to one email a day. error_log() is always immediate. */
function asg_alert( $subject, $msg ) {
    if ( ! defined( 'ASG_ALERT_EMAIL' ) || ! function_exists( 'wp_mail' ) ) {
        return;
    }
    $last = (int) get_option( 'asg_alert_last', 0 );
    if ( time() - $last < DAY_IN_SECONDS ) {
        return;
    }
    update_option( 'asg_alert_last', time(), false );
    $host = function_exists( 'home_url' ) ? wp_parse_url( home_url(), PHP_URL_HOST ) : '';
    wp_mail( ASG_ALERT_EMAIL, trim( "{$subject}: {$host}" ), $msg );
}

/** "table.column" => live width in bytes (0 when it cannot be read). */
function asg_column_widths() {
    global $wpdb;
    $widths = array();
    foreach ( asg_guarded_columns() as $table => $column ) {
        $widths[ "{$table}.{$column}" ] = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME, $table, $column
        ) );
    }
    return $widths;
}

function asg_min_column_width() {
    $widths = array_filter( asg_column_widths() );
    return $widths ? min( $widths ) : 0;
}

/** Human-readable list of columns narrower than the target. */
function asg_short_columns( $widths = null ) {
    $widths = null === $widths ? array_filter( asg_column_widths() ) : $widths;
    $short  = array();
    foreach ( $widths as $col => $len ) {
        if ( $len < ASG_COLUMN_LEN ) {
            $short[] = "{$col} (now {$len})";
        }
    }
    return $short;
}

/* DATA CHECK
 * The tripwire watches the COLUMN, never the slugs, so it infers a data conclusion
 * from a schema observation. Verify the outcome, not the proxy.
 *
 * There are three cut classes, and the loudest is the least useful to hunt:
 *
 *   inside an escape       "...%d9%85%d"    nginx rejects the request with 400 before
 *                                           WordPress even runs, so it is already in
 *                                           the access log. Detectable, but you knew.
 *   on an escape boundary  "...%d9%85%d8"   complete escapes wrapping a stranded UTF-8
 *                                           lead byte. Routing never decodes the slug,
 *                                           it byte-matches post_name, so this serves
 *                                           200. SILENT. Only code that decodes it
 *                                           (REST/JSON, 404 handlers) ever notices.
 *   on a char boundary     "...%d8%aa"      perfectly valid, simply the WRONG slug.
 *                                           Serves 200. Utterly silent.
 *
 * Encoding checks alone therefore find the class that already screams and miss both
 * silent ones. The real detector compares the stored slug against what the title
 * regenerates to: a stored slug that is a strict PREFIX of the regenerated one was cut.
 * Gate it on length, because a slug sitting at exactly an old ceiling (200, or 191 from
 * trashing) is the corroboration that turns a coincidence into a finding.
 *
 * It yields CANDIDATES, never verdicts: a hand-shortened slug is indistinguishable from
 * a truncated one, and a row whose title changed after publication will not match at
 * all, so the count is a LOWER BOUND. */

/**
 * Are the bytes of this slug malformed? Deliberately UNANCHORED: an end-anchored
 * test misses a broken escape hiding before a suffix ("...%d9%8-2"). Says nothing
 * about whether data was lost, only that the bytes will not decode. See
 * asg_classify_slug(), which needs the regeneration to tell damage from artifact.
 */
function asg_slug_is_malformed( $slug ) {
    if ( '' === $slug || false === strpos( $slug, '%' ) ) {
        return false;
    }
    if ( preg_match( '/%(?![0-9A-Fa-f]{2})/', $slug ) ) {
        return true;  // an escape that is not %XX, anywhere in the slug
    }
    return ! seems_utf8( rawurldecode( $slug ) );  // complete escapes, stranded lead byte
}

/**
 * Classify one stored slug against the slug its title regenerates to.
 *
 * ORDER MATTERS. Compare first, strip last. Stripping a trailing "-<digits>" up front
 * mangles every title that legitimately ends in a number ("...-1985"), because the
 * stripped stem is then a strict prefix of its own faithful regeneration. That single
 * inversion turned ~280 real findings into 8268 on a real Arabic corpus.
 *
 * Returns: clean | trashed | artifact | corrupt | cut | cut-suffixed
 */
function asg_classify_slug( $stored, $regen ) {
    if ( '' === $stored ) {
        return 'clean';
    }
    // wp_trash_post() cuts to 191 bytes one-way. A known core limit, not a finding.
    if ( '__trashed' === substr( $stored, -9 ) ) {
        return 'trashed';
    }

    $malformed = asg_slug_is_malformed( $stored );

    // Reproducible output of the live generator: nothing was lost, whatever it looks
    // like. Arabic writes the percent sign BEFORE the numeral, so a title opening
    // "%80 ..." makes core's "preserve escaped octets" step keep %80 as an octet. The
    // slug then opens on an orphan continuation byte and fails decode, yet it is
    // exactly what the generator produces, serves 200, and has lost nothing.
    if ( $stored === $regen ) {
        return $malformed ? 'artifact' : 'clean';
    }
    if ( $malformed ) {
        return 'corrupt';
    }

    $suffixed = preg_match( '/^(.*)-\d+$/', $stored, $m ) ? $m[1] : null;

    // A genuine uniqueness suffix on an otherwise complete slug.
    if ( null !== $suffixed && $suffixed === $regen ) {
        return 'clean';
    }
    // Truncated: the stored slug is a strict prefix of the full regeneration.
    if ( 0 === strpos( $regen, $stored ) && strlen( $regen ) > strlen( $stored ) ) {
        return 'cut';
    }
    // Truncated, then given a uniqueness suffix. Weaker: only reached when the whole
    // stored slug is not itself a prefix, so a hand-edit ending in digits lands here.
    if ( null !== $suffixed && '' !== $suffixed
         && 0 === strpos( $regen, $suffixed ) && strlen( $regen ) > strlen( $suffixed ) ) {
        return 'cut-suffixed';
    }
    return 'clean';
}

/**
 * How many bytes can the LIVE generator actually emit? Probe it, never infer it.
 *
 * The longest slug a corpus happens to contain is not the generator's reach: on a
 * healthy site the longest slug equals its own regeneration, and on a capped one the
 * cap is invisible. Feed a title far longer than any cap and measure the output.
 */
function asg_generator_ceiling() {
    return strlen( sanitize_title( trim( str_repeat( 'كلمة ', 600 ) ), '', 'save' ) );
}

/**
 * Is this row beyond what the live generator can out-run?
 *
 * Keyed on the SLUG length, deliberately, NOT on the suffix-stripped stem. That single
 * choice is what makes the two blindness clauses interlock: rows enter the scan only
 * when strlen(slug) >= $min_length, so ceiling <= $min_length implies every scanned row
 * is unprovable, which leaves the ceiling clause covering only the empty scan.
 *
 * Re-key this on the stem, which looks like the obvious improvement because the stem is
 * what the prefix test compares, and that implication dies: a stem can dip below the
 * gate after suffix-stripping. The ceiling clause silently becomes load-bearing again
 * and asg_data_findings()'s comment becomes false. Hence a pure function, pinned by a
 * test, rather than an expression buried in the $wpdb-bound scan loop.
 *
 * Slug-keying makes the count an UPPER BOUND: a 200-byte slug with a 197-byte stem is
 * counted at ceiling 199, though that stem could in fact be out-run. It errs toward
 * refusing "Data OK", which is the safe direction.
 */
function asg_row_is_unprovable( $slug_length, $ceiling ) {
    return $slug_length >= $ceiling;
}

/**
 * Does the SCAN have findings of its own?
 *
 * Deliberately independent of the schema verdict. Gating the success line on the
 * command's accumulated $failed made a short column print "Data OK" directly beneath
 * its own corrupt rows: once $failed was already true, no comparison against it could
 * tell "the scan added nothing" from "the scan added plenty."
 *
 * Artifacts and trashed rows are informational, not findings.
 *
 * The two blindness clauses are NOT independent. Rows are only scanned when their
 * length >= $min_length, so if ceiling <= $min_length every scanned row is also at or
 * above the ceiling and 'unprovable' already covers it. The ceiling clause therefore
 * earns its keep in exactly one case: an EMPTY scan under a capped generator, where
 * there is nothing to count yet nothing can be proved either. Keep both.
 */
function asg_data_findings( $scan, $min_length ) {
    return (bool) ( $scan['corrupt'] || $scan['cut'] || $scan['suffixed']
                 || $scan['unprovable'] || $scan['ceiling'] <= $min_length );
}

/**
 * Full scan. CLI only: it reads every long slug and regenerates one per row.
 */
function asg_scan_slugs( $min_length = 190, $batch = 500 ) {
    global $wpdb;

    // Lift OUR clamp, never swap the generator. sanitize_title() must run the live
    // filter chain, because whatever is on that filter is what actually minted these
    // slugs. On a Layer-2-off site that is the theme's fork, and comparing against
    // ours would diff two different generators and call the difference truncation.
    asg_force_slug_budget( ASG_SLUG_BYTES );

    $out = array(
        'corrupt'    => array(),
        'artifact'   => array(),
        'cut'        => array(),
        'suffixed'   => array(),
        'trashed'    => 0,
        'unprovable' => 0,
        'scanned'    => 0,
        'lengths'    => array(),
        'ceiling'    => asg_generator_ceiling(),
    );

    $targets = array(
        array( $wpdb->posts, 'post_name', 'post_title', 'ID' ),
        array( $wpdb->terms, 'slug', 'name', 'term_id' ),
    );

    foreach ( $targets as list( $table, $col, $title_col, $pk ) ) {
        $offset = 0;
        do {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT `{$pk}` AS id, `{$col}` AS slug, `{$title_col}` AS title FROM `{$table}`
                  WHERE LENGTH(`{$col}`) >= %d ORDER BY `{$pk}` LIMIT %d OFFSET %d",
                $min_length,
                $batch,
                $offset
            ) );
            $rows = $rows ? $rows : array();

            foreach ( $rows as $row ) {
                $where = "{$table}.{$col} #{$row->id}";
                $len   = strlen( $row->slug );
                ++$out['scanned'];

                // A slug at least as long as the generator's reach can never be shown
                // to be a strict prefix of its own regeneration. Unprovable, whatever
                // the classifier then says about it. See asg_row_is_unprovable().
                if ( asg_row_is_unprovable( $len, $out['ceiling'] ) ) {
                    ++$out['unprovable'];
                }

                switch ( asg_classify_slug( $row->slug, sanitize_title( $row->title, '', 'save' ) ) ) {
                    case 'trashed':
                        ++$out['trashed'];
                        break;
                    case 'artifact':
                        $out['artifact'][] = "{$where}: {$row->slug}";
                        break;
                    case 'corrupt':
                        $out['corrupt'][] = "{$where}: {$row->slug}";
                        break;
                    case 'cut':
                        $out['cut'][]           = "{$where} ({$len} bytes)";
                        $out['lengths'][ $len ] = 1 + ( isset( $out['lengths'][ $len ] ) ? $out['lengths'][ $len ] : 0 );
                        break;
                    case 'cut-suffixed':
                        $out['suffixed'][]      = "{$where} ({$len} bytes)";
                        $out['lengths'][ $len ] = 1 + ( isset( $out['lengths'][ $len ] ) ? $out['lengths'][ $len ] : 0 );
                        break;
                }
            }
            $offset += $batch;
        } while ( count( $rows ) === $batch );
    }

    asg_force_slug_budget( null );
    ksort( $out['lengths'] );
    return $out;
}

/* WP-CLI
 *   wp asg verify                          schema + Layer-1 drift (cheap, safe for cron)
 *   wp asg verify --data                   also scan slugs for corruption + truncation
 *   wp asg verify --data --min-length=190  tune the length gate on the prefix test
 * Exits NON-ZERO on any finding, so a plain `wp asg verify || alert` actually fires. */
if ( $asg_l1 && defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'asg verify', function ( $args, $assoc_args ) use ( $asg_l2 ) {
        $failed = false;

        foreach ( asg_short_columns() as $row ) {
            WP_CLI::log( "SHORT: {$row}" );
            $failed = true;
        }

        $drift = get_option( 'asg_l1_status' );
        if ( $drift ) {
            WP_CLI::log( "LAYER-1 DRIFT ({$drift}): the canonical-schema rewrite did not apply; core changed its CREATE TABLE string." );
            $failed = true;
        }

        /* Layer-2 drift and the fail-safe both mean slugs are being generated wrongly or
         * silently truncated. They must fail the DEFAULT invocation: cron runs
         * `wp asg verify || alert`, never `--data`. A fail-safe whose loudness depends on
         * an optional flag is not loud, which is the failure it exists to prevent. */
        if ( $asg_l2 ) {
            // The drift oracle is bound to admin_init, so a headless site (WP-CLI, REST,
            // no wp-admin visits) never fires it. That is exactly how a drifted fork rode
            // along for three releases. Evaluate it here rather than trust the option.
            asg_guard_layer2_drift();

            $l2 = (string) get_option( 'asg_l2_status' );
            if ( 0 === strpos( $l2, 'drift:' ) ) {
                WP_CLI::log( "LAYER-2 DRIFT ({$l2}): the slug generator no longer matches core; re-sync the fork." );
                $failed = true;
            }
        }

        if ( get_option( 'asg_l2_failsafe_active' ) ) {
            WP_CLI::log( 'LAYER 2 FAILED SAFE to core: new slugs cap at 200 bytes and truncate SILENTLY.' );
            WP_CLI::log( '  They serve HTTP 200 and never reach a log. Re-sync the fork, unset ASG_L2_FAILSAFE,' );
            WP_CLI::log( '  then audit what has already been cut with: wp asg verify --data' );
            $failed = true;
        }

        if ( ! empty( $assoc_args['data'] ) ) {
            $min  = isset( $assoc_args['min-length'] ) ? (int) $assoc_args['min-length'] : 190;
            $scan = asg_scan_slugs( $min );

            $data_findings = asg_data_findings( $scan, $min );

            WP_CLI::log( sprintf(
                'Scanned %d slugs at or above %d bytes. Live generator reaches %d bytes.',
                $scan['scanned'],
                $min,
                $scan['ceiling']
            ) );

            // BLINDNESS. The prefix test needs a generator that can out-run the stored
            // slug. Anchor this to the generator's measured reach, never to the length
            // gate: with a 200-byte cap and a 190-byte gate, `ceiling <= min` is false
            // and the guard is dead while every 200-byte row is unprovable. And never
            // key it on asg_l2_failsafe_active, which is only ONE route to a capped
            // generator (a theme's own fallback, or a future core change, are others).
            if ( $scan['ceiling'] <= $min ) {
                $failed = true;
                WP_CLI::log( sprintf(
                    'SCAN IS BLIND: the generator emits at most %d bytes, at or below the %d-byte gate.'
                    . ' It cannot out-run any scanned slug, so this scan proves NOTHING.',
                    $scan['ceiling'],
                    $min
                ) );
            } elseif ( $scan['unprovable'] ) {
                $failed = true;
                WP_CLI::log( sprintf(
                    'SCAN IS PARTIALLY BLIND: %d of %d scanned slugs are at or above the generator ceiling (%d bytes),'
                    . ' so truncation there is not reliably provable. An UPPER bound: a row whose suffix-stripped stem'
                    . ' falls below the ceiling may still be provable. Fix the generator, then re-run.',
                    $scan['unprovable'],
                    $scan['scanned'],
                    $scan['ceiling']
                ) );
            }

            if ( $scan['corrupt'] ) {
                $failed = true;
                WP_CLI::log( sprintf( 'CORRUPT: %d (malformed bytes AND different from the regenerated slug: data is gone)', count( $scan['corrupt'] ) ) );
                foreach ( array_slice( $scan['corrupt'], 0, 20 ) as $row ) {
                    WP_CLI::log( "  {$row}" );
                }
            }

            if ( $scan['cut'] || $scan['suffixed'] ) {
                $failed = true;
                WP_CLI::log( sprintf(
                    'TRUNCATION CANDIDATES: %d certain-shape + %d suffixed (lower confidence)',
                    count( $scan['cut'] ),
                    count( $scan['suffixed'] )
                ) );
                foreach ( $scan['lengths'] as $len => $n ) {
                    WP_CLI::log( sprintf( '  %d at exactly %d bytes', $n, $len ) );
                }
                WP_CLI::log( '  A pile at one length IS the ceiling that cut them. CANDIDATES, NOT VERDICTS: a' );
                WP_CLI::log( '  hand-shortened slug is indistinguishable from a truncated one, and rows whose titles' );
                WP_CLI::log( '  changed after publication never match, so this count is a LOWER BOUND.' );
                WP_CLI::log( '  They serve HTTP 200 today. Repairing them MOVES live URLs, so change slugs through' );
                WP_CLI::log( '  wp_update_post(), which records _wp_old_slug so wp_old_slug_redirect() 301s the old URL.' );
                WP_CLI::log( '  A raw $wpdb->update() bypasses that and silently breaks links that work right now.' );
            }

            if ( $scan['artifact'] ) {
                WP_CLI::log( sprintf(
                    'GENERATOR ARTIFACTS: %d (undecodable bytes, but identical to the regenerated slug: nothing lost)',
                    count( $scan['artifact'] )
                ) );
                WP_CLI::log( '  Typically an Arabic title opening with a percent sign before a numeral ("%80 ..."),' );
                WP_CLI::log( '  which core preserves as an escaped octet. They serve 200. Anything that DECODES them' );
                WP_CLI::log( '  (REST, json_encode) will still choke. Not a truncation, and not repairable by rewriting.' );
            }

            if ( $scan['trashed'] ) {
                WP_CLI::log( sprintf( 'TRASHED: %d skipped (wp_trash_post() cuts to 191 bytes one-way; a core limit)', $scan['trashed'] ) );
            }

            if ( ! $data_findings ) {
                WP_CLI::log( 'Data OK: nothing corrupt, no truncation candidates, no blind spots.' );
            }
        }

        if ( $failed ) {
            WP_CLI::error( 'Check failed.' );  // exits 1
        }
        WP_CLI::success( sprintf( 'Schema OK: both columns at VARCHAR(%d).', ASG_COLUMN_LEN ) );
    } );
}
