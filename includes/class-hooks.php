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

		// WebP delivery via <picture> wrapping (cache-safe, no Accept header).
		add_filter( 'wp_content_img_tag', array( $this, 'wrap_content_img' ), 20, 3 );
		add_filter( 'wp_get_attachment_image', array( $this, 'wrap_attachment_image' ), 20, 5 );

		// Catch-all: many themes build <img> markup by hand in template files
		// (wp_get_attachment_image_url() + a manual <img>), which neither
		// filter above ever sees. A frontend output buffer rewrites the whole
		// rendered page so those images get wrapped too.
		add_action( 'template_redirect', array( $this, 'start_output_buffer' ) );
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
	 * Wraps content images (the_content, blocks, etc.) in a <picture> with a
	 * WebP <source>. Fires on wp_content_img_tag (WP 6.0+).
	 *
	 * @param string $filtered_image The <img> tag HTML.
	 * @param string $context        Optional context (unused).
	 * @param int    $attachment_id  Optional attachment ID (unused).
	 * @return string
	 */
	public function wrap_content_img( $filtered_image, $context = '', $attachment_id = 0 ) {
		unset( $context, $attachment_id );

		return $this->maybe_wrap_picture( $filtered_image );
	}

	/**
	 * Wraps the output of wp_get_attachment_image() (themes, widgets, blocks)
	 * in a <picture> with a WebP <source>.
	 *
	 * @param string       $html          The <img> tag HTML.
	 * @param int          $attachment_id Attachment ID (unused).
	 * @param string|int[] $size          Requested size (unused).
	 * @param bool         $icon          Whether treated as icon (unused).
	 * @param array        $attr          Image attributes (unused).
	 * @return string
	 */
	public function wrap_attachment_image( $html, $attachment_id = 0, $size = '', $icon = false, $attr = array() ) {
		unset( $attachment_id, $size, $icon, $attr );

		return $this->maybe_wrap_picture( $html );
	}

	/**
	 * Starts a frontend-only output buffer whose callback rewrites the full
	 * rendered page HTML. Fires on template_redirect, which only runs for
	 * front-end page requests (not admin, and — with the guards below — not
	 * feeds, REST or AJAX).
	 *
	 * @return void
	 */
	public function start_output_buffer() {
		if ( is_admin() || is_feed() ) {
			return;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return;
		}

		ob_start( array( $this, 'process_page_html' ) );
	}

	/**
	 * Output-buffer callback: wraps every eligible standalone <img> in the
	 * page in a <picture> element, leaving images that are already inside a
	 * <picture> untouched.
	 *
	 * @param string $html The full buffered page HTML.
	 * @return string
	 */
	public function process_page_html( $html ) {
		if ( ! is_string( $html ) || '' === $html || false === stripos( $html, '<img' ) ) {
			return $html;
		}

		// Mask existing <picture> blocks so their inner <img> is never
		// re-wrapped, then restore them verbatim afterwards.
		$placeholders = array();

		$masked = preg_replace_callback(
			'/<picture\b.*?<\/picture>/is',
			function ( $match ) use ( &$placeholders ) {
				$key                  = '<!--tkmo-picture-' . count( $placeholders ) . '-->';
				$placeholders[ $key ] = $match[0];

				return $key;
			},
			$html
		);

		// preg_replace_callback returns null on failure (e.g. backtrack limit
		// on a huge page); fall back to the untouched HTML in that case.
		if ( null === $masked ) {
			return $html;
		}

		$wrapped = preg_replace_callback(
			'/<img\b[^>]*>/i',
			function ( $match ) {
				return $this->maybe_wrap_picture( $match[0] );
			},
			$masked
		);

		if ( null === $wrapped ) {
			return $html;
		}

		if ( ! empty( $placeholders ) ) {
			$wrapped = strtr( $wrapped, $placeholders );
		}

		return $wrapped;
	}

	/**
	 * Wraps a single <img> tag in a <picture> element that offers a WebP
	 * <source> ahead of the original <img> fallback.
	 *
	 * Delivery no longer depends on the request's Accept header: the markup
	 * is identical for every visitor, so it survives full-page caches
	 * (W3TC, etc.). The browser itself picks the <source> when it supports
	 * WebP and falls back to the untouched <img> otherwise.
	 *
	 * Returns the input unchanged when there is nothing to offer:
	 *  - no <img> tag present;
	 *  - the img is already inside a <picture>;
	 *  - opted out via the data-tkmo-skip attribute or a class;
	 *  - no WebP file exists on disk for any candidate URL.
	 *
	 * The original <img> is preserved byte-for-byte, so every attribute
	 * (class, alt, width, height, loading, sizes, fetchpriority, decoding,
	 * srcset) stays intact and CLS / lazy-loading behaviour is unchanged.
	 *
	 * @param string $html Markup containing exactly one <img> tag.
	 * @return string
	 */
	private function maybe_wrap_picture( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}

		// Already a <picture> (or CSS background markup with none): leave it.
		if ( false !== stripos( $html, '<picture' ) ) {
			return $html;
		}

		if ( ! preg_match( '/<img\s[^>]*>/i', $html, $matches ) ) {
			return $html;
		}

		$img = $matches[0];

		// Explicit opt-out.
		if ( false !== stripos( $img, 'data-tkmo-skip' ) || false !== stripos( $img, 'tkmo-no-webp' ) ) {
			return $html;
		}

		$src    = $this->get_img_attr( $img, 'src' );
		$srcset = $this->get_img_attr( $img, 'srcset' );
		$sizes  = $this->get_img_attr( $img, 'sizes' );

		$webp_srcset = '';

		if ( '' !== $srcset ) {
			$webp_srcset = $this->build_webp_srcset( $srcset );
		}

		// No srcset (or none of its candidates had a webp): try the src.
		if ( '' === $webp_srcset && '' !== $src ) {
			$webp_src = $this->url_to_webp_if_exists( $src );

			if ( false !== $webp_src ) {
				$webp_srcset = $webp_src;
			}
		}

		if ( '' === $webp_srcset ) {
			return $html;
		}

		$source = '<source type="image/webp" srcset="' . esc_attr( $webp_srcset ) . '"';

		if ( '' !== $sizes ) {
			$source .= ' sizes="' . esc_attr( $sizes ) . '"';
		}

		$source .= ' />';

		$picture = '<picture>' . $source . $img . '</picture>';

		// Replace only the matched <img>, preserving any surrounding markup
		// (e.g. an anchor wrapping the image in content).
		$pos = strpos( $html, $img );

		if ( false === $pos ) {
			return $html;
		}

		return substr( $html, 0, $pos ) . $picture . substr( $html, $pos + strlen( $img ) );
	}

	/**
	 * Extracts a single attribute value from an <img> tag. Supports both
	 * double- and single-quoted values.
	 *
	 * @param string $img  The <img> tag HTML.
	 * @param string $name Attribute name.
	 * @return string Attribute value, or '' when absent.
	 */
	private function get_img_attr( $img, $name ) {
		$pattern = '/\s' . preg_quote( $name, '/' ) . '\s*=\s*("([^"]*)"|\'([^\']*)\')/i';

		if ( ! preg_match( $pattern, $img, $matches ) ) {
			return '';
		}

		if ( isset( $matches[2] ) && '' !== $matches[2] ) {
			return $matches[2];
		}

		return isset( $matches[3] ) ? $matches[3] : '';
	}

	/**
	 * Rebuilds a srcset string keeping only the candidates whose .webp
	 * sibling exists on disk, swapping each URL for its .webp counterpart
	 * and preserving the width/pixel descriptor.
	 *
	 * @param string $srcset Original srcset attribute value.
	 * @return string WebP srcset, or '' when no candidate has a webp file.
	 */
	private function build_webp_srcset( $srcset ) {
		$candidates = explode( ',', $srcset );
		$out        = array();

		foreach ( $candidates as $candidate ) {
			$candidate = trim( $candidate );

			if ( '' === $candidate ) {
				continue;
			}

			$parts      = preg_split( '/\s+/', $candidate, 2 );
			$url        = $parts[0];
			$descriptor = isset( $parts[1] ) ? ' ' . $parts[1] : '';

			$webp_url = $this->url_to_webp_if_exists( $url );

			if ( false !== $webp_url ) {
				$out[] = $webp_url . $descriptor;
			}
		}

		return implode( ', ', $out );
	}

	/**
	 * Maps an image URL to its .webp URL, but only when the corresponding
	 * .webp file actually exists on disk under the uploads directory.
	 *
	 * @param string $url Original (PNG/JPG/JPEG) image URL.
	 * @return string|false WebP URL, or false when unavailable.
	 */
	private function url_to_webp_if_exists( $url ) {
		if ( '' === $url ) {
			return false;
		}

		$decoded   = html_entity_decode( $url, ENT_QUOTES );
		$url_path  = wp_parse_url( $decoded, PHP_URL_PATH );

		if ( empty( $url_path ) ) {
			return false;
		}

		$extension = strtolower( pathinfo( $url_path, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, array( 'jpg', 'jpeg', 'png' ), true ) ) {
			return false;
		}

		$upload_dir = wp_get_upload_dir();
		$baseurl    = $upload_dir['baseurl'];

		if ( 0 !== strpos( $decoded, $baseurl ) ) {
			return false;
		}

		$relative  = substr( $decoded, strlen( $baseurl ) );
		$file_path = wp_normalize_path( $upload_dir['basedir'] . $relative );
		$path_info = pathinfo( $file_path );

		if ( empty( $path_info['dirname'] ) || empty( $path_info['filename'] ) ) {
			return false;
		}

		$webp_path = trailingslashit( $path_info['dirname'] ) . $path_info['filename'] . '.webp';

		if ( ! file_exists( $webp_path ) ) {
			return false;
		}

		// Swap the extension on the original URL string (keeps its encoding).
		return preg_replace( '/\.(png|jpe?g)$/i', '.webp', $url );
	}
}
