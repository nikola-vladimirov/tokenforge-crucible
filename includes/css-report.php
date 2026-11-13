<?php
/**
 * The classes part of the site report: classes of the Global SCSS nobody uses, classes that
 * say the same thing twice, and what each class weighs in the compiled CSS.
 *
 * A report only: nothing is deleted or merged. It is a collector of the site scan
 * (site-scan.php), so the site is read once for every report.
 *
 * @package TokenForge_Crucible
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The classes of compiled CSS and the bytes of the rules each one starts
 * (rules inside @media and the like count too): name => bytes.
 *
 * A class that only shows up later in a selector (".card .title") is listed with 0 bytes.
 * Names with an escaped character (".md\:flex") are left out: they cannot be matched as a word.
 *
 * @param string   $css  Compiled CSS.
 * @param int      $from Start offset.
 * @param int|null $to   End offset (the whole text when null).
 * @return array
 */
function tokenforge_crucible_class_bytes( $css, $from = 0, $to = null ) {
	$out = array();
	foreach ( \TokenForgeCrucible\Scss_Sync::items( $css, $from, null === $to ? strlen( $css ) : $to ) as $it ) {
		if ( 'block' !== $it['type'] ) {
			continue;
		}
		if ( '@' === substr( $it['header'], 0, 1 ) ) {
			if ( preg_match( '/^@(media|supports|container|layer)\b/i', $it['header'] ) ) {
				foreach ( tokenforge_crucible_class_bytes( $css, $it['open'] + 1, $it['close'] ) as $name => $bytes ) {
					$out[ $name ] = ( isset( $out[ $name ] ) ? $out[ $name ] : 0 ) + $bytes;
				}
			}
			continue; // @keyframes, @font-face and so on hold no classes.
		}
		$owners = array();
		foreach ( \TokenForgeCrucible\Scss_Sync::split( $it['header'], ',' ) as $part ) {
			// An attribute value ([href$=".pdf"]) is no class.
			if ( ! preg_match_all( '/\.(-?[_a-zA-Z](?:[\w-]|\\\\.)*)/', (string) preg_replace( '/\[[^\]]*\]/', '', $part ), $m ) ) {
				continue;
			}
			foreach ( $m[1] as $i => $name ) {
				if ( false !== strpos( $name, '\\' ) ) {
					continue;
				}
				$out += array( $name => 0 );
				if ( 0 === $i ) {
					$owners[ $name ] = true;
				}
			}
		}
		foreach ( $owners as $name => $one ) {
			$out[ $name ] += $it['end'] - $it['start'];
		}
	}
	return $out;
}

/**
 * Groups of names that share a key, two or more in a group: [ [ name, name ], ... ] (100 groups at most).
 *
 * @param array $by Key => names.
 * @return array
 */
function tokenforge_crucible_css_groups( $by ) {
	$out = array();
	foreach ( $by as $names ) {
		if ( count( $names ) > 1 ) {
			$out[] = array_values( $names );
		}
	}
	return array_slice( $out, 0, 100 );
}

/**
 * The site scan collector for classes. See tokenforge_crucible_site_scan().
 *
 * The scan feeds the Global SCSS first, then the options that say which id stands for which
 * class, then everything a class can be used in: the id maps must be known before the use is read.
 *
 * @return array [ add => callable, done => callable ]
 */
