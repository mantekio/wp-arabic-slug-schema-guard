<?php
/**
 * Shared test bootstrap. No WordPress, no database: plain PHP.
 *
 * Functions under test are EXTRACTED FROM THE SHIPPED PLUGIN, never copied here.
 * A copied gate re-encodes the blindness it is meant to catch, and passes itself.
 */

// Override to run the suite against another build, e.g. to confirm it still catches a
// bug a previous release shipped:  ASG_PLUGIN=/tmp/v1.2.0.php php tests/run.php
define( 'ASG_TEST_PLUGIN', getenv( 'ASG_PLUGIN' ) ?: dirname( __DIR__ ) . '/wp-arabic-slug-schema-guard.php' );

/** The source text of one function, straight out of the plugin file. */
function asg_test_source( $fn ) {
    $src = file_get_contents( ASG_TEST_PLUGIN );
    if ( ! preg_match( '/^function ' . preg_quote( $fn, '/' ) . '\(.*?^}$/ms', $src, $m ) ) {
        fwrite( STDERR, "bootstrap: cannot extract {$fn}() from the plugin\n" );
        exit( 1 );
    }
    return $m[0];  // the docblock sits above `function`, so this is signature + body only
}

/** Pull named functions straight out of the plugin file and define them. */
function asg_test_load( array $names ) {
    foreach ( $names as $fn ) {
        if ( ! function_exists( $fn ) ) {
            eval( asg_test_source( $fn ) );
        }
    }
}

/** Is this function declared in the plugin file? */
function asg_test_declares( $fn ) {
    return (bool) preg_match(
        '/^function ' . preg_quote( $fn, '/' ) . '\s*\(/m',
        file_get_contents( ASG_TEST_PLUGIN )
    );
}

/* PURITY WALK
 *
 * A function that "reads only its arguments" cannot be proved so by its signature (a
 * back door needs no parameter), nor by scanning its own body (the door can sit in a
 * helper it delegates to). It has to be established over the whole transitive call
 * graph, and from the SOURCE, using the tokenizer rather than regex, because
 * /\bglobal\b/ matches the word "global" inside a comment.
 *
 * And it must REQUIRE THE GOOD, never enumerate the bad. Three earlier versions of this
 * walk enumerated: they denied `$GLOBALS` (one superglobal of nine), they denied a list
 * of escape-hatch function names (which a variable call `$f()` never matches, because it
 * is a T_VARIABLE, not a T_STRING), and they admitted every PHP builtin via isInternal()
 * (getenv, constant, ini_get, error_get_last and file_get_contents are all internal).
 * Every one of those is the same mistake: guarding the shapes you happened to think of.
 *
 * So: superglobals are a closed set PHP will not quietly extend, and all nine are
 * rejected. Anything that cannot be resolved statically (a variable call, a method, a
 * constructor, a static call) is a violation by virtue of being unverifiable, because
 * purity you cannot check must be refused rather than assumed. And the only calls
 * permitted are plugin functions, which are followed, plus a short, explicit allow-list
 * of pure builtins. Adding to that list is a deliberate act with a reviewer attached. */

/** PHP's superglobals. A closed set; the language will not add to it silently. */
const ASG_TEST_SUPERGLOBALS = array(
    '$GLOBALS', '$_ENV', '$_SERVER', '$_GET', '$_POST',
    '$_REQUEST', '$_SESSION', '$_COOKIE', '$_FILES',
);

/** Builtins a pure predicate may call. Allow-list: extend deliberately, never casually. */
const ASG_TEST_PURE_BUILTINS = array(
    'count', 'strlen', 'in_array', 'is_array', 'array_key_exists', 'array_keys',
    'array_values', 'max', 'min', 'abs', 'intval', 'str_starts_with', 'strpos',
    'substr', 'sprintf', 'preg_match',
);

/**
 * Constants a pure predicate may read. Also an allow-list, for the same reason.
 *
 * Once `new`, `::`, `->` and call sites are flagged, a bare T_STRING has exactly three
 * remaining roles: true/false/null, a call name, or a CONSTANT READ. That is a closed
 * enumeration of roles, not a guessed list of shapes, so this stays a completeness
 * argument. A bare `if ( ASG_KILL )` is a config toggle reaching into the gate; a bare
 * `PHP_INT_MAX` is a compile-time value. Nothing in the tokens tells them apart, so a
 * human decides once, here, rather than the walk assuming.
 */
