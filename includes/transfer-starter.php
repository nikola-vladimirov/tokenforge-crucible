<?php
/**
 * Global SCSS and your recipes in Export / Import, and in the Starter reset.
 *
 * Uses the hooks TokenForge 0.54.2 adds: tokenforge_transfer_parts,
 * tokenforge_transfer_export_data, tokenforge_transfer_imported,
 * tokenforge_starter_parts and tokenforge_starter_reset. The file format
 * matches 0.53.7: data.scss and data.recipes.custom (name + code).
 *
 * @package TokenForge_Crucible
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Breakpoint setting keys (bp_xs … bp_xxl).
 *
 * @return array
 */
function tokenforge_crucible_bp_keys() {
	return array( 'bp_xs', 'bp_s', 'bp_m', 'bp_l', 'bp_xl', 'bp_xxl' );
}

add_filter(
	'tokenforge_transfer_parts',
	static function ( $parts ) {
		return array_merge( (array) $parts, array( 'scss' ) );
	}
);

add_filter(
	'tokenforge_transfer_export_data',
	static function ( $data ) {
		$sync = tokenforge_crucible_sync_settings();
		// 0.53.7 kept the breakpoints (bp_*) in this part too, so the file format matches.
		$data['scss']    = array_merge(
			array_intersect_key( (array) \TokenForge\Scss::settings(), array_flip( tokenforge_crucible_bp_keys() ) ),
			array(
				'enabled'     => tokenforge_crucible_scss_enabled(),
				'source'      => (string) get_option( tokenforge_crucible_scss_option(), '' ),
				'sync'        => $sync['sync'],
				'sync_pull'   => $sync['sync_pull'],
				'sync_create' => $sync['sync_create'],
			)
		);
		$rec             = isset( $data['recipes'] ) && is_array( $data['recipes'] ) ? $data['recipes'] : array();
		$rec['custom']   = array_map(
			static function ( $r ) {
				return array(
					'name' => $r['n'],
					'code' => $r['code'],
				);
			},
			tokenforge_crucible_recipes_saved()
		);
		$data['recipes'] = $rec;
		return $data;
	}
);

add_action(
	'tokenforge_transfer_imported',
	static function ( $done, $data ) {
		if ( in_array( 'scss', $done, true ) && isset( $data['scss'] ) && is_array( $data['scss'] ) ) {
			$in = $data['scss'];
			if ( isset( $in['source'] ) && is_string( $in['source'] ) && strlen( $in['source'] ) <= TOKENFORGE_CRUCIBLE_SCSS_MAX_BYTES ) {
				update_option( tokenforge_crucible_scss_option(), str_replace( "\r\n", "\n", $in['source'] ), false );
			}
			if ( array_key_exists( 'enabled', $in ) ) {
				update_option( 'tokenforge_crucible_scss_enabled', ! empty( $in['enabled'] ), false );
			}
			tokenforge_crucible_sync_save( array_intersect_key( $in, array_flip( array( 'sync', 'sync_pull', 'sync_create' ) ) ) );
			$bp = array_intersect_key( $in, array_flip( tokenforge_crucible_bp_keys() ) );
			if ( $bp ) {
				\TokenForge\Scss::save_settings( array_merge( (array) \TokenForge\Scss::settings(), $bp ) );
			}
			\TokenForge\Css_Generator::get_instance()->generate();
		}
		if ( in_array( 'recipes', $done, true ) && isset( $data['recipes']['custom'] ) && is_array( $data['recipes']['custom'] ) ) {
			$list = array();
			foreach ( array_slice( $data['recipes']['custom'], 0, 100 ) as $item ) {
				$name = isset( $item['name'] ) && is_string( $item['name'] ) ? \TokenForge\Recipes::name( $item['name'] ) : '';
				$code = isset( $item['code'] ) && is_string( $item['code'] ) ? substr( str_replace( "\r\n", "\n", $item['code'] ), 0, 4000 ) : '';
				// The first entry with a name wins, as in the Recipes screen.
				if ( '' !== $name && '' !== trim( $code ) && ! isset( $list[ $name ] ) ) {
					$list[ $name ] = array(
						'n'    => $name,
						'code' => $code,
					);
				}
			}
			update_option( 'tokenforge_crucible_recipes', array_values( $list ), false );
		}
	},
	10,
	2
);

add_filter(
	'tokenforge_starter_parts',
	static function ( $parts ) {
		return array_merge( (array) $parts, array( 'scss', 'recipes' ) );
	}
);

add_action(
	'tokenforge_starter_reset',
	static function ( $parts ) {
		// Settings keep the Global SCSS and your recipes, as in 0.53.7; these two parts clear them.
		if ( in_array( 'scss', $parts, true ) ) {
			update_option( tokenforge_crucible_scss_option(), '', false );
			\TokenForge\Css_Generator::get_instance()->generate();
		}
		if ( in_array( 'recipes', $parts, true ) ) {
			update_option( 'tokenforge_crucible_recipes', array(), false );
		}
	}
);
