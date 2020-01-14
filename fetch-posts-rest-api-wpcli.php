<?php
/**
 * Plugin Name: WP_CLI Assignment 2
 * Author: Adarsh Verma
 * Version: 1.0.0
 * Description: This plugin relates to the second assignment with wp-cli.
 * Text Domain: wpcli-assignment-2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Function declared to debug code.
 */
if ( ! function_exists( 'debug' ) ) {
	function debug( $params ) {
		echo '<pre>';
		print_r( $params );
		echo '</pre>';

		return;
	}
}

/**
 * Add custom command at wp-cli init.
 */
function wa2_initialize() {
	WP_CLI::add_command( 'fetch_posts', 'wa2_fetch_posts' );
}

add_action( 'cli_init', 'wa2_initialize' );

if ( ! class_exists( 'wa2_fetch_posts' ) ) :
	class wa2_fetch_posts {
		public function __construct() {
		}

		public function from( $args ) {
			if ( isset( $args[0] ) && ! empty( $args[0] ) ) {
				$url = $args[0];
				if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
					$response = wp_remote_get( "{$url}/wp-json/wp/v2/posts?per_page=2" );
					if ( is_wp_error( $response ) ) {
						WP_CLI::error( esc_html__( 'The API returned an error.', 'wpcli-assignment-2' ) );
					}

					// Get the body.
					$posts = json_decode( wp_remote_retrieve_body( $response ) );

					// Exit if nothing is returned.
					if ( empty( $posts ) ) {
						WP_CLI::error( esc_html__( 'There are no posts in the URL requested.', 'wpcli-assignment-2' ) );
					}

					// For each post.
					foreach ( $posts as $post ) {
						if ( isset( $post->title->rendered ) && ! empty( $post->title->rendered ) ) {
							$modified_date = date( 'n/j/Y', strtotime( $post->modified ) );
							$title         = $post->title->rendered;
							debug( $title );
						}
					}
				} else {
					WP_CLI::error( sprintf( esc_html__( '%1$s is not a valid URL.', 'wpcli-assignment-2' ), $url ) );
				}

				return;
			} else {
				WP_CLI::error( 'URL parameter missing.' );
			}
		}
	}
endif;
