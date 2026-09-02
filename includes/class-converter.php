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
	 * Human-readable reason for the most recent convert() failure. Read it
	 * with get_last_error() right after a convert() call returns false.
	 *
	 * @var string
	 */
	private static $last_error = '';

	/**
	 * Returns the reason for the most recent failed conversion.
	 *
	 * @return string Empty string when the last conversion succeeded.
	 */
	public static function get_last_error() {
		return self::$last_error;
	}

	/**
	 * Records a failure reason and returns false, so callers can
	 * `return self::fail( '...' );` in one line.
	 *
	 * @param string $reason Human-readable failure reason.
	 * @return false
	 */
	private static function fail( $reason ) {
		self::$last_error = $reason;

		return false;
	}

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
		self::$last_error = '';

		if ( ! file_exists( $source_path ) || ! is_readable( $source_path ) ) {
			return self::fail( esc_html__( 'Arquivo de origem ausente ou sem permissão de leitura.', 'tk-media-optimizer' ) );
		}

		if ( ! self::is_supported_mime( $mime_type ) ) {
			return self::fail(
				sprintf(
					/* translators: %s: detected mime type */
					esc_html__( 'Tipo de arquivo não suportado (%s).', 'tk-media-optimizer' ),
					$mime_type ? $mime_type : 'desconhecido'
				)
			);
		}

		$oversize = self::pixel_budget_reason( $source_path );

		if ( '' !== $oversize ) {
			return self::fail( $oversize );
		}

		if ( ! self::has_available_backend() ) {
			return self::fail( esc_html__( 'Nenhum conversor (GD ou Imagick) disponível neste servidor.', 'tk-media-optimizer' ) );
		}

		// GD/Imagick allocate the full decoded bitmap in memory; give them room.
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		$destination_path = self::build_destination_path( $source_path );

		if ( false === $destination_path ) {
			return self::fail( esc_html__( 'Não foi possível montar o caminho de destino .webp.', 'tk-media-optimizer' ) );
		}

		if ( ! is_writable( dirname( $destination_path ) ) ) {
			return self::fail( esc_html__( 'A pasta de destino não tem permissão de escrita.', 'tk-media-optimizer' ) );
		}

		if ( self::gd_available() ) {
			$result = self::convert_with_gd( $source_path, $destination_path, $mime_type, $quality );

			if ( $result ) {
				self::$last_error = '';

				return $destination_path;
			}
		}

		if ( self::imagick_available() ) {
			$result = self::convert_with_imagick( $source_path, $destination_path, $quality );

			if ( $result ) {
				self::$last_error = '';

				return $destination_path;
			}
		}

		if ( '' === self::$last_error ) {
			self::$last_error = esc_html__( 'A conversão falhou por motivo desconhecido.', 'tk-media-optimizer' );
		}

		return false;
	}

	/**
	 * Reports whether an image is too large to decode safely within the
	 * server's memory budget. Uses getimagesize() (reads only the header,
	 * never the pixels) so the check itself costs almost nothing.
	 *
	 * @param string $source_path Absolute path to the source file.
	 * @return string Failure reason, or '' when the image is within budget.
	 */
	private static function pixel_budget_reason( $source_path ) {
		$max_megapixels = defined( 'TKMO_MAX_MEGAPIXELS' ) ? (float) TKMO_MAX_MEGAPIXELS : 24.0;

		if ( $max_megapixels <= 0 ) {
			return '';
		}

		$dimensions = @getimagesize( $source_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_array( $dimensions ) || empty( $dimensions[0] ) || empty( $dimensions[1] ) ) {
			return esc_html__( 'Não foi possível ler as dimensões da imagem (arquivo corrompido ou não é uma imagem válida).', 'tk-media-optimizer' );
		}

		$width      = (int) $dimensions[0];
		$height     = (int) $dimensions[1];
		$megapixels = ( $width * $height ) / 1000000;

		if ( $megapixels <= $max_megapixels ) {
			return '';
		}

		return sprintf(
			/* translators: 1: image width, 2: image height, 3: megapixels, 4: megapixel limit */
			esc_html__( 'Imagem grande demais: %1$dx%2$d (%3$s MP), acima do limite de %4$s MP. Aumente TKMO_MAX_MEGAPIXELS se o servidor tiver memória.', 'tk-media-optimizer' ),
			$width,
			$height,
			number_format_i18n( $megapixels, 1 ),
			number_format_i18n( $max_megapixels, 1 )
		);
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
			return self::fail( esc_html__( 'GD não conseguiu abrir a imagem de origem (formato inesperado ou arquivo corrompido).', 'tk-media-optimizer' ) );
		}

		if ( 'image/png' === $mime_type ) {
			imagepalettetotruecolor( $image );
			imagealphablending( $image, true );
			imagesavealpha( $image, true );
		}

		$saved = @imagewebp( $image, $destination_path, $quality ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		imagedestroy( $image );

		if ( $saved && file_exists( $destination_path ) ) {
			return true;
		}

		return self::fail( esc_html__( 'GD abriu a imagem mas falhou ao gravar o arquivo .webp.', 'tk-media-optimizer' ) );
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

			if ( $saved && file_exists( $destination_path ) ) {
				return true;
			}

			return self::fail( esc_html__( 'Imagick processou a imagem mas não gravou o arquivo .webp.', 'tk-media-optimizer' ) );
		} catch ( Exception $e ) {
			return self::fail(
				sprintf(
					/* translators: %s: Imagick exception message */
					esc_html__( 'Imagick falhou: %s', 'tk-media-optimizer' ),
					$e->getMessage()
				)
			);
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
