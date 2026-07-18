<?php
/**
 * Fires on plugin uninstall — removes every generated .webp file and
 * the post meta this plugin created. Does not touch original images.
 *
 * @package TK_Media_Optimizer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Deletes all .webp files and post meta created by TK Media Optimizer,
 * in batches to avoid memory issues on large media libraries.
 *
 * @return void
 */
function tkmo_uninstall_cleanup() {
	$batch_size = 200;

	do {
		$attachment_ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => $batch_size,
				'fields'         => 'ids',
				'meta_key'       => '_tk_webp_path', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_tk_webp_path',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		foreach ( $attachment_ids as $attachment_id ) {
			$webp_path = get_post_meta( $attachment_id, '_tk_webp_path', true );

			if ( $webp_path && file_exists( $webp_path ) ) {
				wp_delete_file( $webp_path );
			}

			delete_post_meta( $attachment_id, '_tk_webp_url' );
			delete_post_meta( $attachment_id, '_tk_webp_path' );
			delete_post_meta( $attachment_id, '_tk_webp_error' );
		}
	} while ( count( $attachment_ids ) === $batch_size );
}

tkmo_uninstall_cleanup();
