<?php
/**
 * Handles conversion of raster images to WebP.
 *
 * @package TK_Media_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TKMO_Converter
 *
 * Converts JPG/PNG images to WebP using GD (primary) or Imagick (fallback).
 * Fails silently (returns false) whenever a required extension or file is
 * unavailable so uploads are never blocked on shared hosting.
 */
class TKMO_Converter {

	/**
	 * Mime types eligible for WebP conversion.
	 *
	 * @var string[]
	 */
	private static $supported_mimes = array(
		'image/jpeg',
		'image/jpg',
		'image/png',
	);

	/**
	 * Checks whether the given mime type can be converted.
	 *
	 * @param string $mime_type Mime type of the source file.
	 * @return bool
	 */
	public static function is_supported_mime( $mime_type ) {
		return in_array( $mime_type, self::$supported_mimes, true );
	}

	/**
	 * Checks whether at least one conversion backend is available.
	 *
	 * @return bool
	 */
	public static function has_available_backend() {
		return self::gd_available() || self::imagick_available();
	}

	/**
	 * Checks GD availability with WebP support.
	 *
	 * @return bool
	 */
	private static function gd_available() {
		return function_exists( 'imagecreatefromjpeg' )
			&& function_exists( 'imagecreatefrompng' )
			&& function_exists( 'imagewebp' );
	}

	/**
	 * Checks Imagick availability with WebP support.
	 *
	 * @return bool
	 */
	private static function imagick_available() {
		if ( ! class_exists( 'Imagick' ) ) {
			return false;
		}

		$formats = @Imagick::queryFormats( 'WEBP' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return ! empty( $formats );
	}

	/**
	 * Converts a source image file to WebP.
	 *
	 * @param string $source_path Absolute path to the source JPG/PNG file.
	 * @param string $mime_type   Mime type of the source file.
	 * @param int    $quality     WebP quality (0-100).
	 * @return string|false Absolute path to the generated .webp file, or false on failure.
	 */
	public static function convert( $source_path, $mime_type, $quality = 82 ) {
		if ( ! file_exists( $source_path ) || ! is_readable( $source_path ) ) {
			return false;
		}

		if ( ! self::is_supported_mime( $mime_type ) ) {
			return false;
		}

		if ( self::exceeds_pixel_budget( $source_path ) ) {
			return false;
		}

		// GD/Imagick allocate the full decoded bitmap in memory; give them room.
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		$destination_path = self::build_destination_path( $source_path );

		if ( false === $destination_path ) {
			return false;
		}

		if ( self::gd_available() ) {
			$result = self::convert_with_gd( $source_path, $destination_path, $mime_type, $quality );

			if ( $result ) {
				return $destination_path;
			}
		}

		if ( self::imagick_available() ) {
			$result = self::convert_with_imagick( $source_path, $destination_path, $quality );

			if ( $result ) {
				return $destination_path;
			}
		}

		return false;
	}

	/**
	 * Reports whether an image is too large to decode safely within the
	 * server's memory budget. Uses getimagesize() (reads only the header,
	 * never the pixels) so the check itself costs almost nothing.
	 *
	 * @param string $source_path Absolute path to the source file.
	 * @return bool True when the image should be skipped.
	 */
	private static function exceeds_pixel_budget( $source_path ) {
		$max_megapixels = defined( 'TKMO_MAX_MEGAPIXELS' ) ? (float) TKMO_MAX_MEGAPIXELS : 24.0;

		if ( $max_megapixels <= 0 ) {
			return false;
		}

		$dimensions = @getimagesize( $source_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_array( $dimensions ) || empty( $dimensions[0] ) || empty( $dimensions[1] ) ) {
			return false;
		}

		$megapixels = ( (int) $dimensions[0] * (int) $dimensions[1] ) / 1000000;

		return $megapixels > $max_megapixels;
	}

	/**
	 * Builds the destination .webp path from the source path.
	 *
	 * @param string $source_path Absolute path to the source file.
	 * @return string|false
	 */
	private static function build_destination_path( $source_path ) {
		$path_info = pathinfo( $source_path );

		if ( empty( $path_info['dirname'] ) || empty( $path_info['filename'] ) ) {
			return false;
		}

		return trailingslashit( $path_info['dirname'] ) . $path_info['filename'] . '.webp';
	}

	/**
	 * Converts using the GD extension.
	 *
	 * @param string $source_path      Absolute path to the source file.
	 * @param string $destination_path Absolute path for the .webp output.
	 * @param string $mime_type        Mime type of the source file.
	 * @param int    $quality          WebP quality (0-100).
	 * @return bool
	 */
	private static function convert_with_gd( $source_path, $destination_path, $mime_type, $quality ) {
		$image = false;

		if ( 'image/png' === $mime_type ) {
			$image = @imagecreatefrompng( $source_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} elseif ( in_array( $mime_type, array( 'image/jpeg', 'image/jpg' ), true ) ) {
			$image = @imagecreatefromjpeg( $source_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		if ( ! $image ) {
			return false;
		}

		if ( 'image/png' === $mime_type ) {
			imagepalettetotruecolor( $image );
			imagealphablending( $image, true );
			imagesavealpha( $image, true );
		}

		$saved = @imagewebp( $image, $destination_path, $quality ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		imagedestroy( $image );

		return (bool) $saved && file_exists( $destination_path );
	}

	/**
	 * Converts using the Imagick extension.
	 *
	 * @param string $source_path      Absolute path to the source file.
	 * @param string $destination_path Absolute path for the .webp output.
	 * @param int    $quality          WebP quality (0-100).
	 * @return bool
	 */
	private static function convert_with_imagick( $source_path, $destination_path, $quality ) {
		try {
			$imagick = new Imagick( $source_path );
			$imagick->setImageFormat( 'webp' );
			$imagick->setImageCompressionQuality( $quality );
			$imagick->stripImage();

			$saved = $imagick->writeImage( $destination_path );
			$imagick->clear();
			$imagick->destroy();

			return (bool) $saved && file_exists( $destination_path );
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Deletes a WebP file if it exists.
	 *
	 * @param string $webp_path Absolute path to the .webp file.
	 * @return void
	 */
	public static function delete( $webp_path ) {
		if ( $webp_path && file_exists( $webp_path ) ) {
			wp_delete_file( $webp_path );
		}
	}
}
