<?php
/**
 * Plugin Name: Arabic Slug Schema Guard
 * Plugin URI:  https://github.com/mantekio/wp-arabic-slug-schema-guard
 * Description: Keeps wp_posts.post_name and wp_terms.slug at VARCHAR(1024) across
 *              WordPress core database upgrades, so long URL-encoded Arabic slugs
 *              are never truncated. Prevention-first: dbDelta never truncates,
 *              because the canonical schema it diffs against already says 1024.
 *              The slug fork self-tests against core on each update and can fail safe.
 * Version:     1.2.0
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
 * Each layer can be switched off on its own: set an ASG_LAYER{1,2,3} constant, or
 * use an asg_layer{N}_enabled filter. See PER-LAYER SWITCHES below.
 */

defined( 'ABSPATH' ) || exit;

const ASG_COLUMN_LEN = 1024;  // physical column width (bytes)
const ASG_SLUG_BYTES = 1000;  // max generated slug length, under the column width
const ASG_SCHEMA_SIG = 'post_name:1024;slug:1024';
// define( 'ASG_ALERT_EMAIL', 'ops@example.com' );  // optional, set in wp-config.php
// define( 'ASG_L2_FAILSAFE', true );               // optional: on Layer-2 drift, fall back to core (slugs cap at 200) until you re-sync
// define( 'ASG_LAYER2', false );                   // optional: skip the long-slug generator, keep only Layer 1's wide column

/* PER-LAYER SWITCHES
 * Every layer is ON by default (Layer 3 is opt-in), so existing installs are
 * unchanged. Turn one off with a constant in wp-config.php, or with a filter from
 * a theme or plugin that already owns slug generation:
 *
 *   define( 'ASG_LAYER2', false );                         // wp-config.php
 *   add_filter( 'asg_layer2_enabled', '__return_false' );  // e.g. a theme's functions.php
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
// Layer 3 (de-duplication) is reserved; its switch already resolves through the
// helper and inherits Layer 1, only the handler is unshipped. See the note below.

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
    error_log( '[Arabic Slug Schema Guard] Layer 2 needs Layer 1 (the VARCHAR(1024) column), '
             . 'but Layer 1 is disabled, so long-slug generation is held off to avoid truncation.' );
    update_option( 'asg_dep_warned', 1, false );
}

/* LAYER 1: PREVENTION
 * Rewrite the canonical CREATE TABLE dbDelta diffs against, so "desired" already
 * equals the live 1024-wide column and no destructive CHANGE COLUMN is emitted. */
if ( $asg_l1 ) {
    add_filter( 'dbdelta_create_queries', function ( $queries ) {
        global $wpdb;
        foreach ( array( $wpdb->posts => 'post_name', $wpdb->terms => 'slug' ) as $table => $column ) {
            if ( isset( $queries[ $table ] ) ) {
                $queries[ $table ] = preg_replace(
                    '/(\b' . $column . '\s+varchar\()\s*200\s*(\))/i',
                    '${1}' . ASG_COLUMN_LEN . '${2}',
                    $queries[ $table ]
                );
            }
        }
        return $queries;
    } );
}

/* LAYER 2: GENERATION
 * Core caps new slugs at 200 bytes via utf8_uri_encode($title, 200). Swap in a
 * copy of sanitize_title_with_dashes() whose only change is the byte budget.
 * Installed only when Layer 2 is enabled and the fork is not held back by the
 * drift fail-safe: if the drift guard (below) flagged THIS core version and
 * ASG_L2_FAILSAFE is on, stay on core's default (caps at 200, degraded but safe
 * and known) rather than run a fork we know has drifted. */
