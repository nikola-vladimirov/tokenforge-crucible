<?php
/**
 * SCSS files: partials that a line `@use "name";` loads, and page files that are compiled
 * by themselves and printed only on the pages they are set to load on.
 *
 * Options: tokenforge_crucible_scss_files (the files, not autoloaded),
 * tokenforge_crucible_page_map (which page file loads where: small, autoloaded, only there
 * while a page file exists), tokenforge_crucible_page_css_<md5 of the name> (the compiled
 * CSS of one page file, not autoloaded) and tokenforge_crucible_page_css_built (what each
 * page file was last compiled from, so an unchanged one is not compiled again; not autoloaded).
 *
 * @package TokenForge_Crucible
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Largest SCSS a compile accepts once its files are put in, in bytes.
 */
const TOKENFORGE_CRUCIBLE_SCSS_BUNDLE_MAX_BYTES = 307200;

/**
 * Most files the store keeps.
 */
const TOKENFORGE_CRUCIBLE_SCSS_FILES_MAX = 100;

/**
 * Checks a file name: lower case letters, digits, "-" and "_", up to four parts with "/" between.
 *
 * @param mixed $name Name to check.
 * @return string The name, or '' when it is not allowed.
 */
function tokenforge_crucible_scss_file_name( $name ) {
	return is_string( $name ) && preg_match( '~^[a-z0-9][a-z0-9_-]{0,40}(/[a-z0-9][a-z0-9_-]{0,40}){0,3}$~D', $name ) ? $name : '';
}

/**
 * Checks a list of files (saved, sent or imported) and returns only what is allowed.
 *
 * @param mixed $raw Map of name => { code, pages, types }.
 * @return array
 */
function tokenforge_crucible_scss_files_clean( $raw ) {
	$out = array();
	foreach ( is_array( $raw ) ? $raw : array() as $name => $file ) {
		$name = tokenforge_crucible_scss_file_name( (string) $name );
		if ( '' === $name || ! is_array( $file ) || ! isset( $file['code'] ) || ! is_string( $file['code'] ) || strlen( $file['code'] ) > TOKENFORGE_CRUCIBLE_SCSS_MAX_BYTES ) {
			continue;
		}
		if ( count( $out ) >= TOKENFORGE_CRUCIBLE_SCSS_FILES_MAX ) {
			break;
		}
		$pages = array();
		foreach ( isset( $file['pages'] ) && is_array( $file['pages'] ) ? $file['pages'] : array() as $id ) {
			if ( is_scalar( $id ) && (int) $id > 0 ) {
				$pages[] = (int) $id;
			}
		}
		$types = array();
		foreach ( isset( $file['types'] ) && is_array( $file['types'] ) ? $file['types'] : array() as $type ) {
			$type = is_string( $type ) ? sanitize_key( $type ) : '';
			if ( '' !== $type ) {
				$types[] = $type;
			}
		}
		$out[ $name ] = array(
			'code'  => str_replace( "\r\n", "\n", $file['code'] ),
			'pages' => array_slice( array_values( array_unique( $pages ) ), 0, 50 ),
			'types' => array_slice( array_values( array_unique( $types ) ), 0, 20 ),
		);
	}
	return $out;
}

/**
 * The saved files: name => { code, pages, types }.
 *
 * @return array
 */
function tokenforge_crucible_scss_files() {
	return tokenforge_crucible_scss_files_clean( get_option( 'tokenforge_crucible_scss_files', array() ) );
}

/**
 * Which page file loads where, from the files: { ids: { post ID: [ names ] }, types: { post type: [ names ] } }.
 *
 * @param array $files Checked files.
 * @return array
 */
function tokenforge_crucible_page_map_of( $files ) {
	$map = array(
		'ids'   => array(),
		'types' => array(),
	);
	foreach ( $files as $name => $file ) {
		foreach ( $file['pages'] as $id ) {
			$map['ids'][ $id ][] = (string) $name;
		}
		foreach ( $file['types'] as $type ) {
			$map['types'][ $type ][] = (string) $name;
		}
	}
	return $map;
}

/**
 * The option that keeps the compiled CSS of one page file.
 *
 * @param string $name File name.
 * @return string
 */
function tokenforge_crucible_page_css_option( $name ) {
	return 'tokenforge_crucible_page_css_' . md5( (string) $name );
}

