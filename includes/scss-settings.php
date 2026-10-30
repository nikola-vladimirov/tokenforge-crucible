<?php
/**
 * Settings used by the SCSS sync.
 *
 * @package TokenForge_Crucible
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the option name that stores the SCSS sync settings.
 *
 * @return string
 */
function tokenforge_crucible_sync_option() {
	return 'tokenforge_crucible_scss_sync';
}

/**
 * Returns the settings the SCSS sync works with.
 *
 * TokenForge's own Scss settings are merged with the add-on's SCSS source and
 * the sync switches, which TokenForge itself no longer has.
 *
 * @return array
 */
function tokenforge_crucible_sync_settings() {
	$settings = array();

	if ( class_exists( '\TokenForge\Scss' ) ) {
		$settings = (array) \TokenForge\Scss::settings();
	}

	$sync = get_option( tokenforge_crucible_sync_option(), array() );
	$sync = is_array( $sync ) ? $sync : array();

	$settings['source']      = (string) get_option( tokenforge_crucible_scss_option(), '' );
	$settings['sync']        = isset( $sync['sync'] ) ? (bool) $sync['sync'] : true;
	$settings['sync_pull']   = isset( $sync['sync_pull'] ) ? (bool) $sync['sync_pull'] : true;
	$settings['sync_create'] = isset( $sync['sync_create'] ) ? (bool) $sync['sync_create'] : false;

	return $settings;
}

/**
 * Saves the sync switches (sync, sync_pull, sync_create); a switch not sent keeps its value.
 *
 * @param array $in Raw input.
 */
function tokenforge_crucible_sync_save( $in ) {
	$now = tokenforge_crucible_sync_settings();
	$out = array();

	foreach ( array( 'sync', 'sync_pull', 'sync_create' ) as $key ) {
		$out[ $key ] = array_key_exists( $key, $in ) ? ! empty( $in[ $key ] ) : $now[ $key ];
	}

	update_option( tokenforge_crucible_sync_option(), $out, false );
}

/**
 * One time: moves the Global SCSS source and the sync switches an older TokenForge
 * kept in the tokenforge_scss option into the add-on's own options. The source is
 * only copied when the add-on has none yet.
 */
function tokenforge_crucible_scss_migrate() {
	if ( get_option( 'tokenforge_crucible_scss_migrated' ) ) {
		return;
	}
	$old = get_option( 'tokenforge_scss', array() );
	$old = is_array( $old ) ? $old : array();

	if ( isset( $old['source'] ) && is_string( $old['source'] ) && '' !== trim( $old['source'] ) && '' === trim( (string) get_option( tokenforge_crucible_scss_option(), '' ) ) ) {
		update_option( tokenforge_crucible_scss_option(), $old['source'], false );
	}
	if ( false === get_option( tokenforge_crucible_sync_option(), false ) && array_intersect_key( $old, array_flip( array( 'sync', 'sync_pull', 'sync_create' ) ) ) ) {
		tokenforge_crucible_sync_save( $old );
	}
	if ( array_key_exists( 'enabled', $old ) && false === get_option( 'tokenforge_crucible_scss_enabled', false ) ) {
		update_option( 'tokenforge_crucible_scss_enabled', ! empty( $old['enabled'] ), false );
	}
	update_option( 'tokenforge_crucible_scss_migrated', 1, false );
}

/**
 * Registers the compile-scss ability (0.53.7) next to TokenForge's own abilities.
 * Only when TokenForge's AI abilities switch is on, since that is when its
 * 'tokenforge' ability category is registered.
 */
function tokenforge_crucible_register_ability() {
	$s = \TokenForge\Settings::get_instance()->get_settings();
	if ( ! function_exists( 'wp_register_ability' ) || empty( $s['ai_abilities'] ) ) {
		return;
	}

	wp_register_ability(
		'tokenforge/compile-scss',
		array(
			'label'               => 'TokenForge: compile SCSS',
			'description'         => 'Compiles SCSS with the TokenForge mixins and functions available (btn, card, center, auto-grid, breakpoint, fluid() ...) and returns plain CSS you can put in an element’s or class’s custom CSS. Nothing is saved.',
			'category'            => 'tokenforge',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'scss' => array( 'type' => 'string', 'description' => 'SCSS source.' ),
				),
				'required'             => array( 'scss' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => static function ( $input = array() ) {
				$r = \TokenForge\Scss::compile( isset( $input['scss'] ) ? (string) $input['scss'] : '' );
				return array( 'ok' => null === $r['error'], 'css' => $r['css'], 'error' => $r['error'] );
			},
			'permission_callback' => static function () {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				'public'       => true,
				'show_in_rest' => true,
				'mcp'          => array( 'public' => true, 'type' => 'tool' ),
			),
		)
	);
}
add_action( 'wp_abilities_api_init', 'tokenforge_crucible_register_ability' );
