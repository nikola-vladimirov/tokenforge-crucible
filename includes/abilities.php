<?php
/**
 * AI abilities for the Global SCSS: read, write one class, lint, site report, versions.
 * Registered next to TokenForge's own abilities, only when its AI abilities switch is on.
 * Restoring a version is left out on purpose: it replaces settings, so it stays a human click.
 *
 * @package TokenForge_Crucible
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A class name an ability may read or write: the same shape the sync reads as a class block.
 *
 * @param mixed $name Class name, with or without the leading dot.
 * @return string The name without the dot, '' when it is not a plain class name.
 */
function tokenforge_crucible_ability_class( $name ) {
	return is_string( $name ) && preg_match( '/^\.?(-?[_a-zA-Z][\w-]{0,99})$/D', $name, $m ) ? $m[1] : '';
}

/**
 * A problem, in the shape every ability answers with.
 *
 * @param string $message What is wrong.
 * @param array  $more    Other keys for the answer.
 * @return array
 */
function tokenforge_crucible_ability_error( $message, $more = array() ) {
	return array(
		'ok'    => false,
		'error' => $message,
	) + $more;
}

/**
 * Ability scss-get: the saved Global SCSS, or one class block of it.
 *
 * @param array $input { class?, file? }.
 * @return array
 */
function tokenforge_crucible_ability_scss_get( $input = array() ) {
	$files = tokenforge_crucible_scss_files();
	$file  = isset( $input['file'] ) && '' !== $input['file'] ? tokenforge_crucible_scss_file_name( $input['file'] ) : null;
	if ( null !== $file && ! isset( $files[ $file ] ) ) {
		return tokenforge_crucible_ability_error( __( 'No SCSS file with this name.', 'tokenforge-crucible' ), array( 'files' => array_map( 'strval', array_keys( $files ) ) ) );
	}
	$source = null === $file ? (string) get_option( tokenforge_crucible_scss_option(), '' ) : $files[ $file ]['code'];
	$blocks = \TokenForgeCrucible\Scss_Sync::class_blocks( $source );
	$status = tokenforge_crucible_compile( $source, (string) $file );
	$out    = array(
		'ok'            => true,
		'files'         => array_map( 'strval', array_keys( $files ) ),
		'scss'          => $source,
		'rev'           => md5( $source ),
		'compiles'      => null === $status['error'],
		'compile_error' => $status['error'],
		'classes'       => array_map( 'strval', array_keys( $blocks ) ),
	);
	if ( isset( $input['class'] ) && '' !== $input['class'] ) {
		$class = tokenforge_crucible_ability_class( $input['class'] );
		if ( '' === $class || ! isset( $blocks[ $class ] ) ) {
			return tokenforge_crucible_ability_error( __( 'The Global SCSS has no class block with this name.', 'tokenforge-crucible' ), array( 'classes' => $out['classes'] ) );
		}
		$out['scss'] = substr( $source, $blocks[ $class ]['start'], $blocks[ $class ]['end'] - $blocks[ $class ]['start'] );
	}
	return $out;
}

/**
 * Ability scss-write: replaces or adds one class block, compiles and lints it, and saves
 * only when asked and the whole Global SCSS still compiles.
 *
 * @param array $input { class, scss, rev?, save?, file? }.
 * @return array
 */