/**
 * Stores the files with the page map. The compiled CSS of a file that is gone, or no longer a
 * page file, is removed. The caller writes the TokenForge stylesheet after this, which compiles the page files.
 *
 * @param mixed $files Map of name => { code, pages, types }; checked here.
 * @return array The files as stored.
 */
function tokenforge_crucible_scss_files_store( $files ) {
	$old   = tokenforge_crucible_scss_files();
	$files = tokenforge_crucible_scss_files_clean( $files );
	ksort( $files, SORT_STRING );
	update_option( 'tokenforge_crucible_scss_files', $files, false );

	$map = tokenforge_crucible_page_map_of( $files );
	if ( $map['ids'] || $map['types'] ) {
		// Autoloaded: the front end reads it on every page, so it must cost no query.
		update_option( 'tokenforge_crucible_page_map', $map, true );
	} else {
		delete_option( 'tokenforge_crucible_page_map' );
	}
	foreach ( $old as $name => $file ) {
		if ( ! isset( $files[ $name ] ) || ( ! $files[ $name ]['pages'] && ! $files[ $name ]['types'] ) ) {
			delete_option( tokenforge_crucible_page_css_option( $name ) );
			// Its CSS is gone, so nothing may say it is still built.
			if ( $file['pages'] || $file['types'] ) {
				delete_option( 'tokenforge_crucible_page_css_built' );
			}
		}
	}
	return $files;
}

/**
 * One line of the bundle: its text and where it came from.
 *
 * @param array  $state Bundle state (lines, map, done, error), changed in place.
 * @param string $text  The line.
 * @param string $file  File the line is in ('' = the SCSS that was passed in).
 * @param int    $line  Line in that file, from 1.
 */
function tokenforge_crucible_bundle_line( &$state, $text, $file, $line ) {
	$state['lines'][] = $text;
	$state['map'][]   = array( $file, $line );
}

/**
 * Adds SCSS to the bundle, with every `@use "name";` or `@import "name";` line of a saved
 * file replaced by that file.
 *
 * @param string $code  SCSS.
 * @param string $file  File the SCSS is ('' = the SCSS that was passed in).
 * @param array  $files Saved files.
 * @param array  $stack Files being read right now, outermost first.
 * @param array  $state Bundle state, changed in place.
 */
function tokenforge_crucible_bundle_add( $code, $file, $files, $stack, &$state ) {
	foreach ( explode( "\n", $code ) as $i => $text ) {
		if ( null !== $state['error'] ) {
			return;
		}
		$name = '';
		if ( false !== strpos( $text, '@' ) && preg_match( '~^\s*@(?:use|import)\s+(["\'])([^"\']+)\1([^;]*);?\s*(?://.*)?$~', $text, $m ) ) {
			// "_name" and "name.scss" are the file "name".
			$name = tokenforge_crucible_scss_file_name( (string) preg_replace( array( '~\.scss$~', '~(^|/)_([^/]+)$~' ), array( '', '$1$2' ), $m[2] ) );
			$name = isset( $files[ $name ] ) ? $name : '';
		}
		// Anything that is not a saved file stays as it is (a CSS @import, a name with a typing mistake).
		if ( '' === $name ) {
			tokenforge_crucible_bundle_line( $state, $text, $file, $i + 1 );
			continue;
		}
		$error = '';
		if ( '' !== trim( $m[3] ) ) {
			$error = __( 'Partials load with @use "name"; (one file on a line, without "as" or "with").', 'tokenforge-crucible' );
		} elseif ( in_array( $name, $stack, true ) ) {
			/* translators: %s: file name. */
			$error = sprintf( __( 'The file "%s" loads itself (a loop of @use lines).', 'tokenforge-crucible' ), $name );
		}
		if ( '' !== $error ) {
			$state['error'] = array(
				'message' => $error,
				'line'    => $i + 1,
				'column'  => 0,
				'file'    => $file,
			);
			return;
		}
		// Each file goes in once: a second @use of it leaves an empty line.
		if ( isset( $state['done'][ $name ] ) ) {
			tokenforge_crucible_bundle_line( $state, '', $file, $i + 1 );
			continue;
		}
		$state['done'][ $name ] = true;
		tokenforge_crucible_bundle_add( $files[ $name ]['code'], $name, $files, array_merge( $stack, array( $name ) ), $state );
	}
}