const ASG_TEST_PURE_CONSTANTS = array(
    'PHP_INT_MAX', 'PHP_INT_MIN', 'PHP_INT_SIZE', 'PHP_FLOAT_EPSILON', 'PHP_EOL',
);

/** Body tokens of a function: everything after the signature's opening brace. */
function asg_test_tokens( $fn ) {
    $tokens  = token_get_all( '<?php ' . asg_test_source( $fn ) );
    $body    = array();
    $started = false;
    foreach ( $tokens as $t ) {
        if ( ! $started ) {
            $started = ( '{' === $t );
            continue;
        }
        $body[] = $t;
    }
    return $body;
}

/** The next / previous significant token, skipping whitespace and comments. */
function asg_test_significant( array $tokens, $i, $step ) {
    $skip = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT );
    for ( $j = $i + $step; isset( $tokens[ $j ] ); $j += $step ) {
        if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], $skip, true ) ) {
            continue;
        }
        return $tokens[ $j ];
    }
    return null;
}

/** Names of functions CALLED in a token stream. Keywords are their own tokens, so
 *  `return (`, `isset(`, `if (` and casts like `(bool)` never appear here. */
function asg_test_calls( array $tokens ) {
    $calls  = array();
    $before = array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW );
    if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ) {
        $before[] = T_NULLSAFE_OBJECT_OPERATOR;
    }
    foreach ( $tokens as $i => $t ) {
        if ( ! is_array( $t ) || T_STRING !== $t[0] ) {
            continue;
        }
        $prev = asg_test_significant( $tokens, $i, -1 );
        if ( is_array( $prev ) && in_array( $prev[0], $before, true ) ) {
            continue;  // a method, a static call, or a declaration
        }
        if ( '(' === asg_test_significant( $tokens, $i, 1 ) ) {
            $calls[] = $t[1];
        }
    }
    return array_values( array_unique( $calls ) );
}

/** Ways a token stream reaches state, or does something we cannot statically verify. */
function asg_test_state_access( array $tokens ) {
    $hits    = array();
    $dynamic = array( ')', ']', '}' );   // $a['f']() , foo()() , ${'f'}()

    foreach ( $tokens as $i => $t ) {
        if ( is_array( $t ) ) {
            if ( T_GLOBAL === $t[0] ) {
                $hits[] = 'the `global` keyword';
            } elseif ( T_STATIC === $t[0] ) {
                $hits[] = 'a `static` variable';
            } elseif ( T_NEW === $t[0] ) {
                $hits[] = '`new` (the constructor is not audited)';
            } elseif ( T_DOUBLE_COLON === $t[0] ) {
                $hits[] = 'a `::` static access';
            } elseif ( T_OBJECT_OPERATOR === $t[0]
                || ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && T_NULLSAFE_OBJECT_OPERATOR === $t[0] ) ) {
                $hits[] = 'a method call';
            } elseif ( T_VARIABLE === $t[0] && in_array( $t[1], ASG_TEST_SUPERGLOBALS, true ) ) {
                $hits[] = "the superglobal {$t[1]}";
            } elseif ( T_STRING === $t[0] ) {
                $next = asg_test_significant( $tokens, $i, 1 );
                $prev = asg_test_significant( $tokens, $i, -1 );
                $skip = array( T_NEW, T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_FUNCTION );

                if ( '(' === $next ) {
                    continue;  // a call: audited by name in asg_test_purity()
                }
                if ( is_array( $next ) && T_DOUBLE_COLON === $next[0] ) {
                    continue;  // a class name; the `::` itself is already reported
                }
                if ( is_array( $prev ) && in_array( $prev[0], $skip, true ) ) {
                    continue;  // already reported by the operator that precedes it
                }
                if ( in_array( strtolower( $t[1] ), array( 'true', 'false', 'null' ), true ) ) {
                    continue;
                }
                if ( ! in_array( $t[1], ASG_TEST_PURE_CONSTANTS, true ) ) {
                    $hits[] = "the constant {$t[1]} (a bare `if ( ASG_KILL )` is a config toggle)";
                }
            }
            continue;
        }

        // A call whose callee is not a plain name cannot be resolved, so it cannot be
        // audited, so it cannot be certified. `$f = 'getenv'; $f(...)` lives here.
        if ( '(' !== $t ) {
            continue;
        }
        $prev = asg_test_significant( $tokens, $i, -1 );
        if ( is_array( $prev ) && T_VARIABLE === $prev[0] ) {
            $hits[] = 'a variable function call `$f()`';
        } elseif ( ! is_array( $prev ) && in_array( $prev, $dynamic, true ) ) {
            $hits[] = 'a dynamic call through an expression';
        }
    }
    return array_unique( $hits );
}

