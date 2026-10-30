<?php
/**
 * Enhanced SCSS option and filter.
 *
 * @package TokenForge_Crucible
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the option name that stores the enhanced SCSS.
 *
 * @return string
 */
function tokenforge_crucible_scss_option() {
	return 'tokenforge_enhanced_scss';
}

/**
 * Appends the enhanced SCSS from the option to the SCSS passed in by TokenForge.
 *
 * @param string $scss SCSS passed in by TokenForge.
 * @return string
 */
function tokenforge_crucible_scss_source( $scss ) {
	if ( ! is_string( $scss ) ) {
		$scss = '';
	}

	$enhanced = get_option( tokenforge_crucible_scss_option(), '' );

	if ( ! is_string( $enhanced ) || '' === trim( $enhanced ) ) {
		return $scss;
	}

	// The value is SCSS compiled by TokenForge, so it must not be escaped here.
	return $scss . "\n" . $enhanced;
}