/**
 * Puts the saved files into SCSS: the one place that reads `@use "name";` lines.
 *
 * @param string     $source SCSS.
 * @param string     $own   Name of the file the SCSS is, '' for the Global SCSS or any other text.
 * @param array|null $files  Files to use; null = the saved ones (read only when a line could need them).
 * @return array { scss, error: null or { message, line, column, file }, map: null or per line [ file, line ] }
 */
function tokenforge_crucible_bundle( $source, $own = '', $files = null ) {
	$source = str_replace( "\r\n", "\n", (string) $source );
	$out    = array(
		'scss'  => $source,
		'error' => null,
		'map'   => null,
	);
	if ( false === strpos( $source, '@use' ) && false === strpos( $source, '@import' ) ) {
		return $out;
	}
	if ( null === $files ) {
		$files = tokenforge_crucible_scss_files();
	}
	if ( ! $files ) {
		return $out;
	}
	$state = array(
		'lines' => array(),
		'map'   => array(),
		'done'  => array(),
		'error' => null,
	);
	$own   = (string) $own;
	tokenforge_crucible_bundle_add( $source, $own, $files, '' === $own ? array() : array( $own ), $state );
	if ( null !== $state['error'] ) {
		$out['error'] = $state['error'];
		return $out;
	}
	$out['scss'] = implode( "\n", $state['lines'] );
	$out['map']  = $state['map'];
	return $out;
}

/**
 * A partial in a bundle that leaves a block open (or closes one it did not open). The compiler
 * reports that at the end of the whole bundle, which is a line of the file that loads it.
 * shortcut: a block comment over several lines is not skipped; enough to name the file after a failed compile.
 *
 * @param array  $bundle Result of tokenforge_crucible_bundle() with a map.
 * @param string $own    The file being compiled ('' = the Global SCSS): not checked.
 * @return array|null [ file, line ] of the open { or the extra }, null when every partial is balanced.
 */
