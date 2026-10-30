<?php
/**
 * Global SCSS inside the TokenForge dashboard (its own nav entry, no submenu).
 *
 * The dashboard's section list is open through wp.hooks (tokenforge.sections,
 * tokenforge.nav, tokenforge.render). assets/admin/crucible-sections.js uses them.
 *
 * @package TokenForge_Crucible
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the Global SCSS section on the TokenForge dashboard.
 *
 * Runs before TokenForge's own admin script (priority 1), so the section filters
 * are registered before the dashboard builds its navigation.
 *
 * @param string $hook The current admin page hook suffix.
 */
function tokenforge_crucible_scss_enqueue( $hook ) {
	if ( 'toplevel_page_tokenforge' !== $hook ) {
		return;
	}

	$url = plugin_dir_url( TOKENFORGE_CRUCIBLE_FILE ) . 'assets/admin/';

	wp_enqueue_style( 'tokenforge-crucible-admin', $url . 'crucible-admin.css', array( 'tokenforge-admin' ), TOKENFORGE_CRUCIBLE_VERSION );
	// CodeMirror (SCSS mode) when wp-admin has it; the shared editor falls back to the textarea without it.
	if ( function_exists( 'wp_enqueue_code_editor' ) ) {
		wp_enqueue_code_editor( array( 'type' => 'text/x-scss', 'codemirror' => array( 'indentUnit' => 4, 'tabSize' => 4, 'indentWithTabs' => true ) ) );
	}

	wp_enqueue_script( 'tokenforge-crucible-scss-outline', $url . 'scss-outline.js', array(), TOKENFORGE_CRUCIBLE_VERSION, true );
	wp_enqueue_script( 'tokenforge-crucible-scss-editor', $url . 'scss-editor.js', array( 'tokenforge-crucible-scss-outline' ), TOKENFORGE_CRUCIBLE_VERSION, true );
	wp_enqueue_script( 'tokenforge-crucible-sections', $url . 'crucible-sections.js', array( 'wp-hooks', 'wp-i18n', 'tokenforge-crucible-scss-editor' ), TOKENFORGE_CRUCIBLE_VERSION, true );
	wp_set_script_translations( 'tokenforge-crucible-sections', 'tokenforge-crucible', plugin_dir_path( TOKENFORGE_CRUCIBLE_FILE ) . 'languages' );

	wp_localize_script(
		'tokenforge-crucible-sections',
		'TokenForgeCrucible',
		array(
			'rest'   => esc_url_raw( rest_url( 'tokenforge-crucible/v1' ) ),
			'tfRest' => esc_url_raw( rest_url( 'tokenforge/v1' ) ),
			'nonce'  => wp_create_nonce( 'wp_rest' ),
		)
	);
}

// Priority 1: this script must be enqueued before TokenForge's admin script, which reads the filters at load.
add_action( 'admin_enqueue_scripts', 'tokenforge_crucible_scss_enqueue', 1 );
