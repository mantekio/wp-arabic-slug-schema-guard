<?php
/**
 * The Layer-2 oracle, run offline.
 *
 * The plugin's fork of sanitize_title_with_dashes() must differ from core in the byte
 * budget and NOTHING else. Short inputs, so neither cap fires: any difference is drift.
 *
 * This exists because v1.0.0 through v1.2.0 shipped a fork missing core's entire
 * "strip these characters entirely" block. The in-WordPress drift guard would have
 * caught it, but it only runs on admin_init against a live core, so on a fixture rig it
 * never executed. Running the same diff offline removes that hiding place.
 */

require __DIR__ . '/bootstrap.php';

define( 'ASG_SLUG_BYTES', 1000 );
function asg_slug_budget() { return ASG_SLUG_BYTES; }
function asg_force_slug_budget( $bytes = null ) { return null; }

/** Verbatim from wp-includes/formatting.php. The oracle. */
function core_sanitize_title_with_dashes( $title, $raw_title = '', $context = 'display' ) {
    $title = strip_tags( $title );
    $title = preg_replace( '|%([a-fA-F0-9][a-fA-F0-9])|', '---$1---', $title );
    $title = str_replace( '%', '', $title );
    $title = preg_replace( '|---([a-fA-F0-9][a-fA-F0-9])---|', '%$1', $title );

    if ( seems_utf8( $title ) ) {
        if ( function_exists( 'mb_strtolower' ) ) { $title = mb_strtolower( $title, 'UTF-8' ); }
        $title = utf8_uri_encode( $title, 200 );
    }
    $title = strtolower( $title );

    if ( 'save' === $context ) {
        $title = str_replace( array( '%c2%a0', '%e2%80%93', '%e2%80%94' ), '-', $title );
        $title = str_replace( array( '&nbsp;', '&#160;', '&ndash;', '&#8211;', '&mdash;', '&#8212;' ), '-', $title );
        $title = str_replace( '/', '-', $title );
        $title = str_replace(
            array(
                '%c2%ad', '%c2%a1', '%c2%bf',
                '%c2%ab', '%c2%bb', '%e2%80%b9', '%e2%80%ba',
                '%e2%80%98', '%e2%80%99', '%e2%80%9c', '%e2%80%9d',
                '%e2%80%9a', '%e2%80%9b', '%e2%80%9e', '%e2%80%9f',
                '%e2%80%a2',
                '%c2%a9', '%c2%ae', '%c2%b0', '%e2%80%a6', '%e2%84%a2',
                '%c2%b4', '%cb%8a', '%cc%81', '%cd%81',
                '%cc%80', '%cc%84', '%cc%8c',
                '%e2%80%8b', '%e2%80%8c', '%e2%80%8d', '%e2%80%8e', '%e2%80%8f',
                '%e2%80%aa', '%e2%80%ab', '%e2%80%ac', '%e2%80%ad', '%e2%80%ae',
                '%ef%bb%bf', '%ef%bf%bc',
            ), '', $title
        );
        $title = str_replace( '%c3%97', 'x', $title );
    }

    $title = preg_replace( '/&.+?;/', '', $title );
    $title = str_replace( '.', '-', $title );
    $title = preg_replace( '/[^%a-z0-9 _-]/', '', $title );
    $title = preg_replace( '/\s+/', '-', $title );
    $title = preg_replace( '|-+|', '-', $title );
    return trim( $title, '-' );
}

asg_test_load( array( 'asg_sanitize_title_with_dashes' ) );

echo "Layer-2 fork vs core\n";

// One fixture per branch of core's 'save' block. Arabic first: these are the ones that
// only fail on a real corpus.
$fixtures = array(
    'plain Arabic'              => 'الذكاء الاصطناعي',
    'angle quotes (Arabic “”)'  => 'عاجل: تطورات «مهمة» اليوم',
    'copyright sign'            => 'حقوق © 2017 الأخبار',
    'times sign maps to x'      => 'قياس 3 × 4 متر',
    'right-to-left mark'        => "علامة\xE2\x80\x8F اتجاه",
    'curly quotes'              => 'مقال “مقتبس” و‘مفرد’',
    'trademark, degree, hellip' => 'منتج ™ 30° … نهاية',
    'mixed script + em dash'    => 'Mixed عربي + English — dash',
    'entities and slashes'      => 'foo/bar &mdash; baz',
    'case and whitespace'       => 'UPPER   multiple   spaces',
    'pre-encoded octet'         => '%d8%a7 pre-encoded octet',
);

foreach ( $fixtures as $label => $title ) {
    $core = core_sanitize_title_with_dashes( $title, $title, 'save' );
    $ours = asg_sanitize_title_with_dashes( $title, $title, 'save' );
    asg_ok( $core === $ours, $label, $core === $ours ? '' : "core = {$core}\n          fork = {$ours}" );
}

asg_test_exit();
