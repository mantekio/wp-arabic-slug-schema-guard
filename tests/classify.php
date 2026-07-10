<?php
/**
 * asg_classify_slug() and asg_slug_is_malformed().
 *
 * Every case here comes from a real Arabic corpus (~250k posts). None of them fail on
 * Latin fixtures, which is exactly why they shipped.
 */

require __DIR__ . '/bootstrap.php';
asg_test_load( array( 'asg_slug_is_malformed', 'asg_classify_slug' ) );

echo "Slug classifier\n";

$stored = '%d8%a7%d9%84%d8%b0%d9%83%d8%a7%d8%a1';                                   // الذكاء
$long   = $stored . '-%d8%a7%d9%84%d8%a7%d8%b5%d8%b7%d9%86%d8%a7%d8%b9%d9%8a';      // + الاصطناعي

$cases = array(
    // stored, regenerated, expected, label
    array( $stored,        $stored, 'clean', 'exact match' ),
    array( $stored . '-2', $stored, 'clean', 'genuine -2 uniqueness suffix' ),

    // Compare first, strip last. Stripping "-<digits>" up front made a title ending in
    // a year a strict prefix of its own faithful regeneration: 8268 candidates against
    // ~280 real ones on the corpus.
    array( 'foo-1985',    'foo-1985',    'clean', 'title legitimately ends in a year' ),
    array( 'aleppo-2011', 'aleppo-2011', 'clean', 'trailing number, nothing truncated' ),
    array( 'qanat-24',    'qanat-24',    'clean', 'trailing number, channel name' ),
    array( 'foo-2',       'foo-2',       'clean', 'title ends in a single digit' ),

    // Truncation.
    array( $stored,        $long, 'cut',          'strict prefix of the regeneration' ),
    array( $stored . '-2', $long, 'cut-suffixed', 'truncated, then suffixed (lower confidence)' ),
    array( 'hand-picked',  'hand-picked-and-then-some', 'cut', 'hand-shortened slug (honest false positive)' ),

    // Arabic writes the percent sign BEFORE the numeral, so core keeps "%80" as an
    // escaped octet. Undecodable, reproducible, serves 200, nothing lost.
    array( '%80-%d9%86%d8%b3%d8%a8%d8%a9', '%80-%d9%86%d8%b3%d8%a8%d8%a9', 'artifact', 'title opens "%80 ..."' ),
    array( '%80-%d9%86%d8%b3',             '%80-%d9%86%d8%b3%d8%a8%d8%a9', 'corrupt',  'same shape, but truncated too' ),

    // Malformed AND different from the regeneration: bytes are gone.
    array( '%d8%a7%d9%84%d8', $long, 'corrupt', 'orphan lead byte (silent, serves 200)' ),
    array( '%d8%a7%d9%84%d',  $long, 'corrupt', 'cut inside an escape (nginx 400)' ),
    array( '%d8%a7%d9%8-2',   $long, 'corrupt', 'broken escape BEFORE a suffix (end-anchored regex misses this)' ),

    array( 'foo__trashed', 'foo-bar-baz', 'trashed', 'wp_trash_post() cuts to 191, one-way' ),
);

foreach ( $cases as list( $s, $r, $expect, $label ) ) {
    $got = asg_classify_slug( $s, $r );
    asg_ok( $got === $expect, $label, $got === $expect ? '' : "got '{$got}', expected '{$expect}'" );
}

echo "\nMalformed-byte detection (must be UNANCHORED)\n";
asg_ok( ! asg_slug_is_malformed( $stored ),          'healthy Arabic slug' );
asg_ok( ! asg_slug_is_malformed( 'hello-world' ),    'plain ascii, no escapes' );
asg_ok( asg_slug_is_malformed( '%d8%a7%d9%8-2' ),    'broken escape mid-slug' );
asg_ok( asg_slug_is_malformed( '%d8%a7%d9%84%d' ),   'broken escape at the end' );
asg_ok( asg_slug_is_malformed( '%d8%a7%d9%84%d8' ),  'complete escapes, stranded lead byte' );
asg_ok( ! asg_slug_is_malformed( '%d8%a7%d9%84' ),   'clean character-boundary cut (valid, merely wrong)' );

asg_test_exit();
