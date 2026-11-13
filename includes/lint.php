<?php
/**
 * Token lint: hard-coded values in the Global SCSS and in the builder's CSS fields,
 * each with the token TokenForge suggests for it.
 *
 * The matching is TokenForge's own CSS check (Ai::check_css); nothing here decides what
 * a value should be. Reads only: a fix is made in the editor text and stored by Save.
 *
 * @package TokenForge_Crucible
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Most declarations one site scan checks.
 */
const TOKENFORGE_CRUCIBLE_LINT_MAX_DECLS = 20000;

/**
 * The SCSS sources the lint and the site scan read: id => source. The Global SCSS is "global",
 * a saved file is "file:<name>".
 *
 * @return array
 */
function tokenforge_crucible_scss_sources() {
	$out = array( 'global' => (string) get_option( tokenforge_crucible_scss_option(), '' ) );
	foreach ( tokenforge_crucible_scss_files() as $name => $file ) {
		$out[ 'file:' . $name ] = $file['code'];
	}
	return $out;
}

/**
 * Findings in everything that is saved. A finding in a file names it ("file"), since its line counts in that file.
 *
 * @return array See tokenforge_crucible_check().
 */
function tokenforge_crucible_lint_saved() {
	$found = array();
	foreach ( tokenforge_crucible_scss_sources() as $id => $source ) {
		foreach ( tokenforge_crucible_lint_scss( $source ) as $finding ) {
			if ( 'global' !== $id ) {
				$finding['file'] = substr( $id, 5 );
			}
			$found[] = $finding;
		}
	}
	return $found;
}

/**
 * The plain declarations of SCSS or CSS text, nested blocks too, each with its line.
 *
 * Left out: variables, at-rules and custom properties (statements starting with $, @ or --)
 * and values Sass computes (a $variable, #{} or a function CSS does not have).
 *
 * @param string   $src  Source.
 * @param int      $from Start offset.
 * @param int|null $to   End offset (the whole text when null).
 * @param int      $depth How deep in blocks this text is (blocks more than 20 deep are not read).
 * @param array    $seen  Declarations so far for each "line prop", for pn; shared by the nested calls.
 * @return array [ [ prop, value, line, pn ], ... ] pn = how many declarations of this prop come before it on its line.
 */
function tokenforge_crucible_lint_decls( $src, $from = 0, $to = null, $depth = 0, &$seen = array() ) {
	$out = array();
	foreach ( \TokenForgeCrucible\Scss_Sync::items( $src, $from, null === $to ? strlen( $src ) : $to ) as $item ) {
		if ( 'block' === $item['type'] ) {
			// Every level parses its text again and calls itself: thousands of nested braces would not end.
			if ( $depth < 20 ) {
				foreach ( tokenforge_crucible_lint_decls( $src, $item['open'] + 1, $item['close'], $depth + 1, $seen ) as $d ) {
					$out[] = $d;
				}
			}
			continue;
		}
		if ( ! preg_match( '/^([a-z][a-z-]*)\s*:\s*(.+)$/is', $item['text'], $m ) ) {
			continue;
		}
		$prop  = strtolower( $m[1] );
		$value = trim( $m[2] );
		// A length of 0 means "to the end" on PHP 7.
		$line = 1 + ( $item['start'] > 0 ? substr_count( $src, "\n", 0, $item['start'] ) : 0 );
		// Counted for every declaration, also the ones left out below: the editor finds the place by counting them.
		$pn                          = isset( $seen[ $line . ' ' . $prop ] ) ? $seen[ $line . ' ' . $prop ] : 0;
		$seen[ $line . ' ' . $prop ] = $pn + 1;
		if ( strlen( $value ) > 500 || strpbrk( $value, '{};' ) ) {
			continue; // A brace or semicolon (inside a string) would break the one-rule-per-declaration text the check gets.
		}
		// Sass-computed, so there is no fixed value to compare.
		if ( false !== strpos( $value, '$' ) || false !== strpos( $value, '#{' ) || ( preg_match_all( '/([a-z_][\w-]*)\(/i', $value, $fn ) && array_diff( array_map( 'strtolower', $fn[1] ), \TokenForgeCrucible\Scss_Sync::CSS_FUNCTIONS ) ) ) {
			continue;
		}
		// Only values that can hold a colour, a length or a font reach the check; text in quotes (content: "#1") is not a value.
		if ( 'font-family' !== $prop && ( strpbrk( $value, '"\'' ) || ! preg_match( '/[\d#]|white|black/i', $value ) ) ) {
			continue;
		}
		$out[] = array(
			'prop'  => $prop,
			'value' => $value,
			'line'  => $line,
			'pn'    => $pn,
		);
	}
	return $out;
}

