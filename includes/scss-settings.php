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
	$settings['sync']        = isset( $sync['sync'] ) ? (bool) $sync['sync'] : false;
	$settings['sync_pull']   = isset( $sync['sync_pull'] ) ? (bool) $sync['sync_pull'] : true;
	$settings['sync_create'] = isset( $sync['sync_create'] ) ? (bool) $sync['sync_create'] : false;

	return $settings;
}
