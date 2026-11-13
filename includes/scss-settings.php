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
 * Whether the current user may change the Global SCSS, your recipes and their versions.
 * Same rule as TokenForge's CSS writes: on multisite a site admin also needs edit_css.
 *
 * @return bool
 */
function tokenforge_crucible_can_edit_scss() {
	return current_user_can( 'manage_options' ) && ( ! is_multisite() || current_user_can( 'edit_css' ) );
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
	$settings['sync']        = isset( $sync['sync'] ) ? (bool) $sync['sync'] : true;
	$settings['sync_pull']   = isset( $sync['sync_pull'] ) ? (bool) $sync['sync_pull'] : true;
	$settings['sync_create'] = isset( $sync['sync_create'] ) ? (bool) $sync['sync_create'] : false;

	return $settings;
}

/**
 * Saves the sync switches (sync, sync_pull, sync_create); a switch not sent keeps its value.
 *
 * @param array $in Raw input.
 */
function tokenforge_crucible_sync_save( $in ) {
	$now = tokenforge_crucible_sync_settings();
	$out = array();

	foreach ( array( 'sync', 'sync_pull', 'sync_create' ) as $key ) {
		$out[ $key ] = array_key_exists( $key, $in ) ? ! empty( $in[ $key ] ) : $now[ $key ];
	}

	update_option( tokenforge_crucible_sync_option(), $out, false );
}

/**
 * Whether a one-time job already ran. Its flag is autoloaded, so the check costs no query on page views.
 *
 * @param string $flag Option name of the flag.
 * @return bool
 */
function tokenforge_crucible_migrated( $flag ) {
	if ( isset( wp_load_alloptions()[ $flag ] ) ) {
		return true;
	}
	if ( ! get_option( $flag ) ) {
		return false;
	}
	// Up to 0.3.6 the flag was stored without autoload: store it again with autoload.
	delete_option( $flag );
	add_option( $flag, 1, '', true );
	return true;
}

/**
 * One time: moves the Global SCSS source and the sync switches an older TokenForge
 * kept in the tokenforge_scss option into the add-on's own options. The source is
 * only copied when the add-on has none yet.
 */
function tokenforge_crucible_scss_migrate() {
	if ( tokenforge_crucible_migrated( 'tokenforge_crucible_scss_migrated' ) ) {
		return;
	}
	$old = get_option( 'tokenforge_scss', array() );
	$old = is_array( $old ) ? $old : array();

	if ( isset( $old['source'] ) && is_string( $old['source'] ) && '' !== trim( $old['source'] ) && '' === trim( (string) get_option( tokenforge_crucible_scss_option(), '' ) ) ) {
		update_option( tokenforge_crucible_scss_option(), $old['source'], false );
	}
	if ( false === get_option( tokenforge_crucible_sync_option(), false ) && array_intersect_key( $old, array_flip( array( 'sync', 'sync_pull', 'sync_create' ) ) ) ) {
		tokenforge_crucible_sync_save( $old );
	}
	if ( array_key_exists( 'enabled', $old ) && false === get_option( 'tokenforge_crucible_scss_enabled', false ) ) {
		update_option( 'tokenforge_crucible_scss_enabled', ! empty( $old['enabled'] ), false );
	}
	add_option( 'tokenforge_crucible_scss_migrated', 1, '', true );
}

/**
 * Largest SCSS source the compile routes accept, in bytes.
 */
const TOKENFORGE_CRUCIBLE_SCSS_MAX_BYTES = 102400;

/**
 * Compiles SCSS with the saved files its @use lines name put in, unless it is over the size
 * cap, so one request cannot tie up PHP. An error says which file and line it is in.
 *
 * @param string     $source SCSS source.
 * @param string     $own    Name of the file the source is, '' for the Global SCSS or any other text.
 * @param array|null $files  Files to use instead of the saved ones (to check a file before it is saved).
 * @return array Same keys as TokenForge's Scss::compile(): css and error (with "file" when files were put in).
 */