/**
 * Walk $fn and everything it transitively calls. $seen collects the plugin functions
 * visited (use it to derive the extraction list, so no one can "repair" a fatal by
 * hand-adding a helper). $violations collects every route to outside state, and every
 * construct whose purity cannot be established statically.
 */
function asg_test_purity( $fn, array &$seen, array &$violations ) {
    if ( isset( $seen[ $fn ] ) ) {
        return;
    }
    $seen[ $fn ] = true;
    $tokens      = asg_test_tokens( $fn );

    foreach ( asg_test_state_access( $tokens ) as $route ) {
        $violations[] = "{$fn}() uses {$route}";
    }

    foreach ( asg_test_calls( $tokens ) as $callee ) {
        if ( in_array( $callee, ASG_TEST_PURE_BUILTINS, true ) ) {
            continue;                                        // allow-listed, deliberately
        }
        if ( asg_test_declares( $callee ) ) {
            asg_test_purity( $callee, $seen, $violations );   // delegation: follow it
            continue;
        }
        $violations[] = "{$fn}() calls {$callee}(), which is neither a plugin function nor "
                      . 'on the pure-builtin allow-list';
    }
}

$GLOBALS['asg_test_failures'] = 0;

function asg_ok( $condition, $label, $detail = '' ) {
    if ( $condition ) {
        printf( "  ok    %s\n", $label );
        return;
    }
    ++$GLOBALS['asg_test_failures'];
    printf( "  FAIL  %s%s\n", $label, $detail ? "\n          {$detail}" : '' );
}

function asg_test_exit() {
    $n = $GLOBALS['asg_test_failures'];
    echo $n ? "\n{$n} failure(s)\n" : "\nAll good.\n";
    exit( $n ? 1 : 0 );
}

/* WordPress functions the plugin's own code leans on, copied from core so the
 * extracted functions can run standalone. These are core's, not ours. */

if ( ! function_exists( 'seems_utf8' ) ) {
    function seems_utf8( $str ) {
        $length = strlen( $str );
        for ( $i = 0; $i < $length; $i++ ) {
            $c = ord( $str[ $i ] );
            if ( $c < 0x80 )                  { $n = 0; }
            elseif ( ( $c & 0xE0 ) === 0xC0 ) { $n = 1; }
            elseif ( ( $c & 0xF0 ) === 0xE0 ) { $n = 2; }
            elseif ( ( $c & 0xF8 ) === 0xF0 ) { $n = 3; }
            else                              { return false; }
            for ( $j = 0; $j < $n; $j++ ) {
                if ( ( ++$i === $length ) || ( ( ord( $str[ $i ] ) & 0xC0 ) !== 0x80 ) ) {
                    return false;
                }
            }
        }
        return true;
    }
}

if ( ! function_exists( 'utf8_uri_encode' ) ) {
    function utf8_uri_encode( $utf8_string, $length = 0, $encode_ascii_characters = false ) {
        $unicode = ''; $values = array(); $num_octets = 1; $unicode_length = 0;
        $string_length = strlen( $utf8_string );
        for ( $i = 0; $i < $string_length; $i++ ) {
            $value = ord( $utf8_string[ $i ] );
            if ( $value < 128 ) {
                $char                = chr( $value );
                $encoded_char        = $encode_ascii_characters ? rawurlencode( $char ) : $char;
                $encoded_char_length = strlen( $encoded_char );
                if ( $length && ( $unicode_length + $encoded_char_length ) > $length ) { break; }
                $unicode        .= $encoded_char;
                $unicode_length += $encoded_char_length;
            } else {
                if ( count( $values ) === 0 ) {
                    $num_octets = ( $value < 224 ) ? 2 : ( ( $value < 240 ) ? 3 : 4 );
                }
                $values[] = $value;
                if ( $length && ( $unicode_length + ( $num_octets * 3 ) ) > $length ) { break; }
                if ( count( $values ) === $num_octets ) {
                    for ( $j = 0; $j < $num_octets; $j++ ) { $unicode .= '%' . dechex( $values[ $j ] ); }
                    $unicode_length += $num_octets * 3;
                    $values = array(); $num_octets = 1;
                }
            }
        }
        return $unicode;
    }
}
