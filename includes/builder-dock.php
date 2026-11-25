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
 * Loads the dock in the other builders TokenForge supports, for admins with the Global SCSS on.
 *
 * Bricks keeps its own path above, so it gets no second dock here.
 *
 * @param string $builder The builder TokenForge detected (etch, oxygen, breakdance, elementor, divi).
 */
function tokenforge_crucible_builder_enqueue( $builder ) {
	$builder = is_string( $builder ) ? sanitize_key( $builder ) : '';
	if ( '' === $builder || 'bricks' === $builder || ! current_user_can( 'manage_options' ) || ! tokenforge_crucible_scss_enabled() ) {
		return;
	}
	tokenforge_crucible_dock_assets( 'builder', $builder );
}
add_action( 'tokenforge_builder_enqueue', 'tokenforge_crucible_builder_enqueue' );

/** The first TokenForge with the builder hook the dock needs outside Bricks and the block editor. */
const TOKENFORGE_CRUCIBLE_BUILDER_HOOK_VERSION = '0.58.0';

/**
 * Names the builder the dock cannot reach with this TokenForge, or ''.
 *
 * @param string  $tf_version TokenForge version.
 * @param array[] $detected   From \TokenForge\Platforms\Platforms::detected() (id, label, active).
 * @return string The builder's label, or '' when nothing is missing.
 */
function tokenforge_crucible_builder_hook_missing( $tf_version, $detected ) {
	if ( version_compare( $tf_version, TOKENFORGE_CRUCIBLE_BUILDER_HOOK_VERSION, '>=' ) ) {
		return '';
	}
	foreach ( (array) $detected as $p ) {
		if ( ! empty( $p['active'] ) && in_array( $p['id'] ?? '', array( 'etch', 'oxygen', 'breakdance', 'elementor', 'divi' ), true ) ) {
			return (string) $p['label'];
		}
	}
	return '';
}

/**
 * Tells admins, on the plugins and TokenForge screens, when the dock cannot load in their builder.
 */
function tokenforge_crucible_builder_hook_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! current_user_can( 'manage_options' ) || ! defined( 'TOKENFORGE_VERSION' ) || ! tokenforge_crucible_scss_enabled()
		|| ( 'plugins' !== $screen->id && false === strpos( (string) $screen->id, 'tokenforge' ) )
		|| ! is_callable( array( '\TokenForge\Platforms\Platforms', 'detected' ) ) ) {
		return;
	}
	$label = tokenforge_crucible_builder_hook_missing( TOKENFORGE_VERSION, \TokenForge\Platforms\Platforms::detected() );
	if ( '' === $label ) {
		return;
	}
	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: builder name, 2: TokenForge version. */
				__( 'The Global SCSS dock needs TokenForge %2$s or later to load in %1$s. Update TokenForge to use it there.', 'tokenforge-crucible' ),
				$label,
				TOKENFORGE_CRUCIBLE_BUILDER_HOOK_VERSION
			)
		)
	);
}
add_action( 'admin_notices', 'tokenforge_crucible_builder_hook_notice' );

/**
 * Enqueues the dock scripts and style with their settings.
 *
 * @param string $platform '' for Bricks, 'blocks' for the block editor (includes/block-editor.php), 'builder' for another builder.
 * @param string $builder  The builder's name, for 'builder'.
 */
function tokenforge_crucible_dock_assets( $platform = '', $builder = '' ) {
	$base = plugin_dir_url( TOKENFORGE_CRUCIBLE_FILE );
	$ver  = TOKENFORGE_CRUCIBLE_VERSION;
	$deps = array( 'tokenforge-crucible-scss-editor', 'tokenforge-crucible-builder-classes', 'tokenforge-crucible-scss-lint' );
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
	} elseif ( 'builder' === $platform ) {
		// The dock reads TokenForgeBuilder (the canvas, the selected element) from TokenForge's builder tools.
		$deps[]          = 'tokenforge-builder';
		$cfg['platform'] = 'builder';
		$cfg['builder']  = $builder;
	}
	// Outside Bricks (which has its own CodeMirror) the dock gets WordPress's code editor; false when the user turned highlighting off.
	// shortcut: not in Oxygen and Breakdance, where TokenForge prints the queue itself and the editor's underscore would replace a global _ there; add them once checked live.
	if ( '' !== $platform && ! in_array( $builder, array( 'oxygen', 'breakdance' ), true ) && function_exists( 'wp_enqueue_code_editor' ) ) {
		$cfg['codeEditor'] = wp_enqueue_code_editor(
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

	wp_enqueue_script( 'tokenforge-crucible-scss-outline', $base . 'assets/admin/scss-outline.js', array(), $ver, true );
	wp_enqueue_script( 'tokenforge-crucible-scss-editor', $base . 'assets/admin/scss-editor.js', array( 'tokenforge-crucible-scss-outline' ), $ver, true );
	// The token lint (findings and fixes), shared with the builder dock.
	wp_enqueue_script( 'tokenforge-crucible-scss-lint', $base . 'assets/admin/scss-lint.js', array( 'wp-i18n' ), $ver, true );
	wp_set_script_translations( 'tokenforge-crucible-scss-lint', 'tokenforge-crucible', plugin_dir_path( TOKENFORGE_CRUCIBLE_FILE ) . 'languages' );
	wp_enqueue_style( 'tokenforge-crucible-scss-dock', $base . 'assets/builder/scss-dock.css', array(), $ver );
	wp_enqueue_script( 'tokenforge-crucible-builder-classes', plugin_dir_url( TOKENFORGE_CRUCIBLE_FILE ) . 'assets/builder/builder-classes.js', array(), TOKENFORGE_CRUCIBLE_VERSION, true );
	wp_enqueue_script( 'tokenforge-crucible-scss-dock', $base . 'assets/builder/scss-dock.js', $deps, $ver, true );

	wp_localize_script( 'tokenforge-crucible-scss-dock', 'TokenForgeDockCfg', $cfg );
}
