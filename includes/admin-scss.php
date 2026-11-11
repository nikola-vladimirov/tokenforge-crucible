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
	$code_editor = false;
	if ( function_exists( 'wp_enqueue_code_editor' ) ) {
		$code_editor = wp_enqueue_code_editor(
			array(
				'type'       => 'text/x-scss',
				'codemirror' => array(
					'indentUnit'     => 4,
					'tabSize'        => 4,
					'indentWithTabs' => true,
				),
			)
		);
	}

	wp_enqueue_script( 'tokenforge-crucible-scss-outline', $url . 'scss-outline.js', array(), TOKENFORGE_CRUCIBLE_VERSION, true );
	wp_enqueue_script( 'tokenforge-crucible-scss-editor', $url . 'scss-editor.js', array( 'tokenforge-crucible-scss-outline' ), TOKENFORGE_CRUCIBLE_VERSION, true );
	wp_enqueue_script( 'tokenforge-crucible-sections', $url . 'crucible-sections.js', array( 'wp-hooks', 'wp-i18n', 'tokenforge-crucible-scss-editor' ), TOKENFORGE_CRUCIBLE_VERSION, true );
	wp_set_script_translations( 'tokenforge-crucible-sections', 'tokenforge-crucible', plugin_dir_path( TOKENFORGE_CRUCIBLE_FILE ) . 'languages' );

	wp_localize_script(
		'tokenforge-crucible-sections',
		'TokenForgeCrucible',
		array(
			'rest'       => esc_url_raw( rest_url( 'tokenforge-crucible/v1' ) ),
			'tfRest'     => esc_url_raw( rest_url( 'tokenforge/v1' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'codeEditor' => $code_editor, // wp.codeEditor settings, or false when the user turned syntax highlighting off.
		)
	);
}

// Priority 1: this script must be enqueued before TokenForge's admin script, which reads the filters at load.
add_action( 'admin_enqueue_scripts', 'tokenforge_crucible_scss_enqueue', 1 );

/**
 * Enqueues the Global SCSS section in the TokenForge window outside wp-admin (the front
 * end and the builder), wherever TokenForge loads its dashboard script.
 *
 * Runs after TokenForge's own enqueue (priority 12) and makes the dashboard script depend
 * on this one, so the section filters exist before the dashboard builds its navigation.
 */
function tokenforge_crucible_window_enqueue() {
	$scripts = wp_scripts();
	if ( ! wp_script_is( 'tokenforge-dashboard', 'enqueued' ) || ! isset( $scripts->registered['tokenforge-dashboard'] ) ) {
		return;
	}

	$url = plugin_dir_url( TOKENFORGE_CRUCIBLE_FILE ) . 'assets/admin/';
	$css = add_query_arg( 'ver', TOKENFORGE_CRUCIBLE_VERSION, $url . 'crucible-admin.css' );
	// On the front end the window is a shadow root: the script adds the stylesheet there itself.
	if ( wp_style_is( 'tokenforge-admin', 'enqueued' ) ) {
		wp_enqueue_style( 'tokenforge-crucible-admin', $url . 'crucible-admin.css', array( 'tokenforge-admin' ), TOKENFORGE_CRUCIBLE_VERSION );
		$css = '';
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
			'css'    => $css ? esc_url_raw( $css ) : '',
		)
	);
	if ( ! in_array( 'tokenforge-crucible-sections', $scripts->registered['tokenforge-dashboard']->deps, true ) ) {
		$scripts->registered['tokenforge-dashboard']->deps[] = 'tokenforge-crucible-sections';
	}
}
add_action( 'wp_enqueue_scripts', 'tokenforge_crucible_window_enqueue', 13 );
