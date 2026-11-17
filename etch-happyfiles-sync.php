<?php
/**
 * Plugin Name:       Etch HappyFiles Sync
 * Description:       Keeps HappyFiles media folders and Etch media collections in step, both ways: folders, nesting, order, and which files sit in which folder.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            NormadicStudio
 * Author URI:        https://normadicstudio.com
 * License:           GPL-2.0-or-later
 * Text Domain:       etch-happyfiles-sync
 *
 * How it works: both plugins store folders as a hierarchical taxonomy on attachments
 * (HappyFiles: happyfiles_category, Etch: etch_collection). Every folder gets a twin in the
 * other taxonomy, linked by the term meta _ehs_twin on both sides. Core term hooks mirror
 * each change to the twin; a re-entrancy guard stops the mirror from mirroring itself.
 */

namespace NormadicStudio\EtchHappyFilesSync;

defined( 'ABSPATH' ) || exit;

final class Sync {

	const HF       = 'happyfiles_category';
	const ETCH     = 'etch_collection';
	const TWIN_KEY = '_ehs_twin';

	/** Position meta key per taxonomy. */
	const POSITION = array(
		self::HF   => 'happyfiles_position',
		self::ETCH => 'position',
	);

	/** True while this plugin itself is writing, so its own writes are not mirrored back. */
	private static bool $busy = false;

	/** Twin ids of terms about to be deleted (term meta may be gone by delete_term). */
	private static array $doomed = array();