function tokenforge_crucible_ability_scss_write( $input = array() ) {
	$class = tokenforge_crucible_ability_class( isset( $input['class'] ) ? $input['class'] : '' );
	if ( '' === $class ) {
		return tokenforge_crucible_ability_error( __( 'Not a class name. Use letters, digits, "-" and "_", and do not start with a digit.', 'tokenforge-crucible' ) );
	}
	$body = trim( str_replace( "\r\n", "\n", isset( $input['scss'] ) && is_string( $input['scss'] ) ? $input['scss'] : '' ), "\n" );
	// Text that starts at the left edge is moved one tab in, as the editor shows a class block.
	$block = '.' . $class . " {\n" . ( preg_match( '/^\S/m', $body ) ? preg_replace( '/^(?=.)/m', "\t", $body ) : $body ) . "\n}";
	// The text must stay inside its own block: a stray "}" or an open comment or string would reach the rest of the SCSS.
	// So it is read with a marker after it: the marker must come out as the one statement after the one block.
	$items = \TokenForgeCrucible\Scss_Sync::items( $block . "\n@tfx-end;", 0, strlen( $block ) + 10 );
	if ( 2 !== count( $items ) || 'block' !== $items[0]['type'] || '.' . $class !== $items[0]['header'] || 'stmt' !== $items[1]['type'] || '@tfx-end' !== $items[1]['text'] ) {
		return tokenforge_crucible_ability_error( __( 'Send only what goes inside the class block, with every brace, comment and quote closed.', 'tokenforge-crucible' ) );
	}

	// With "file" the block goes into that saved SCSS file, else into the Global SCSS.
	$files = tokenforge_crucible_scss_files();
	$file  = isset( $input['file'] ) && '' !== $input['file'] ? tokenforge_crucible_scss_file_name( $input['file'] ) : null;
	if ( null !== $file && ! isset( $files[ $file ] ) ) {
		return tokenforge_crucible_ability_error( __( 'No SCSS file with this name.', 'tokenforge-crucible' ), array( 'files' => array_map( 'strval', array_keys( $files ) ) ) );
	}
	$current = null === $file ? (string) get_option( tokenforge_crucible_scss_option(), '' ) : $files[ $file ]['code'];
	$rev     = md5( $current );
	if ( isset( $input['rev'] ) && '' !== $input['rev'] && $rev !== $input['rev'] ) {
		return tokenforge_crucible_ability_error(
			__( 'The Global SCSS changed since you read it. Read it again, then write.', 'tokenforge-crucible' ),
			array(
				'conflict' => true,
				'rev'      => $rev,
			)
		);
	}
	$blocks = \TokenForgeCrucible\Scss_Sync::class_blocks( $current );
	$at     = isset( $blocks[ $class ] ) ? $blocks[ $class ]['start'] : strlen( rtrim( $current ) . ( '' === trim( $current ) ? '' : "\n\n" ) );
	$source = isset( $blocks[ $class ] )
		? substr_replace( $current, $block, $at, $blocks[ $class ]['end'] - $at )
		: substr( rtrim( $current ) . "\n\n", 0, $at ) . $block . "\n";
	if ( strlen( $source ) > TOKENFORGE_CRUCIBLE_SCSS_MAX_BYTES ) {
		return tokenforge_crucible_ability_error( __( 'SCSS is larger than 100 KB.', 'tokenforge-crucible' ) );
	}

	if ( null !== $file && ! $files[ $file ]['pages'] && ! $files[ $file ]['types'] ) {
		// A partial is judged inside the Global SCSS that loads it, with the new text in place.
		$with                  = $files;
		$with[ $file ]['code'] = $source;
		$status                = tokenforge_crucible_compile( (string) get_option( tokenforge_crucible_scss_option(), '' ), '', $with );
	} else {
		$status = tokenforge_crucible_compile( $source, (string) $file );
	}
	$first = 1 + ( $at > 0 ? substr_count( $source, "\n", 0, $at ) : 0 ); // the line of the block in the whole SCSS (a length of 0 means "to the end" on PHP 7).
	// An error in another file (one a @use line loads) has no line here: the message names that file.
	$line = is_array( $status['error'] ) && isset( $status['error']['line'] ) && ( ! isset( $status['error']['file'] ) || (string) $file === $status['error']['file'] ) ? (int) $status['error']['line'] : 0;
	$out  = array(
		'ok'         => null === $status['error'],
		'saved'      => false,
		'added'      => ! isset( $blocks[ $class ] ),
		'block'      => $block,
		'error'      => is_array( $status['error'] ) && isset( $status['error']['message'] ) ? (string) $status['error']['message'] : $status['error'],
		'line'       => $line,
		// The same line counted from the first line of the block, 0 when the error is elsewhere.
		'block_line' => $line >= $first && $line <= $first + substr_count( $block, "\n" ) ? $line - $first + 1 : 0,
		'findings'   => tokenforge_crucible_lint_scss( $block ),
		'rev'        => $rev,
	);
	if ( $out['ok'] && ! empty( $input['save'] ) ) {
		if ( null !== $file ) {
			// A file is not paired with builder classes: stored with a history snapshot, then the stylesheet.
			tokenforge_crucible_scss_file_save( $file, array_merge( $files[ $file ], array( 'code' => $source ) ) );
			$out['saved'] = true;
			$out['rev']   = md5( $source );
			return $out;
		}
		$sync               = tokenforge_crucible_scss_store( $source );
		$out['saved']       = true;
		$out['rev']         = md5( (string) get_option( tokenforge_crucible_scss_option(), '' ) ); // the builder sync may have rewritten the source.
		$out['sync_errors'] = $sync['errors'];
	}
	return $out;
}

