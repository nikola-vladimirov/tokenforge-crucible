<?php
/**
 * Global SCSS editor screen.
 *
 * @package TokenForge_Crucible
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the Global SCSS screen under the TokenForge menu.
 */
function tokenforge_crucible_admin_menu() {
	add_submenu_page(
		'tokenforge',
		__( 'Global SCSS', 'tokenforge-crucible' ),
		__( 'Global SCSS', 'tokenforge-crucible' ),
		'manage_options',
		'tokenforge-crucible-scss',
		'tokenforge_crucible_scss_screen'
	);
}

/**
 * Renders the Global SCSS screen.
 */
function tokenforge_crucible_scss_screen() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to edit the Global SCSS.', 'tokenforge-crucible' ) );
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Global SCSS', 'tokenforge-crucible' ); ?></h1>
		<p><?php echo esc_html__( 'This SCSS is appended to the styles TokenForge compiles for the site.', 'tokenforge-crucible' ); ?></p>
		<textarea id="tokenforge-crucible-scss" name="tokenforge_crucible_scss" rows="20" class="large-text code"><?php echo esc_textarea( get_option( tokenforge_crucible_scss_option(), '' ) ); ?></textarea>
		<p><button type="button" class="button button-primary" id="tokenforge-crucible-save"><?php echo esc_html__( 'Save', 'tokenforge-crucible' ); ?></button></p>
	</div>
	<?php
}

/**
 * Enqueues the editor assets on the Global SCSS screen.
 *
 * @param string $hook The current admin page hook suffix.
 */
function tokenforge_crucible_scss_enqueue( $hook ) {
	if ( get_plugin_page_hookname( 'tokenforge-crucible-scss', 'tokenforge' ) !== $hook ) {
		return;
	}

	$url = plugin_dir_url( TOKENFORGE_CRUCIBLE_FILE ) . 'assets/admin/';

	wp_enqueue_script(
		'tokenforge-crucible-scss-outline',
		$url . 'scss-outline.js',
		array(),
		TOKENFORGE_CRUCIBLE_VERSION,
		true
	);

	wp_enqueue_script(
		'tokenforge-crucible-scss-editor',
		$url . 'scss-editor.js',
		array( 'tokenforge-crucible-scss-outline' ),
		TOKENFORGE_CRUCIBLE_VERSION,
		true
	);

	wp_enqueue_script(
		'tokenforge-crucible-screen',
		$url . 'scss-screen.js',
		array( 'tokenforge-crucible-scss-editor' ),
		TOKENFORGE_CRUCIBLE_VERSION,
		true
	);

	wp_localize_script(
		'tokenforge-crucible-screen',
		'TokenForgeCrucible',
		array(
			'tfRest'       => esc_url_raw( rest_url( 'tokenforge/v1' ) ),
			'crucibleRest' => esc_url_raw( rest_url( 'tokenforge-crucible/v1' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
		)
	);
}

/**
 * Returns the stored Global SCSS.
 *
 * @return WP_REST_Response
 */
function tokenforge_crucible_scss_rest_get() {
	return rest_ensure_response(
		array(
			'scss' => get_option( tokenforge_crucible_scss_option(), '' ),
		)
	);
}

/**
 * Saves the Global SCSS.
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function tokenforge_crucible_scss_rest_save( $request ) {
	$value = $request->get_param( 'scss' );

	if ( ! is_string( $value ) ) {
		return new WP_Error(
			'tokenforge_crucible_scss_invalid',
			__( 'The SCSS must be a string.', 'tokenforge-crucible' ),
			array( 'status' => 400 )
		);
	}

	update_option( tokenforge_crucible_scss_option(), $value, false );

	return rest_ensure_response( array( 'saved' => true ) );
}

/**
 * Registers the Global SCSS REST routes.
 */
function tokenforge_crucible_register_routes() {
	register_rest_route(
		'tokenforge-crucible/v1',
		'/scss',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => 'tokenforge_crucible_scss_rest_get',
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => 'tokenforge_crucible_scss_rest_save',
				'args'                => array(
					'scss' => array(
						'type'              => 'string',
						'sanitize_callback' => null,
						'validate_callback' => function ( $value ) {
							return is_string( $value );
						},
					),
				),
			),
		)
	);
}

// TokenForge registers its top-level menu at the default priority, and the submenu needs that parent to exist.
add_action( 'admin_menu', 'tokenforge_crucible_admin_menu', 20 );
add_action( 'admin_enqueue_scripts', 'tokenforge_crucible_scss_enqueue' );
add_action( 'rest_api_init', 'tokenforge_crucible_register_routes' );
