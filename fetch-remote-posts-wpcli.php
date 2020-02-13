<?php
/**
 * The plugin bootstrap file
 *
 * @link        https://github.com/vermadarsh/fetch-posts-rest-api-wpcli
 * @since       1.0.0
 * @package     FP_Fetch_Remote_Posts
 *
 * Plugin Name: Fetch Remote Posts - WPCLI
 * Author:      Adarsh Verma
 * Author URI:  https://github.com/vermadarsh/
 * Version:     1.0.0
 * Description: This plugin creates a custom wp-cli command that helps fetching posts from a remote WordPress URL.
 * Text Domain: fetchremoteposts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add a custom command wpcli `fetch_posts`.
 *
 * There are 2 parameters used.
 * 1. --website=<wordpress_website_url>
 * 2. --post_type=<any_post_type> optional. Default is post.
 * In case multiple post types are to be imported, provide the comma-separated list. Use --post_type=CPT1,CPT2,CPT3
 *
 * Sample: wp fetch_posts from --website=https://example.com --post_type=post
 */
function frpwp_initialize_wpcli() {
	WP_CLI::add_command( 'fetch_posts', 'FP_Fetch_Remote_Posts' );
}

add_action( 'cli_init', 'frpwp_initialize_wpcli' );

/**
 * This file holds the code that imports the posts from a remote WordPress website.
 */
require_once __DIR__ . '/class-fp-fetch-remote-posts.php';
