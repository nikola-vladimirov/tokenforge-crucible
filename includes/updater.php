<?php
/**
 * Updates from GitHub: the latest release of the public repository (tag vX.Y.Z with the plugin zip
 * attached, published by .github/workflows/release.yml) shows up in Dashboard > Updates and on the
 * Plugins screen like any other update, auto-updates included. Needs the Update URI header.
 *
 * @package TokenForge_Crucible
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TOKENFORGE_CRUCIBLE_REPO_API', 'https://api.github.com/repos/nikola-vladimirov/tokenforge-crucible' );

/**
 * The latest release with its zip, or null. Cached for six hours, so the unauthenticated
 * GitHub limit (60 requests an hour) is never near.
 *
 * @return array|null
 */
function tokenforge_crucible_release() {
	$cached = get_site_transient( 'tokenforge_crucible_release' );
	if ( is_array( $cached ) ) {
		return $cached['release'];
	}

	$response = wp_remote_get(
		TOKENFORGE_CRUCIBLE_REPO_API . '/releases/latest',
		array(
			'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			'timeout' => 15,
		)
	);
	$found    = null;
	$data     = 200 === (int) wp_remote_retrieve_response_code( $response ) ? json_decode( (string) wp_remote_retrieve_body( $response ), true ) : null;

	// Only a zip from this repository's own releases is offered as the update package.
	$downloads = str_replace( 'https://api.github.com/repos/', 'https://github.com/', TOKENFORGE_CRUCIBLE_REPO_API ) . '/releases/download/';
	if ( is_array( $data ) && ! empty( $data['tag_name'] ) ) {
		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			if ( isset( $asset['name'], $asset['browser_download_url'] ) && '.zip' === substr( $asset['name'], -4 ) && 0 === strpos( (string) $asset['browser_download_url'], $downloads ) ) {
				$found = array(
					'version' => ltrim( (string) $data['tag_name'], 'v' ),
					'url'     => (string) $data['html_url'],
					'package' => (string) $asset['browser_download_url'],
					'notes'   => (string) $data['body'],
					'date'    => (string) $data['published_at'],
				);
				break;
			}
		}
	}

	// A failed request is cached for an hour only, so a GitHub hiccup does not hide an update for long.
	set_site_transient( 'tokenforge_crucible_release', array( 'release' => $found ), $found ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
	return $found;
}

/**
 * The update for this plugin, read by WordPress through the Update URI hostname.
 *
 * @param array|false $update      The update so far.
 * @param array       $plugin_data Plugin headers.
 * @param string      $plugin_file Plugin basename.
 * @return array|false
 */
function tokenforge_crucible_update_check( $update, $plugin_data, $plugin_file ) {
	if ( plugin_basename( TOKENFORGE_CRUCIBLE_FILE ) !== $plugin_file ) {
		return $update;
	}
	$release = tokenforge_crucible_release();
	return $release ? array(
		'slug'    => 'tokenforge-crucible',
		'version' => $release['version'],
		'url'     => $release['url'],
		'package' => $release['package'],
		'icons'   => array(
			'1x' => plugins_url( 'assets/icon-128x128.png', TOKENFORGE_CRUCIBLE_FILE ),
			'2x' => plugins_url( 'assets/icon-256x256.png', TOKENFORGE_CRUCIBLE_FILE ),
		),
	) : $update;
}
add_filter( 'update_plugins_github.com', 'tokenforge_crucible_update_check', 10, 3 );

/**
 * The Description section of the installed readme.txt as HTML, for the details window.
 *
 * @return string Escaped HTML; '' when the readme has no Description.
 */
function tokenforge_crucible_readme_description() {
	$readme = (string) @file_get_contents( plugin_dir_path( TOKENFORGE_CRUCIBLE_FILE ) . 'readme.txt' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file of this plugin.
	if ( ! preg_match( '/^== Description ==\s*$(.*?)^== /ms', str_replace( "\r\n", "\n", $readme ), $m ) ) {
		return '';
	}
	$html = '';
	$list = array();
	foreach ( explode( "\n", trim( $m[1] ) ) as $line ) {
		if ( 0 === strpos( $line, '* ' ) ) {
			$list[] = '<li>' . esc_html( substr( $line, 2 ) ) . '</li>';
			continue;
		}
		if ( $list ) {
			$html .= '<ul>' . implode( '', $list ) . '</ul>';
			$list  = array();
		}
		$html .= '' === trim( $line ) ? '' : '<p>' . esc_html( $line ) . '</p>';
	}
	return $html . ( $list ? '<ul>' . implode( '', $list ) . '</ul>' : '' );
}

/**
 * "View version details" on the update row: the description, the release notes, the banner and icon.
 *
 * @param false|object|array $result The result so far.
 * @param string             $action The API action.
 * @param object             $args   The request.
 * @return false|object|array
 */
function tokenforge_crucible_update_details( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || 'tokenforge-crucible' !== ( $args->slug ?? '' ) ) {
		return $result;
	}
	$release = tokenforge_crucible_release();
	if ( ! $release ) {
		return $result;
	}
	return (object) array(
		'name'          => 'TokenForge Crucible',
		'slug'          => 'tokenforge-crucible',
		'version'       => $release['version'],
		'homepage'      => $release['url'],
		'last_updated'  => $release['date'],
		'download_link' => $release['package'],
		'banners'       => array(
			'low'  => plugins_url( 'assets/banner-772x250.png', TOKENFORGE_CRUCIBLE_FILE ),
			'high' => plugins_url( 'assets/banner-1544x500.png', TOKENFORGE_CRUCIBLE_FILE ),
		),
		'icons'         => array(
			'1x' => plugins_url( 'assets/icon-128x128.png', TOKENFORGE_CRUCIBLE_FILE ),
			'2x' => plugins_url( 'assets/icon-256x256.png', TOKENFORGE_CRUCIBLE_FILE ),
		),
		'sections'      => array(
			'description' => tokenforge_crucible_readme_description(),
			'changelog'   => '<pre style="white-space:pre-wrap">' . esc_html( $release['notes'] ) . '</pre>',
		),
	);
}
add_filter( 'plugins_api', 'tokenforge_crucible_update_details', 10, 3 );

/**
 * A finished update or a manual "Check again" reads the latest release afresh.
 *
 * @return void
 */
function tokenforge_crucible_update_flush() {
	delete_site_transient( 'tokenforge_crucible_release' );
}
add_action( 'upgrader_process_complete', 'tokenforge_crucible_update_flush' );
add_action( 'delete_site_transient_update_plugins', 'tokenforge_crucible_update_flush' );
