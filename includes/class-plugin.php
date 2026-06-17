<?php
/**
 * Plugin bootstrap: hooks, activation, batch jobs.
 *
 * @package Singular_Markdown
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Singular_Markdown_Plugin
 */
class Singular_Markdown_Plugin {

	/**
	 * Legacy option key (pre–neutral rename).
	 */
	const LEGACY_OPTION_KEY = 'flolive_md_options';

	/**
	 * Legacy cron hook.
	 */
	const LEGACY_CRON_HOOK = 'flolive_md_batch_regenerate';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	public function init() {
		Singular_Markdown_Settings::init();
		Singular_Markdown_Post_Options::init();
		add_action( 'init', array( 'Singular_Markdown_Router', 'register_rewrites' ), 5 );
		Singular_Markdown_Router::init();

		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 3 );
		add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'on_before_delete_post' ), 10, 1 );
		add_action( 'wp_trash_post', array( $this, 'on_trash_post' ), 10, 1 );
		add_action( 'permalink_manager_updated_post_uri', array( $this, 'on_permalink_manager_updated_post_uri' ), 20, 6 );
		add_action( 'updated_option', array( $this, 'on_updated_option' ), 20, 3 );

		add_action( Singular_Markdown_Settings::CRON_HOOK_BATCH, array( __CLASS__, 'run_batch_regeneration' ) );
		add_action( Singular_Markdown_Generator::CRON_HOOK_GENERATE, array( 'Singular_Markdown_Generator', 'run_scheduled_regeneration' ), 10, 1 );
		add_action( Singular_Markdown_Generator::CRON_HOOK_GENERATE_ARCHIVE, array( 'Singular_Markdown_Generator', 'run_scheduled_archive_regeneration' ), 10, 1 );
	}

	/**
	 * Plugin activation.
	 */
	public static function activate() {
		self::maybe_migrate_legacy_options();

		Singular_Markdown_Storage::ensure_directory();
		Singular_Markdown_Router::register_rewrites();
		flush_rewrite_rules( false );
		Singular_Markdown_Settings::schedule_full_regeneration();
	}

	/**
	 * Copy options from legacy floLIVE-prefixed plugin if present.
	 */
	private static function maybe_migrate_legacy_options() {
		$legacy = get_option( self::LEGACY_OPTION_KEY, null );
		if ( ! is_array( $legacy ) ) {
			return;
		}
		$current = get_option( Singular_Markdown_Settings::OPTION_KEY, false );
		if ( false !== $current && null !== $current ) {
			delete_option( self::LEGACY_OPTION_KEY );
			wp_clear_scheduled_hook( self::LEGACY_CRON_HOOK );
			return;
		}
		update_option( Singular_Markdown_Settings::OPTION_KEY, array_merge( Singular_Markdown_Settings::defaults(), $legacy ), false );
		delete_option( self::LEGACY_OPTION_KEY );
		wp_clear_scheduled_hook( self::LEGACY_CRON_HOOK );
	}

	/**
	 * Plugin deactivation.
	 */
	public static function deactivate() {
		flush_rewrite_rules( false );
		wp_clear_scheduled_hook( Singular_Markdown_Settings::CRON_HOOK_BATCH );
	}

	/**
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an update.
	 */
	public function on_save_post( $post_id, $post, $update ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		Singular_Markdown_Post_Type_Registry::clear_post_eligibility_cache( $post_id );
		$this->schedule_listing_page_if_configured( $post_id );

		if ( ! Singular_Markdown_Post_Type_Registry::is_post_eligible( $post_id ) ) {
			Singular_Markdown_Storage::delete( $post_id );
			$this->schedule_listing_pages_for_post_type( $post->post_type );
			return;
		}

		if ( Singular_Markdown_Post_Options::uses_custom_markdown( $post_id ) ) {
			$md = Singular_Markdown_Post_Options::get_filtered_custom_markdown( $post_id );
			if ( false !== $md && '' !== trim( (string) $md ) ) {
				Singular_Markdown_Storage::write( $post_id, $md );
			}
			$this->schedule_listing_pages_for_post_type( $post->post_type );
			return;
		}

		Singular_Markdown_Generator::schedule_regeneration( $post_id );
		$this->schedule_listing_pages_for_post_type( $post->post_type );
	}

	/**
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public function on_transition_post_status( $new_status, $old_status, $post ) {
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}
		Singular_Markdown_Post_Type_Registry::clear_post_eligibility_cache( $post->ID );
		if ( 'publish' === $new_status && Singular_Markdown_Post_Type_Registry::is_post_eligible( $post->ID ) ) {
			// Markdown is refreshed in save_post (priority 20), after Singular_Markdown_Post_Options saves meta (priority 15).
			return;
		}
		if ( 'publish' === $old_status && 'publish' !== $new_status ) {
			Singular_Markdown_Storage::delete( $post->ID );
			$this->schedule_listing_pages_for_post_type( $post->post_type );
		}
	}

	/**
	 * @param int $post_id Post ID.
	 */
	public function on_before_delete_post( $post_id ) {
		$post = get_post( (int) $post_id );
		Singular_Markdown_Storage::delete( (int) $post_id );
		if ( $post instanceof WP_Post ) {
			$this->schedule_listing_pages_for_post_type( $post->post_type );
		}
	}

	/**
	 * @param int $post_id Post ID.
	 */
	public function on_trash_post( $post_id ) {
		$post = get_post( (int) $post_id );
		Singular_Markdown_Storage::delete( (int) $post_id );
		if ( $post instanceof WP_Post ) {
			$this->schedule_listing_pages_for_post_type( $post->post_type );
		}
	}

	/**
	 * Refresh Markdown when Permalink Manager changes a singular post URI.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $new_uri     New custom URI.
	 * @param string $old_uri     Previous custom URI.
	 * @param string $native_uri  Native URI.
	 * @param string $default_uri Default URI.
	 * @param bool   $uri_saved   Whether the URI was saved.
	 */
	public function on_permalink_manager_updated_post_uri( $post_id, $new_uri = '', $old_uri = '', $native_uri = '', $default_uri = '', $uri_saved = true ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}
		if ( false === $uri_saved && (string) $new_uri === (string) $old_uri ) {
			return;
		}

		$this->refresh_post_markdown( $post_id );
	}

	/**
	 * Refresh Markdown when permalink-related options change.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Previous value.
	 * @param mixed  $value     New value.
	 */
	public function on_updated_option( $option, $old_value, $value ) {
		if ( in_array( $option, array( 'permalink_structure', 'permalink-manager-permastructs' ), true ) ) {
			if ( $old_value !== $value ) {
				Singular_Markdown_Settings::schedule_full_regeneration();
				Singular_Markdown_Settings::schedule_listing_pages_regeneration();
			}
			return;
		}

		if ( 'permalink-manager-uris' !== $option || $old_value === $value ) {
			return;
		}

		$old_value = is_array( $old_value ) ? $old_value : array();
		$value     = is_array( $value ) ? $value : array();
		$post_ids  = array_unique( array_filter( array_map( 'absint', array_merge( array_keys( $old_value ), array_keys( $value ) ) ) ) );

		foreach ( $post_ids as $post_id ) {
			$old_uri = isset( $old_value[ $post_id ] ) ? $old_value[ $post_id ] : null;
			$new_uri = isset( $value[ $post_id ] ) ? $value[ $post_id ] : null;
			if ( $old_uri !== $new_uri ) {
				$this->refresh_post_markdown( $post_id );
			}
		}
	}

	/**
	 * Delete stale cache and schedule fresh Markdown for a post.
	 *
	 * @param int $post_id Post ID.
	 */
	private function refresh_post_markdown( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		Singular_Markdown_Post_Type_Registry::clear_post_eligibility_cache( $post_id );
		Singular_Markdown_Storage::delete( $post_id );

		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		if ( ! Singular_Markdown_Post_Type_Registry::is_post_eligible( $post_id ) ) {
			$this->schedule_listing_pages_for_post_type( $post->post_type );
			return;
		}

		if ( Singular_Markdown_Post_Options::uses_custom_markdown( $post_id ) ) {
			$md = Singular_Markdown_Post_Options::get_filtered_custom_markdown( $post_id );
			if ( false !== $md && '' !== trim( (string) $md ) ) {
				Singular_Markdown_Storage::write( $post_id, $md );
			}
		} else {
			Singular_Markdown_Generator::schedule_regeneration( $post_id );
		}

		$this->schedule_listing_pages_for_post_type( $post->post_type );
	}

	/**
	 * Refresh configured listing pages that include this post type.
	 *
	 * @param string $post_type Post type.
	 */
	private function schedule_listing_pages_for_post_type( $post_type ) {
		$post_type = sanitize_key( (string) $post_type );
		if ( '' === $post_type ) {
			return;
		}
		foreach ( Singular_Markdown_Settings::get_listing_pages() as $mapping ) {
			if ( empty( $mapping['page_id'] ) || empty( $mapping['post_type'] ) || $post_type !== $mapping['post_type'] ) {
				continue;
			}
			$path = wp_parse_url( get_permalink( (int) $mapping['page_id'] ), PHP_URL_PATH );
			if ( is_string( $path ) && '' !== $path ) {
				Singular_Markdown_Generator::schedule_archive_regeneration( $path );
			}
		}
	}

	/**
	 * Refresh a listing page when the page itself changes.
	 *
	 * @param int $post_id Post ID.
	 */
	private function schedule_listing_page_if_configured( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}
		foreach ( Singular_Markdown_Settings::get_listing_pages() as $mapping ) {
			if ( empty( $mapping['page_id'] ) || (int) $mapping['page_id'] !== $post_id ) {
				continue;
			}
			$path = wp_parse_url( get_permalink( $post_id ), PHP_URL_PATH );
			if ( is_string( $path ) && '' !== $path ) {
				Singular_Markdown_Generator::schedule_archive_regeneration( $path );
			}
		}
	}

	/**
	 * Background batch regeneration.
	 */
	public static function run_batch_regeneration() {
		$opts   = Singular_Markdown_Settings::get_options();
		$offset = isset( $opts['batch_offset'] ) ? (int) $opts['batch_offset'] : 0;
		$batch  = 15;

		$ids = Singular_Markdown_Post_Type_Registry::query_published_ids( $offset, $batch );
		foreach ( $ids as $id ) {
			if ( Singular_Markdown_Post_Type_Registry::is_post_eligible( $id ) ) {
				Singular_Markdown_Generator::generate_and_cache( $id );
			} else {
				Singular_Markdown_Storage::delete( $id );
			}
		}

		if ( count( $ids ) < $batch ) {
			$opts['batch_offset'] = 0;
			update_option( Singular_Markdown_Settings::OPTION_KEY, $opts, false );
			return;
		}

		$opts['batch_offset'] = $offset + $batch;
		update_option( Singular_Markdown_Settings::OPTION_KEY, $opts, false );
		if ( ! wp_next_scheduled( Singular_Markdown_Settings::CRON_HOOK_BATCH ) ) {
			wp_schedule_single_event( time() + 10, Singular_Markdown_Settings::CRON_HOOK_BATCH );
		}
	}
}