function tokenforge_crucible_compile( $source, $own = '', $files = null ) {
	if ( strlen( $source ) > TOKENFORGE_CRUCIBLE_SCSS_MAX_BYTES ) {
		return array(
			'css'   => '',
			'error' => 'SCSS is larger than 100 KB.',
		);
	}
	$own    = (string) $own;
	$bundle = tokenforge_crucible_bundle( $source, $own, $files );
	if ( null === $bundle['error'] && strlen( $bundle['scss'] ) > TOKENFORGE_CRUCIBLE_SCSS_BUNDLE_MAX_BYTES ) {
		$bundle['error'] = array(
			'message' => __( 'The SCSS with its files is larger than 300 KB.', 'tokenforge-crucible' ),
			'line'    => 0,
			'column'  => 0,
			'file'    => $own,
		);
	}
	if ( null !== $bundle['error'] ) {
		$r = array(
			'css'   => '',
			'error' => $bundle['error'],
		);
	} else {
		$r = \TokenForge\Scss::compile( $bundle['scss'] );
		// The compiler counts lines in the bundle: give the file and the line the text came from.
		if ( is_array( $r['error'] ) && $bundle['map'] && isset( $r['error']['line'], $bundle['map'][ (int) $r['error']['line'] - 1 ] ) ) {
			list( $r['error']['file'], $r['error']['line'] ) = $bundle['map'][ (int) $r['error']['line'] - 1 ];
		}
		// A partial that leaves a block open fails at the end of the bundle: name that partial instead.
		$open = is_array( $r['error'] ) && $bundle['map'] ? tokenforge_crucible_bundle_open_block( $bundle, $own ) : null;
		if ( $open ) {
			list( $r['error']['file'], $r['error']['line'] ) = $open;
		}
	}
	// An error in another file than the one being compiled names that file.
	if ( is_array( $r['error'] ) && isset( $r['error']['file'], $r['error']['message'] ) && $own !== $r['error']['file'] ) {
		/* translators: 1: error message, 2: file name, 3: line number. */
		$r['error']['message'] = sprintf( __( '%1$s (in the file "%2$s", line %3$d)', 'tokenforge-crucible' ), $r['error']['message'], '' === $r['error']['file'] ? __( 'Global SCSS', 'tokenforge-crucible' ) : $r['error']['file'], (int) $r['error']['line'] );
	}
	return $r;
}

/**
 * Registers the compile-scss ability (0.53.7) next to TokenForge's own abilities.
 * Only when TokenForge's AI abilities switch is on, since that is when its
 * 'tokenforge' ability category is registered.
 */
function tokenforge_crucible_register_ability() {
	$s = \TokenForge\Settings::get_instance()->get_settings();
	if ( ! function_exists( 'wp_register_ability' ) || empty( $s['ai_abilities'] ) ) {
		return;
	}

	wp_register_ability(
		'tokenforge/compile-scss',
		array(
			'label'               => 'TokenForge: compile SCSS',
			'description'         => 'Compiles SCSS with the TokenForge mixins and functions available (btn, card, center, auto-grid, breakpoint, fluid() ...) and returns plain CSS you can put in an element’s or class’s custom CSS. Nothing is saved.',
			'category'            => 'tokenforge',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'scss' => array(
						'type'        => 'string',
						'description' => 'SCSS source.',
					),
				),
				'required'             => array( 'scss' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => static function ( $input = array() ) {
				$r = tokenforge_crucible_compile( isset( $input['scss'] ) ? (string) $input['scss'] : '' );
				return array(
					'ok'    => null === $r['error'],
					'css'   => $r['css'],
					'error' => $r['error'],
				);
			},
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'public'       => true,
				'show_in_rest' => true,
				'mcp'          => array(
					'public' => true,
					'type'   => 'tool',
				),
			),
		)
	);
}
add_action( 'wp_abilities_api_init', 'tokenforge_crucible_register_ability' );
