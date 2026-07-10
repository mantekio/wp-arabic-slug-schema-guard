<?php
/**
 * asg_data_findings(): the gate on the "Data OK" success line, and on blindness.
 *
 * Two bugs lived here. The gate was once compared against a sentinel captured after the
 * schema check, so a short column made it read `true === true` and print "Data OK"
 * directly beneath its own corrupt rows. And blindness was once anchored to the length
 * gate rather than to the generator's measured ceiling, which made it dead: with core's
 * 200-byte cap and a 190-byte gate, `200 <= 190` is false while every 200-byte row is
 * unprovable.
 *
 * Both were correct on a clean-schema, uncapped-generator rig. Only the combination
 * (Arabic rows + short column + capped generator) exposes them, so it lives here now.
 */

require __DIR__ . '/bootstrap.php';

/* STRUCTURE BEFORE BEHAVIOUR.
 *
 * These run before asg_data_findings() is ever called. A back door such as get_option()
 * raises a fatal on that first call, which would turn the suite red for the right reason
 * by accident while these assertions never executed. Stub get_option() into the
 * bootstrap one day and the door opens in silence. Structure is the guard; the fatal is
 * a coincidence.
 *
 * And the extraction list below is DERIVED from the purity walk, never hand-written. If
 * the gate is refactored to delegate, the walk finds the helper, audits it, and loads
 * it. "Add the helper to asg_test_load()" therefore stops being an available repair for
 * a failing suite, which is precisely how a delegating back door would otherwise land. */
echo "The gate answers from its arguments and nothing else\n";

$seen       = array();
$violations = array();
asg_test_purity( 'asg_data_findings', $seen, $violations );
asg_test_purity( 'asg_row_is_unprovable', $seen, $violations );

asg_ok(
    empty( $violations ),
    'gate + transitive callees reach no outside state, and nothing unverifiable',
    implode( "\n          ", $violations )
);
printf( "        audited: %s\n", implode( ', ', array_keys( $seen ) ) );

// Never run the behavioural cases through a gate that is structurally compromised: the
// results would be meaningless, and a fatal from the back door would bury the finding.
if ( $violations ) {
    asg_test_exit();
}

// Source-only checks are done. Load, then anything needing reflection.
asg_test_load( array_keys( $seen ) );

asg_ok(
    ( new ReflectionFunction( 'asg_data_findings' ) )->getNumberOfParameters() === 2,
    'signature takes exactly ( $scan, $min_length )'
);

$MIN = 190;

/** A scan result, defaulting to a healthy uncapped site. */
function scan( array $overrides = array() ) {
    return array_merge(
        array(
            'corrupt' => array(), 'artifact' => array(), 'cut' => array(), 'suffixed' => array(),
            'trashed' => 0, 'unprovable' => 0, 'scanned' => 100, 'lengths' => array(), 'ceiling' => 1000,
        ),
        $overrides
    );
}

function rows( $n ) { return array_fill( 0, $n, 'row' ); }

echo "\n\"Data OK\" fires only on a genuinely clean scan\n";

$cases = array(
    // scan, data-OK expected, label
    array( scan(), true, 'TRUE POSITIVE: clean slice, uncapped generator' ),
    array( scan( array( 'artifact' => rows( 3 ), 'trashed' => 9 ) ), true,
        'TRUE POSITIVE: artifacts and trashed rows are informational, not findings' ),
    array( scan( array( 'scanned' => 0, 'ceiling' => 1000 ) ), true,
        'TRUE POSITIVE: nothing long enough to scan, generator healthy' ),

    // Observed on the corpus, under a SHORT wp_terms.slug. The old gate printed
    // "Data OK" underneath all of this.
    array( scan( array( 'corrupt' => rows( 81 ), 'cut' => rows( 2525 ), 'suffixed' => rows( 1424 ) ) ), false,
        'corpus: corrupt + candidates while the schema check already failed' ),

    // Observed under the failsafe emulation: a capped generator, 199-byte ceiling.
    array( scan( array( 'ceiling' => 199, 'unprovable' => 178695, 'scanned' => 185792, 'corrupt' => rows( 84 ) ) ), false,
        'corpus: PARTIALLY BLIND at a 199-byte ceiling, atop corrupt rows' ),

    // Each finding class alone must suppress the line.
    array( scan( array( 'corrupt'    => rows( 1 ) ) ), false, 'one corrupt row alone' ),
    array( scan( array( 'cut'        => rows( 1 ) ) ), false, 'one truncation candidate alone' ),
    array( scan( array( 'suffixed'   => rows( 1 ) ) ), false, 'one suffixed candidate alone' ),
    array( scan( array( 'unprovable' => 1 ) ),         false, 'one unprovable row alone' ),

    // Blindness clause 1: ceiling <= gate.
    array( scan( array( 'ceiling' => 190, 'unprovable' => 100 ) ), false, 'fully blind: ceiling == gate (realistic)' ),
    array( scan( array( 'ceiling' => 190, 'unprovable' => 0, 'scanned' => 0 ) ), false,
        'fully blind on an EMPTY scan: the one case clause 1 catches alone' ),
    array( scan( array( 'ceiling' => 191 ) ), true, 'ceiling one byte above the gate, nothing unprovable' ),
);

foreach ( $cases as list( $s, $expect, $label ) ) {
    $data_ok = ! asg_data_findings( $s, $MIN );
    asg_ok( $data_ok === $expect, $label,
        $data_ok === $expect ? '' : sprintf( 'Data OK = %s, expected %s', $data_ok ? 'yes' : 'no', $expect ? 'yes' : 'no' ) );
}

echo "\nThe gate answers from the scan alone\n";
$clean = scan();
$dirty = scan( array( 'corrupt' => rows( 1 ) ) );
asg_ok( ! asg_data_findings( $clean, $MIN ), 'clean scan says OK regardless of schema state' );
asg_ok( asg_data_findings( $dirty, $MIN ), 'dirty scan says findings regardless of schema state' );

echo "\nBlindness clause 1 is subsumed by clause 2 for any NON-EMPTY scan\n";
// The whole justification for keeping both clauses rests on 'unprovable' being keyed on
// SLUG length, not on the suffix-stripped stem. Re-key it on the stem and the implication
// below dies quietly. Pin it here, because it lives in the $wpdb-bound scan loop.
asg_ok( asg_row_is_unprovable( 190, 190 ), 'a row exactly at the ceiling is unprovable' );
asg_ok( ! asg_row_is_unprovable( 189, 190 ), 'a row one byte below the ceiling is provable' );
asg_ok( asg_row_is_unprovable( 200, 199 ), 'the corpus case: 200-byte slug, 199-byte ceiling' );

// Rows only enter the scan when strlen(slug) >= $MIN. So whenever ceiling <= $MIN, EVERY
// scanned row must be unprovable, which is what makes clause 1 redundant unless empty.
$violations = 0;
for ( $ceiling = 1; $ceiling <= $MIN; $ceiling++ ) {
    foreach ( array( $MIN, $MIN + 1, 200, 512, 1024 ) as $slug_len ) {   // any scannable row
        if ( ! asg_row_is_unprovable( $slug_len, $ceiling ) ) {
            ++$violations;
        }
    }
}
asg_ok( 0 === $violations, 'ceiling <= gate implies every scanned row is unprovable (interlock holds)' );

// Which leaves exactly one case where clause 1 acts alone.
asg_ok( asg_data_findings( scan( array( 'ceiling' => $MIN, 'unprovable' => 0, 'scanned' => 0 ) ), $MIN ),
    'empty scan under a capped generator: the sole case clause 1 catches alone' );

asg_test_exit();