function tokenforge_crucible_bundle_open_block( $bundle, $own ) {
	$lines = explode( "\n", (string) $bundle['scss'] );
	$open  = array();
	foreach ( (array) $bundle['map'] as $i => $at ) {
		list( $file, $line ) = $at;
		if ( (string) $own === $file || ! isset( $lines[ $i ] ) ) {
			continue;
		}
		// Strings, one-line comments and // comments (not the // of url(http://...)) hold no braces that count.
		$text   = (string) preg_replace( array( '~"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'~', '~/\*.*?\*/~', '~(^|[^:])//.*$~' ), array( '""', '', '$1' ), $lines[ $i ] );
		$braces = (string) preg_replace( '~[^{}]~', '', $text );
		if ( '' === $braces ) {
			continue; // str_split( '' ) is array( '' ) before PHP 8.2, which would count as an extra }.
		}
		foreach ( str_split( $braces ) as $brace ) {
			if ( '{' === $brace ) {
				$open[ $file ][] = $line;
			} elseif ( empty( $open[ $file ] ) ) {
				return array( $file, $line );
			} else {
				array_pop( $open[ $file ] );
			}
		}
	}
	foreach ( $open as $file => $stack ) {
		if ( $stack ) {
			return array( $file, (int) end( $stack ) );
		}
	}
	return null;
}

/**
 * The files for a list: name, bytes, rev and where each loads (pages with their titles).
 *
 * @param array|null $files Checked files; null = the saved ones.
 * @return array
 */
function tokenforge_crucible_scss_files_list( $files = null ) {
	$rows = array();
	foreach ( null === $files ? tokenforge_crucible_scss_files() : $files as $name => $file ) {
		$pages = array();
		foreach ( $file['pages'] as $id ) {
			$pages[] = array(
				'id'    => $id,
				'title' => html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			);
		}
		$rows[] = array(
			'name'  => (string) $name,
			'bytes' => strlen( $file['code'] ),
			'rev'   => md5( $file['code'] ),
			'pages' => $pages,
			'types' => $file['types'],
		);
	}
	return $rows;
}

/**
 * Saves or deletes one file: a history snapshot first (so it can be undone), then the files,
 * the page map and the stylesheet (which compiles the page files).
 *
 * @param string     $name Checked file name.
 * @param array|null $file { code, pages, types }, or null to delete the file.
 * @return array The files as stored.
 */
function tokenforge_crucible_scss_file_save( $name, $file ) {
	$files = tokenforge_crucible_scss_files();
	tokenforge_crucible_snapshot();
	if ( null === $file ) {
		unset( $files[ $name ] );
	} else {
		$files[ $name ] = $file;
	}
	$files = tokenforge_crucible_scss_files_store( $files );
	\TokenForge\Css_Generator::get_instance()->generate();
	return $files;
}

/**
 * The page map without a query: it is autoloaded, and missing while no page file exists.
 *
 * @return array Empty when no page file exists.
 */
function tokenforge_crucible_page_map() {
	$all = wp_load_alloptions();
	if ( empty( $all['tokenforge_crucible_page_map'] ) ) {
		return array();
	}
	$map = maybe_unserialize( $all['tokenforge_crucible_page_map'] );
	return is_array( $map ) ? $map : array();
}

/**
 * Compiles every page file and keeps its CSS. A file with an error keeps its last working CSS.
 *
 * A file is compiled only when what it is made of changed: the option
 * tokenforge_crucible_page_css_built keeps, for each page file, a hash of TokenForge's SCSS
 * library with the file and its partials, and the error of that compile.
 *
 * @param array|null $files Checked files; null = the saved ones.
 * @return array The error of each page file (null = it compiles): name => error.
 */
function tokenforge_crucible_page_css_build( $files = null ) {
	$errors = array();
	if ( ! class_exists( '\TokenForge\Scss' ) || ! function_exists( 'tokenforge_crucible_compile' ) ) {
		return $errors;
	}
	$old   = get_option( 'tokenforge_crucible_page_css_built', array() );
	$old   = is_array( $old ) ? $old : array();
	$built = array();
	$lib   = null;
	foreach ( null === $files ? tokenforge_crucible_scss_files() : $files as $name => $file ) {
		if ( ! $file['pages'] && ! $file['types'] ) {
			continue;
		}
		$name   = (string) $name;
		$lib    = null === $lib ? TOKENFORGE_VERSION . \TokenForge\Scss::library() : $lib;
		$bundle = tokenforge_crucible_bundle( $file['code'], $name );
		$hash   = md5( $lib . "\n" . $bundle['scss'] . "\n" . wp_json_encode( $bundle['error'] ) );
		if ( isset( $old[ $name ]['hash'] ) && $hash === $old[ $name ]['hash'] ) {
			$built[ $name ] = $old[ $name ];
		} else {
			$r = tokenforge_crucible_compile( $file['code'], $name );
			if ( empty( $r['error'] ) ) {
				update_option( tokenforge_crucible_page_css_option( $name ), (string) $r['css'], false );
			}
			$built[ $name ] = array(
				'hash'  => $hash,
				'error' => empty( $r['error'] ) ? null : $r['error'],
			);
		}
		$errors[ $name ] = isset( $built[ $name ]['error'] ) ? $built[ $name ]['error'] : null;
	}
	if ( $built !== $old ) {
		update_option( 'tokenforge_crucible_page_css_built', $built, false );
	}
	return $errors;
}

/**
 * Compiles the page files again after TokenForge wrote its stylesheet (tokens, mixins or a
 * partial may have changed). Does nothing, and reads nothing, while no page file exists.
 */
function tokenforge_crucible_page_css_rebuild() {
	if ( tokenforge_crucible_page_map() ) {
		tokenforge_crucible_page_css_build();
	}
}
add_action( 'update_option_tokenforge_css_meta', 'tokenforge_crucible_page_css_rebuild', 10, 0 );
add_action( 'add_option_tokenforge_css_meta', 'tokenforge_crucible_page_css_rebuild', 10, 0 );

/**
 * The CSS of the page files that load on these posts or this post type.
 *
 * @param array  $ids  Post IDs (the page, the templates around it).
 * @param string $type Post type, '' for none.
 * @return string CSS, '' when nothing loads here or the Global SCSS is switched off.
 */
function tokenforge_crucible_page_css( $ids, $type ) {
	$map = tokenforge_crucible_page_map();
	if ( ! $map ) {
		return '';
	}
	$names = array();
	foreach ( (array) $ids as $id ) {
		if ( isset( $map['ids'][ $id ] ) && is_array( $map['ids'][ $id ] ) ) {
			$names = array_merge( $names, $map['ids'][ $id ] );
		}
	}
	if ( '' !== (string) $type && isset( $map['types'][ $type ] ) && is_array( $map['types'][ $type ] ) ) {
		$names = array_merge( $names, $map['types'][ $type ] );
	}
	// The switch is not autoloaded: read only when a file loads here, so other pages cost no query.
	if ( ! $names || ! tokenforge_crucible_scss_enabled() ) {
		return '';
	}
	$css = '';
	foreach ( array_unique( array_filter( $names, 'is_string' ) ) as $name ) {
		if ( '' !== tokenforge_crucible_scss_file_name( $name ) ) {
			$css .= trim( (string) get_option( tokenforge_crucible_page_css_option( $name ), '' ) ) . "\n";
		}
	}
	// TokenForge's compile already guards this; a second time here, where it is printed in a style element.
	return str_ireplace( '</style', '<\/style', trim( $css ) );
}

/**
 * Prints the page CSS inline, after the TokenForge stylesheet.
 *
 * @param array  $ids  Post IDs.
 * @param string $type Post type, '' for none.
 */
function tokenforge_crucible_page_css_enqueue( $ids, $type ) {
	$css = tokenforge_crucible_page_css( $ids, $type );
	if ( '' === $css ) {
		return;
	}
	wp_register_style( 'tokenforge-crucible-page', false, array(), TOKENFORGE_CRUCIBLE_VERSION );
	wp_enqueue_style( 'tokenforge-crucible-page' );
	wp_add_inline_style( 'tokenforge-crucible-page', $css );
}

/**
 * Front end: the page files of the page shown, of the builder templates around it and of its post type.
 */
function tokenforge_crucible_page_css_front() {
	// No page file: stop before anything is read.
	if ( ! tokenforge_crucible_page_map() ) {
		return;
	}
	$ids  = array();
	$type = '';
	$post = get_queried_object();
	if ( $post instanceof WP_Post ) {
		$ids[] = (int) $post->ID;
		$type  = is_singular() ? (string) $post->post_type : '';
	}
	// The builder's header, footer and other templates in use on this page.
	if ( class_exists( '\Bricks\Database', false ) && isset( \Bricks\Database::$active_templates ) && is_array( \Bricks\Database::$active_templates ) ) {
		$templates = \Bricks\Database::$active_templates;
		array_walk_recursive(
			$templates,
			static function ( $id ) use ( &$ids ) {
				if ( is_numeric( $id ) && (int) $id > 0 ) {
					$ids[] = (int) $id;
				}
			}
		);
	}
	tokenforge_crucible_page_css_enqueue( array_unique( $ids ), $type );
}
add_action( 'wp_enqueue_scripts', 'tokenforge_crucible_page_css_front', 20 );

/**
 * Block editor: the page files of the post being edited, in the canvas.
 */
function tokenforge_crucible_page_css_editor() {
	if ( ! is_admin() || ! tokenforge_crucible_page_map() ) {
		return;
	}
	$post = get_post();
	if ( $post instanceof WP_Post ) {
		tokenforge_crucible_page_css_enqueue( array( (int) $post->ID ), (string) $post->post_type );
	}
}
add_action( 'enqueue_block_assets', 'tokenforge_crucible_page_css_editor' );

/**
 * Registers the file routes.
 */
function tokenforge_crucible_scss_files_routes() {
	$read = static function () {
		return current_user_can( 'manage_options' );
	};
	$one  = static function ( $name, $file ) {
		$list = tokenforge_crucible_scss_files_list( array( $name => $file ) );
		return array_merge( $list[0], array( 'code' => $file['code'] ) );
	};

	register_rest_route(
		'tokenforge-crucible/v1',
		'/scss-files',
		array(
			'methods'             => 'GET',
			'permission_callback' => $read,
			'callback'            => static function () {
				return rest_ensure_response(
					array(
						'ok'    => true,
						'files' => tokenforge_crucible_scss_files_list(),
					)
				);
			},
		)
	);

	register_rest_route(
		'tokenforge-crucible/v1',
		'/scss-file',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => $read,
				'callback'            => static function ( $request ) use ( $one ) {
					$name  = tokenforge_crucible_scss_file_name( $request->get_param( 'name' ) );
					$files = tokenforge_crucible_scss_files();
					if ( '' === $name || ! isset( $files[ $name ] ) ) {
						return rest_ensure_response(
							array(
								'ok'    => false,
								'error' => __( 'No SCSS file with this name.', 'tokenforge-crucible' ),
							)
						);
					}
					return rest_ensure_response(
						array(
							'ok'   => true,
							'file' => $one( $name, $files[ $name ] ),
						)
					);
				},
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => 'tokenforge_crucible_can_edit_scss',
				'callback'            => static function ( $request ) use ( $one ) {
					$body  = (array) $request->get_json_params();
					$fail  = static function ( $message, $more = array() ) {
						return rest_ensure_response(
							array_merge(
								array(
									'ok'    => false,
									'error' => $message,
								),
								$more
							)
						);
					};
					$name  = tokenforge_crucible_scss_file_name( isset( $body['name'] ) ? $body['name'] : '' );
					$files = tokenforge_crucible_scss_files();
					if ( '' === $name ) {
						return $fail( __( 'Not a file name. Use lower case letters, digits, "-" and "_", with "/" for folders (up to four parts).', 'tokenforge-crucible' ) );
					}
					if ( ! empty( $body['delete'] ) ) {
						$files = isset( $files[ $name ] ) ? tokenforge_crucible_scss_file_save( $name, null ) : $files;
						return rest_ensure_response(
							array(
								'ok'    => true,
								'files' => tokenforge_crucible_scss_files_list( $files ),
							)
						);
					}
					if ( ! isset( $body['code'] ) || ! is_string( $body['code'] ) ) {
						return $fail( __( 'No SCSS was sent.', 'tokenforge-crucible' ) );
					}
					$code = str_replace( "\r\n", "\n", $body['code'] );
					if ( strlen( $code ) > TOKENFORGE_CRUCIBLE_SCSS_MAX_BYTES ) {
						return $fail( __( 'SCSS is larger than 100 KB.', 'tokenforge-crucible' ) );
					}
					if ( ! isset( $files[ $name ] ) && count( $files ) >= TOKENFORGE_CRUCIBLE_SCSS_FILES_MAX ) {
						return $fail( __( 'There are 100 files already. Delete one first.', 'tokenforge-crucible' ) );
					}
					// Someone else saved this file since it was opened (or made a file with this name).
					if ( isset( $files[ $name ] ) && empty( $body['force'] ) && isset( $body['rev'] ) && md5( $files[ $name ]['code'] ) !== $body['rev'] && $code !== $files[ $name ]['code'] ) {
						return $fail(
							__( 'This file changed since you opened it. Open it again, then save.', 'tokenforge-crucible' ),
							array(
								'conflict' => true,
								'code'     => $files[ $name ]['code'],
								'rev'      => md5( $files[ $name ]['code'] ),
							)
						);
					}
					$types = isset( $body['types'] ) ? $body['types'] : array();
					$clean = tokenforge_crucible_scss_files_clean(
						array(
							$name => array(
								'code'  => $code,
								'pages' => isset( $body['pages'] ) ? $body['pages'] : array(),
								'types' => is_string( $types ) ? explode( ',', $types ) : $types,
							),
						)
					);
					$files = tokenforge_crucible_scss_file_save( $name, $clean[ $name ] );
					// A page file compiles by itself; a partial is judged inside the Global SCSS that loads it.
					$alone = $clean[ $name ]['pages'] || $clean[ $name ]['types'];
					// The save compiled the page files that changed: this reads their results, and compiles only what that left out.
					$built = tokenforge_crucible_page_css_build( $files );
					$r     = $alone ? array( 'error' => isset( $built[ $name ] ) ? $built[ $name ] : null ) : tokenforge_crucible_compile( (string) get_option( tokenforge_crucible_scss_option(), '' ) );
					// A partial may be loaded only by page files: check those too, so its error is not missed.
					foreach ( $alone ? array() : $built as $error ) {
						if ( empty( $r['error'] ) && $error ) {
							$r['error'] = $error;
						}
					}

					return rest_ensure_response(
						array(
							'ok'     => true,
							'file'   => $one( $name, $files[ $name ] ),
							'rev'    => md5( $code ),
							'status' => array(
								'ok'    => empty( $r['error'] ),
								'error' => $r['error'],
							),
							'files'  => tokenforge_crucible_scss_files_list( $files ),
						)
					);
				},
			),
		)
	);
}
add_action( 'rest_api_init', 'tokenforge_crucible_scss_files_routes' );
