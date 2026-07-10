<?php
/**
 * Run every suite. No WordPress, no database.
 *
 *   php tests/run.php
 *
 * Exits non-zero on any failure, so CI and a pre-tag hook can both use it.
 */

$suites = array( 'fork-vs-core', 'classify', 'data-findings' );
$failed = 0;

foreach ( $suites as $suite ) {
    $file = __DIR__ . '/' . $suite . '.php';
    echo "\n=== {$suite} ===\n";
    passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ), $status );
    if ( 0 !== $status ) {
        ++$failed;
    }
}

echo "\n";
if ( $failed ) {
    fwrite( STDERR, "{$failed} suite(s) failed.\n" );
    exit( 1 );
}
echo "All suites passed.\n";
