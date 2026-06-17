<?php
/**
 * Fetches rendered HTML and converts main content to Markdown.
 *
 * @package Singular_Markdown
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Singular_Markdown_Generator
 */
class Singular_Markdown_Generator {

	const CRON_HOOK_GENERATE = 'singular_markdown_generate_post';

	const CRON_HOOK_GENERATE_ARCHIVE = 'singular_markdown_generate_archive';

	const LOCK_PREFIX = 'singular_md_generating_';

	const LOCK_TTL = 300;

	/**
	 * Default CSS selectors / tag names to strip from main content.
	 *
	 * @return string[]
	 */
	public static function default_excluded_selectors() {
		$selectors = array(
			'header',
			'footer',
			'nav',
			'script',
			'style',
			'noscript',
			'iframe',
			'svg',
			'form',
			'.breadcrumbs-area',
			'.toc',
			'.news-single__meta',
			'.news-single__meta_bottom',
			'.news-single__share-lnk',
			'.navbar-toggler',
			'.start',
		);

		$opts = Singular_Markdown_Settings::get_options();
		$raw  = isset( $opts['extra_strip_selectors'] ) ? (string) $opts['extra_strip_selectors'] : '';
		foreach ( Singular_Markdown_Settings::parse_selector_lines( $raw ) as $sel ) {
			if ( ! in_array( $sel, $selectors, true ) ) {
				$selectors[] = $sel;
			}
		}

		/**
		 * Additional selectors (tags, #id, or .class) removed before Markdown conversion.
		 *
		 * @param string[] $selectors Selector list.
		 */
		return apply_filters( 'singular_markdown_excluded_selectors', $selectors );
	}

	/**
	 * CSS selectors for main HTML fragment extraction (tag, #id, or .class per line).
	 *
	 * @return string[]
	 */
	public static function get_main_content_selector_candidates() {
		$opts = Singular_Markdown_Settings::get_options();
		$raw  = isset( $opts['main_content_selectors'] ) ? (string) $opts['main_content_selectors'] : '';
		$lines = Singular_Markdown_Settings::parse_selector_lines( $raw );

		$defaults = array(
			'.main-wrap',
			'main',
			'article',
			'.entry-content',
			'.wp-block-post-content',
		);

		$merged = array();
		foreach ( array_merge( $lines, $defaults ) as $sel ) {
			$sel = trim( (string) $sel );
			if ( '' === $sel ) {
				continue;
			}
			if ( ! in_array( $sel, $merged, true ) ) {
				$merged[] = $sel;
			}
		}

		/**
		 * Selectors tried in order when extracting main content from rendered HTML.
		 *
		 * @param string[] $selectors Selector list (tag, #id, or .class).
		 */
		return apply_filters( 'singular_markdown_main_content_selectors', $merged );
	}

	/**
	 * Build Markdown for a post and write to cache.
	 *
	 * @param int|string $post_id Post ID or archive lock identifier.
	 * @return string|false Markdown or false on failure.
	 */
	public static function generate_and_cache( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return false;
		}

		if ( ! Singular_Markdown_Post_Type_Registry::is_post_eligible( $post_id ) ) {
			Singular_Markdown_Storage::delete( $post_id );
			return false;
		}

		if ( Singular_Markdown_Post_Options::uses_custom_markdown( $post_id ) ) {
			$md = Singular_Markdown_Post_Options::get_filtered_custom_markdown( $post_id );
			if ( false === $md || '' === trim( (string) $md ) ) {
				return false;
			}
			Singular_Markdown_Storage::write( $post_id, $md );
			return $md;
		}

		if ( ! self::acquire_generation_lock( $post_id ) ) {
			return false;
		}