/**
 * Ability scss-lint: hard-coded values that have a token, in the text sent, in the builder
 * CSS of one page, or in the saved Global SCSS.
 *
 * @param array $input { scss?, post_id? }.
 * @return array
 */
function tokenforge_crucible_ability_scss_lint( $input = array() ) {
	if ( ! empty( $input['post_id'] ) ) {
		$post = get_post( (int) $input['post_id'] );
		if ( ! $post ) {
			return tokenforge_crucible_ability_error( __( 'No post with this ID.', 'tokenforge-crucible' ) );
		}
		$lint  = tokenforge_crucible_lint_collector();
		$place = array(
			'place' => tokenforge_crucible_post_label( $post ),
			'post'  => (int) $post->ID,
		);
		foreach ( \TokenForge\Class_Usage::META as $key ) {
			$value = get_post_meta( $post->ID, $key, true );
			if ( $value ) {
				$lint['add']( $key, $value, $place );
			}
		}
		$found = $lint['done']();

		return array(
			'ok'         => true,
			'total'      => $found['total'],
			'with_token' => $found['with_token'],
			'findings'   => $found['items'],
		);
	}
	$source = isset( $input['scss'] ) && is_string( $input['scss'] ) ? str_replace( "\r\n", "\n", $input['scss'] ) : null;
	if ( null !== $source && strlen( $source ) > TOKENFORGE_CRUCIBLE_SCSS_MAX_BYTES ) {
		return tokenforge_crucible_ability_error( __( 'SCSS is larger than 100 KB.', 'tokenforge-crucible' ) );
	}
	// Nothing sent: the saved Global SCSS and the saved SCSS files (a finding in a file names it).
	$found = null === $source ? tokenforge_crucible_lint_saved() : tokenforge_crucible_lint_scss( $source );

	return array(
		'ok'         => true,
		'total'      => count( $found ),
		'with_token' => count( array_filter( array_column( $found, 'fix' ) ) ),
		'findings'   => $found,
	);
}

/**
 * Ability scss-versions: the versions, or what changed since one of them (the SCSS as a plain text diff).
 *
 * @param array $input { key? }.
 * @return array
 */
function tokenforge_crucible_ability_scss_versions( $input = array() ) {
	if ( ! isset( $input['key'] ) || '' === $input['key'] ) {
		return tokenforge_crucible_versions_lists();
	}
	$diff = tokenforge_crucible_version_diff( (string) $input['key'] );
	if ( null === $diff ) {
		return tokenforge_crucible_ability_error( __( 'This version is gone or damaged.', 'tokenforge-crucible' ) );
	}
	if ( ! class_exists( 'Text_Diff_Renderer', false ) ) {
		require ABSPATH . WPINC . '/wp-diff.php';
	}
	$then = tokenforge_crucible_version_maps( (array) tokenforge_crucible_version_data( (string) $input['key'] ) );
	$now  = tokenforge_crucible_version_maps( \TokenForge\History::data() );
	unset( $diff['scss_html'] );
	foreach ( $diff['files'] as $i => $row ) {
		$diff['files'][ $i ] = array_slice( $row, 0, 2 ); // name and state, without the HTML table.
	}
	// Lines with "<" are the version, lines with ">" are now.
	$diff['scss_diff'] = ( new Text_Diff_Renderer() )->render( new Text_Diff( 'auto', array( explode( "\n", $then['source'] ), explode( "\n", $now['source'] ) ) ) );

	return array( 'ok' => true ) + $diff;
}

