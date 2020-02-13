<?php
/**
 * Plugin Name: Fetch Remote Posts - WPCLI
 * Author: Adarsh Verma
 * Author URI: https://github.com/vermadarsh/
 * Version: 1.0.0
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
function frpwpInitializeWpCli() {
    WP_CLI::add_command( 'fetch_posts', 'frpwpFetchPosts' );
}

add_action( 'cli_init', 'frpwpInitializeWpCli' );

/**
 * Check if the custom class exists.
 */
if ( ! class_exists( 'frpwpFetchPosts' ) ) :
    class frpwpFetchPosts {

        /**
         * This function accepts argument, sets the WP CLI command to receive url argument.
         *
         * @param $args
         * @param $assoc_args
         *
         * @return string
         */
        function from( $args, $assoc_args ) {

            if ( isset( $assoc_args['website'] ) && ! empty( $assoc_args['website'] ) ) :
                $url = $assoc_args['website'];

                if ( filter_var( $url, FILTER_VALIDATE_URL ) ) :
                    if ( isset($assoc_args['post_type']) && ! empty($assoc_args['post_type']) ) :
                        $post_types = $assoc_args['post_type'];
                        /**
                         * Check if this argument sets for multiple CPTs.
                         */
                        if (false !== stripos($post_types, ',', true)) :
                            $post_types = explode( ',', $post_types );
                            if ( ! empty( $post_types ) && is_array( $post_types ) ) :
                                foreach( $post_types as $post_type ) :
                                    $this->frpImportPosts($url, $post_type);
                                endforeach;
                            endif;
                        else :
                            $this->frpImportPosts($url, $post_types);
                        endif;
                    else :
                        $this->frpImportPosts($url, 'posts');
                    endif;
                else :
                    WP_CLI::error( sprintf( esc_html__( '%1$s is not a valid URL.', 'fetchremoteposts' ), $url ) );
                endif;
            else :
                WP_CLI::error( 'URL parameter missing.' );
            endif;
        }

        /**
         * This function imports the posts from the receiving URL and post type
         */
        function frpImportPosts( $url, $post_type ) {

            $remote = "{$url}/wp-json/wp/v2/{$post_type}?per_page=9";
            $response = wp_remote_get( $remote );

            if ( is_wp_error( $response ) ) :
                $error_msg = $response->get_error_message();
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

                esc_html_e('Importing posts started..', 'fetchremoteposts');
                echo "\n";

                if ( isset( $post->title->rendered ) && ! empty( $post->title->rendered ) ) :
                    $remote_post_id = $post->id;
                    $title          = $post->title->rendered;
                    $content        = ( isset( $post->content->rendered ) && ! empty( $post->content->rendered ) ) ? $post->content->rendered : '';
                    $excerpt        = ( isset( $post->excerpt->rendered ) && ! empty( $post->excerpt->rendered ) ) ? $post->excerpt->rendered : '';
                    $status         = ( isset( $post->status ) && ! empty( $post->status ) ) ? $post->status : '';
                    $post_id       = wp_insert_post(
                        array(
                            'post_title'   => $title,
                            'post_content' => $content,
                            'post_excerpt' => $excerpt,
                            'post_status'  => $status,
                            'post_author'  => 1, // Assigning the post to the site administrator.
                            'post_type'    => $post->type
                        )
                    );

                    // check if the featured image is set
                    if ( isset( $post->featured_media ) && ! empty( $post->featured_media ) ) :
                        $this->frpUpdateFeaturedImage( $url, $post->featured_media, $post_id );
                    endif;

                    // check if the post categories are set
                    if ( isset( $post->categories ) && ! empty( $post->categories ) && is_array( $post->categories ) ) :
                        $this->frpUpdatePostCategories( $url, $post->categories, $post_id, $remote_post_id );
                    endif;

                    // check if the post tags are set
                    if ( isset( $post->tags ) && ! empty( $post->tags ) && is_array( $post->tags ) ) :
                        $this->frpUpdatePostTags( $url, $post->tags, $post_id, $remote_post_id );
                    endif;

                    // import post comments
                    $this->frpImportPostComments( $url, $post_id, $remote_post_id );

                endif;
            endforeach;
            WP_CLI::success(esc_html__('All the posts fetched.', 'fetchremoteposts'));

            return;

        }

        /**
         * Add the featured image fetched from remote url.
         *
         * @param $url
         * @param $media_id
         * @param $post_id
         */
        function frpUpdateFeaturedImage( $url, $media_id, $post_id ) {

            $response = wp_remote_get( "{$url}/wp-json/wp/v2/media/{$media_id}" );

            if ( is_wp_error( $response ) ) :
                $error_msg = $response->get_error_message();
                WP_CLI::error( sprintf( esc_html__( 'The API returned an error: %1$s', 'fetchremoteposts' ) ), $error_msg );
            endif;

            // Get the body.
            $media_data = json_decode( wp_remote_retrieve_body( $response ) );

            if ( isset( $media_data->guid->rendered ) && ! empty( $media_data->guid->rendered ) ) :
                esc_html_e('Adding featured media..', 'fetchremoteposts');
                echo "\n";
                $image_url = $media_data->guid->rendered;

                // Add Featured Image to Post
                $image_name       = basename( $image_url );
                $upload_dir       = wp_upload_dir(); // Set upload folder
                $image_data       = file_get_contents( $image_url ); // Get image data
                $unique_file_name = wp_unique_filename( $upload_dir['path'], $image_name ); // Generate unique name
                $filename         = basename( $unique_file_name ); // Create image file name

                // Check folder permission and define file location
                if ( wp_mkdir_p( $upload_dir['path'] ) ) :
                    $file = $upload_dir['path'] . '/' . $filename;
                else :
                    $file = $upload_dir['basedir'] . '/' . $filename;
                endif;

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
                WP_CLI::success(esc_html__('Featured media added to the post.', 'fetchremoteposts'));

            endif;

            return;

        }

        /**
         * Update the post with categories fetched from remote url.
         *
         * @param $url
         * @param $categories
         * @param $post_id
         * @param $remote_post_id
         */
        function frpUpdatePostCategories( $url, $categories, $post_id, $remote_post_id ) {

            $response = wp_remote_get( "{$url}/wp-json/wp/v2/categories?post={$remote_post_id}" );

            if ( is_wp_error( $response ) ) :
                $error_msg = $response->get_error_message();
                WP_CLI::error( sprintf( esc_html__( 'The API returned an error: %1$s', 'fetchremoteposts' ) ), $error_msg );
            endif;

            // Get the body.
            $categories_arr = json_decode( wp_remote_retrieve_body( $response ) );

            if ( ! empty($categories_arr) && is_array($categories_arr) ) :
                esc_html_e('Adding post categories..', 'fetchremoteposts');
                echo "\n";
                foreach($categories_arr as $category_arr):
                    $existing_term = get_term_by('slug', $category_arr->slug, $category_arr->taxonomy);

                    if ( false === $existing_term ) :
                        /**
                         * Create the term.
                         */
                        $term_id = wp_insert_term(
                            $category_arr->name,
                            $category_arr->taxonomy,
                            array(
                                'description' => $category_arr->description,
                                'slug' => $category_arr->slug
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
                WP_CLI::success(esc_html__('Categories added to the post.', 'fetchremoteposts'));
            endif;

            return;

        }

        /**
         * Update the post with tags fetched from remote url.
         *
         * @param $url
         * @param $tags
         * @param $post_id
         * @param $remote_post_id
         */
        function frpUpdatePostTags( $url, $tags, $post_id, $remote_post_id ) {

            $response = wp_remote_get( "{$url}/wp-json/wp/v2/tags?post={$remote_post_id}" );

            if ( is_wp_error( $response ) ) :
                $error_msg = $response->get_error_message();
                WP_CLI::error( sprintf( esc_html__( 'The API returned an error: %1$s', 'fetchremoteposts' ) ), $error_msg );
            endif;

            // Get the body.
            $tags_arr = json_decode( wp_remote_retrieve_body( $response ) );

            if ( ! empty($tags_arr) && is_array($tags_arr) ) :
                esc_html_e('Adding post tags..', 'fetchremoteposts');
                echo "\n";
                foreach($tags_arr as $tag_arr):
                    $existing_term = get_term_by('slug', $tag_arr->slug, $tag_arr->taxonomy);

                    if ( false === $existing_term ) :
                        /**
                         * Create the term.
                         */
                        $term_id = wp_insert_term(
                            $tag_arr->name,
                            $tag_arr->taxonomy,
                            array(
                                'description' => $tag_arr->description,
                                'slug' => $tag_arr->slug
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
                WP_CLI::success(esc_html__('Tags added to the post.', 'fetchremoteposts'));
            endif;

            return;

        }

        /**
         * Fetch comments from remote url and import here
         *
         * @param $url
         * @param $post_id
         * @param $remote_post_id
         */
        function frpImportPostComments( $url, $post_id, $remote_post_id ) {

            $response = wp_remote_get( "{$url}/wp-json/wp/v2/comments?post={$remote_post_id}" );

            if ( is_wp_error( $response ) ) :
                $error_msg = $response->get_error_message();
                WP_CLI::error( sprintf( esc_html__( 'The API returned an error: %1$s', 'fetchremoteposts' ) ), $error_msg );
            endif;

            // Get the body.
            $comments_arr = json_decode( wp_remote_retrieve_body( $response ) );

            if ( ! empty($comments_arr) && is_array($comments_arr) ) :
                esc_html_e('Adding post comments..', 'fetchremoteposts');
                echo "\n";
                foreach($comments_arr as $comment):
                    $comment_content = ( isset( $comment->content->rendered ) && ! empty( $comment->content->rendered ) ) ? $comment->content->rendered : '';
                    wp_insert_comment(
                        array(
                            'comment_approved'   => 0,
                            'comment_author' => $comment->author_name,
                            'comment_content' => $comment_content,
                            'comment_type'  => $comment->type,
                            'comment_post_ID'  => $post_id,
                        )
                    );
                endforeach;
                WP_CLI::success(esc_html__('Comments added to the post.', 'fetchremoteposts'));
            endif;

            return;

        }
    }
endif;
