<?php
/**
 * Registers WordPress hooks for the plugin.
 *
 * @package TK_Media_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TKMO_Hooks
 *
 * Wires the upload pipeline to TKMO_Converter and keeps generated .webp
 * files in sync with their original attachment (creation and deletion).
 */
class TKMO_Hooks {

	/**
	 * Singleton instance.
	 *
	 * @var TKMO_Hooks|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance, registering hooks on first call.
	 *
	 * @return TKMO_Hooks
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers WordPress hooks.
	 */
	private function __construct() {
		add_filter( 'wp_handle_upload', array( $this, 'handle_upload' ), 10, 2 );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'attach_webp_meta' ), 10, 2 );
		add_action( 'delete_attachment', array( $this, 'delete_webp_file' ) );

		add_filter( 'wp_get_attachment_url', array( $this, 'filter_attachment_url' ), 10, 2 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'filter_image_srcset' ), 10, 5 );
		add_filter( 'wp_get_attachment_image_src', array( $this, 'filter_attachment_image_src' ), 10, 4 );
	}

	/**
	 * Converts a newly uploaded image to WebP.
	 *
	 * Runs on the wp_handle_upload filter, so it fires for every file
	 * uploaded through the WordPress media pipeline (including front-end
	 * forms). The original upload array is always returned unmodified so
	 * the upload never fails because of this plugin.
	 *
	 * @param array  $upload  Upload data (file, url, type, ...).
	 * @param string $context Either 'upload' or 'sideload'.
	 * @return array
	 */
	public function handle_upload( $upload, $context = 'upload' ) {
		unset( $context );

		if ( empty( $upload['file'] ) || empty( $upload['type'] ) ) {
			return $upload;
		}

		if ( ! TKMO_Converter::is_supported_mime( $upload['type'] ) ) {
			return $upload;
		}

		if ( ! TKMO_Converter::has_available_backend() ) {
			return $upload;
		}

		TKMO_Converter::convert( $upload['file'], $upload['type'], TKMO_WEBP_QUALITY );

		return $upload;
	}

	/**
	 * Converts the original attachment file and every generated thumbnail
	 * size to WebP, then links the original's .webp to attachment post meta.
	 *
	 * Fires on wp_generate_attachment_metadata, once WordPress has finished
	 * writing all intermediate size files to disk (unlike add_attachment,
	 * which fires before 'sizes' is populated). Being a filter, $metadata is
	 * always returned unmodified so the attachment metadata pipeline is
	 * never altered by this plugin.
	 *
	 * @param array $metadata      Attachment metadata (sizes, file, ...).
	 * @param int   $attachment_id Attachment post ID.
	 * @return array
	 */
	public function attach_webp_meta( $metadata, $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id ) {
			return $metadata;
		}

		$file_path = get_attached_file( $attachment_id );

		if ( ! $file_path ) {
			return $metadata;
		}

		$mime_type = get_post_mime_type( $attachment_id );

		if ( ! TKMO_Converter::is_supported_mime( $mime_type ) ) {
			return $metadata;
		}

		$path_info = pathinfo( $file_path );

		if ( empty( $path_info['dirname'] ) || empty( $path_info['filename'] ) ) {
			return $metadata;
		}

		$webp_path = trailingslashit( $path_info['dirname'] ) . $path_info['filename'] . '.webp';

		if ( ! file_exists( $webp_path ) ) {
			if ( ! TKMO_Converter::has_available_backend() ) {
				return $metadata;
			}

			$webp_path = TKMO_Converter::convert( $file_path, $mime_type, TKMO_WEBP_QUALITY );
		}

		if ( ! $webp_path || ! file_exists( $webp_path ) ) {
			update_post_meta( $attachment_id, '_tk_webp_error', 1 );
			return $metadata;
		}

		$upload_dir = wp_get_upload_dir();
		$webp_url   = str_replace(
			wp_normalize_path( $upload_dir['basedir'] ),
			$upload_dir['baseurl'],
			wp_normalize_path( $webp_path )
		);

		update_post_meta( $attachment_id, '_tk_webp_url', esc_url_raw( $webp_url ) );
		update_post_meta( $attachment_id, '_tk_webp_path', sanitize_text_field( $webp_path ) );
		delete_post_meta( $attachment_id, '_tk_webp_error' );

		$this->convert_attachment_thumbnails( $metadata, $file_path, $mime_type );

		return $metadata;
	}

	/**
	 * Converts every thumbnail size listed in $metadata['sizes'] to WebP.
	 *
	 * @param array  $metadata  Attachment metadata (sizes, file, ...).
	 * @param string $file_path Absolute path to the original attachment file.
	 * @param string $mime_type Mime type of the original file.
	 * @return void
	 */
	private function convert_attachment_thumbnails( $metadata, $file_path, $mime_type ) {
		if ( ! TKMO_Converter::has_available_backend() ) {
			return;
		}

		if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return;
		}

		$base_dir = trailingslashit( dirname( $file_path ) );

		foreach ( $metadata['sizes'] as $size ) {
			if ( empty( $size['file'] ) ) {
				continue;
			}

			$thumb_mime = ! empty( $size['mime-type'] ) ? $size['mime-type'] : $mime_type;

			if ( ! TKMO_Converter::is_supported_mime( $thumb_mime ) ) {
				continue;
			}

			$thumb_path = $base_dir . $size['file'];

			if ( ! file_exists( $thumb_path ) ) {
				continue;
			}

			$thumb_webp_path = $base_dir . pathinfo( $size['file'], PATHINFO_FILENAME ) . '.webp';

			if ( file_exists( $thumb_webp_path ) ) {
				continue;
			}

			TKMO_Converter::convert( $thumb_path, $thumb_mime, TKMO_WEBP_QUALITY );
		}
	}

	/**
	 * Removes the .webp file when the original attachment is deleted.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return void
	 */
	public function delete_webp_file( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id ) {
			return;
		}

		$webp_path = get_post_meta( $attachment_id, '_tk_webp_path', true );

		if ( $webp_path ) {
			TKMO_Converter::delete( $webp_path );
		}

		delete_post_meta( $attachment_id, '_tk_webp_url' );
		delete_post_meta( $attachment_id, '_tk_webp_path' );
		delete_post_meta( $attachment_id, '_tk_webp_error' );
	}

	/**
	 * Checks whether the requesting browser sent Accept: image/webp.
	 *
	 * @return bool
	 */
	private function browser_accepts_webp() {
		if ( empty( $_SERVER['HTTP_ACCEPT'] ) ) {
			return false;
		}

		$accept = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) );

		return false !== strpos( $accept, 'image/webp' );
	}

	/**
	 * Swaps a JPG/PNG URL for its .webp counterpart when the file exists on
	 * disk and the current browser accepts WebP. Returns the original URL
	 * untouched in every other case.
	 *
	 * WordPress thumbnails carry a size suffix in the filename
	 * (e.g. foto-960x720.png). Since TKMO_Converter only ever generates a
	 * .webp for the full-size original, this tries the exact filename
	 * first (foto-960x720.webp) and, if that is not found, strips the
	 * -{width}x{height} suffix and tries the original's .webp (foto.webp).
	 *
	 * @param string $url URL of the original (JPG/PNG) image.
	 * @return string
	 */
	private function maybe_get_webp_url( $url ) {
		if ( empty( $url ) || ! $this->browser_accepts_webp() ) {
			return $url;
		}

		$extension = strtolower( pathinfo( $url, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, array( 'jpg', 'jpeg', 'png' ), true ) ) {
			return $url;
		}

		$upload_dir = wp_get_upload_dir();

		if ( 0 !== strpos( $url, $upload_dir['baseurl'] ) ) {
			return $url;
		}

		$relative_path = substr( $url, strlen( $upload_dir['baseurl'] ) );
		$file_path     = $upload_dir['basedir'] . $relative_path;
		$path_info     = pathinfo( $file_path );

		if ( empty( $path_info['dirname'] ) || empty( $path_info['filename'] ) ) {
			return $url;
		}

		$url_path_info = pathinfo( $url );

		if ( empty( $url_path_info['dirname'] ) || empty( $url_path_info['filename'] ) ) {
			return $url;
		}

		// 1) Exact filename, extension swapped: foto-960x720.webp.
		$webp_path = trailingslashit( $path_info['dirname'] ) . $path_info['filename'] . '.webp';

		if ( file_exists( $webp_path ) ) {
			return trailingslashit( $url_path_info['dirname'] ) . $url_path_info['filename'] . '.webp';
		}

		// 2) Strip the -{width}x{height} size suffix and try the original's webp: foto.webp.
		$base_filename = preg_replace( '/-\d+x\d+$/', '', $path_info['filename'] );

		if ( $base_filename === $path_info['filename'] ) {
			return $url;
		}

		$webp_path = trailingslashit( $path_info['dirname'] ) . $base_filename . '.webp';

		if ( ! file_exists( $webp_path ) ) {
			return $url;
		}

		$url_base_filename = preg_replace( '/-\d+x\d+$/', '', $url_path_info['filename'] );

		return trailingslashit( $url_path_info['dirname'] ) . $url_base_filename . '.webp';
	}

	/**
	 * Filters wp_get_attachment_url() to serve WebP when available.
	 *
	 * @param string $url           Attachment URL.
	 * @param int    $attachment_id Attachment post ID.
	 * @return string
	 */
	public function filter_attachment_url( $url, $attachment_id ) {
		unset( $attachment_id );

		return $this->maybe_get_webp_url( $url );
	}

	/**
	 * Filters wp_calculate_image_srcset() to serve WebP URLs when available.
	 *
	 * @param array  $sources       One or more arrays of source data.
	 * @param array  $size_array    Width/height of the image.
	 * @param string $image_src     The 'src' of the image.
	 * @param array  $image_meta    The attachment meta data.
	 * @param int    $attachment_id Attachment post ID.
	 * @return array
	 */
	public function filter_image_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		unset( $size_array, $image_src, $image_meta, $attachment_id );

		if ( empty( $sources ) || ! is_array( $sources ) ) {
			return $sources;
		}

		foreach ( $sources as $width => $source ) {
			if ( ! empty( $source['url'] ) ) {
				$sources[ $width ]['url'] = $this->maybe_get_webp_url( $source['url'] );
			}
		}

		return $sources;
	}

	/**
	 * Filters wp_get_attachment_image_src() to serve WebP when available.
	 *
	 * @param array|false $image         Array of image data (url, width, height, is_intermediate), or false.
	 * @param int         $attachment_id Attachment post ID.
	 * @param string|int[] $size         Requested image size.
	 * @param bool        $icon          Whether the image should be treated as an icon.
	 * @return array|false
	 */
	public function filter_attachment_image_src( $image, $attachment_id, $size, $icon ) {
		unset( $attachment_id, $size, $icon );

		if ( empty( $image ) || ! is_array( $image ) || empty( $image[0] ) ) {
			return $image;
		}

		$image[0] = $this->maybe_get_webp_url( $image[0] );

		return $image;
	}
}
