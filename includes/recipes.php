<?php
/**
 * Your recipes: the add-on's own recipes, added to TokenForge's list through the
 * tokenforge_recipes filter. A recipe with a built-in name replaces that recipe.
 *
 * @package TokenForge_Crucible
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the saved recipes: a list of [ 'n' => name, 'code' => code ].
 *
 * @return array
 */
function tokenforge_crucible_recipes_saved() {
	$list = get_option( 'tokenforge_crucible_recipes', array() );

	return is_array( $list ) ? $list : array();
}

/**
 * Adds the saved recipes to TokenForge's recipe list.
 *
 * @param array $recipes The recipes TokenForge has so far.
 * @return array
 */
function tokenforge_crucible_recipes_filter( $recipes ) {
	$index = array();
	foreach ( $recipes as $i => $r ) {
		$index[ $r['n'] ] = $i;
	}

	foreach ( tokenforge_crucible_recipes_saved() as $saved ) {
		// Same rule as 0.53.7: one line without braces that is not a declaration ("prop: ...") is a value.
		$code   = (string) $saved['code'];
		$inline = isset( $saved['inline'] )
			? (bool) $saved['inline']
			: ( false === strpos( $code, "\n" ) && false === strpos( $code, '{' ) && ! preg_match( '/^\s*-*[a-z][a-z-]*\s*:/i', $code ) );
		$recipe = array(
			'n'      => $saved['n'],
			'c'      => __( 'Your recipes', 'tokenforge-crucible' ),
			'code'   => $code,
			'scss'   => null,
			'inline' => $inline,
			'kind'   => isset( $saved['kind'] ) && in_array( $saved['kind'], array( 'value', 'block', 'text' ), true ) ? $saved['kind'] : ( $inline ? 'value' : 'block' ),
			'custom' => true,
		);
		if ( isset( $index[ $saved['n'] ] ) ) {
			$recipes[ $index[ $saved['n'] ] ] = $recipe;
		} else {
			$recipes[] = $recipe;
		}
	}

	return $recipes;
}

/**
 * Registers the recipes REST routes.
 */
function tokenforge_crucible_recipes_routes() {
	$permission = function () {
		return current_user_can( 'manage_options' );
	};

	register_rest_route(
		'tokenforge-crucible/v1',
		'/recipes',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => $permission,
				'callback'            => static function () {
					return rest_ensure_response( array( 'recipes' => tokenforge_crucible_recipes_saved() ) );
				},
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => $permission,
				'callback'            => static function ( $request ) {
					$body = (array) $request->get_json_params();
					$list = array();

					foreach ( array_slice( isset( $body['recipes'] ) && is_array( $body['recipes'] ) ? $body['recipes'] : array(), 0, 100 ) as $item ) {
						$name = isset( $item['n'] ) && is_string( $item['n'] ) ? \TokenForge\Recipes::name( $item['n'] ) : '';
						$code = isset( $item['code'] ) && is_string( $item['code'] ) ? substr( str_replace( "\r\n", "\n", $item['code'] ), 0, 4000 ) : '';
						// The first entry with a name wins, as in 0.53.7.
						if ( '' !== $name && '' !== trim( $code ) && ! isset( $list[ $name ] ) ) {
							$list[ $name ] = array( 'n' => $name, 'code' => $code );
						}
					}

					update_option( 'tokenforge_crucible_recipes', array_values( $list ), false );

					return rest_ensure_response( array( 'ok' => true, 'recipes' => array_values( $list ) ) );
				},
			),
		)
	);
}

/**
 * One time: moves the custom recipes an older TokenForge kept in the
 * tokenforge_recipes option (key 'custom') into the add-on's own list.
 */
function tokenforge_crucible_recipes_migrate() {
	if ( get_option( 'tokenforge_crucible_recipes_migrated' ) ) {
		return;
	}
	$old  = get_option( 'tokenforge_recipes', array() );
	$list = tokenforge_crucible_recipes_saved();
	$seen = array_column( $list, 'n' );

	foreach ( is_array( $old ) && isset( $old['custom'] ) && is_array( $old['custom'] ) ? $old['custom'] : array() as $r ) {
		$name = is_array( $r ) && isset( $r['name'] ) ? \TokenForge\Recipes::name( $r['name'] ) : '';
		$code = is_array( $r ) && isset( $r['code'] ) ? (string) $r['code'] : '';
		if ( '' !== $name && '' !== trim( $code ) && ! in_array( $name, $seen, true ) ) {
			$seen[] = $name;
			$list[] = array( 'n' => $name, 'code' => $code );
		}
	}

	update_option( 'tokenforge_crucible_recipes', $list, false );
	update_option( 'tokenforge_crucible_recipes_migrated', 1, false );
}

add_action( 'init', 'tokenforge_crucible_recipes_migrate' );
add_filter( 'tokenforge_recipes', 'tokenforge_crucible_recipes_filter' );
add_action( 'rest_api_init', 'tokenforge_crucible_recipes_routes' );
