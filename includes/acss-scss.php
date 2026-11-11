<?php
/**
 * Imports Automatic.css custom SCSS into the Global SCSS when TokenForge imports ACSS settings.
 *
 * @package TokenForge_Crucible
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const TOKENFORGE_CRUCIBLE_ACSS_MARK = '/* --- Automatic.css custom SCSS --- */';

/**
 * Reads the ACSS custom SCSS (uploads file, then the custom-global-css setting), as 0.53.7 did.
 *
 * @return string
 */
function tokenforge_crucible_acss_code() {
	$up   = wp_get_upload_dir();
	$file = trailingslashit( $up['basedir'] ) . 'automatic-css/automatic-custom-css.scss';
	$code = '';
	if ( file_exists( $file ) ) {
		$code = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$code = preg_replace( '#^[ \t]*//[ \t]*(Load SCSS Powers|Add Your Code Below)[ \t]*$#mi', '', $code );
		// Automatic.css's own import of its generated mixins; TokenForge has no such file, so it must not reach the compiler.
		$code = preg_replace( '#^[ \t]*@import[ \t]+["\']automatic-imports["\'];?[ \t]*$#mi', '', $code );
	}
	if ( '' === trim( $code ) ) {
		$acss = get_option( 'automatic_css_settings', array() );
		$code = ( is_array( $acss ) && ! empty( $acss['custom-global-css'] ) && is_string( $acss['custom-global-css'] ) ) ? $acss['custom-global-css'] : '';
	}
	return trim( str_replace( "\r\n", "\n", $code ) );
}

/**
 * After a successful TokenForge ACSS import, appends (or refreshes) the ACSS block below the user's SCSS.
 *
 * @param WP_HTTP_Response $result  Response.
 * @param WP_REST_Server   $server  Server.
 * @param WP_REST_Request  $request Request.
 * @return WP_HTTP_Response
 */
function tokenforge_crucible_acss_after_import( $result, $server, $request ) {
	$data = $result instanceof WP_HTTP_Response ? $result->get_data() : null;
	if ( '/tokenforge/v1/import-acss' !== $request->get_route() || ! is_array( $data ) || empty( $data['ok'] ) ) {
		return $result;
	}
	// TokenForge's ACSS import only needs manage_options; writing CSS on multisite also needs edit_css.
	if ( is_multisite() && ! current_user_can( 'edit_css' ) ) {
		return $result;
	}
	$code = tokenforge_crucible_acss_code();
	if ( '' === $code ) {
		return $result;
	}
	$source = str_replace( "\r\n", "\n", (string) get_option( tokenforge_crucible_scss_option(), '' ) ); // Same line endings as the imported code, so an unedited block still matches.
	$mark   = TOKENFORGE_CRUCIBLE_ACSS_MARK;
	$pos    = strpos( $source, $mark );
	$user   = false === $pos ? rtrim( $source ) : rtrim( substr( $source, 0, $pos ) ); // Everything above the mark is the user's.
	$after  = '';
	if ( false !== $pos ) {
		$below = trim( substr( $source, $pos + strlen( $mark ) ) );
		$last  = trim( (string) get_option( 'tokenforge_crucible_acss_last', '' ) );
		// The block no longer starts with the last import, so the user edited it (or no import was recorded): their version wins, no refresh.
		if ( '' !== $below && ( '' === $last || 0 !== strpos( $below, $last ) ) ) {
			return $result;
		}
		// Text typed after the unedited block is the user's: it stays after the block, so its overrides still win.
		$after = trim( substr( $below, strlen( $last ) ) );
	}
	$new = ( '' === $user ? '' : $user . "\n\n" ) . $mark . "\n" . $code . "\n" . ( '' === $after ? '' : "\n" . $after . "\n" );
	if ( strlen( $new ) > TOKENFORGE_CRUCIBLE_SCSS_MAX_BYTES ) {
		return $result; // Over the cap the SCSS could not compile or be imported back; leave it as it is.
	}
	update_option( 'tokenforge_crucible_acss_last', $code, false );
	if ( $new !== $source ) {
		update_option( tokenforge_crucible_scss_option(), $new, false );
		\TokenForgeCrucible\Scss_Sync::push();
		\TokenForge\Css_Generator::get_instance()->generate();
	}
	return $result;
}
add_filter( 'rest_post_dispatch', 'tokenforge_crucible_acss_after_import', 10, 3 );