	public static function boot(): void {
		add_action( 'created_term', array( __CLASS__, 'on_created' ), 20, 3 );
		add_action( 'edited_term', array( __CLASS__, 'on_edited' ), 20, 3 );
		add_action( 'pre_delete_term', array( __CLASS__, 'on_pre_delete' ), 10, 2 );
		add_action( 'delete_term', array( __CLASS__, 'on_deleted' ), 20, 3 );
		add_action( 'added_term_meta', array( __CLASS__, 'on_meta' ), 20, 4 );
		add_action( 'updated_term_meta', array( __CLASS__, 'on_meta' ), 20, 4 );
		add_action( 'set_object_terms', array( __CLASS__, 'on_object_terms' ), 20, 4 );
		add_action( 'deleted_term_relationships', array( __CLASS__, 'on_relationships_deleted' ), 20, 3 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'etch-happyfiles-sync', __NAMESPACE__ . '\\Cli' );
		}
	}

	/* ---------------------------------------------------------------- helpers */

	/** Both taxonomies registered, i.e. both plugins active. */
	public static function ready(): bool {
		return taxonomy_exists( self::HF ) && taxonomy_exists( self::ETCH );
	}

	private static function other( string $taxonomy ): ?string {
		if ( self::HF === $taxonomy ) {
			return self::ETCH;
		}
		if ( self::ETCH === $taxonomy ) {
			return self::HF;
		}
		return null;
	}

	/** Run $fn with mirroring switched off for nested hooks. */
	private static function quietly( callable $fn ) {
		$was        = self::$busy;
		self::$busy = true;
		try {
			return $fn();
		} finally {
			self::$busy = $was;
		}
	}

	private static function should_handle( string $taxonomy ): bool {
		return ! self::$busy && null !== self::other( $taxonomy ) && self::ready();
	}

	/** The linked twin of a term, or 0. Clears a link that points at a term which no longer exists. */
	public static function twin( int $term_id, string $taxonomy ): int {
		$twin_id = (int) get_term_meta( $term_id, self::TWIN_KEY, true );
		if ( ! $twin_id ) {
			return 0;
		}
		$twin = get_term( $twin_id, self::other( $taxonomy ) );
		if ( $twin instanceof \WP_Term ) {
			return $twin_id;
		}
		self::quietly( fn() => delete_term_meta( $term_id, self::TWIN_KEY ) );
		return 0;
	}

	private static function link( int $a, int $b ): void {
		self::quietly(
			function () use ( $a, $b ) {
				update_term_meta( $a, self::TWIN_KEY, $b );
				update_term_meta( $b, self::TWIN_KEY, $a );
			}
		);
	}

	/**
	 * The twin of a term, created (parents first) if it does not exist yet. An unlinked
	 * folder with the same name under the same parent is adopted instead of duplicated.
	 */
	public static function ensure_twin( int $term_id, string $taxonomy ): int {
		$existing = self::twin( $term_id, $taxonomy );
		if ( $existing ) {
			return $existing;
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return 0;
		}
		$target = self::other( $taxonomy );
		$parent = $term->parent ? self::ensure_twin( (int) $term->parent, $taxonomy ) : 0;

		$found   = term_exists( $term->name, $target, $parent );
		$twin_id = 0;
		if ( $found && ! get_term_meta( (int) $found['term_id'], self::TWIN_KEY, true ) ) {
			$twin_id = (int) $found['term_id'];
		} else {
			$created = self::quietly( fn() => wp_insert_term( $term->name, $target, array( 'parent' => $parent ) ) );
			if ( is_wp_error( $created ) ) {
				// Name taken by a folder already linked elsewhere: make it unique and retry once.
				$created = self::quietly( fn() => wp_insert_term( $term->name . ' (' . $term_id . ')', $target, array( 'parent' => $parent ) ) );
			}
			if ( is_wp_error( $created ) ) {
				return 0;
			}
			$twin_id = (int) $created['term_id'];
		}

		self::link( $term_id, $twin_id );

		$position = get_term_meta( $term_id, self::POSITION[ $taxonomy ], true );
		if ( '' !== $position ) {
			self::quietly( fn() => update_term_meta( $twin_id, self::POSITION[ $target ], (int) $position ) );
		}
		return $twin_id;
	}

	/** Make an attachment's folders in the other taxonomy match its folders in $taxonomy. */
	public static function mirror_object( int $object_id, string $taxonomy ): void {
		if ( 'attachment' !== get_post_type( $object_id ) ) {
			return;
		}
		$target = self::other( $taxonomy );
		$ids    = wp_get_object_terms( $object_id, $taxonomy, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $ids ) ) {
			return;
		}
		$twins = array_values( array_filter( array_map( fn( $id ) => self::ensure_twin( (int) $id, $taxonomy ), $ids ) ) );
		self::quietly( fn() => wp_set_object_terms( $object_id, $twins, $target, false ) );
	}

	/* ------------------------------------------------------------------ hooks */

	public static function on_created( $term_id, $tt_id, $taxonomy ): void {
		if ( self::should_handle( $taxonomy ) ) {
			self::ensure_twin( (int) $term_id, $taxonomy );
		}
	}

	/** Rename or move: copy name and (twinned) parent across. */
	public static function on_edited( $term_id, $tt_id, $taxonomy ): void {
		if ( ! self::should_handle( $taxonomy ) ) {
			return;
		}
		$term = get_term( (int) $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return;
		}
		$twin_id = self::ensure_twin( (int) $term_id, $taxonomy );
		if ( ! $twin_id ) {
			return;
		}
		$target = self::other( $taxonomy );
		$twin   = get_term( $twin_id, $target );
		$parent = $term->parent ? self::ensure_twin( (int) $term->parent, $taxonomy ) : 0;

		if ( $twin->name !== $term->name || (int) $twin->parent !== $parent ) {
			self::quietly( fn() => wp_update_term( $twin_id, $target, array( 'name' => $term->name, 'parent' => $parent ) ) );
		}
	}

	public static function on_pre_delete( $term_id, $taxonomy ): void {
		if ( self::should_handle( $taxonomy ) ) {
			self::$doomed[ $taxonomy . ':' . $term_id ] = self::twin( (int) $term_id, $taxonomy );
		}
	}

	/** Deleting a folder deletes its twin. Files are never deleted, only unassigned. */
	public static function on_deleted( $term_id, $tt_id, $taxonomy ): void {
		$key = $taxonomy . ':' . $term_id;
		if ( ! isset( self::$doomed[ $key ] ) ) {
			return;
		}
		$twin_id = self::$doomed[ $key ];
		unset( self::$doomed[ $key ] );
		if ( $twin_id && ! self::$busy ) {
			$target = self::other( $taxonomy );
			self::quietly(
				function () use ( $twin_id, $target ) {
					delete_term_meta( $twin_id, self::POSITION[ $target ] );
					wp_delete_term( $twin_id, $target );
				}
			);
		}
	}

	/** Folder order: HappyFiles' happyfiles_position <-> Etch's position. */
	public static function on_meta( $meta_id, $term_id, $meta_key, $meta_value ): void {
		if ( self::$busy ) {
			return;
		}
		$taxonomy = array_search( $meta_key, self::POSITION, true );
		if ( false === $taxonomy || ! self::ready() ) {
			return;
		}
		$term = get_term( (int) $term_id );
		if ( ! $term instanceof \WP_Term || $term->taxonomy !== $taxonomy ) {
			return;
		}
		$twin_id = self::twin( (int) $term_id, $taxonomy );
		if ( $twin_id ) {
			$target = self::other( $taxonomy );
			self::quietly( fn() => update_term_meta( $twin_id, self::POSITION[ $target ], (int) $meta_value ) );
		}
	}

	public static function on_object_terms( $object_id, $terms, $tt_ids, $taxonomy ): void {
		if ( self::should_handle( $taxonomy ) ) {
			self::mirror_object( (int) $object_id, $taxonomy );
		}
	}

	public static function on_relationships_deleted( $object_id, $tt_ids, $taxonomy ): void {
		if ( self::should_handle( $taxonomy ) ) {
			self::mirror_object( (int) $object_id, $taxonomy );
		}
	}

	/* --------------------------------------------------------------- backfill */

	/**
	 * Link or create a twin for every existing folder on both sides, then give every
	 * attachment the union of its folders from both sides. Safe to run repeatedly.
	 *
	 * @return array{folders:int, files:int}
	 */
	public static function backfill(): array {
		if ( ! self::ready() ) {
			return array( 'folders' => 0, 'files' => 0 );
		}
		$folders = 0;
		foreach ( array( self::HF, self::ETCH ) as $taxonomy ) {
			$ids = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'ids' ) );
			foreach ( is_array( $ids ) ? $ids : array() as $id ) {
				if ( ! self::twin( (int) $id, $taxonomy ) && self::ensure_twin( (int) $id, $taxonomy ) ) {
					++$folders;
				}
			}
		}

		$objects = array_unique(
			array_merge(
				self::objects_in( self::HF ),
				self::objects_in( self::ETCH )
			)
		);
		$files = 0;
		foreach ( $objects as $object_id ) {
			if ( 'attachment' !== get_post_type( $object_id ) ) {
				continue;
			}
			$hf   = wp_get_object_terms( $object_id, self::HF, array( 'fields' => 'ids' ) );
			$etch = wp_get_object_terms( $object_id, self::ETCH, array( 'fields' => 'ids' ) );
			$hf_all   = array_unique( array_merge( $hf, array_map( fn( $id ) => self::twin( (int) $id, self::ETCH ), $etch ) ) );
			$etch_all = array_unique( array_merge( $etch, array_map( fn( $id ) => self::twin( (int) $id, self::HF ), $hf ) ) );
			$hf_all   = array_values( array_filter( array_map( 'intval', $hf_all ) ) );
			$etch_all = array_values( array_filter( array_map( 'intval', $etch_all ) ) );
			if ( count( $hf_all ) !== count( $hf ) || count( $etch_all ) !== count( $etch ) ) {
				++$files;
			}
			self::quietly(
				function () use ( $object_id, $hf_all, $etch_all ) {
					wp_set_object_terms( $object_id, $hf_all, self::HF, false );
					wp_set_object_terms( $object_id, $etch_all, self::ETCH, false );
				}
			);
		}
		return array( 'folders' => $folders, 'files' => $files );
	}

	private static function objects_in( string $taxonomy ): array {
		$ids = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'ids' ) );
		if ( ! is_array( $ids ) || ! $ids ) {
			return array();
		}
		$objects = get_objects_in_term( $ids, $taxonomy );
		return is_array( $objects ) ? array_map( 'intval', $objects ) : array();
	}
}

