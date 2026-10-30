<?php
/**
 * Plugin Name:       TokenForge Crucible
 * Plugin URI:        https://pristadigital.com
 * Description:       Add-on for TokenForge that restores Global SCSS, custom recipes and builder sync.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Prista Digital
 * Author URI:        https://pristadigital.com
 * License:           GPL-2.0-or-later
 * Text Domain:       tokenforge-crucible
 * Domain Path:       /languages
 * Requires Plugins:  tokenforge
 *
 * @package TokenForge_Crucible
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TOKENFORGE_CRUCIBLE_VERSION', '0.2.0' );
define( 'TOKENFORGE_CRUCIBLE_FILE', __FILE__ );

// TokenForge 0.54.1 is the first build with the wp.hooks dashboard filters Crucible uses.
define( 'TOKENFORGE_CRUCIBLE_MIN_TOKENFORGE_VERSION', '0.54.1' );

/**
 * Checks whether the active TokenForge version can run this add-on.
 *
 * @return bool
 */
function tokenforge_crucible_requirements_met() {
	return defined( 'TOKENFORGE_VERSION' ) && version_compare( TOKENFORGE_VERSION, TOKENFORGE_CRUCIBLE_MIN_TOKENFORGE_VERSION, '>=' );
}

/**
 * Stops activation when TokenForge is missing or too old.
 */
function tokenforge_crucible_activate() {
	if ( tokenforge_crucible_requirements_met() ) {
		return;
	}

	deactivate_plugins( plugin_basename( TOKENFORGE_CRUCIBLE_FILE ), true );

	wp_die(
		sprintf(
			/* translators: %s: Minimum required TokenForge version. */
			esc_html__( 'TokenForge Crucible requires TokenForge %s or later. Please install or update TokenForge, then activate TokenForge Crucible again.', 'tokenforge-crucible' ),
			esc_html( TOKENFORGE_CRUCIBLE_MIN_TOKENFORGE_VERSION )
		)
	);
}
register_activation_hook( TOKENFORGE_CRUCIBLE_FILE, 'tokenforge_crucible_activate' );

/**
 * Shows an admin error when TokenForge is missing or too old.
 */
function tokenforge_crucible_admin_notice() {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		sprintf(
			/* translators: %s: Minimum required TokenForge version. */
			esc_html__( 'TokenForge Crucible requires TokenForge %s or later. TokenForge Crucible features are disabled until TokenForge is updated.', 'tokenforge-crucible' ),
			esc_html( TOKENFORGE_CRUCIBLE_MIN_TOKENFORGE_VERSION )
		)
	);
}

/**
 * Boots the add-on features.
 */
function tokenforge_crucible_boot() {
	// Features load in later milestones; this is the first.
	require_once __DIR__ . '/includes/scss.php';
	add_filter( 'tokenforge_global_scss', 'tokenforge_crucible_scss_source' );
	require_once __DIR__ . '/includes/admin-scss.php';
	require_once __DIR__ . '/includes/scss-settings.php';
	require_once __DIR__ . '/includes/scss-sync.php';
	require_once __DIR__ . '/includes/rest-scss.php';
	require_once __DIR__ . '/includes/acss-scss.php';
	require_once __DIR__ . '/includes/builder-dock.php';
	require_once __DIR__ . '/includes/recipes.php';

	tokenforge_crucible_scss_migrate();

	// Builder <-> Global SCSS sync, as TokenForge 0.53.7 started it (Bricks only).
	if ( \TokenForge\Platforms\Bricks::is_active() ) {
		new \TokenForgeCrucible\Scss_Sync();
	}
}

/**
 * Loads the plugin text domain.
 */
function tokenforge_crucible_load_textdomain() {
	load_plugin_textdomain(
		'tokenforge-crucible',
		false,
		dirname( plugin_basename( TOKENFORGE_CRUCIBLE_FILE ) ) . '/languages'
	);
}
add_action( 'init', 'tokenforge_crucible_load_textdomain' );

/**
 * Starts the add-on or registers the requirement notice.
 */
function tokenforge_crucible_init() {
	if ( ! tokenforge_crucible_requirements_met() ) {
		add_action( 'admin_notices', 'tokenforge_crucible_admin_notice' );
		return;
	}

	tokenforge_crucible_boot();
}
add_action( 'plugins_loaded', 'tokenforge_crucible_init' );