/**
 * Runs declarations through TokenForge's CSS check.
 *
 * Each declaration goes in as its own rule (.tfl-<n>{prop:value}) and the suggestions are
 * matched back by that selector, 30 declarations a call (the check stops at 150 suggestions).
 * shortcut: a batch whose declarations average over 5 suggestions loses the rest, lower the batch size then.
 *
 * @param array $decls [ [ prop, value, ... ], … ]; every other key is kept on the findings.
 * @return array Findings: the declaration (without value) plus found, use, match, fix and nth
 *               (which occurrence of "found" in the value, from 0). fix = TokenForge has a token for it.
 */
function tokenforge_crucible_check( $decls ) {
	static $ai = null;
	if ( null === $ai ) {
		// Without the constructor: it registers the AI abilities, and the check needs none of that.
		$ai = ( new \ReflectionClass( '\TokenForge\Ai' ) )->newInstanceWithoutConstructor();
	}
	$out = array();
	foreach ( array_chunk( array_values( $decls ), 30, true ) as $batch ) {
		$css = '';
		foreach ( $batch as $n => $d ) {
			$css .= '.tfl-' . $n . '{' . $d['prop'] . ':' . $d['value'] . "}\n";
		}
		$result = $ai->check_css( array( 'css' => $css ) );
		if ( is_wp_error( $result ) || empty( $result['suggestions'] ) ) {
			continue;
		}
		$seen = array();
		foreach ( $result['suggestions'] as $s ) {
			$n = isset( $s['selector'] ) ? (int) substr( $s['selector'], 5 ) : -1;
			if ( ! isset( $batch[ $n ], $s['found'], $s['use'], $s['match'] ) ) {
				continue;
			}
			$key          = $n . ' ' . $s['found'];
			$seen[ $key ] = isset( $seen[ $key ] ) ? $seen[ $key ] + 1 : 0;
			$decl         = $batch[ $n ];
			unset( $decl['value'] );
			$out[] = $decl + array(
				'found' => (string) $s['found'],
				'use'   => (string) $s['use'],
				'match' => (string) $s['match'],
				'fix'   => in_array( $s['match'], array( 'exact', 'close' ), true ) && 1 === preg_match( '/^var\(--[a-z0-9-]+\)$/', $s['use'] ),
				'nth'   => $seen[ $key ],
			);
		}
	}
	return $out;
}

/**
 * Findings in SCSS text.
 *
 * @param string $src SCSS source.
 * @return array See tokenforge_crucible_check().
 */
function tokenforge_crucible_lint_scss( $src ) {
	return tokenforge_crucible_check( tokenforge_crucible_lint_decls( (string) $src ) );
}

/**
 * The declarations of a CSS field, each tagged with where it is. Not checked yet: the site
 * scan gathers every field first and checks them together.
 *
 * @param string $css   CSS text.
 * @param array  $place Keys for the findings: place (label) and, when known, link, post, el.
 * @return array For tokenforge_crucible_check().
 */
function tokenforge_crucible_lint_css( $css, $place ) {
	$out = array();
	if ( is_string( $css ) && '' !== trim( $css ) ) {
		foreach ( tokenforge_crucible_lint_decls( $css ) as $d ) {
			$out[] = $d + $place;
		}
	}
	return $out;
}

/**
 * The lint part of the site scan (includes/site-scan.php): add() takes every source the
 * scan reads, done() gives the "lint" key of the site report.
 *
 * @return array [ add => callable( $key, $value, $place ), done => callable() ]
 */
