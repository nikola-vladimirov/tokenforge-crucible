<?php
/**
 * The site scan: one pass over the Global SCSS, the builder options and every post,
 * shared by the reports built from it. Each report is a collector: add() gets every
 * source the scan reads, done() gives its part of the stored site report.
 *
 * @package TokenForge_Crucible
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/css-report.php';

/**
 * The last site report: { time, posts, capped, lint: { total, with_token, by_place, items },
 * classes: { total, compiles, unused, unused_total, dupes: { bricks, scss }, sizes, total_kb } }, or [].
 *
 * @return array
 */
function tokenforge_crucible_site_report() {
	$report = get_option( 'tokenforge_crucible_site_report', array() );

	return is_array( $report ) ? $report : array();
}

/**
 * "Page: Home", "Template: Header" and so on.
 *
 * @param \WP_Post $post Post.
 * @return string
 */
function tokenforge_crucible_post_label( $post ) {
	$type  = get_post_type_object( $post->post_type );
	$title = trim( wp_strip_all_tags( (string) $post->post_title ) );

	/* translators: 1: kind of content (Page, Template and so on), 2: its title */
	return sprintf( __( '%1$s: %2$s', 'tokenforge-crucible' ), $type && isset( $type->labels->singular_name ) ? $type->labels->singular_name : $post->post_type, '' !== $title ? $title : '#' . $post->ID );
}

/**
 * Where a post is edited: in the builder when it is there, else the WordPress editor.
 *
 * @param int $post_id Post ID.
 * @return string URL, or '' when the user cannot edit it.
 */
function tokenforge_crucible_edit_link( $post_id ) {
	$link = is_callable( array( '\Bricks\Helpers', 'get_builder_edit_link' ) ) ? \Bricks\Helpers::get_builder_edit_link( $post_id ) : get_edit_post_link( $post_id, 'raw' );

	return $link ? esc_url_raw( $link ) : '';
}

/**
 * Reads the whole site once and builds the site report.
 *
 * @param bool $store Keep the report (autoload off).
 * @return array See tokenforge_crucible_site_report().
 */
function tokenforge_crucible_site_scan( $store = true ) {
	// A collector: [ add => callable( $key, $value, $place ), done => callable( $capped ) ]. A new report adds its collector here.
	$collectors = array(
		'lint'    => tokenforge_crucible_lint_collector(),
		'classes' => tokenforge_crucible_css_report_collector(),
	);
	$feed       = static function ( $key, $value, $place ) use ( $collectors ) {
		foreach ( $collectors as $c ) {
			$c['add']( $key, $value, $place );
		}
	};

	$files = tokenforge_crucible_scss_files();
	foreach ( tokenforge_crucible_scss_sources() as $id => $source ) {
		$name = 'global' === $id ? '' : (string) substr( $id, 5 );
		// A partial is only read for hard-coded values: its classes are counted in the SCSS that loads it.
		$alone = '' === $name || ( isset( $files[ $name ] ) && ( $files[ $name ]['pages'] || $files[ $name ]['types'] ) );
		$feed(
			$alone ? 'scss' : 'scss-partial',
			$source,
			array(
				/* translators: %s: file name. */
				'place' => '' === $name ? __( 'Global SCSS', 'tokenforge-crucible' ) : sprintf( __( 'SCSS file "%s"', 'tokenforge-crucible' ), $name ),
				'link'  => '',
			)
		);
	}
	// Options: key = the option name. First the ones that say which id stands for which class (the classes report reads the use after them).
	foreach ( array_merge( array( 'bricks_global_classes', 'etch_styles', 'etch_global_stylesheets', 'oxygen_oxy_selectors_json_string' ), \TokenForge\Class_Usage::OPTIONS ) as $option ) {
		$value = get_option( $option );
		if ( $value ) {
			$feed( $option, $value, array() );
		}
	}
	// Posts (drafts and menu items too): key = 'post_content' or the meta key; place = label and post ID.
	list( $posts, $capped ) = \TokenForge\Token_Usage::each_post(
		array_diff( \TokenForge\Token_Usage::SKIP_TYPES, array( 'nav_menu_item' ) ),
		static function ( $post ) use ( $feed ) {
			$place = array(
				'place' => tokenforge_crucible_post_label( $post ),
				'post'  => (int) $post->ID,
			);
			$feed( 'post_content', (string) $post->post_content, $place );
			// With the data of the other builders, read for the classes they use.
			foreach ( array_merge( \TokenForge\Class_Usage::META, array( '_breakdance_data', '_oxygen_data' ) ) as $key ) {
				$value = get_post_meta( $post->ID, $key, true );
				if ( $value ) {
					$feed( $key, $value, $place );
				}
			}
		}
	);

	$report = array(
		'time'   => time(),
		'posts'  => $posts,
		'capped' => $capped,
	);
	foreach ( $collectors as $name => $c ) {
		$report[ $name ] = $c['done']( $capped );
	}
	if ( $store ) {
		update_option( 'tokenforge_crucible_site_report', $report, false );
	}
	return $report;
}

/**
 * Registers the site report routes.
 */
function tokenforge_crucible_register_site_report_routes() {
	$admin = static function () {
		return current_user_can( 'manage_options' );
	};

	register_rest_route(
		'tokenforge-crucible/v1',
		'/site-report',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'callback'            => static function () {
					return rest_ensure_response( (object) tokenforge_crucible_site_report() );
				},
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function () {
					return rest_ensure_response( tokenforge_crucible_site_scan() );
				},
			),
		)
	);
}
add_action( 'rest_api_init', 'tokenforge_crucible_register_site_report_routes' );
