<?php
/**
 * Plugin Name:       	Singular Markdown
 * Plugin URI: 			https://github.com/shoot56/singular-markdown
 * Description:         Markdown alternates for public singular content at permalink + .md, with Link headers on HTML responses.
 * Version:             1.4.11
 * Requires at least:   6.0
 * Requires PHP:        7.4
 * Author:              Dmitry Shutko
 * Author URI: 			https://procoders.tech
 * License:             GPL-2.0-or-later
 * Text Domain:         singular-markdown
 * 
 * GitHub Plugin URI: shoot56/singular-markdown
 * Primary Branch: main
 *
 * @package Singular_Markdown
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SINGULAR_MARKDOWN_VERSION', '1.4.11' );
define( 'SINGULAR_MARKDOWN_PLUGIN_FILE', __FILE__ );
define( 'SINGULAR_MARKDOWN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SINGULAR_MARKDOWN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SINGULAR_MARKDOWN_PLUGIN_DIR . 'includes/class-eligibility.php';
require_once SINGULAR_MARKDOWN_PLUGIN_DIR . 'includes/class-post-type-registry.php';
require_once SINGULAR_MARKDOWN_PLUGIN_DIR . 'includes/class-markdown-storage.php';
require_once SINGULAR_MARKDOWN_PLUGIN_DIR . 'includes/class-markdown-post-options.php';
require_once SINGULAR_MARKDOWN_PLUGIN_DIR . 'includes/class-markdown-generator.php';
require_once SINGULAR_MARKDOWN_PLUGIN_DIR . 'includes/class-markdown-router.php';
require_once SINGULAR_MARKDOWN_PLUGIN_DIR . 'includes/class-settings.php';
require_once SINGULAR_MARKDOWN_PLUGIN_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'Singular_Markdown_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Singular_Markdown_Plugin', 'deactivate' ) );

Singular_Markdown_Plugin::instance()->init();
