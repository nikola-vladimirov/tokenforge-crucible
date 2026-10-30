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
	$code = tokenforge_crucible_acss_code();
	if ( '' === $code ) {
		return $result;
	}
	$source = (string) get_option( tokenforge_crucible_scss_option(), '' );
	$mark   = TOKENFORGE_CRUCIBLE_ACSS_MARK;
	$pos    = strpos( $source, $mark );
	$user   = false === $pos ? rtrim( $source ) : rtrim( substr( $source, 0, $pos ) ); // The block is always last, so everything above is the user's.
	$new    = ( '' === $user ? '' : $user . "\n\n" ) . $mark . "\n" . $code . "\n";
	if ( $new !== $source ) {
		update_option( tokenforge_crucible_scss_option(), $new, false );
		\TokenForgeCrucible\Scss_Sync::push();
		\TokenForge\Css_Generator::get_instance()->generate();
	}
	return $result;
}
add_filter( 'rest_post_dispatch', 'tokenforge_crucible_acss_after_import', 10, 3 );
