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
						$error_msg = $response->get_error_message();
						WP_CLI::error( sprintf( esc_html__( 'The API returned an error: %1$s', 'wpcli-assignment-2' ) ), $error_msg );
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
							$remote_post_id = $post->id;
							$author_id      = $this->create_user_from_author_id( $url, $post->author );
							$title          = $post->title->rendered;
							$content        = ( isset( $post->content->rendered ) && ! empty( $post->content->rendered ) ) ? $post->content->rendered : '';
							$excerpt        = ( isset( $post->excerpt->rendered ) && ! empty( $post->excerpt->rendered ) ) ? $post->excerpt->rendered : '';
							$status         = ( isset( $post->status ) && ! empty( $post->status ) ) ? $post->status : '';

							// This code is commented just to avoid creating posts on every debug.
							// Under development.

							/*$post_id       = wp_insert_post(
								array(
									'post_title'   => $title,
									'post_content' => $content,
									'post_excerpt' => $excerpt,
									'post_status'  => $status,
									'post_author'  => $author_id,
									'post_type'    => 'post'
								)
							);*/
							$post_id = 316;

							var_dump( $post->categories );

							return;

							// check if the featured image is set
							if ( isset( $post->featured_media ) && ! empty( $post->featured_media ) ) {
								//$this->update_featured_image( $url, $post->featured_media, $post_id );
							}

							// check if the post categories are set
							if ( isset( $post->categories ) && ! empty( $post->categories ) && is_array( $post->categories ) ) {
								$this->update_post_categories( $url, $post->categories, $post_id, $remote_post_id );
							}

							// check if the post tags are set
							if ( isset( $post->tags ) && ! empty( $post->tags ) && is_array( $post->tags ) ) {
								$this->update_post_tags( $url, $post->tags, $post_id, $remote_post_id );
							}

							var_dump( $post->categories );

							return;
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

		/**
		 * This function checks for the existing user from the fetched user id.
		 * If the user exists, user ID is returned, else a new user is created.
		 *
		 * @param $url
		 * @param $user_id
		 *
		 * @return bool|int
		 */
		public function create_user_from_author_id( $url, $user_id ) {

			$response = wp_remote_get( "{$url}/wp-json/wp/v2/users/{$user_id}" );
			if ( is_wp_error( $response ) ) {
				$error_msg = $response->get_error_message();
				WP_CLI::error( sprintf( esc_html__( 'The API returned an error: %1$s', 'wpcli-assignment-2' ) ), $error_msg );
			}

			// Get the body.
			$user_data = json_decode( wp_remote_retrieve_body( $response ) );
			$username  = $user_data->name;
			if ( username_exists( $username ) ) {
				/**
				 * If the username exists, we shall return the user id of the existing user
				 */
				$user = get_user_by( 'login', $username );

				return $user->ID;
			} else {
				echo 'does not exist';

				return false;
				/*$username = 'username123';
				$password = 'pasword123';
				$email = 'drew@example.com';
				$user_id = wp_create_user( $username, $password, $email );
				$user = get_user_by( 'id', $user_id );
				$user->remove_role( 'subscriber' );
				$user->add_role( 'administrator' );*/
			}
		}

		/**
		 * This function adds the featured image to the post created.
		 *
		 * @param $url
		 * @param $media_id
		 */
		public function update_featured_image( $url, $media_id, $post_id ) {

			$response = wp_remote_get( "{$url}/wp-json/wp/v2/media/{$media_id}" );
			if ( is_wp_error( $response ) ) {
				$error_msg = $response->get_error_message();
				WP_CLI::error( sprintf( esc_html__( 'The API returned an error: %1$s', 'wpcli-assignment-2' ) ), $error_msg );
			}

			// Get the body.
			$media_data = json_decode( wp_remote_retrieve_body( $response ) );
			if ( isset( $media_data->guid->rendered ) && ! empty( $media_data->guid->rendered ) ) {
				$image_url = $media_data->guid->rendered;

				// Add Featured Image to Post
				$image_name       = basename( $image_url );
				$upload_dir       = wp_upload_dir(); // Set upload folder
				$image_data       = file_get_contents( $image_url ); // Get image data
				$unique_file_name = wp_unique_filename( $upload_dir['path'], $image_name ); // Generate unique name
				$filename         = basename( $unique_file_name ); // Create image file name

				// Check folder permission and define file location
				if ( wp_mkdir_p( $upload_dir['path'] ) ) {
					$file = $upload_dir['path'] . '/' . $filename;
				} else {
					$file = $upload_dir['basedir'] . '/' . $filename;
				}

				// Create the image  file on the server
				file_put_contents( $file, $image_data );

				// Check image file type
				$wp_filetype = wp_check_filetype( $filename, null );

				// Set attachment data
				$attachment = array(
					'post_mime_type' => $wp_filetype['type'],
					'post_title'     => sanitize_file_name( $filename ),
					'post_content'   => '',
					'post_status'    => 'inherit'
				);

				// Create the attachment
				$attach_id = wp_insert_attachment( $attachment, $file, $post_id );

				// Include image.php
				require_once( ABSPATH . 'wp-admin/includes/image.php' );

				// Define attachment metadata
				$attach_data = wp_generate_attachment_metadata( $attach_id, $file );

				// Assign metadata to attachment
				wp_update_attachment_metadata( $attach_id, $attach_data );

				// And finally assign featured image to post
				set_post_thumbnail( $post_id, $attach_id );

				return;
			}

		}

		public function update_post_categories( $url, $categories, $post_id, $remote_post_id ) {

			$response = wp_remote_get( "{$url}/wp-json/wp/v2/categories?post={$remote_post_id}" );
			if ( is_wp_error( $response ) ) {
				$error_msg = $response->get_error_message();
				WP_CLI::error( sprintf( esc_html__( 'The API returned an error: %1$s', 'wpcli-assignment-2' ) ), $error_msg );
			}

			// Get the body.
			$categories_data = json_decode( wp_remote_retrieve_body( $response ) );
			debug( $categories_data );
			return;
		}

		public function update_post_tags( $url, $categories, $post_id, $remote_post_id ) {

		}
	}
endif;
