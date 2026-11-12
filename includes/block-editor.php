<?php
/**
 * Crucible in the block editor: the Global SCSS dock, the window sections and
 * the Global SCSS classes in TokenForge's class field.
 *
 * @package TokenForge_Crucible
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the window sections and the dock where TokenForge loads its editor script.
 *
 * Runs after TokenForge's own callbacks on this action (the window, priority 10).
 */
function tokenforge_crucible_blocks_enqueue() {
	// Only does something when the TokenForge window is loaded here (administrators, window switch on).
	tokenforge_crucible_window_enqueue();

	if ( ! current_user_can( 'manage_options' ) || ! tokenforge_crucible_scss_enabled() ) {
		return;
	}
	tokenforge_crucible_dock_assets( 'blocks' );
}
add_action( 'tokenforge_block_editor_enqueue', 'tokenforge_crucible_blocks_enqueue', 20 );

/**
 * The classes in compiled CSS, each once, with the declarations of its plain rule as a preview.
 *
 * @param string $css Compiled CSS.
 * @return array Rows [ class name, preview ].
 */
function tokenforge_crucible_css_classes( $css ) {
	$css  = (string) preg_replace( '~/\*.*?\*/~s', '', (string) $css );
	$rows = array();
	if ( ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER ) ) {
		return array();
	}
	foreach ( $rules as $rule ) {
		// Attribute values and strings can hold dots that are not classes.
		$selector = (string) preg_replace( '/\[[^\]]*\]|"[^"]*"|\'[^\']*\'/', '', $rule[1] );
		if ( ! preg_match_all( '/\.(-?[_a-zA-Z][\w\-]*)/', $selector, $names ) ) {
			continue;
		}
		foreach ( $names[1] as $name ) {
			if ( ! isset( $rows[ $name ] ) ) {
				$rows[ $name ] = '';
			}
			if ( '' === $rows[ $name ] && trim( $rule[1] ) === '.' . $name ) {
				$preview       = trim( (string) preg_replace( '/\s+/', ' ', $rule[2] ) );
				$rows[ $name ] = strlen( $preview ) > 80 ? substr( $preview, 0, 77 ) . '...' : $preview;
			}
		}
	}
	$out = array();
	foreach ( $rows as $name => $preview ) {
		$out[] = array( (string) $name, $preview );
	}
	return $out;
}

/**
 * Adds the classes of the saved Global SCSS to the block editor's class field.
 *
 * @param array $rows Rows [ class name, preview ] from TokenForge.
 * @return array
 */
function tokenforge_crucible_editor_classes( $rows ) {
	$rows = is_array( $rows ) ? $rows : array();
	if ( ! tokenforge_crucible_scss_enabled() || ! class_exists( '\TokenForge\Settings' ) ) {
		return $rows;
	}
	$settings = \TokenForge\Settings::get_instance()->get_settings();
	$source   = get_option( tokenforge_crucible_scss_option(), '' );
	if ( empty( $settings['block_class_field'] ) || ! is_string( $source ) || '' === trim( $source ) ) {
		return $rows;
	}

	// Compiling is slow: once per saved source and per TokenForge stylesheet (classes built from its tokens change with it).
	$sig    = md5( TOKENFORGE_CRUCIBLE_VERSION . '|' . \TokenForge\Css_Generator::get_instance()->version() . '|' . $source );
	$cached = get_transient( 'tokenforge_crucible_editor_classes' );
	if ( is_array( $cached ) && isset( $cached['sig'], $cached['rows'] ) && $sig === $cached['sig'] && is_array( $cached['rows'] ) ) {
		$mine = $cached['rows'];
	} else {
		$compiled = tokenforge_crucible_compile( $source );
		$mine     = empty( $compiled['error'] ) ? tokenforge_crucible_css_classes( $compiled['css'] ) : array();
		set_transient(
			'tokenforge_crucible_editor_classes',
			array(
				'sig'  => $sig,
				'rows' => $mine,
			),
			WEEK_IN_SECONDS
		);
	}

	$have = array();
	foreach ( $rows as $row ) {
		if ( is_array( $row ) && isset( $row[0] ) ) {
			$have[ $row[0] ] = true;
		}
	}
	foreach ( $mine as $row ) {
		if ( is_array( $row ) && isset( $row[0], $row[1] ) && ! isset( $have[ $row[0] ] ) ) {
			$rows[] = array( (string) $row[0], (string) $row[1] );
		}
	}
	return $rows;
}
add_filter( 'tokenforge_editor_classes', 'tokenforge_crucible_editor_classes' );