/**
 * Registers the Global SCSS abilities. Only when TokenForge's AI abilities switch is on,
 * since that is when its 'tokenforge' ability category is registered.
 */
function tokenforge_crucible_abilities_register() {
	$s = \TokenForge\Settings::get_instance()->get_settings();
	if ( ! function_exists( 'wp_register_ability' ) || empty( $s['ai_abilities'] ) ) {
		return;
	}
	$read  = static function () {
		return current_user_can( 'manage_options' );
	};
	$class = array(
		'type'        => 'string',
		'description' => __( 'Class name, without the dot.', 'tokenforge-crucible' ),
	);
	$file  = array(
		'type'        => 'string',
		'description' => __( 'Name of a saved SCSS file (scss-get lists them under "files"). Leave out for the Global SCSS.', 'tokenforge-crucible' ),
	);
	// [ label, description, input properties (null = no input), required, callback, permission, readonly, idempotent ].
	$abilities = array(
		'scss-get'          => array(
			__( 'TokenForge: read the Global SCSS', 'tokenforge-crucible' ),
			__( 'Returns the saved Global SCSS of the site, or one class block of it, with the names of its classes, whether it compiles and "rev". Pass that "rev" to scss-write so a change made in between is noticed. "files" lists the saved SCSS files (loaded with @use "name"; or only on chosen pages); pass "file" to read one of them instead.', 'tokenforge-crucible' ),
			array(
				'class' => $class,
				'file'  => $file,
			),
			array(),
			'tokenforge_crucible_ability_scss_get',
			$read,
			true,
			true,
		),
		'scss-write'        => array(
			__( 'TokenForge: write one class of the Global SCSS', 'tokenforge-crucible' ),
			__( 'Replaces the block of one class in the Global SCSS, or adds it at the end. Send only what goes inside the block. The answer says whether the SCSS compiles (with the line of the error) and lists hard-coded values that have a token. Nothing is saved unless "save" is true and the SCSS compiles; a saved change can be undone in TokenForge History. With "file" the class is written into that saved SCSS file.', 'tokenforge-crucible' ),
			array(
				'class' => $class,
				'file'  => $file,
				'scss'  => array(
					'type'        => 'string',
					'description' => __( 'What goes inside the class block: declarations, nested rules, mixins.', 'tokenforge-crucible' ),
				),
				'rev'   => array(
					'type'        => 'string',
					'description' => __( 'The "rev" scss-get gave. The write is refused when the Global SCSS changed since.', 'tokenforge-crucible' ),
				),
				'save'  => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'False: only check. True: save when it compiles.', 'tokenforge-crucible' ),
				),
			),
			array( 'class', 'scss' ),
			'tokenforge_crucible_ability_scss_write',
			'tokenforge_crucible_can_edit_scss',
			false,
			true,
		),
		'scss-lint'         => array(
			__( 'TokenForge: find hard-coded values', 'tokenforge-crucible' ),
			__( 'Lists hard-coded values (colours, sizes, fonts) that have a TokenForge token, with the token to use. Checks the SCSS you send, or the custom CSS of the builder on one page (post_id), or the saved Global SCSS when you send nothing. Nothing is changed.', 'tokenforge-crucible' ),
			array(
				'scss'    => array(
					'type'        => 'string',
					'description' => __( 'SCSS or CSS to check.', 'tokenforge-crucible' ),
				),
				'post_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Check the builder CSS of this page or template.', 'tokenforge-crucible' ),
				),
			),
			array(),
			'tokenforge_crucible_ability_scss_lint',
			$read,
			true,
			true,
		),
		'css-report'        => array(
			__( 'TokenForge: site CSS report', 'tokenforge-crucible' ),
			__( 'Scans the site now and returns the hard-coded values by place, the classes of the Global SCSS nothing uses, the classes that do the same as another one and the biggest classes. Slow on a big site. Nothing is stored or changed.', 'tokenforge-crucible' ),
			null,
			array(),
			static function () {
				return array( 'ok' => true ) + tokenforge_crucible_site_scan( false );
			},
			$read,
			true,
			true,
		),
		'scss-versions'     => array(
			__( 'TokenForge: versions of the Global SCSS', 'tokenforge-crucible' ),
			__( 'Lists the named versions (key "n" and the id, as n3) and the recent saves (key "h" and the id). With a key: what changed between that version and now (classes, recipes, tokens and the SCSS as a text diff). Restoring a version is done by a person in TokenForge, not here.', 'tokenforge-crucible' ),
			array(
				'key' => array(
					'type'        => 'string',
					'description' => __( 'Version key, as n3 or h12.', 'tokenforge-crucible' ),
				),
			),
			array(),
			'tokenforge_crucible_ability_scss_versions',
			$read,
			true,
			true,
		),
		'scss-save-version' => array(
			__( 'TokenForge: save a version', 'tokenforge-crucible' ),
			__( 'Saves the current Global SCSS, recipes and tokens as a version with a name, so a person can go back to it later. Do this before a larger change.', 'tokenforge-crucible' ),
			array(
				'name' => array(
					'type'        => 'string',
					'description' => __( 'Name of the version, up to 60 characters.', 'tokenforge-crucible' ),
				),
			),
			array( 'name' ),
			static function ( $input = array() ) {
				$made = tokenforge_crucible_version_create( isset( $input['name'] ) ? $input['name'] : '' );

				return is_array( $made ) ? array(
					'ok'   => true,
					'key'  => 'n' . $made['id'],
					'name' => $made['name'],
				) : tokenforge_crucible_ability_error( $made );
			},
			'tokenforge_crucible_can_edit_scss',
			false,
			false,
		),
	);
	foreach ( $abilities as $name => $a ) {
		$args = array(
			'label'               => $a[0],
			'description'         => $a[1],
			'category'            => 'tokenforge',
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => $a[4],
			'permission_callback' => $a[5],
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => $a[6],
					'destructive' => false,
					'idempotent'  => $a[7],
				),
				'public'       => true,
				'show_in_rest' => true,
				'mcp'          => array(
					'public' => true,
					'type'   => 'tool',
				),
			),
		);
		if ( null !== $a[2] ) {
			$args['input_schema'] = array(
				'type'                 => 'object',
				'properties'           => $a[2],
				'required'             => $a[3],
				'additionalProperties' => false,
			);
			if ( ! $a[3] ) {
				$args['input_schema']['default'] = array(); // a call without input is valid.
			}
		}
		wp_register_ability( 'tokenforge/' . $name, $args );
	}
}
add_action( 'wp_abilities_api_init', 'tokenforge_crucible_abilities_register' );

/**
 * MCP server instructions: one line after TokenForge's own pointer, when its AI guidance is on.
 *
 * @param array $config Default server config.
 * @return array
 */
function tokenforge_crucible_abilities_server_config( $config ) {
	$s = \TokenForge\Settings::get_instance()->get_settings();
	if ( is_array( $config ) && ! empty( $s['ai_guidance'] ) && ! empty( $s['ai_abilities'] ) ) {
		$config['server_description'] = trim( ( isset( $config['server_description'] ) ? (string) $config['server_description'] : '' ) . "\n\nThe site's own classes live in the TokenForge Global SCSS: read it with tokenforge/scss-get, check a class with tokenforge/scss-write (save false) before saving it, and find hard-coded values with tokenforge/scss-lint." );
	}
	return $config;
}
add_filter( 'mcp_adapter_default_server_config', 'tokenforge_crucible_abilities_server_config', 21 );
