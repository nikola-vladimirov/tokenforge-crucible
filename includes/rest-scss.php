<?php
/**
 * REST routes for the SCSS dock.
 *
 * @package TokenForge_Crucible
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compiles the source and reports whether it is valid.
 *
 * @param string $source SCSS source.
 * @return array
 */
function tokenforge_crucible_scss_status( $source ) {
	$result = \TokenForge\Scss::compile( $source );

	return array(
		'ok'    => null === $result['error'],
		'error' => $result['error'],
	);
}

/**
 * Reference data for the dock (empty for now).
 *
 * @return array
 */
function tokenforge_crucible_scss_reference() {
	return array();
}

/**
 * Registers the dock routes.
 */
function tokenforge_crucible_register_dock_routes() {
	$permission = static function () {
		return current_user_can( 'manage_options' );
	};

	register_rest_route(
		'tokenforge-crucible/v1',
		'/scss',
		array(
			'methods'             => 'GET',
			'permission_callback' => $permission,
			'callback'            => static function () {
				$source = (string) get_option( tokenforge_crucible_scss_option(), '' );
				$sync   = tokenforge_crucible_sync_settings();

				return rest_ensure_response(
					array(
						'source'    => $source,
						'rev'       => md5( $source ),
						'enabled'   => true,
						'sync'      => ! empty( $sync['sync'] ),
						'status'    => tokenforge_crucible_scss_status( $source ),
						'ref'       => tokenforge_crucible_scss_reference(),
						'recipes'   => \TokenForge\Recipes::all(),
						'recipes_on' => false,
					)
				);
			},
		)
	);

	register_rest_route(
		'tokenforge-crucible/v1',
		'/scss-rev',
		array(
			'methods'             => 'GET',
			'permission_callback' => $permission,
			'callback'            => static function () {
				return rest_ensure_response(
					array( 'rev' => md5( (string) get_option( tokenforge_crucible_scss_option(), '' ) ) )
				);
			},
		)
	);

	register_rest_route(
		'tokenforge-crucible/v1',
		'/scss-compile',
		array(
			'methods'             => 'POST',
			'permission_callback' => $permission,
			'callback'            => static function ( $request ) {
				$body   = (array) $request->get_json_params();
				$source = isset( $body['source'] ) ? (string) $body['source'] : '';
				$result = \TokenForge\Scss::compile( $source );

				return rest_ensure_response(
					array(
						'ok'    => null === $result['error'],
						'css'   => $result['css'],
						'error' => $result['error'],
					)
				);
			},
		)
	);

	register_rest_route(
		'tokenforge-crucible/v1',
		'/css-version',
		array(
			'methods'             => 'GET',
			'permission_callback' => $permission,
			'callback'            => static function () {
				$generator = \TokenForge\Css_Generator::get_instance();

				return rest_ensure_response(
					array(
						'ver' => $generator->version(),
						'url' => $generator->css_url(),
					)
				);
			},
		)
	);

	register_rest_route(
		'tokenforge-crucible/v1',
		'/cheatsheet',
		array(
			'methods'             => 'GET',
			'permission_callback' => $permission,
			'callback'            => static function () {
				return rest_ensure_response( array( 'groups' => \TokenForge\Cheatsheet::data() ) );
			},
		)
	);

	register_rest_route(
		'tokenforge-crucible/v1',
		'/scss-save',
		array(
			'methods'             => 'POST',
			'permission_callback' => $permission,
			'callback'            => static function ( $request ) {
				$body = (array) $request->get_json_params();

				if ( ! isset( $body['source'] ) || ! is_string( $body['source'] ) ) {
					return rest_ensure_response( array( 'ok' => false, 'error' => 'No source.' ) );
				}

				$source  = str_replace( "\r\n", "\n", $body['source'] );
				$current = (string) get_option( tokenforge_crucible_scss_option(), '' );

				if ( empty( $body['force'] ) && isset( $body['rev'] ) && md5( $current ) !== $body['rev'] && $source !== $current ) {
					return rest_ensure_response(
						array(
							'ok'       => false,
							'conflict' => true,
							'source'   => $current,
							'rev'      => md5( $current ),
						)
					);
				}

				update_option( tokenforge_crucible_scss_option(), $source, false );
				$sync      = TokenForgeCrucible\Scss_Sync::push();
				$generator = \TokenForge\Css_Generator::get_instance();
				$generator->generate();

				return rest_ensure_response(
					array(
						'ok'          => true,
						'rev'         => md5( $source ),
						'source'      => $source,
						'status'      => tokenforge_crucible_scss_status( $source ),
						'css_ver'     => $generator->version(),
						'css_url'     => $generator->css_url(),
						'synced'      => $sync['classes'],
						'sync_errors' => $sync['errors'],
					)
				);
			},
		)
	);

	register_rest_route(
		'tokenforge-crucible/v1',
		'/scss-live',
		array(
			'methods'             => 'POST',
			'permission_callback' => $permission,
			'callback'            => static function ( $request ) {
				$body = (array) $request->get_json_params();

				if ( ! TokenForgeCrucible\Scss_Sync::enabled() || ! isset( $body['source'] ) || ! is_string( $body['source'] ) ) {
					return rest_ensure_response( array( 'ok' => false ) );
				}

				$source = $body['source'];
				$list   = array();
				$items  = isset( $body['classes'] ) && is_array( $body['classes'] ) ? array_slice( $body['classes'], 0, 300 ) : array();

				foreach ( $items as $item ) {
					if ( ! is_array( $item ) || ! isset( $item['name'] ) || ! is_string( $item['name'] ) ) {
						continue;
					}
					$list[] = array(
						'name'     => $item['name'],
						'settings' => isset( $item['settings'] ) && is_array( $item['settings'] ) ? $item['settings'] : array(),
						'old'      => isset( $item['old'] ) && is_array( $item['old'] ) ? $item['old'] : array(),
					);
				}

				if ( isset( $body['mode'] ) && 'pull' === $body['mode'] ) {
					$out = TokenForgeCrucible\Scss_Sync::live_pull( $source, $list );

					return rest_ensure_response( array( 'ok' => true, 'source' => $out !== $source ? $out : null ) );
				}

				$r = TokenForgeCrucible\Scss_Sync::push( array( 'source' => $source, 'classes' => $list, 'dry' => true ) );

				return rest_ensure_response( array( 'ok' => true, 'classes' => $r['classes'], 'errors' => $r['errors'] ) );
			},
		)
	);
}
add_action( 'rest_api_init', 'tokenforge_crucible_register_dock_routes' );