		try {
			$md = self::generate_markdown( $post_id );
			if ( false === $md || '' === $md ) {
				return false;
			}

			Singular_Markdown_Storage::write( $post_id, $md );
			return $md;
		} finally {
			self::release_generation_lock( $post_id );
		}
	}

	/**
	 * Schedule a controlled background regeneration for one post.
	 *
	 * @param int $post_id Post ID.
	 * @param int $delay   Delay in seconds.
	 * @return bool
	 */
	public static function schedule_regeneration( $post_id, $delay = 5 ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || ! Singular_Markdown_Post_Type_Registry::is_post_eligible( $post_id ) ) {
			return false;
		}
		if ( Singular_Markdown_Post_Options::uses_custom_markdown( $post_id ) ) {
			return false;
		}

		$args = array( $post_id );
		if ( wp_next_scheduled( self::CRON_HOOK_GENERATE, $args ) ) {
			return true;
		}

		return (bool) wp_schedule_single_event( time() + max( 1, (int) $delay ), self::CRON_HOOK_GENERATE, $args );
	}

	/**
	 * Cron callback for one-post regeneration.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function run_scheduled_regeneration( $post_id ) {
		self::generate_and_cache( (int) $post_id );
	}

	/**
	 * Schedule background regeneration for an archive Markdown cache.
	 *
	 * @param string $slug_path Archive path without .md.
	 * @param int    $delay     Delay in seconds.
	 * @return bool
	 */
	public static function schedule_archive_regeneration( $slug_path, $delay = 5 ) {
		$slug_path = self::normalize_archive_slug_path( $slug_path );
		if ( '' === $slug_path || ! self::is_archive_path_supported( $slug_path ) ) {
			return false;
		}

		$args = array( $slug_path );
		if ( wp_next_scheduled( self::CRON_HOOK_GENERATE_ARCHIVE, $args ) ) {
			return true;
		}

		return (bool) wp_schedule_single_event( time() + max( 1, (int) $delay ), self::CRON_HOOK_GENERATE_ARCHIVE, $args );
	}

	/**
	 * Cron callback for archive regeneration.
	 *
	 * @param string $slug_path Archive path without .md.
	 */
	public static function run_scheduled_archive_regeneration( $slug_path ) {
		self::generate_archive_and_cache( $slug_path );
	}

	/**
	 * Build archive Markdown and write it to cache.
	 *
	 * @param string $slug_path Archive path without .md.
	 * @return string|false
	 */
	public static function generate_archive_and_cache( $slug_path ) {
		$slug_path = self::normalize_archive_slug_path( $slug_path );
		if ( '' === $slug_path || ! self::is_archive_path_supported( $slug_path ) ) {
			return false;
		}

		$lock_id = 'archive_' . Singular_Markdown_Storage::get_archive_key( $slug_path );
		if ( ! self::acquire_generation_lock( $lock_id ) ) {
			return false;
		}

		try {
			$query = self::archive_query_for_path( $slug_path );
			if ( ! $query || ! $query->have_posts() ) {
				return false;
			}

			$markdown = self::generate_archive_markdown_from_query( $slug_path, $query );
			if ( false === $markdown || '' === trim( $markdown ) ) {
				return false;
			}

			Singular_Markdown_Storage::write_archive( $slug_path, $markdown );
			return $markdown;
		} finally {
			self::release_generation_lock( $lock_id );
		}
	}

	/**
	 * Whether a path can be treated as an archive/home listing.
	 *
	 * @param string $slug_path Archive path without .md.
	 * @return bool
	 */
	public static function is_archive_path_supported( $slug_path ) {
		$query = self::archive_query_for_path( $slug_path );
		if ( ! $query || $query->is_search() || $query->is_singular() || ! $query->have_posts() ) {
			return false;
		}
		return (bool) ( $query->is_home() || $query->is_archive() );
	}

	/**
	 * Resolve an archive URL path through WordPress rewrite rules.
	 *
	 * @param string $slug_path Archive path without .md.
	 * @return WP_Query|null
	 */
	private static function archive_query_for_path( $slug_path ) {
		global $wp_rewrite;

		$slug_path = self::normalize_archive_slug_path( $slug_path );
		if ( '' === $slug_path ) {
			return null;
		}

		$posts_page_query = self::posts_page_archive_query_for_path( $slug_path );
		if ( $posts_page_query ) {
			return $posts_page_query;
		}

		$listing_page_query = self::listing_page_archive_query_for_path( $slug_path );
		if ( $listing_page_query ) {
			return $listing_page_query;
		}

		$rules = $wp_rewrite instanceof WP_Rewrite ? $wp_rewrite->wp_rewrite_rules() : array();
		if ( empty( $rules ) || ! is_array( $rules ) ) {
			return null;
		}

		foreach ( $rules as $match => $query ) {
			if ( ! preg_match( '#^' . $match . '#', $slug_path, $matches ) ) {
				continue;
			}

			$query = (string) $query;
			for ( $i = 1, $count = count( $matches ); $i < $count; $i++ ) {
				$query = str_replace( '$matches[' . $i . ']', $matches[ $i ], $query );
			}
			$query = preg_replace( '#^index\.php\??#', '', $query );

			$vars = array();
			parse_str( (string) $query, $vars );
			$vars = array_filter(
				$vars,
				static function ( $value ) {
					return '' !== $value;
				}
			);

			if ( empty( $vars ) || isset( $vars['feed'] ) || isset( $vars['attachment'] ) ) {
				continue;
			}

			$q = new WP_Query();
			$q->query( $vars );
			if ( $q->is_404() || $q->is_singular() || $q->is_search() ) {
				continue;
			}
			if ( $q->is_home() || $q->is_archive() ) {
				return $q;
			}
		}

		return null;
	}

	/**
	 * Resolve the configured posts page as a home/archive listing.
	 *
	 * @param string $slug_path Archive path without .md.
	 * @return WP_Query|null
	 */
	private static function posts_page_archive_query_for_path( $slug_path ) {
		$page_id = (int) get_option( 'page_for_posts' );
		if ( $page_id <= 0 ) {
			return null;
		}

		$path = wp_parse_url( get_permalink( $page_id ), PHP_URL_PATH );
		if ( ! is_string( $path ) || self::normalize_archive_slug_path( $path ) !== $slug_path ) {
			return null;
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'post',
				'post_status'            => 'publish',
				'posts_per_page'         => (int) get_option( 'posts_per_page', 10 ),
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$query->is_home = true;
		return $query;
	}

	/**
	 * Resolve user-configured listing pages as archive-like queries.
	 *
	 * @param string $slug_path Archive path without .md.
	 * @return WP_Query|null
	 */
	private static function listing_page_archive_query_for_path( $slug_path ) {
		$mapping = self::listing_page_mapping_for_path( $slug_path );
		if ( empty( $mapping ) ) {
			return null;
		}

		$query = new WP_Query(
			array(
				'post_type'              => $mapping['post_type'],
				'post_status'            => 'publish',
				'posts_per_page'         => (int) apply_filters( 'singular_markdown_listing_posts_per_page', get_option( 'posts_per_page', 10 ), $mapping ),
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'singular_md_listing_page_id'   => (int) $mapping['page_id'],
				'singular_md_listing_post_type' => $mapping['post_type'],
			)
		);
		$query->is_archive = true;
		return $query;
	}

	/**
	 * Find configured listing page mapping by URL path.
	 *
	 * @param string $slug_path Archive path without .md.
	 * @return array{page_id:int,post_type:string}|null
	 */
	private static function listing_page_mapping_for_path( $slug_path ) {
		$slug_path = self::normalize_archive_slug_path( $slug_path );
		foreach ( Singular_Markdown_Settings::get_listing_pages() as $mapping ) {
			$page_id = isset( $mapping['page_id'] ) ? (int) $mapping['page_id'] : 0;
			if ( $page_id <= 0 ) {
				continue;
			}
			$path = wp_parse_url( get_permalink( $page_id ), PHP_URL_PATH );
			if ( is_string( $path ) && self::normalize_archive_slug_path( $path ) === $slug_path ) {
				return $mapping;
			}
		}

		return null;
	}

	/**
	 * @param string   $slug_path Archive path without .md.
	 * @param WP_Query $query     Archive query.
	 * @return string|false
	 */
	private static function generate_archive_markdown_from_query( $slug_path, WP_Query $query ) {
		$title = self::archive_title( $slug_path, $query );
		if ( '' === $title ) {
			return false;
		}

		$markdown = '# ' . self::escape_md_heading_text( $title ) . "\n\n";
		foreach ( $query->posts as $post ) {
			if ( ! ( $post instanceof WP_Post ) || ! Singular_Markdown_Post_Type_Registry::is_post_eligible( $post->ID ) ) {
				continue;
			}
			$post_title = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$url        = get_permalink( $post );
			if ( '' === trim( $post_title ) || ! $url ) {
				continue;
			}

			$image = self::archive_post_image_markdown( $post, $url, $post_title );
			if ( '' !== $image ) {
				$markdown .= $image . "\n\n";
			}
			$markdown .= '## [' . self::escape_md_link_text( $post_title ) . '](' . self::escape_md_url( $url ) . ")\n\n";
			$excerpt = self::archive_post_excerpt( $post );
			if ( '' !== $excerpt ) {
				$markdown .= $excerpt . "\n\n";
			}
		}

		$markdown = self::normalize_markdown_output( $markdown );

		return apply_filters( 'singular_markdown_archive_output', $markdown, $slug_path, $query );
	}

	/**
	 * @param string   $slug_path Archive path without .md.
	 * @param WP_Query $query     Archive query.
	 * @return string
	 */
	private static function archive_title( $slug_path, WP_Query $query ) {
		$title = '';
		if ( $query->is_home() ) {
			$page_id = (int) get_option( 'page_for_posts' );
			$title   = $page_id > 0 ? get_the_title( $page_id ) : get_bloginfo( 'name' );
		} elseif ( (int) $query->get( 'singular_md_listing_page_id' ) > 0 ) {
			$title = get_the_title( (int) $query->get( 'singular_md_listing_page_id' ) );
		} elseif ( $query->is_category() || $query->is_tag() || $query->is_tax() ) {
			$obj = $query->get_queried_object();
			if ( $obj instanceof WP_Term ) {
				$title = $obj->name;
			}
		} elseif ( $query->is_post_type_archive() ) {
			$post_type = $query->get( 'post_type' );
			$post_type = is_array( $post_type ) ? reset( $post_type ) : $post_type;
			$obj       = $post_type ? get_post_type_object( $post_type ) : null;
			$title     = $obj && isset( $obj->labels->name ) ? $obj->labels->name : '';
		} elseif ( $query->is_author() ) {
			$obj = $query->get_queried_object();
			if ( $obj instanceof WP_User ) {
				$title = $obj->display_name;
			}
		} elseif ( $query->is_date() ) {
			$title = trim( (string) $slug_path, '/' );
		}

		$title = html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return (string) apply_filters( 'singular_markdown_archive_title', $title, $slug_path, $query );
	}

	/**
	 * Featured image Markdown for archive/listing entries.
	 *
	 * @param WP_Post $post       Post.
	 * @param string  $post_url   Post permalink.
	 * @param string  $post_title Plain post title.
	 * @return string
	 */
	private static function archive_post_image_markdown( WP_Post $post, $post_url, $post_title ) {
		$thumbnail_id = get_post_thumbnail_id( $post );
		if ( ! $thumbnail_id ) {
			return '';
		}

		$size = apply_filters( 'singular_markdown_archive_image_size', 'medium', $post );
		$url  = wp_get_attachment_image_url( $thumbnail_id, $size );
		if ( ! $url ) {
			return '';
		}

		$alt = get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true );
		$alt = '' !== trim( (string) $alt ) ? $alt : $post_title;
		$alt = html_entity_decode( wp_strip_all_tags( (string) $alt ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return '[![' . self::escape_md_alt( $alt ) . '](' . self::escape_md_url( $url ) . ')](' . self::escape_md_url( $post_url ) . ')';
	}

	/**
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function archive_post_excerpt( WP_Post $post ) {
		$excerpt = has_excerpt( $post ) ? $post->post_excerpt : '';
		if ( '' === trim( $excerpt ) ) {
			$excerpt = wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 55 );
		}
		return trim( html_entity_decode( wp_strip_all_tags( $excerpt ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * @param string $slug_path Path.
	 * @return string
	 */
	private static function normalize_archive_slug_path( $slug_path ) {
		$slug_path = trim( (string) $slug_path, '/' );
		$base      = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$base      = is_string( $base ) ? trim( $base, '/' ) : '';
		if ( '' !== $base && 0 === strpos( $slug_path, $base . '/' ) ) {
			$slug_path = substr( $slug_path, strlen( $base ) + 1 );
		}
		return trim( $slug_path, '/' );
	}

	/**
	 * Whether this post currently has a generation lock.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_generation_locked( $post_id ) {
		$expires = (int) get_option( self::get_lock_key( $post_id ), 0 );
		if ( $expires <= 0 ) {
			return false;
		}
		if ( $expires < time() ) {
			delete_option( self::get_lock_key( $post_id ) );
			return false;
		}
		return true;
	}

	/**
	 * @param int $post_id Post ID.
	 * @return bool True when lock acquired.
	 */
	private static function acquire_generation_lock( $post_id ) {
		$key     = self::get_lock_key( $post_id );
		$expires = time() + self::LOCK_TTL;
		if ( add_option( $key, $expires, '', 'no' ) ) {
			return true;
		}

		$current = (int) get_option( $key, 0 );
		if ( $current >= time() ) {
			return false;
		}

		delete_option( $key );
		return add_option( $key, $expires, '', 'no' );
	}

	/**
	 * @param int $post_id Post ID.
	 */
	private static function release_generation_lock( $post_id ) {
		delete_option( self::get_lock_key( $post_id ) );
	}

	/**
	 * @param int $post_id Post ID.
	 * @return string Option key.
	 */
	private static function get_lock_key( $post_id ) {
		$post_id = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $post_id );
		return self::LOCK_PREFIX . $post_id;
	}

	/**
	 * Produce Markdown string for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string|false
	 */
	public static function generate_markdown( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$html = self::fetch_rendered_html( $post_id );
		if ( false === $html || '' === $html ) {
			$html = self::fallback_content_html( $post_id );
		}

		$fragment = self::extract_main_fragment_html( $html, $post_id );
		if ( '' === $fragment ) {
			$fragment = self::fallback_content_html( $post_id );
		}

		$dom = self::html_to_dom( $fragment );
		if ( ! $dom ) {
			return false;
		}

		self::strip_excluded_nodes( $dom );

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			return false;
		}

		$title = get_the_title( $post_id );
		$title = html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		self::strip_leading_title_duplicate_h1( $body, $title );

		$markdown  = '# ' . self::escape_md_heading_text( $title ) . "\n\n";
		$markdown .= self::convert_children( $body, 1 );

		$markdown = self::normalize_markdown_output( $markdown );

		/**
		 * Filter final Markdown output.
		 *
		 * @param string $markdown Markdown text.
		 * @param int    $post_id  Post ID.
		 */
		return apply_filters( 'singular_markdown_output', $markdown, $post_id );
	}

	/**
	 * Fetch public HTML for the post permalink.
	 *
	 * @param int $post_id Post ID.
	 * @return string|false
	 */
	private static function fetch_rendered_html( $post_id ) {
		$url = get_permalink( $post_id );
		if ( ! $url ) {
			return false;
		}

		$opts    = Singular_Markdown_Settings::get_options();
		$timeout = isset( $opts['fetch_timeout'] ) ? (int) $opts['fetch_timeout'] : 30;
		$timeout = max( 5, min( 120, $timeout ) );
		/**
		 * Timeout in seconds for the HTTP request that fetches rendered HTML.
		 *
		 * @param int $timeout Seconds.
		 * @param int $post_id Post ID.
		 */
		$timeout = (int) apply_filters( 'singular_markdown_fetch_timeout', $timeout, $post_id );

		$args = array(
			'timeout'     => $timeout,
			'redirection' => 5,
			'headers'     => array(
				'Accept' => 'text/html,application/xhtml+xml',
			),
		);

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) && self::should_retry_fetch_without_ssl_verification( $url, $post_id, $response ) ) {
			$args['sslverify'] = false;
			$response          = wp_remote_get( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 400 ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		return is_string( $body ) ? $body : false;
	}

	/**
	 * Whether to retry rendered HTML fetch without SSL verification.
	 *
	 * This is intended for local loopback requests with self-signed certificates.
	 * Production keeps SSL verification unless explicitly overridden by filter.
	 *
	 * @param string   $url      Permalink being fetched.
	 * @param int      $post_id  Post ID.
	 * @param WP_Error $error    Initial request error.
	 * @return bool
	 */
	private static function should_retry_fetch_without_ssl_verification( $url, $post_id, WP_Error $error ) {
		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return false;
		}

		$host        = wp_parse_url( $url, PHP_URL_HOST );
		$host        = is_string( $host ) ? strtolower( $host ) : '';
		$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		$allowed     = in_array( $environment, array( 'local', 'development' ), true ) || ( '' !== $host && preg_match( '/(^|\.)local$/', $host ) );

		/**
		 * Allow local/self-signed SSL retry for rendered HTML fetches.
		 *
		 * @param bool     $allowed Whether retry is allowed.
		 * @param int      $post_id Post ID.
		 * @param string   $url     Permalink being fetched.
		 * @param WP_Error $error   Initial request error.
		 */
		return (bool) apply_filters( 'singular_markdown_retry_fetch_without_sslverify', $allowed, $post_id, $url, $error );
	}

	/**
	 * Fallback: rendered post_content only (no full theme shell).
	 *
	 * @param int $post_id Post ID.
	 * @return string HTML fragment.
	 */
	private static function fallback_content_html( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		setup_postdata( $post );
		$html = apply_filters( 'the_content', $post->post_content );
		wp_reset_postdata();
		return '<div class="main-wrap singular-markdown-fallback">' . $html . '</div>';
	}

	/**
	 * Extract inner HTML of main content wrapper.
	 *
	 * @param string $full_html Full document HTML.
	 * @return string HTML fragment (with body wrapper for DOM).
	 */
	private static function extract_main_fragment_html( $full_html, $post_id = 0 ) {
		$dom = self::html_to_dom( $full_html );
		if ( ! $dom ) {
			return '';
		}

		$xpath = new DOMXPath( $dom );

		foreach ( self::get_post_content_selector_candidates( $post_id ) as $sel ) {
			$sel = trim( (string) $sel );
			if ( '' === $sel ) {
				continue;
			}
			$expr = self::selector_to_xpath( $sel );
			if ( '' === $expr ) {
				continue;
			}
			$nodes = @$xpath->query( $expr ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $nodes && $nodes->length > 0 ) {
				$wrapper = $nodes->item( 0 );
				if ( $wrapper instanceof DOMElement ) {
					$html = self::inner_html( $wrapper );
					if ( '' !== trim( wp_strip_all_tags( $html ) ) ) {
						return $html;
					}
				}
			}
		}

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		return $body ? self::inner_html( $body ) : '';
	}

	/**
	 * Main content selectors with post-specific Elementor/WP wrappers first.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private static function get_post_content_selector_candidates( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return self::get_main_content_selector_candidates();
		}

		$selectors = array(
			'.elementor-' . $post_id,
			'#post-' . $post_id,
		);

		foreach ( self::get_main_content_selector_candidates() as $selector ) {
			if ( ! in_array( $selector, $selectors, true ) ) {
				$selectors[] = $selector;
			}
		}

		return $selectors;
	}

	/**
	 * Parse HTML into DOMDocument with UTF-8 handling.
	 *
	 * @param string $html HTML string.
	 * @return DOMDocument|null
	 */
	private static function html_to_dom( $html ) {
		if ( '' === $html ) {
			return null;
		}
		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$wrapped = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>';
		$dom->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		return $dom;
	}

	/**
	 * Remove nodes matching excluded selectors.
	 *
	 * @param DOMDocument $dom Document whose body will be cleaned.
	 */
	private static function strip_excluded_nodes( DOMDocument $dom ) {
		$xpath = new DOMXPath( $dom );
		$remove = array();

		foreach ( self::default_excluded_selectors() as $sel ) {
			$sel = trim( (string) $sel );
			if ( '' === $sel ) {
				continue;
			}
			$expr = self::selector_to_xpath( $sel );
			if ( '' === $expr ) {
				continue;
			}
			$nodes = @$xpath->query( $expr ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $nodes ) {
				continue;
			}
			foreach ( $nodes as $n ) {
				$remove[] = $n;
			}
		}

		foreach ( $remove as $node ) {
			if ( $node && $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}
	}

	/**
	 * Map a simple selector to XPath (tags, #id, .class only).
	 *
	 * @param string $sel Selector.
	 * @return string XPath or empty.
	 */
	private static function selector_to_xpath( $sel ) {
		if ( preg_match( '/^[a-zA-Z][a-zA-Z0-9_-]*$/', $sel ) ) {
			return '//' . strtolower( $sel );
		}
		if ( 0 === strpos( $sel, '#' ) ) {
			$id = substr( $sel, 1 );
			$id = preg_replace( '/[^a-zA-Z0-9_-]/', '', $id );
			return $id ? "//*[@id='" . $id . "']" : '';
		}
		if ( 0 === strpos( $sel, '.' ) ) {
			$class = substr( $sel, 1 );
			$class = preg_replace( '/[^a-zA-Z0-9_-]/', '', $class );
			return $class ? "//*[contains(concat(' ', normalize-space(@class), ' '), ' " . $class . " ')]" : '';
		}
		return '';
	}

	/**
	 * Remove first h1 if it matches the document title (avoid duplicate top heading).
	 *
	 * @param DOMElement $root  Body element.
	 * @param string     $title Post title plain text.
	 */
	private static function strip_leading_title_duplicate_h1( DOMElement $root, $title ) {
		$xpath = new DOMXPath( $root->ownerDocument );
		$h1s   = $xpath->query( './/h1', $root );
		if ( ! $h1s || $h1s->length === 0 ) {
			return;
		}
		$first = $h1s->item( 0 );
		$text  = self::element_text_content( $first );
		if ( '' === $text ) {
			return;
		}
		$a = mb_strtolower( preg_replace( '/\s+/u', ' ', trim( $title ) ) );
		$b = mb_strtolower( preg_replace( '/\s+/u', ' ', trim( $text ) ) );
		if ( $a === $b && $first->parentNode ) {
			$first->parentNode->removeChild( $first );
		}
	}

	/**
	 * Convert block children of a node to Markdown with heading level offset.
	 *
	 * @param DOMElement $el           Element.
	 * @param int        $heading_bump Add to HTML heading level (1 = default after title #).
	 * @return string
	 */
	private static function convert_children( DOMElement $el, $heading_bump = 1 ) {
		$out = '';
		foreach ( iterator_to_array( $el->childNodes ) as $child ) {
			$out .= self::convert_node( $child, $heading_bump );
		}
		return $out;
	}

	/**
	 * Convert a single node.
	 *
	 * @param DOMNode $node         Node.
	 * @param int     $heading_bump Heading bump.
	 * @return string
	 */
	private static function convert_node( DOMNode $node, $heading_bump = 1 ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			$text = self::normalize_block_text( $node->nodeValue );
			return '' === $text ? '' : self::escape_md_inline( $text ) . "\n\n";
		}
		if ( XML_COMMENT_NODE === $node->nodeType ) {
			return '';
		}
		if ( ! ( $node instanceof DOMElement ) ) {
			return '';
		}

		$tag = strtolower( $node->nodeName );

		switch ( $tag ) {
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$lvl = (int) substr( $tag, 1 ) + (int) $heading_bump;
				$lvl = max( 1, min( 6, $lvl ) );
				$hashes = str_repeat( '#', $lvl );
				$text   = trim( self::convert_inline_children( $node ) );
				if ( '' === $text ) {
					return '';
				}
				return $hashes . ' ' . $text . "\n\n";

			case 'p':
				$t = trim( self::convert_inline_children( $node ) );
				return '' === $t ? '' : $t . "\n\n";

			case 'br':
				return '';

			case 'a':
				$t = trim( self::convert_link_element( $node ) );
				return '' === $t ? '' : $t . "\n\n";

			case 'blockquote':
				$inner = trim( self::convert_children( $node, $heading_bump ) );
				if ( '' === $inner ) {
					return '';
				}
				$lines = preg_split( '/\R/u', $inner );
				$pref  = '';
				foreach ( (array) $lines as $line ) {
					$pref .= '> ' . rtrim( (string) $line, "\n" ) . "\n";
				}
				return rtrim( $pref, "\n" ) . "\n\n";

			case 'ul':
				$buf = '';
				foreach ( iterator_to_array( $node->childNodes ) as $li ) {
					if ( ! ( $li instanceof DOMElement ) || 'li' !== strtolower( $li->nodeName ) ) {
						continue;
					}
					$item = trim( self::convert_list_item_content( $li, $heading_bump ) );
					if ( '' !== $item ) {
						$buf .= '- ' . $item . "\n";
					}
				}
				return '' === $buf ? '' : $buf . "\n";

			case 'ol':
				$idx = 1;
				$buf = '';
				foreach ( iterator_to_array( $node->childNodes ) as $li ) {
					if ( ! ( $li instanceof DOMElement ) || 'li' !== strtolower( $li->nodeName ) ) {
						continue;
					}
					$item = trim( self::convert_list_item_content( $li, $heading_bump ) );
					if ( '' !== $item ) {
						$buf .= $idx . '. ' . $item . "\n";
						++$idx;
					}
				}
				return '' === $buf ? '' : $buf . "\n";

			case 'table':
				return self::convert_table( $node, $heading_bump );

			case 'hr':
				return "---\n\n";

			case 'div':
			case 'section':
			case 'article':
			case 'main':
			case 'figure':
				return self::convert_children( $node, $heading_bump );

			case 'img':
				$image = self::convert_image_element( $node );
				return '' === $image ? '' : $image . "\n\n";

			default:
				return self::convert_inline_children( $node );
		}
	}

	/**
	 * Convert an image element to Markdown.
	 *
	 * Handles rendered lazy-load markup where src is a placeholder and the real
	 * image lives in data attributes such as NitroPack's nitro-lazy-src.
	 *
	 * @param DOMElement $img Image element.
	 * @return string Markdown image or empty.
	 */
	private static function convert_image_element( DOMElement $img ) {
		$src = self::resolve_image_src( $img );
		if ( '' === $src ) {
			return '';
		}

		return '![' . self::escape_md_alt( $img->getAttribute( 'alt' ) ) . '](' . self::escape_md_url( $src ) . ')';
	}

	/**
	 * Resolve the best usable image URL from normal and lazy-load attributes.
	 *
	 * @param DOMElement $img Image element.
	 * @return string URL or empty.
	 */
	private static function resolve_image_src( DOMElement $img ) {
		$attachment_url = self::resolve_attachment_image_src( $img );
		if ( '' !== $attachment_url ) {
			return $attachment_url;
		}

		$source_attributes = array(
			'src',
			'nitro-lazy-src',
			'data-src',
			'data-lazy-src',
			'data-original',
			'data-ll-src',
		);

		/**
		 * Ordered image URL attributes checked during Markdown conversion.
		 *
		 * @param string[]   $source_attributes Attribute names.
		 * @param DOMElement $img               Image element.
		 */
		$source_attributes = (array) apply_filters( 'singular_markdown_image_source_attributes', $source_attributes, $img );

		foreach ( $source_attributes as $attribute ) {
			$url = $img->getAttribute( (string) $attribute );
			if ( self::is_usable_media_url( $url ) ) {
				return self::normalize_media_url( $url );
			}
		}

		$srcset_attributes = array(
			'srcset',
			'nitro-lazy-srcset',
			'data-srcset',
			'data-lazy-srcset',
		);

		/**
		 * Ordered srcset attributes checked during Markdown conversion.
		 *
		 * @param string[]   $srcset_attributes Attribute names.
		 * @param DOMElement $img               Image element.
		 */
		$srcset_attributes = (array) apply_filters( 'singular_markdown_image_srcset_attributes', $srcset_attributes, $img );

		foreach ( $srcset_attributes as $attribute ) {
			$url = self::first_usable_srcset_url( $img->getAttribute( (string) $attribute ) );
			if ( '' !== $url ) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * Resolve a WordPress attachment URL from common wp-image-{id} classes.
	 *
	 * @param DOMElement $img Image element.
	 * @return string URL or empty.
	 */
	private static function resolve_attachment_image_src( DOMElement $img ) {
		if ( ! function_exists( 'wp_get_attachment_url' ) ) {
			return '';
		}

		$classes = $img->getAttribute( 'class' );
		if ( ! preg_match( '/(?:^|\s)wp-image-(\d+)(?:\s|$)/', $classes, $matches ) ) {
			return '';
		}

		$url = wp_get_attachment_url( (int) $matches[1] );
		if ( ! self::is_usable_media_url( $url ) ) {
			return '';
		}

		return self::normalize_media_url( $url );
	}

	/**
	 * Extract the first usable URL from a srcset-like value.
	 *
	 * @param string $srcset Srcset value.
	 * @return string URL or empty.
	 */
	private static function first_usable_srcset_url( $srcset ) {
		if ( '' === trim( (string) $srcset ) ) {
			return '';
		}

		foreach ( explode( ',', (string) $srcset ) as $candidate ) {
			$parts = preg_split( '/\s+/', trim( $candidate ) );
			$url   = isset( $parts[0] ) ? $parts[0] : '';
			if ( self::is_usable_media_url( $url ) ) {
				return self::normalize_media_url( $url );
			}
		}

		return '';
	}

	/**
	 * Determine whether a media URL is suitable for Markdown output.
	 *
	 * @param string $url URL candidate.
	 * @return bool
	 */
	private static function is_usable_media_url( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return false;
		}

		if ( 0 === stripos( $url, 'data:' ) || 0 === stripos( $url, 'about:' ) ) {
			return false;
		}

		return '' !== esc_url_raw( $url );
	}

	/**
	 * Normalize optimized CDN media URLs back to their source URL when possible.
	 *
	 * NitroPack URLs include the source host/path after the optimization segment,
	 * for example:
	 * cdn-*.nitrocdn.com/.../stage.example.com/wp-content/uploads/image.jpg.
	 *
	 * @param string $url URL candidate.
	 * @return string Normalized URL.
	 */
	private static function normalize_media_url( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) || false === stripos( $parts['host'], 'nitrocdn.com' ) || empty( $parts['path'] ) ) {
			return $url;
		}

		$path_segments = array_values( array_filter( explode( '/', $parts['path'] ) ) );
		$wp_index      = array_search( 'wp-content', $path_segments, true );

		if ( false === $wp_index || 0 === $wp_index || empty( $path_segments[ $wp_index + 1 ] ) ) {
			return $url;
		}

		$source_host = $path_segments[ $wp_index - 1 ];
		if ( false === strpos( $source_host, '.' ) ) {
			return $url;
		}

		$source_path = implode( '/', array_slice( $path_segments, $wp_index ) );

		return 'https://' . $source_host . '/' . $source_path;
	}

	/**
	 * List item: flatten block children with line breaks.
	 *
	 * @param DOMElement $li           Li element.
	 * @param int        $heading_bump Heading bump.
	 * @return string
	 */
	private static function convert_list_item_content( DOMElement $li, $heading_bump ) {
		$parts = array();
		foreach ( iterator_to_array( $li->childNodes ) as $c ) {
			if ( XML_TEXT_NODE === $c->nodeType ) {
				$t = trim( $c->nodeValue );
				if ( '' !== $t ) {
					$parts[] = self::escape_md_inline( $t );
				}
				continue;
			}
			if ( $c instanceof DOMElement ) {
				$tag = strtolower( $c->nodeName );
				if ( in_array( $tag, array( 'ul', 'ol', 'table', 'blockquote' ), true ) ) {
					$parts[] = trim( self::convert_node( $c, $heading_bump ) );
				} elseif ( in_array( $tag, array( 'p', 'div' ), true ) ) {
					$parts[] = trim( self::convert_inline_children( $c ) );
				} else {
					$parts[] = trim( self::convert_node( $c, $heading_bump ) );
				}
			}
		}
		return trim( preg_replace( '/\s+/u', ' ', implode( ' ', array_filter( $parts ) ) ) );
	}

	/**
	 * Simple GFM-style table conversion.
	 *
	 * @param DOMElement $table        Table node.
	 * @param int        $heading_bump Unused depth for cells.
	 * @return string
	 */
	private static function convert_table( DOMElement $table, $heading_bump ) {
		$rows = array();
		foreach ( $table->getElementsByTagName( 'tr' ) as $tr ) {
			if ( ! self::is_descendant_of_table( $table, $tr ) ) {
				continue;
			}
			$cells = array();
			foreach ( $tr->childNodes as $cell ) {
				if ( ! ( $cell instanceof DOMElement ) ) {
					continue;
				}
				$cn = strtolower( $cell->nodeName );
				if ( ! in_array( $cn, array( 'th', 'td' ), true ) ) {
					continue;
				}
				$cells[] = trim( preg_replace( '/\s+/u', ' ', self::convert_inline_children( $cell ) ) );
			}
			if ( ! empty( $cells ) ) {
				$rows[] = $cells;
			}
		}
		if ( empty( $rows ) ) {
			return '';
		}
		$width = max( array_map( 'count', $rows ) );
		foreach ( $rows as &$r ) {
			while ( count( $r ) < $width ) {
				$r[] = '';
			}
		}
		unset( $r );

		$lines   = array();
		$lines[] = '| ' . implode( ' | ', $rows[0] ) . ' |';
		$lines[] = '| ' . implode( ' | ', array_fill( 0, $width, '---' ) ) . ' |';
		for ( $i = 1, $c = count( $rows ); $i < $c; $i++ ) {
			$lines[] = '| ' . implode( ' | ', $rows[ $i ] ) . ' |';
		}
		return implode( "\n", $lines ) . "\n\n";
	}

	/**
	 * Check tr belongs to this table (not nested).
	 *
	 * @param DOMElement $table Table.
	 * @param DOMElement $tr    Row.
	 * @return bool
	 */
	private static function is_descendant_of_table( DOMElement $table, DOMElement $tr ) {
		$p = $tr->parentNode;
		while ( $p ) {
			if ( $p === $table ) {
				return true;
			}
			if ( $p instanceof DOMElement && 'table' === strtolower( $p->nodeName ) ) {
				return false;
			}
			$p = $p->parentNode;
		}
		return false;
	}

	/**
	 * Phrasing content: recurse for inline/bold/link.
	 *
	 * @param DOMElement $el Element.
	 * @return string
	 */
	private static function convert_inline_children( DOMElement $el ) {
		$out = '';
		foreach ( iterator_to_array( $el->childNodes ) as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$out .= self::escape_md_inline( self::normalize_inline_text( $child->nodeValue ) );
				continue;
			}
			if ( ! ( $child instanceof DOMElement ) ) {
				continue;
			}
			$tag = strtolower( $child->nodeName );
			switch ( $tag ) {
				case 'strong':
				case 'b':
					$inner = trim( self::convert_inline_children( $child ) );
					$out  .= '' !== $inner ? '**' . $inner . '**' : '';
					break;
				case 'em':
				case 'i':
					$inner = trim( self::convert_inline_children( $child ) );
					$out  .= '' !== $inner ? '*' . $inner . '*' : '';
					break;
				case 'code':
					$inner = trim( self::convert_inline_children( $child ) );
					$out  .= '`' . str_replace( '`', '\`', $inner ) . '`';
					break;
				case 'a':
					$out .= self::convert_link_element( $child );
					break;
				case 'br':
					$out .= ' ';
					break;
				default:
					$out .= self::convert_inline_children( $child );
					break;
			}
		}
		return $out;
	}

	/**
	 * Convert an anchor element to Markdown link text.
	 *
	 * @param DOMElement $el Link element.
	 * @return string
	 */
	private static function convert_link_element( DOMElement $el ) {
		$href = $el->getAttribute( 'href' );
		$txt  = trim( preg_replace( '/\s+/u', ' ', self::convert_inline_children( $el ) ) );
		if ( '' === $txt ) {
			return '';
		}
		if ( '' === $href ) {
			return $txt;
		}
		return '[' . self::escape_md_link_text( $txt ) . '](' . self::escape_md_url( $href ) . ')';
	}

	/**
	 * Inner HTML of an element.
	 *
	 * @param DOMElement $el Element.
	 * @return string
	 */
	private static function inner_html( DOMElement $el ) {
		$html = '';
		foreach ( iterator_to_array( $el->childNodes ) as $child ) {
			$html .= $el->ownerDocument->saveHTML( $child );
		}
		return $html;
	}

	/**
	 * Plain text of element.
	 *
	 * @param DOMElement $el Element.
	 * @return string
	 */
	private static function element_text_content( DOMElement $el ) {
		return trim( html_entity_decode( $el->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Escape inline markdown special chars lightly.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function escape_md_inline( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return str_replace( array( "\r\n", "\r" ), "\n", $text );
	}

	/**
	 * Normalize text nodes that appear directly in block-level conversion.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function normalize_block_text( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( (string) $text );
	}

	/**
	 * Normalize text nodes inside inline conversion while preserving edge spacing.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function normalize_inline_text( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( '' === trim( $text ) ) {
			return '';
		}

		$leading  = preg_match( '/^\s/u', $text ) ? ' ' : '';
		$trailing = preg_match( '/\s$/u', $text ) ? ' ' : '';
		$text     = preg_replace( '/\s+/u', ' ', trim( $text ) );
		return $leading . (string) $text . $trailing;
	}

	/**
	 * Remove template indentation and excessive blank lines from generated Markdown.
	 *
	 * @param string $markdown Markdown.
	 * @return string
	 */
	private static function normalize_markdown_output( $markdown ) {
		$markdown = str_replace( array( "\r\n", "\r" ), "\n", (string) $markdown );
		$lines    = explode( "\n", $markdown );
		$lines    = array_map( 'trim', $lines );
		$lines    = array_map(
			static function ( $line ) {
				return preg_replace( '/[ \t]{2,}/', ' ', $line );
			},
			$lines
		);
		$markdown = implode( "\n", $lines );
		$markdown = preg_replace( "/\n{3,}/", "\n\n", $markdown );
		return trim( (string) $markdown ) . "\n";
	}

	/**
	 * Heading text escape.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function escape_md_heading_text( $text ) {
		return trim( str_replace( array( "\n", "\r" ), ' ', self::escape_md_inline( $text ) ) );
	}

	/**
	 * Link label escape.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function escape_md_link_text( $text ) {
		$t = str_replace( array( '\\', '[', ']' ), array( '\\\\', '\\[', '\\]' ), $text );
		return $t;
	}

	/**
	 * URL for markdown.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function escape_md_url( $url ) {
		return str_replace( array( ' ', '(' ), array( '%20', '%28' ), esc_url_raw( $url ) );
	}

	/**
	 * Alt text.
	 *
	 * @param string $alt Alt.
	 * @return string
	 */
	private static function escape_md_alt( $alt ) {
		return str_replace( array( ']', '[' ), array( '', '' ), self::escape_md_inline( $alt ) );
	}
}
