<?php
/**
 * This file contains the class that imports posts from remote WordPress URL.
 *
 * @link     https://github.com/vermadarsh/fetch-posts-rest-api-wpcli
 * @package  FP_Fetch_Remote_Posts
 * @since    1.0.0
 */

/**
 * Exit if accessed directly.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 *
 * Check if the custom class exists.
 */
if ( ! class_exists( 'FP_Fetch_Remote_Posts' ) ) :
	/**
	 * Main class.
	 *
	 * This is used to define functions that import posts from remote WordPress URL.
	 *
	 * @since      1.0.0
	 * @package    FP_Fetch_Remote_Posts
	 * @author     Adarsh Verma <adarsh.verma@rtcamp.com>
	 */
	class FP_Fetch_Remote_Posts {

		/**
		 * This function accepts argument, sets the WP CLI command to receive url argument.
		 *
		 * @param array $args Accepts the list of indexed arguments.
		 * @param array $assoc_args Accepts the list of associative arguments.
		 */
		public function from( $args, $assoc_args ) {

			$url = WP_CLI\Utils\get_flag_value( $assoc_args, 'website' );
			if ( ! empty( $url ) && filter_var( $url, FILTER_VALIDATE_URL ) ) :

				$post_types = WP_CLI\Utils\get_flag_value( $assoc_args, 'post_type' );
				if ( ! empty( $assoc_args['post_type'] ) ) :

					/**
					 * Check if this argument sets for multiple CPTs.
					 */
					if ( false !== stripos( $post_types, ',', true ) ) :

						$post_types = explode( ',', $post_types );
						if ( ! empty( $post_types ) && is_array( $post_types ) ) :

							foreach ( $post_types as $post_type ) :
								$this->frp_import_posts( $url, $post_type );
							endforeach;

						endif;

					else :
						$this->frp_import_posts( $url, $post_types );
					endif;

				else :
					$this->frp_import_posts( $url, 'posts' );
				endif;

			else :
				/* translators: %s: remote url */
				WP_CLI::error( sprintf( esc_html__( '%1$s is not a valid URL.', 'fetchremoteposts' ), $url ) );
			endif;

		}

		/**
		 * This function imports the posts from the receiving URL and post type.
		 *
		 * @param string $url The remote WordPress URL.
		 * @param string $post_type The post type to be imported.
		 */
		protected function frp_import_posts( $url, $post_type ) {

			$remote   = "{$url}/wp-json/wp/v2/{$post_type}?per_page=9";
			$response = wp_remote_get( $remote );

			if ( is_wp_error( $response ) ) :
				$error_msg = $response->get_error_message();
				/* translators: %s: error message */
				WP_CLI::error( sprintf( esc_html__( 'The API returned an error: %1$s', 'fetchremoteposts' ) ), $error_msg );
			endif;

			// Get the body.
			$posts = json_decode( wp_remote_retrieve_body( $response ) );

			// Exit if nothing is returned.
			if ( empty( $posts ) ) :
				WP_CLI::error( esc_html__( 'There are no posts in the URL requested.', 'fetchremoteposts' ) );
			endif;

			// For each post.
			foreach ( $posts as $post ) :

				esc_html_e( 'Importing posts started..', 'fetchremoteposts' );
				echo "\n";

				if ( isset( $post->title->rendered ) && ! empty( $post->title->rendered ) ) :
					$remote_post_id = $post->id;
					$title          = $post->title->rendered;
					$content        = ( isset( $post->content->rendered ) && ! empty( $post->content->rendered ) ) ? $post->content->rendered : '';
					$excerpt        = ( isset( $post->excerpt->rendered ) && ! empty( $post->excerpt->rendered ) ) ? $post->excerpt->rendered : '';
					$status         = ( isset( $post->status ) && ! empty( $post->status ) ) ? $post->status : '';
					$post_id        = wp_insert_post(
						array(
							'post_title'   => $title,
							'post_content' => $content,
							'post_excerpt' => $excerpt,
							'post_status'  => $status,
							'post_author'  => 1, // Assigning the post to the site administrator.
							'post_type'    => $post->type,
						)
					);

					// check if the featured image is set.
					if ( isset( $post->featured_media ) && ! empty( $post->featured_media ) ) :
						$this->frp_update_featured_image( $url, $post->featured_media, $post_id );
					endif;

					// check if the post categories are set.
					if ( isset( $post->categories ) && ! empty( $post->categories ) && is_array( $post->categories ) ) :
						$this->frp_update_post_categories( $url, $post_id, $remote_post_id );
					endif;

					// check if the post tags are set.
					if ( isset( $post->tags ) && ! empty( $post->tags ) && is_array( $post->tags ) ) :
						$this->frp_update_post_tags( $url, $post_id, $remote_post_id );
					endif;

					// import post comments.
					$this->frp_import_post_comments( $url, $post_id, $remote_post_id );

				endif;
			endforeach;
			WP_CLI::success( esc_html__( 'All the posts fetched.', 'fetchremoteposts' ) );

		}

		/**
		 * Add the featured image fetched from remote url.
		 *
		 * @param string $url The remote WordPress URL.
		 * @param int    $media_id ID of the media to fetch the media details from remote URL.
		 * @param int    $post_id ID of the post imported in current website.
		 */
		protected function frp_update_featured_image( $url, $media_id, $post_id ) {

			esc_html_e( 'Fetching featured media..', 'fetchremoteposts' );
			echo "\n";
			$response = wp_remote_get( "{$url}/wp-json/wp/v2/media/{$media_id}" );

			if ( is_wp_error( $response ) ) :
				$error_msg = $response->get_error_message();
				/* translators: %s: error message */
				echo sprintf( esc_html__( 'Error while fetching featured media: %1$s', 'fetchremoteposts' ), $error_msg ) . "\n";
			endif;

			// Get the body.
			$media_data = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $media_data->guid->rendered ) && ! empty( $media_data->guid->rendered ) ) :

				global $wp_filesystem;
				esc_html_e( 'Adding featured media..', 'fetchremoteposts' );
				echo "\n";
				$image_url = $media_data->guid->rendered;

				// Add Featured Image to Post.
				$image_name = basename( $image_url );
				$upload_dir = wp_upload_dir(); // Set upload folder.

				if ( ! function_exists( 'WP_Filesystem' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}

				if ( is_null( $wp_filesystem ) ) {
					WP_Filesystem();
				}

				$image_data       = $wp_filesystem->get_contents( $image_url );
				$unique_file_name = wp_unique_filename( $upload_dir['path'], $image_name ); // Generate unique name.
				$filename         = basename( $unique_file_name ); // Create image file name.

				// Check folder permission and define file location.
				if ( wp_mkdir_p( $upload_dir['path'] ) ) :
					$file = $upload_dir['path'] . '/' . $filename;
				else :
					$file = $upload_dir['basedir'] . '/' . $filename;
				endif;

				// Create the image  file on the server.
				$wp_filesystem->put_contents(
					$file,
					$image_data,
					FS_CHMOD_FILE
				);

				// Check image file type.
				$wp_filetype = wp_check_filetype( $filename, null );

				// Set attachment data.
				$attachment = array(
					'post_mime_type' => $wp_filetype['type'],
					'post_title'     => sanitize_file_name( $filename ),
					'post_content'   => '',
					'post_status'    => 'inherit',
				);

				// Create the attachment.
				$attach_id = wp_insert_attachment( $attachment, $file, $post_id );

				// Include image.php file.
				require_once ABSPATH . 'wp-admin/includes/image.php';

				// Define attachment metadata.
				$attach_data = wp_generate_attachment_metadata( $attach_id, $file );

				// Assign metadata to attachment.
				wp_update_attachment_metadata( $attach_id, $attach_data );

				// And finally assign featured image to post.
				set_post_thumbnail( $post_id, $attach_id );
				WP_CLI::success( esc_html__( 'Featured media added to the post.', 'fetchremoteposts' ) );

			endif;

		}

		/**
		 * Update the post with categories fetched from remote url.
		 *
		 * @param string $url The remote WordPress URL.
		 * @param int    $post_id ID of the post imported in current website.
		 * @param int    $remote_post_id ID of the post imported from remote.
		 */
		protected function frp_update_post_categories( $url, $post_id, $remote_post_id ) {

			esc_html_e( 'Fetching post categories..', 'fetchremoteposts' );
			echo "\n";
			$response = wp_remote_get( "{$url}/wp-json/wp/v2/categories?post={$remote_post_id}" );

			if ( is_wp_error( $response ) ) :
				$error_msg = $response->get_error_message();
				/* translators: %s: error message */
				echo sprintf( esc_html__( 'Error while fetching post categories: %1$s', 'fetchremoteposts' ), $error_msg ) . "\n";
			endif;

			// Get the body.
			$categories_arr = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! empty( $categories_arr ) && is_array( $categories_arr ) ) :
				esc_html_e( 'Adding post categories..', 'fetchremoteposts' );
				echo "\n";
				foreach ( $categories_arr as $category_arr ) :
					$existing_term = get_term_by( 'slug', $category_arr->slug, $category_arr->taxonomy );

					if ( false === $existing_term ) :
						/**
						 * Create the term.
						 */
						$term_id = wp_insert_term(
							$category_arr->name,
							$category_arr->taxonomy,
							array(
								'description' => $category_arr->description,
								'slug'        => $category_arr->slug,
							)
						);
						wp_set_object_terms( $post_id, $term_id, $category_arr->taxonomy );
					else :
						/**
						 * Update the post with the existing term.
						 */
						wp_set_object_terms( $post_id, $existing_term->term_id, $existing_term->taxonomy );
					endif;

				endforeach;
				WP_CLI::success( esc_html__( 'Categories added to the post.', 'fetchremoteposts' ) );
			endif;

		}

		/**
		 * Update the post with tags fetched from remote url.
		 *
		 * @param string $url The remote WordPress URL.
		 * @param int    $post_id ID of the post imported in current website.
		 * @param int    $remote_post_id ID of the post imported from remote.
		 */
		protected function frp_update_post_tags( $url, $post_id, $remote_post_id ) {

			esc_html_e( 'Fetching post tags..', 'fetchremoteposts' );
			echo "\n";
			$response = wp_remote_get( "{$url}/wp-json/wp/v2/tags?post={$remote_post_id}" );

			if ( is_wp_error( $response ) ) :
				$error_msg = $response->get_error_message();
				/* translators: %s: error message */
				echo sprintf( esc_html__( 'Error while fetching post tags: %1$s', 'fetchremoteposts' ), $error_msg ) . "\n";
			endif;

			// Get the body.
			$tags_arr = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! empty( $tags_arr ) && is_array( $tags_arr ) ) :
				esc_html_e( 'Adding post tags..', 'fetchremoteposts' );
				echo "\n";
				foreach ( $tags_arr as $tag_arr ) :
					$existing_term = get_term_by( 'slug', $tag_arr->slug, $tag_arr->taxonomy );

					if ( false === $existing_term ) :
						/**
						 * Create the term.
						 */
						$term_id = wp_insert_term(
							$tag_arr->name,
							$tag_arr->taxonomy,
							array(
								'description' => $tag_arr->description,
								'slug'        => $tag_arr->slug,
							)
						);
						wp_set_object_terms( $post_id, $term_id, $tag_arr->taxonomy );
					else :
						/**
						 * Update the post with the existing term.
						 */
						wp_set_object_terms( $post_id, $existing_term->term_id, $existing_term->taxonomy );
					endif;

				endforeach;
				WP_CLI::success( esc_html__( 'Tags added to the post.', 'fetchremoteposts' ) );
			endif;

		}

		/**
		 * Fetch comments from remote url and import here
		 *
		 * @param string $url The remote WordPress URL.
		 * @param int    $post_id ID of the post imported in current website.
		 * @param int    $remote_post_id ID of the post imported from remote.
		 */
		protected function frp_import_post_comments( $url, $post_id, $remote_post_id ) {

			esc_html_e( 'Fetching post comments..', 'fetchremoteposts' );
			echo "\n";
			$response = wp_remote_get( "{$url}/wp-json/wp/v2/comments?post={$remote_post_id}" );

			if ( is_wp_error( $response ) ) :
				$error_msg = $response->get_error_message();
				/* translators: %s: error message */
				echo sprintf( esc_html__( 'Error while fetching post comments: %1$s', 'fetchremoteposts' ), $error_msg ) . "\n";
			endif;

			// Get the body.
			$comments_arr = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! empty( $comments_arr ) && is_array( $comments_arr ) ) :
				esc_html_e( 'Adding post comments..', 'fetchremoteposts' );
				echo "\n";
				foreach ( $comments_arr as $comment ) :
					$comment_content = ( isset( $comment->content->rendered ) && ! empty( $comment->content->rendered ) ) ? $comment->content->rendered : '';
					wp_insert_comment(
						array(
							'comment_approved' => 0,
							'comment_author'   => $comment->author_name,
							'comment_content'  => $comment_content,
							'comment_type'     => $comment->type,
							'comment_post_ID'  => $post_id,
						)
					);
				endforeach;
				WP_CLI::success( esc_html__( 'Comments added to the post.', 'fetchremoteposts' ) );
			endif;

		}
	}
endif;
