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
         * @param $assocArgs
         *
         * @return string
         */
        function from( $args, $assocArgs ) {

            if ( isset( $assocArgs['website'] ) && ! empty( $assocArgs['website'] ) ) :
                $url = $assocArgs['website'];

                if ( filter_var( $url, FILTER_VALIDATE_URL ) ) :
                    if ( isset($assocArgs['post_type']) && ! empty($assocArgs['post_type']) ) :
                        $postTypes = $assocArgs['post_type'];
                        /**
                         * Check if this argument sets for multiple CPTs.
                         */
                        if (false !== stripos($postTypes, ',', true)) :
                            $postTypes = explode( ',', $postTypes );
                            if ( ! empty( $postTypes ) && is_array( $postTypes ) ) :
                                foreach( $postTypes as $postType ) :
                                    $this->frpImportPosts($url, $postType);
                                endforeach;
                            endif;
                        else :
                            $this->frpImportPosts($url, $postTypes);
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
        function frpImportPosts( $url, $postType ) {

            $remote = "{$url}/wp-json/wp/v2/{$postType}?per_page=9";
            $response = wp_remote_get( $remote );

            if ( is_wp_error( $response ) ) :
                $errorMsg = $response->get_error_message();
                WP_CLI::error( sprintf( esc_html__( 'The API returned an error: %1$s', 'fetchremoteposts' ) ), $errorMsg );
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
                    $remotePostID = $post->id;
                    $title = $post->title->rendered;
                    $content = ( isset( $post->content->rendered ) && ! empty( $post->content->rendered ) ) ? $post->content->rendered : '';
                    $excerpt = ( isset( $post->excerpt->rendered ) && ! empty( $post->excerpt->rendered ) ) ? $post->excerpt->rendered : '';
                    $status = ( isset( $post->status ) && ! empty( $post->status ) ) ? $post->status : '';
                    $postID = wp_insert_post(
                        array(
                            'post_title' => $title,
                            'post_content' => $content,
                            'post_excerpt' => $excerpt,
                            'post_status' => $status,
                            'post_author' => 1, // Assigning the post to the site administrator.
                            'post_type' => $post->type
                        )
                    );

                    // check if the featured image is set
                    if ( isset( $post->featured_media ) && ! empty( $post->featured_media ) ) :
                        $this->frpUpdateFeaturedImage( $url, $post->featured_media, $postID );
                    endif;

                    // check if the post categories are set
                    if ( isset( $post->categories ) && ! empty( $post->categories ) && is_array( $post->categories ) ) :
                        $this->frpUpdatePostCategories( $url, $post->categories, $postID, $remotePostID );
                    endif;

                    // check if the post tags are set
                    if ( isset( $post->tags ) && ! empty( $post->tags ) && is_array( $post->tags ) ) :
                        $this->frpUpdatePostTags( $url, $post->tags, $postID, $remotePostID );
                    endif;

                    // import post comments
                    $this->frpImportPostComments( $url, $postID, $remotePostID );

                endif;
            endforeach;
            WP_CLI::success(esc_html__('All the posts fetched.', 'fetchremoteposts'));

            return;

        }

        /**
         * Add the featured image fetched from remote url.
         *
         * @param $url
         * @param $mediaID
         * @param $postID
         */
        function frpUpdateFeaturedImage( $url, $mediaID, $postID ) {

            $response = wp_remote_get( "{$url}/wp-json/wp/v2/media/{$mediaID}" );

            if ( is_wp_error( $response ) ) :
                $errorMsg = $response->get_error_message();
                WP_CLI::error( sprintf( esc_html__( 'The API returned an error: %1$s', 'fetchremoteposts' ) ), $errorMsg );
            endif;

            // Get the body.
            $media_data = json_decode( wp_remote_retrieve_body( $response ) );

            if ( isset( $media_data->guid->rendered ) && ! empty( $media_data->guid->rendered ) ) :
                esc_html_e('Adding featured media..', 'fetchremoteposts');
                echo "\n";
                $image_url = $media_data->guid->rendered;

                // Add Featured Image to Post
                $imageName = basename( $image_url );
                $uploadDir = wp_upload_dir(); // Set upload folder
                $imageData = file_get_contents( $image_url ); // Get image data
                $uniqueFileName = wp_unique_filename( $uploadDir['path'], $imageName ); // Generate unique name
                $filename = basename( $uniqueFileName ); // Create image file name

                // Check folder permission and define file location
                if ( wp_mkdir_p( $uploadDir['path'] ) ) :
                    $file = $uploadDir['path'] . '/' . $filename;
                else :
                    $file = $uploadDir['basedir'] . '/' . $filename;
                endif;

                // Create the image  file on the server
                file_put_contents( $file, $imageData );

                // Check image file type
                $wpFiletype = wp_check_filetype( $filename, null );

                // Set attachment data
                $attachment = array(
                    'post_mime_type' => $wpFiletype['type'],
                    'post_title' => sanitize_file_name( $filename ),
                    'post_content' => '',
                    'post_status' => 'inherit'
                );

                // Create the attachment
                $attachID = wp_insert_attachment( $attachment, $file, $postID );

                // Include image.php
                require_once( ABSPATH . 'wp-admin/includes/image.php' );

                // Define attachment metadata
                $attachData = wp_generate_attachment_metadata( $attachID, $file );

                // Assign metadata to attachment
                wp_update_attachment_metadata( $attachID, $attachData );

                // And finally assign featured image to post
                set_post_thumbnail( $postID, $attachID );
                WP_CLI::success(esc_html__('Featured media added to the post.', 'fetchremoteposts'));

            endif;

            return;

        }

        /**
         * Update the post with categories fetched from remote url.
         *
         * @param $url
         * @param $categories
         * @param $postID
         * @param $remotePostID
         */
        function frpUpdatePostCategories( $url, $categories, $postID, $remotePostID ) {

            $response = wp_remote_get( "{$url}/wp-json/wp/v2/categories?post={$remotePostID}" );

            if ( is_wp_error( $response ) ) :
                $errorMsg = $response->get_error_message();
                WP_CLI::error( sprintf( esc_html__( 'The API returned an error: %1$s', 'fetchremoteposts' ) ), $errorMsg );
            endif;

            // Get the body.
            $categoriesArr = json_decode( wp_remote_retrieve_body( $response ) );

            if ( ! empty($categoriesArr) && is_array($categoriesArr) ) :
                esc_html_e('Adding post categories..', 'fetchremoteposts');
                echo "\n";
                foreach($categoriesArr as $categoryArr):
                    $existingTerm = get_term_by('slug', $categoryArr->slug, $categoryArr->taxonomy);

                    if ( false === $existingTerm ) :
                        /**
                         * Create the term.
                         */
                        $termID = wp_insert_term(
                            $categoryArr->name,
                            $categoryArr->taxonomy,
                            array(
                                'description' => $categoryArr->description,
                                'slug' => $categoryArr->slug
                            )
                        );
                        wp_set_object_terms( $postID, $termID, $categoryArr->taxonomy );
                    else :
                        /**
                         * Update the post with the existing term.
                         */
                        wp_set_object_terms( $postID, $existingTerm->term_id, $existingTerm->taxonomy );
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
         * @param $postID
         * @param $remotePostID
         */
        function frpUpdatePostTags( $url, $tags, $postID, $remotePostID ) {

            $response = wp_remote_get( "{$url}/wp-json/wp/v2/tags?post={$remotePostID}" );

            if ( is_wp_error( $response ) ) :
                $errorMsg = $response->get_error_message();
                WP_CLI::error( sprintf( esc_html__( 'The API returned an error: %1$s', 'fetchremoteposts' ) ), $errorMsg );
            endif;

            // Get the body.
            $tagsArr = json_decode( wp_remote_retrieve_body( $response ) );

            if ( ! empty($tagsArr) && is_array($tagsArr) ) :
                esc_html_e('Adding post tags..', 'fetchremoteposts');
                echo "\n";
                foreach($tagsArr as $tagArr):
                    $existingTerm = get_term_by('slug', $tagArr->slug, $tagArr->taxonomy);

                    if ( false === $existingTerm ) :
                        /**
                         * Create the term.
                         */
                        $termID = wp_insert_term(
                            $tagArr->name,
                            $tagArr->taxonomy,
                            array(
                                'description' => $tagArr->description,
                                'slug' => $tagArr->slug
                            )
                        );
                        wp_set_object_terms( $postID, $termID, $tagArr->taxonomy );
                    else :
                        /**
                         * Update the post with the existing term.
                         */
                        wp_set_object_terms( $postID, $existingTerm->term_id, $existingTerm->taxonomy );
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
         * @param $postID
         * @param $remotePostID
         */
        function frpImportPostComments( $url, $postID, $remotePostID ) {

            $response = wp_remote_get( "{$url}/wp-json/wp/v2/comments?post={$remotePostID}" );

            if ( is_wp_error( $response ) ) :
                $errorMsg = $response->get_error_message();
                WP_CLI::error( sprintf( esc_html__( 'The API returned an error: %1$s', 'fetchremoteposts' ) ), $errorMsg );
            endif;

            // Get the body.
            $commentsArr = json_decode( wp_remote_retrieve_body( $response ) );

            if ( ! empty($commentsArr) && is_array($commentsArr) ) :
                esc_html_e('Adding post comments..', 'fetchremoteposts');
                echo "\n";
                foreach($commentsArr as $comment):
                    $commentContent = ( isset( $comment->content->rendered ) && ! empty( $comment->content->rendered ) ) ? $comment->content->rendered : '';
                    wp_insert_comment(
                        array(
                            'comment_approved' => 0,
                            'comment_author' => $comment->author_name,
                            'comment_content' => $commentContent,
                            'comment_type' => $comment->type,
                            'comment_post_ID' => $postID,
                        )
                    );
                endforeach;
                WP_CLI::success(esc_html__('Comments added to the post.', 'fetchremoteposts'));
            endif;

            return;

        }
    }
endif;