/**
 * Sync HappyFiles folders and Etch collections.
 */
class Cli {

	/**
	 * Twin every existing folder and merge file assignments from both sides. Safe to re-run.
	 *
	 * @subcommand backfill
	 */
	public function backfill(): void {
		if ( ! Sync::ready() ) {
			\WP_CLI::error( 'Both HappyFiles and Etch (with media collections) must be active.' );
		}
		$r = Sync::backfill();
		\WP_CLI::success( sprintf( 'Twinned %d folder(s), merged folders on %d file(s).', $r['folders'], $r['files'] ) );
	}

	/**
	 * List folders on both sides and whether each is linked.
	 */
	public function status(): void {
		$rows = array();
		foreach ( array( Sync::HF, Sync::ETCH ) as $taxonomy ) {
			$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
			foreach ( is_array( $terms ) ? $terms : array() as $t ) {
				$rows[] = array(
					'taxonomy' => $taxonomy,
					'id'       => $t->term_id,
					'name'     => $t->name,
					'parent'   => $t->parent,
					'position' => get_term_meta( $t->term_id, Sync::POSITION[ $taxonomy ], true ),
					'files'    => $t->count,
					'twin'     => Sync::twin( $t->term_id, $taxonomy ) ?: '-',
				);
			}
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'taxonomy', 'id', 'name', 'parent', 'position', 'files', 'twin' ) );
	}
}

add_action( 'plugins_loaded', array( Sync::class, 'boot' ) );

// On activation, twin whatever already exists. Taxonomies are registered by then (init has run).
register_activation_hook(
	__FILE__,
	function () {
		Sync::backfill();
	}
);
