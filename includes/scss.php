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

	if ( ! tokenforge_crucible_scss_enabled() ) {
		return $scss;
	}

	$enhanced = get_option( tokenforge_crucible_scss_option(), '' );

	if ( ! is_string( $enhanced ) || '' === trim( $enhanced ) ) {
		return $scss;
	}

	// Lines like @use "name"; become the saved file of that name. With a loop or a wrong line the text
	// comes back as it was, which does not compile (files cannot be imported), so the last working CSS stays.
	$bundle = tokenforge_crucible_bundle( $enhanced );

	// A bundle over the size a compile accepts is not compiled either: the same way out.
	if ( strlen( $bundle['scss'] ) > TOKENFORGE_CRUCIBLE_SCSS_BUNDLE_MAX_BYTES ) {
		return $scss . "\n" . $enhanced;
	}

	// The value is SCSS compiled by TokenForge, so it must not be escaped here.
	return $scss . "\n" . $bundle['scss'];
}

/**
 * A history snapshot before a save, one for each burst of saves: none when the newest snapshot
 * is a save by the same user less than 5 minutes old. TokenForge keeps 20 snapshots, and a copy
 * on every Ctrl+S would push its own history out.
 */
function tokenforge_crucible_snapshot() {
	$list = \TokenForge\History::index();
	$last = $list ? $list[0] : array();
	if ( isset( $last['trigger'], $last['time'], $last['user'] ) && 'save' === $last['trigger'] && get_current_user_id() === (int) $last['user'] && time() - (int) $last['time'] < 5 * MINUTE_IN_SECONDS ) {
		return;
	}
	\TokenForge\History::snapshot( 'save' );
}

/**
 * Is the Global SCSS switched on? (Default on, as in 0.53.7; independent of the sync.)
 *
 * @return bool
 */
function tokenforge_crucible_scss_enabled() {
	return (bool) get_option( 'tokenforge_crucible_scss_enabled', true );
}