function tokenforge_crucible_css_report_collector() {
	$scss    = array();
	$bricks  = array();
	$ids     = array(); // id in builder data => class names.
	$found   = array();
	$classes = null;
	// The Global SCSS compiled, once: [ name => bytes, bytes in all, compiles? ].
	$compiled = static function () use ( &$scss, &$classes ) {
		if ( null === $classes ) {
			$classes = array( array(), 0, true );
			foreach ( $scss as $source ) {
				$r = tokenforge_crucible_compile( $source );
				if ( ! empty( $r['error'] ) ) {
					$classes[2] = false;
					continue;
				}
				$classes[1] += strlen( $r['css'] );
				foreach ( tokenforge_crucible_class_bytes( $r['css'] ) as $name => $bytes ) {
					$classes[0][ $name ] = ( isset( $classes[0][ $name ] ) ? $classes[0][ $name ] : 0 ) + $bytes;
				}
			}
		}
		return $classes;
	};
	// Rows with an id and a name, at any depth (the builder's class list).
	$map = static function ( $rows ) use ( &$ids, &$map ) {
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( isset( $row['id'], $row['name'] ) && is_scalar( $row['id'] ) && is_string( $row['name'] ) ) {
				$ids[ (string) $row['id'] ][] = ltrim( $row['name'], '.' );
			}
			$map( $row );
		}
	};
	// The word match of TokenForge's own class scan (Class_Usage::collect), with the id maps.
	$use  = static function ( $value ) use ( &$use, &$ids, &$found, $compiled ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			foreach ( (array) $value as $v ) {
				$use( $v );
			}
			return;
		}
		// Builder data kept as JSON has \n and \t between words.
		if ( ! is_string( $value ) || ! preg_match_all( '/[A-Za-z0-9_-]+/', str_replace( array( '\\n', '\\t', '\\r' ), ' ', $value ), $m ) ) {
			return;
		}
		list( $names ) = $compiled();
		foreach ( array_unique( $m[0] ) as $word ) {
			if ( isset( $names[ $word ] ) ) {
				$found[ $word ] = true;
			} elseif ( isset( $ids[ $word ] ) ) {
				$found += array_fill_keys( $ids[ $word ], true );
			}
		}
	};
	$add  = static function ( $key, $value ) use ( &$scss, &$bricks, &$ids, $map, $use ) {
		switch ( $key ) {
			case 'scss':
				$scss[] = (string) $value;
				break;
			case 'bricks_global_classes':
				$bricks = is_array( $value ) ? $value : array();
				foreach ( $bricks as $class ) {
					if ( isset( $class['id'], $class['name'] ) && is_string( $class['name'] ) ) {
						$ids[ (string) $class['id'] ][] = $class['name'];
					}
				}
				break;
			case 'etch_styles':
				foreach ( is_array( $value ) ? $value : array() as $id => $style ) {
					if ( isset( $style['selector'] ) && is_string( $style['selector'] ) && preg_match_all( '/\.(-?[_a-zA-Z][\w-]*)/', $style['selector'], $m ) ) {
						$ids[ (string) $id ] = $m[1];
					}
				}
				break;
			case 'oxygen_oxy_selectors_json_string':
				// The builder keeps this list as JSON, which may itself be a JSON string.
				$value = is_string( $value ) ? json_decode( $value, true ) : $value;
				$map( is_string( $value ) ? json_decode( $value, true ) : $value );
				break;
			case 'scss-partial':
			case 'etch_global_stylesheets':
				break; // SCSS or CSS text: it names classes, it does not use them.
			default:
				$use( $value );
		}
	};
	$done = static function ( $capped = false ) use ( &$scss, &$bricks, &$found, $compiled ) {
		list( $names, $bytes, $compiles ) = $compiled();
		$unused                           = array();
		// A scan that stopped early cannot say a class is used nowhere.
		if ( ! $capped ) {
			foreach ( $names as $name => $size ) {
				if ( ! isset( $found[ $name ] ) && ! \TokenForge\Class_Usage::kept( (string) $name, array() ) ) {
					$unused[] = (string) $name;
				}
			}
			sort( $unused );
		}
		arsort( $names );
		$sizes = array();
		foreach ( array_slice( array_filter( $names ), 0, 50, true ) as $name => $size ) {
			$sizes[] = array(
				'name'  => (string) $name,
				'bytes' => $size,
			);
		}

		// Builder classes that show the same in the builder once each one's own name is written as %root%.
		$same = array();
		foreach ( $bricks as $class ) {
			if ( ! isset( $class['name'], $class['settings'] ) || ! is_string( $class['name'] ) || ! is_array( $class['settings'] ) ) {
				continue;
			}
			$settings = $class['settings'];
			foreach ( $settings as $k => $css ) {
				if ( is_string( $css ) && 0 === strpos( (string) $k, '_cssCustom' ) ) {
					$settings[ $k ] = preg_replace( '/\.' . preg_quote( $class['name'], '/' ) . '(?![\w-])/', '%root%', $css );
				}
			}
			$view = \TokenForgeCrucible\Scss_Sync::builder_view( $settings, $class['name'] );
			if ( ! $view['units'] && '' === $view['extras'] ) {
				continue; // An empty class is no copy of another empty class.
			}
			ksort( $view['units'] );
			$same[ md5( wp_json_encode( $view['units'] ) . "\n" . \TokenForgeCrucible\Scss_Sync::canon_css( $view['extras'] ) ) ][] = $class['name'];
		}
		// Classes of the Global SCSS with the same text between their braces.
		$twins = array();
		foreach ( $scss as $source ) {
			foreach ( \TokenForgeCrucible\Scss_Sync::class_blocks( $source ) as $name => $block ) {
				$body = \TokenForgeCrucible\Scss_Sync::canon_css( substr( $source, $block['open'] + 1, $block['close'] - $block['open'] - 1 ) );
				if ( '' !== trim( $body, '; ' ) ) {
					$twins[ md5( rtrim( $body, ';' ) ) ][] = (string) $name;
				}
			}
		}

		return array(
			'total'        => count( $names ),
			'compiles'     => $compiles,
			'unused'       => array_slice( $unused, 0, 500 ),
			'unused_total' => count( $unused ),
			'dupes'        => array(
				'bricks' => tokenforge_crucible_css_groups( $same ),
				'scss'   => tokenforge_crucible_css_groups( $twins ),
			),
			'sizes'        => $sizes,
			'total_kb'     => round( $bytes / 1024, 1 ),
		);
	};
	return array(
		'add'  => $add,
		'done' => $done,
	);
}
