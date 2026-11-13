<?php
/**
 * Versions: named copies of the TokenForge state (Global SCSS, your recipes, tokens,
 * settings), a diff against now and a restore by parts.
 *
 * Unnamed copies are TokenForge's own History snapshots (key "h<id>"); only versions
 * with a name are stored here (key "n<id>"), in the same format, autoload off.
 * A restore goes through Transfer::import(), so it is sanitised like an import.
 *
 * @package TokenForge_Crucible
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const TOKENFORGE_CRUCIBLE_VERSIONS_MAX   = 30;
const TOKENFORGE_CRUCIBLE_VERSIONS_BYTES = 5242880; // 5 MB for all named versions together.

/**
 * The named versions, newest first: [ { id, name, time, user, from, size, hash } ].
 *
 * @return array
 */
function tokenforge_crucible_versions() {
	$list = get_option( 'tokenforge_crucible_versions', array() );

	return is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : array();
}

/**
 * The data of a named version ("n5") or of a TokenForge History snapshot ("h12").
 *
 * @param string $key Version key.
 * @return array|null Null when missing or damaged.
 */
function tokenforge_crucible_version_data( $key ) {
	if ( ! preg_match( '/^([nh])(\d+)$/', (string) $key, $m ) ) {
		return null;
	}
	$id = (int) $m[2];
	if ( 'h' === $m[1] ) {
		return \TokenForge\History::get( $id );
	}
	foreach ( tokenforge_crucible_versions() as $v ) {
		if ( (int) $v['id'] !== $id ) {
			continue;
		}
		$blob = get_option( 'tokenforge_crucible_version_' . $id, '' );
		$json = '';
		if ( is_string( $blob ) && 0 === strpos( $blob, 'gz:' ) && function_exists( 'gzuncompress' ) ) {
			$json = (string) @gzuncompress( (string) base64_decode( substr( $blob, 3 ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		} elseif ( is_string( $blob ) && 0 === strpos( $blob, 'js:' ) ) {
			$json = substr( $blob, 3 );
		}
		if ( '' === $json || ! hash_equals( (string) $v['hash'], hash( 'sha256', $json ) ) ) {
			return null;
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : null;
	}
	return null;
}

/**
 * Saves a named version of the current state, or of a History snapshot.
 *
 * @param string $name Name (cut to 60 characters).
 * @param int    $from History snapshot id to keep, 0 for the current state.
 * @return array|string The new entry, or the problem.
 */
function tokenforge_crucible_version_create( $name, $from = 0 ) {
	$name = trim( mb_substr( sanitize_text_field( (string) $name ), 0, 60 ) );
	if ( '' === $name ) {
		return __( 'Give the version a name.', 'tokenforge-crucible' );
	}
	$data = $from ? \TokenForge\History::get( $from ) : \TokenForge\History::data();
	if ( null === $data ) {
		return __( 'This save is gone or damaged.', 'tokenforge-crucible' );
	}
	$json = (string) wp_json_encode( $data );
	// Options hold text: compressed data is stored as base64.
	$blob = function_exists( 'gzcompress' ) ? 'gz:' . base64_encode( gzcompress( $json, 6 ) ) : 'js:' . $json; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	$list = tokenforge_crucible_versions();
	if ( count( $list ) >= TOKENFORGE_CRUCIBLE_VERSIONS_MAX || array_sum( array_column( $list, 'size' ) ) + strlen( $blob ) > TOKENFORGE_CRUCIBLE_VERSIONS_BYTES ) {
		return __( 'Delete a version first.', 'tokenforge-crucible' );
	}
	$entry = array(
		'id'   => $list ? max( array_map( 'intval', array_column( $list, 'id' ) ) ) + 1 : 1,
		'name' => $name,
		'time' => time(),
		'user' => get_current_user_id(),
		'from' => (int) $from,
		'size' => strlen( $blob ),
		'hash' => hash( 'sha256', $json ),
	);
	update_option( 'tokenforge_crucible_version_' . $entry['id'], $blob, false );
	array_unshift( $list, $entry );
	update_option( 'tokenforge_crucible_versions', $list, false );

	return $entry;
}

/**
 * Deletes a named version.
 *
 * @param int $id Version id.
 */
function tokenforge_crucible_version_delete( $id ) {
	$id   = (int) $id;
	$list = tokenforge_crucible_versions();
	$keep = array();
	foreach ( $list as $v ) {
		if ( (int) $v['id'] !== $id ) {
			$keep[] = $v;
		}
	}
	if ( count( $keep ) !== count( $list ) ) {
		delete_option( 'tokenforge_crucible_version_' . $id );
		update_option( 'tokenforge_crucible_versions', $keep, false );
	}
}

/**
 * The rows that differ between two name => text maps: [ name, then, now ], null = not there.
 *
 * @param array $then Map then.
 * @param array $now  Map now.
 * @return array
 */
function tokenforge_crucible_version_changes( $then, $now ) {
	$rows = array();
	foreach ( array_keys( $then + $now ) as $k ) {
		$x = isset( $then[ $k ] ) ? $then[ $k ] : null;
		$y = isset( $now[ $k ] ) ? $now[ $k ] : null;
		if ( $x !== $y ) {
			$rows[] = array( (string) $k, $x, $y );
		}
	}
	return $rows;
}

/**
 * What a version holds, as name => text maps for the diff.
 *
 * @param array $data Version data.
 * @return array { source, classes, recipes, tokens, files }
 */
function tokenforge_crucible_version_maps( $data ) {
	$out = array(
		'source'  => isset( $data['scss']['source'] ) && is_string( $data['scss']['source'] ) ? $data['scss']['source'] : '',
		'classes' => array(),
		'recipes' => array(),
		'tokens'  => array(),
		'files'   => array(),
	);
	// shortcut: a file's text is compared, not where it loads; add pages and types when that change must show.
	foreach ( isset( $data['scss']['files'] ) && is_array( $data['scss']['files'] ) ? $data['scss']['files'] : array() as $name => $file ) {
		if ( is_array( $file ) && isset( $file['code'] ) && is_string( $file['code'] ) ) {
			$out['files'][ (string) $name ] = $file['code'];
		}
	}
	foreach ( \TokenForgeCrucible\Scss_Sync::class_blocks( $out['source'] ) as $name => $it ) {
		$out['classes'][ $name ] = substr( $out['source'], $it['start'], $it['end'] - $it['start'] );
	}
	foreach ( isset( $data['recipes']['custom'] ) && is_array( $data['recipes']['custom'] ) ? $data['recipes']['custom'] : array() as $r ) {
		if ( is_array( $r ) && isset( $r['name'], $r['code'] ) ) {
			$out['recipes'][ (string) $r['name'] ] = (string) $r['code'];
		}
	}
	// shortcut: a token's value and on/off are compared, not its group; add the group when a group change must show.
	foreach ( isset( $data['tokens'] ) && is_array( $data['tokens'] ) ? $data['tokens'] : array() as $t ) {
		if ( is_array( $t ) && isset( $t['name'] ) ) {
			$out['tokens'][ (string) $t['name'] ] = ( isset( $t['value'] ) && is_scalar( $t['value'] ) ? (string) $t['value'] : '' ) . ( isset( $t['enabled'] ) && ! $t['enabled'] ? ' (off)' : '' );
		}
	}
	return $out;
}

/**
 * A side by side line diff as wp_text_diff() builds it, but with 3 lines of context around each
 * change (wp_text_diff() keeps every line, which hides one changed line in a long SCSS).
 *
 * @param string $left  Text of the version.
 * @param string $right Text now.
 * @return string Escaped HTML table, '' when both are equal.
 */
function tokenforge_crucible_text_diff( $left, $right ) {
	if ( ! class_exists( 'WP_Text_Diff_Renderer_Table', false ) ) {
		require ABSPATH . WPINC . '/wp-diff.php';
	}
	$renderer                          = new WP_Text_Diff_Renderer_Table( array( 'show_split_view' => true ) );
	$renderer->_leading_context_lines  = 3;
	$renderer->_trailing_context_lines = 3;
	$rows                              = $renderer->render( new Text_Diff( explode( "\n", normalize_whitespace( $left ) ), explode( "\n", normalize_whitespace( $right ) ) ) );
	if ( ! $rows ) {
		return '';
	}

	return "<table class='diff is-split-view'><thead><tr class='diff-sub-title'><th>" . esc_html__( 'This version', 'tokenforge-crucible' ) . '</th><th>' . esc_html__( 'Now', 'tokenforge-crucible' ) . "</th></tr></thead><tbody>\n" . $rows . "\n</tbody></table>";
}

/**
 * What changed between a version and now.
 *
 * @param string $key Version key.
 * @return array|null { classes, scss_html, files, recipes, tokens, palette } or null when missing or damaged.
 */
function tokenforge_crucible_version_diff( $key ) {
	$then = tokenforge_crucible_version_data( $key );
	if ( null === $then ) {
		return null;
	}
	$now   = json_decode( (string) wp_json_encode( \TokenForge\History::data() ), true ); // same types as the stored copy.
	$a     = tokenforge_crucible_version_maps( $then );
	$b     = tokenforge_crucible_version_maps( $now );
	$state = static function ( $row ) {
		return array( $row[0], null === $row[1] ? 'added' : ( null === $row[2] ? 'removed' : 'changed' ) );
	};

	return array(
		'classes'   => array_map( $state, tokenforge_crucible_version_changes( $a['classes'], $b['classes'] ) ),
		'scss_html' => tokenforge_crucible_text_diff( $a['source'], $b['source'] ),
		// Each SCSS file that differs: [ name, state, line diff as escaped HTML ].
		'files'     => array_map(
			static function ( $row ) use ( $state ) {
				return array_merge( $state( $row ), array( tokenforge_crucible_text_diff( (string) $row[1], (string) $row[2] ) ) );
			},
			tokenforge_crucible_version_changes( $a['files'], $b['files'] )
		),
		'recipes'   => array_map( $state, tokenforge_crucible_version_changes( $a['recipes'], $b['recipes'] ) ),
		'tokens'    => array_slice( tokenforge_crucible_version_changes( $a['tokens'], $b['tokens'] ), 0, 300 ),
		'palette'   => ( isset( $then['palette'] ) ? $then['palette'] : null ) !== ( isset( $now['palette'] ) ? $now['palette'] : null ),
	);
}

/**
 * Puts parts of a version back. A History snapshot of the current state is taken first,
 * so the restore can be undone.
 *
 * @param string $key   Version key.
 * @param array  $parts Any of scss, recipes, tokens (with the colour families), all.
 * @return array|string The Transfer parts that were restored, or the problem.
 */
function tokenforge_crucible_version_restore( $key, $parts ) {
	$map  = array(
		'scss'    => array( 'scss' ),
		'recipes' => array( 'recipes' ),
		'tokens'  => array( 'tokens', 'palette' ),
		'all'     => \TokenForge\Transfer::parts(),
	);
	$want = array();
	foreach ( (array) $parts as $p ) {
		if ( is_string( $p ) && isset( $map[ $p ] ) ) {
			$want = array_merge( $want, $map[ $p ] );
		}
	}
	if ( ! $want ) {
		return __( 'Choose what to restore.', 'tokenforge-crucible' );
	}
	$data = tokenforge_crucible_version_data( $key );
	if ( null === $data ) {
		return __( 'This version is gone or damaged.', 'tokenforge-crucible' );
	}
	\TokenForge\History::snapshot( 'restore' );
	$done = \TokenForge\Transfer::import( array( 'data' => $data ), $want );
	if ( in_array( 'scss', $done, true ) ) {
		\TokenForgeCrucible\Scss_Sync::push();
	}
	\TokenForge\Css_Generator::get_instance()->rebuild();

	return $done;
}

/**
 * The lists the Versions panel shows: named versions and TokenForge's recent saves.
 *
 * @return array
 */
function tokenforge_crucible_versions_lists() {
	$named = array();
	foreach ( tokenforge_crucible_versions() as $v ) {
		$u       = get_userdata( (int) $v['user'] );
		$named[] = array(
			'id'   => (int) $v['id'],
			'name' => (string) $v['name'],
			'time' => (int) $v['time'],
			'user' => $u ? $u->display_name : '',
		);
	}
	return array(
		'ok'     => true,
		'named'  => $named,
		'recent' => \TokenForge\History::items(),
	);
}

/**
 * Registers the versions REST routes.
 */
function tokenforge_crucible_versions_routes() {
	$read  = static function () {
		return current_user_can( 'manage_options' );
	};
	$error = static function ( $message ) {
		return rest_ensure_response(
			array(
				'ok'    => false,
				'error' => $message,
			)
		);
	};

	register_rest_route(
		'tokenforge-crucible/v1',
		'/versions',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => $read,
				'callback'            => static function () {
					return rest_ensure_response( tokenforge_crucible_versions_lists() );
				},
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => 'tokenforge_crucible_can_edit_scss',
				'callback'            => static function ( $request ) use ( $error ) {
					$body = (array) $request->get_json_params();
					$made = tokenforge_crucible_version_create( isset( $body['name'] ) && is_string( $body['name'] ) ? $body['name'] : '', isset( $body['from'] ) ? absint( $body['from'] ) : 0 );

					return is_string( $made ) ? $error( $made ) : rest_ensure_response( tokenforge_crucible_versions_lists() );
				},
			),
		)
	);

	register_rest_route(
		'tokenforge-crucible/v1',
		'/versions/delete',
		array(
			'methods'             => 'POST',
			'permission_callback' => 'tokenforge_crucible_can_edit_scss',
			'callback'            => static function ( $request ) {
				$body = (array) $request->get_json_params();
				tokenforge_crucible_version_delete( isset( $body['id'] ) ? absint( $body['id'] ) : 0 );

				return rest_ensure_response( tokenforge_crucible_versions_lists() );
			},
		)
	);

	register_rest_route(
		'tokenforge-crucible/v1',
		'/versions/diff',
		array(
			'methods'             => 'GET',
			'permission_callback' => $read,
			'callback'            => static function ( $request ) use ( $error ) {
				$diff = tokenforge_crucible_version_diff( sanitize_key( (string) $request->get_param( 'key' ) ) );

				return null === $diff ? $error( __( 'This version is gone or damaged.', 'tokenforge-crucible' ) ) : rest_ensure_response( array_merge( array( 'ok' => true ), $diff ) );
			},
		)
	);

	register_rest_route(
		'tokenforge-crucible/v1',
		'/versions/restore',
		array(
			'methods'             => 'POST',
			'permission_callback' => 'tokenforge_crucible_can_edit_scss',
			'callback'            => static function ( $request ) use ( $error ) {
				$body = (array) $request->get_json_params();
				$done = tokenforge_crucible_version_restore( isset( $body['key'] ) && is_string( $body['key'] ) ? sanitize_key( $body['key'] ) : '', isset( $body['parts'] ) ? $body['parts'] : array() );

				return is_string( $done ) ? $error( $done ) : rest_ensure_response( array_merge( tokenforge_crucible_versions_lists(), array( 'restored' => $done ) ) );
			},
		)
	);
}
add_action( 'rest_api_init', 'tokenforge_crucible_versions_routes' );
