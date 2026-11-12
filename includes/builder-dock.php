<?php
/**
 * Loads the SCSS dock inside the builder.
 *
 * @package TokenForge_Crucible
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the dock assets in the Bricks builder for admins.
 */
function tokenforge_crucible_dock_enqueue() {
	if ( ! class_exists( '\TokenForge\Platforms\Bricks' )
		|| ! \TokenForge\Platforms\Bricks::is_active()
		|| ! \TokenForge\Platforms\Bricks::is_builder_context()
		|| ! current_user_can( 'manage_options' ) ) {
		return;
	}
	tokenforge_crucible_dock_assets();
}
add_action( 'wp_enqueue_scripts', 'tokenforge_crucible_dock_enqueue', 12 );

/**
 * Enqueues the dock scripts and style with their settings.
 *
 * @param string $platform '' for the builder, 'blocks' for the block editor (includes/block-editor.php).
 */
function tokenforge_crucible_dock_assets( $platform = '' ) {
	$base = plugin_dir_url( TOKENFORGE_CRUCIBLE_FILE );
	$ver  = TOKENFORGE_CRUCIBLE_VERSION;
	$deps = array( 'tokenforge-crucible-scss-editor', 'tokenforge-crucible-builder-classes' );
	$cfg  = array(
		'restUrl'  => esc_url_raw( rest_url( 'tokenforge-crucible/v1' ) ),
		'tfRest'   => esc_url_raw( rest_url( 'tokenforge/v1' ) ),
		'nonce'    => wp_create_nonce( 'wp_rest' ),
		'shortcut' => 'alt+shift+s',
		'tab'      => true,
		'marker'   => '/* --- Global SCSS --- */',
	);
	if ( 'blocks' === $platform ) {
		// The dock reads TokenForgeEditor (the canvas) and wp.hooks (the class field) from TokenForge's editor script.
		$deps[]          = 'tokenforge-editor';
		$cfg['platform'] = 'blocks';
	}

	wp_enqueue_script( 'tokenforge-crucible-scss-outline', $base . 'assets/admin/scss-outline.js', array(), $ver, true );
	wp_enqueue_script( 'tokenforge-crucible-scss-editor', $base . 'assets/admin/scss-editor.js', array( 'tokenforge-crucible-scss-outline' ), $ver, true );
	wp_enqueue_style( 'tokenforge-crucible-scss-dock', $base . 'assets/builder/scss-dock.css', array(), $ver );
	wp_enqueue_script( 'tokenforge-crucible-builder-classes', plugin_dir_url( TOKENFORGE_CRUCIBLE_FILE ) . 'assets/builder/builder-classes.js', array(), TOKENFORGE_CRUCIBLE_VERSION, true );
	wp_enqueue_script( 'tokenforge-crucible-scss-dock', $base . 'assets/builder/scss-dock.js', $deps, $ver, true );

	wp_localize_script( 'tokenforge-crucible-scss-dock', 'TokenForgeDockCfg', $cfg );
}