function tokenforge_crucible_lint_collector() {
	$decls  = array();
	$capped = false;
	// Appends in place (a merge would copy the whole list for every field) and stops at the most one scan checks.
	$push = static function ( $css, $place ) use ( &$decls, &$capped ) {
		foreach ( $capped ? array() : tokenforge_crucible_lint_css( $css, $place ) as $d ) {
			if ( count( $decls ) >= TOKENFORGE_CRUCIBLE_LINT_MAX_DECLS ) {
				$capped = true;
				break;
			}
			$decls[] = $d;
		}
	};
	// The builder's custom CSS of one element or class: "_cssCustom" and "_cssCustom:<breakpoint>".
	$fields = static function ( $settings, $place ) use ( $push ) {
		foreach ( is_array( $settings ) ? $settings : array() as $k => $css ) {
			if ( '_cssCustom' !== $k && 0 !== strpos( (string) $k, '_cssCustom:' ) ) {
				continue;
			}
			$bp = (string) substr( $k, 11 );
			$at = $place;
			if ( '' !== $bp ) {
				$at['place'] .= ' (' . $bp . ')';
			}
			$push( $css, $at );
		}
	};
	$add    = static function ( $key, $value, $place ) use ( $push, $fields ) {
		$rows = is_array( $value ) ? $value : array();
		switch ( $key ) {
			case 'scss':
			case 'scss-partial':
				$push( $value, $place );
				break;
			case '_bricks_page_header_2':
			case '_bricks_page_content_2':
			case '_bricks_page_footer_2':
				foreach ( $rows as $el ) {
					if ( isset( $el['id'], $el['settings'] ) ) {
						/* translators: 1: where it is (Page: Home), 2: the element id in the builder */
						$label = sprintf( __( '%1$s, element %2$s', 'tokenforge-crucible' ), $place['place'], $el['id'] );
						$fields(
							$el['settings'],
							array(
								'place' => $label,
								'el'    => (string) $el['id'],
							) + $place
						);
					}
				}
				break;
			case '_bricks_page_settings':
				if ( isset( $rows['customCss'] ) ) {
					/* translators: %s: where it is (Page: Home) */
					$push( $rows['customCss'], array( 'place' => sprintf( __( '%s, page CSS in the builder', 'tokenforge-crucible' ), $place['place'] ) ) + $place );
				}
				break;
			case 'bricks_global_settings':
				if ( isset( $rows['customCss'] ) ) {
					$push(
						$rows['customCss'],
						array(
							'place' => __( 'Site CSS in the builder settings', 'tokenforge-crucible' ),
							'link'  => admin_url( 'admin.php?page=bricks-settings' ),
						)
					);
				}
				break;
			case 'bricks_global_classes':
				foreach ( $rows as $class ) {
					if ( isset( $class['name'], $class['settings'] ) ) {
						/* translators: %s: class name */
						$fields( $class['settings'], array( 'place' => sprintf( __( 'Builder class .%s', 'tokenforge-crucible' ), $class['name'] ) ) );
					}
				}
				break;
			case 'etch_styles':
			case 'etch_global_stylesheets':
				// These are edited in the builder, which opens on a page: the front page when the site has one.
				$front = (int) get_option( 'page_on_front' );
				$link  = $front ? home_url( '/?etch=magic&post_id=' . $front ) : '';
				foreach ( $rows as $row ) {
					if ( ! isset( $row['css'] ) ) {
						continue;
					}
					$name = isset( $row['selector'] ) ? $row['selector'] : ( isset( $row['name'] ) ? $row['name'] : '' );
					/* translators: %s: selector of the style, or name of the stylesheet */
					$label = 'etch_styles' === $key ? __( 'Builder style %s', 'tokenforge-crucible' ) : __( 'Builder stylesheet %s', 'tokenforge-crucible' );
					$push(
						$row['css'],
						array(
							'place' => trim( sprintf( $label, (string) $name ) ),
							'link'  => $link,
						)
					);
				}
				break;
		}
	};
	$done   = static function () use ( &$decls, &$capped ) {
		$found = tokenforge_crucible_check( $decls );
		$by    = array();
		$with  = 0;
		foreach ( $found as $f ) {
			$with             += $f['fix'] ? 1 : 0;
			$by[ $f['place'] ] = ( isset( $by[ $f['place'] ] ) ? $by[ $f['place'] ] : 0 ) + 1;
		}
		arsort( $by );
		$items = array_slice( $found, 0, 500 );
		foreach ( $items as $i => $f ) {
			// The edit link only for what the report lists (a link costs a query per post).
			if ( ! isset( $f['link'] ) ) {
				$items[ $i ]['link'] = empty( $f['post'] ) ? '' : tokenforge_crucible_edit_link( (int) $f['post'] );
			}
		}
		return array(
			'total'      => count( $found ),
			'with_token' => $with,
			'by_place'   => array_slice( $by, 0, 100, true ),
			'items'      => $items,
			'capped'     => $capped,
		);
	};
	return array(
		'add'  => $add,
		'done' => $done,
	);
}

/**
 * Registers the lint route.
 */
function tokenforge_crucible_register_lint_route() {
	register_rest_route(
		'tokenforge-crucible/v1',
		'/lint',
		array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( $request ) {
				// The editor text (saved or not) when sent, else what is saved. Read only: the text is parsed, never stored or printed.
				$body   = (array) $request->get_json_params();
				$source = isset( $body['source'] ) && is_string( $body['source'] ) ? str_replace( "\r\n", "\n", $body['source'] ) : null;
				if ( null !== $source && strlen( $source ) > TOKENFORGE_CRUCIBLE_SCSS_MAX_BYTES ) {
					return rest_ensure_response(
						array(
							'ok'    => false,
							'error' => __( 'SCSS is larger than 100 KB.', 'tokenforge-crucible' ),
						)
					);
				}
				$found = null === $source ? tokenforge_crucible_lint_saved() : tokenforge_crucible_lint_scss( $source );

				return rest_ensure_response(
					array(
						'ok'         => true,
						'findings'   => $found,
						'total'      => count( $found ),
						'with_token' => count( array_filter( array_column( $found, 'fix' ) ) ),
					)
				);
			},
		)
	);
}
add_action( 'rest_api_init', 'tokenforge_crucible_register_lint_route' );