if ( ! $asg_l2 ) {
    // Layer 2 off (disabled, or Layer 1 is off) → leave core's generator in place.
} elseif ( get_option( 'asg_l2_status' ) === 'drift:' . $GLOBALS['wp_version']
     && defined( 'ASG_L2_FAILSAFE' ) && ASG_L2_FAILSAFE ) {
    // Known-drifted on this core version → leave core's generator in place.
} else {
    remove_filter( 'sanitize_title', 'sanitize_title_with_dashes' );
    add_filter( 'sanitize_title', 'asg_sanitize_title_with_dashes', 10, 3 );
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
        $title = utf8_uri_encode( $title, ASG_SLUG_BYTES );  // core hard-codes 200 here
    }

    $title = strtolower( $title );

    if ( 'save' === $context ) {
        // Turn non-breaking spaces, en/em dashes and slashes into hyphens.
        $title = str_replace( array( '%c2%a0', '%e2%80%93', '%e2%80%94' ), '-', $title );
        $title = str_replace( array( '&nbsp;', '&#160;', '&ndash;', '&#8211;', '&mdash;', '&#8212;' ), '-', $title );
        $title = str_replace( '/', '-', $title );
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
    // pure cleanup-logic drift, not the byte budget.
    $fixtures = array(
        'الذكاء الاصطناعي',
        'عاجل: تطورات «مهمة» اليوم',
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
        if ( defined( 'ASG_ALERT_EMAIL' ) && function_exists( 'wp_mail' ) ) {
            wp_mail( ASG_ALERT_EMAIL, 'WP slug Layer-2 drift: ' . wp_parse_url( home_url(), PHP_URL_HOST ), $msg );
        }
        update_option( 'asg_l2_status', $drifted, true );  // autoload: the fail-safe reads it every request
        return;  // do NOT cache "ok" while drifted
    }
    update_option( 'asg_l2_status', $ok, true );
}

/* LAYER 3: DE-DUPLICATION (reserved, opt-in)
 * _truncate_post_slug() also caps at 200, but only when a slug COLLIDES and needs
 * a "-2" suffix, rare for unique headlines, and it isn't filterable. If you need
 * >200 bytes even on collisions, take over uniqueness via pre_wp_unique_post_slug.
 * Most sites skip this, and it only matters when Layer 2 is on (there are no long
 * slugs to collide otherwise).
 *
 * Reserved, not shipped yet: the ASG_LAYER3 constant and asg_layer3_enabled filter
 * already resolve through asg_layer_enabled(), and this layer inherits Layer 1's
 * dependency. Only the handler is pending. To wire it in:
 *   $asg_l3 = $asg_l1 && asg_layer_enabled( 3, false );
 *   if ( $asg_l3 ) add_filter( 'pre_wp_unique_post_slug', 'asg_unique_post_slug', 10, 6 );
 */

/* TRIPWIRE: verify + alert after every core update
 * This only detects and alerts: it restores the COLUMN, never bytes already
 * truncated. Treat any revert as an incident: restore from backup.
 * Bound to Layer 1: with the wide column switched off, there is nothing to watch. */
if ( $asg_l1 ) {
    add_action( 'upgrader_process_complete', function ( $upgrader, $extra ) {
        if ( isset( $extra['type'] ) && 'core' === $extra['type'] ) {
            delete_option( 'asg_schema_sig' );  // force a re-check on the next admin_init
        }
    }, 10, 2 );

    add_action( 'admin_init', function () {
        if ( get_option( 'asg_schema_sig' ) === ASG_SCHEMA_SIG ) {
            return;  // fast path: already verified since the last update
        }
        $reverted = asg_reverted_columns();
        if ( $reverted ) {
            $msg = '[Arabic Slug Schema Guard] Column(s) reverted: ' . implode( ', ', $reverted )
                 . '. Long slugs were truncated: restore from backup and check 404 logs.';
            error_log( $msg );
            if ( defined( 'ASG_ALERT_EMAIL' ) ) {
                wp_mail( ASG_ALERT_EMAIL, 'WP slug schema reverted: ' . wp_parse_url( home_url(), PHP_URL_HOST ), $msg );
            }
            return;  // do NOT cache "ok" while broken
        }
        update_option( 'asg_schema_sig', ASG_SCHEMA_SIG, false );
    } );
}

function asg_reverted_columns() {
    global $wpdb;
    $reverted = array();
    foreach ( array( array( $wpdb->posts, 'post_name' ), array( $wpdb->terms, 'slug' ) ) as list( $table, $column ) ) {
        $len = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME, $table, $column
        ) );
        if ( $len && $len < ASG_COLUMN_LEN ) {
            $reverted[] = "{$table}.{$column} (now {$len})";
        }
    }
    return $reverted;
}

/* WP-CLI:`wp asg verify` for cron-based monitoring of Layer 1's column */
if ( $asg_l1 && defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'asg verify', function () {
        foreach ( asg_reverted_columns() ?: array( 'ok' ) as $row ) {
            WP_CLI::log( 'ok' === $row ? 'Schema OK: both columns at VARCHAR(1024).' : "REVERTED: {$row}" );
        }
    } );
}
