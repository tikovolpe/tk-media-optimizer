<?php
/**
 * Admin page: bulk-convert existing media to WebP.
 *
 * @package TK_Media_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TKMO_Admin_Page
 *
 * Adds a "TK Media Optimizer" page under Tools, with an AJAX-driven
 * batch converter for images already in the media library.
 */
class TKMO_Admin_Page {

	/**
	 * Singleton instance.
	 *
	 * @var TKMO_Admin_Page|null
	 */
	private static $instance = null;

	/**
	 * Batch size processed per AJAX request.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 10;

	/**
	 * AJAX nonce action name.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'tkmo_admin_ajax';

	/**
	 * Returns the singleton instance, registering hooks on first call.
	 *
	 * @return TKMO_Admin_Page
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
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_tkmo_scan_pending', array( $this, 'ajax_scan_pending' ) );
		add_action( 'wp_ajax_tkmo_convert_batch', array( $this, 'ajax_convert_batch' ) );
		add_action( 'wp_ajax_tkmo_get_stats', array( $this, 'ajax_get_stats' ) );
	}

	/**
	 * Registers the Tools > TK Media Optimizer submenu page.
	 *
	 * @return void
	 */
	public function register_page() {
		add_management_page(
			esc_html__( 'TK Media Optimizer', 'tk-media-optimizer' ),
			esc_html__( 'TK Media Optimizer', 'tk-media-optimizer' ),
			'manage_options',
			'tkmo-media-optimizer',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueues the admin script only on this plugin's page.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'tools_page_tkmo-media-optimizer' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'tkmo-admin',
			TKMO_PLUGIN_URL . 'admin/css/tkmo-admin.css',
			array(),
			TKMO_VERSION
		);

		wp_enqueue_script(
			'tkmo-admin',
			TKMO_PLUGIN_URL . 'admin/js/tkmo-admin.js',
			array(),
			TKMO_VERSION,
			true
		);

		wp_localize_script(
			'tkmo-admin',
			'tkmoAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => array(
					'start'      => esc_html__( 'Converter todas as imagens existentes', 'tk-media-optimizer' ),
					'running'    => esc_html__( 'Convertendo...', 'tk-media-optimizer' ),
					'done'       => esc_html__( 'Concluído.', 'tk-media-optimizer' ),
					'none_found' => esc_html__( 'Nenhuma imagem pendente encontrada.', 'tk-media-optimizer' ),
				),
			)
		);
	}

	/**
	 * Builds the meta_query that selects images still missing a WebP copy.
	 *
	 * @return array
	 */
	private static function get_pending_meta_query() {
		return array(
			'relation' => 'AND',
			array(
				'key'     => '_tk_webp_path',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_tk_webp_error',
				'compare' => 'NOT EXISTS',
			),
		);
	}

	/**
	 * Counts total eligible images, how many already have a WebP copy, and
	 * how many failed conversion. Used to render the dashboard stat cards.
	 *
	 * @return array{total:int,converted:int,pending:int,errors:int,percent:int}
	 */
	private static function get_stats() {
		$mime_types = array( 'image/jpeg', 'image/jpg', 'image/png' );

		$total_query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => $mime_types,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		$converted_query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => $mime_types,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_tk_webp_path',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$errors_query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => $mime_types,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_tk_webp_error',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$total     = (int) $total_query->found_posts;
		$converted = (int) $converted_query->found_posts;
		$errors    = (int) $errors_query->found_posts;
		$pending   = max( 0, $total - $converted - $errors );
		$percent   = $total > 0 ? (int) round( ( $converted / $total ) * 100 ) : 0;

		return array(
			'total'     => $total,
			'converted' => $converted,
			'pending'   => $pending,
			'errors'    => $errors,
			'percent'   => $percent,
		);
	}

	/**
	 * AJAX: returns the current dashboard stats.
	 *
	 * @return void
	 */
	public function ajax_get_stats() {
		$this->verify_ajax_request();

		wp_send_json_success( self::get_stats() );
	}

	/**
	 * Verifies the AJAX nonce and capability for every AJAX handler.
	 *
	 * @return void Sends a JSON error and exits on failure.
	 */
	private function verify_ajax_request() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized', 'tk-media-optimizer' ) ), 403 );
		}
	}

	/**
	 * AJAX: returns the total count of images still pending conversion.
	 *
	 * @return void
	 */
	public function ajax_scan_pending() {
		$this->verify_ajax_request();

		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/jpg', 'image/png' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => self::get_pending_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);

		wp_send_json_success( array( 'total' => (int) $query->found_posts ) );
	}

	/**
	 * AJAX: converts one batch of pending images and reports progress.
	 *
	 * @return void
	 */
	public function ajax_convert_batch() {
		$this->verify_ajax_request();

		$converted = 0;
		$errors    = 0;

		if ( ! TKMO_Converter::has_available_backend() ) {
			wp_send_json_success(
				array(
					'converted' => 0,
					'errors'    => 0,
					'remaining' => 0,
					'done'      => true,
					'message'   => esc_html__( 'Nenhum conversor (GD ou Imagick) disponível neste servidor.', 'tk-media-optimizer' ),
				)
			);
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/jpg', 'image/png' ),
				'posts_per_page' => self::BATCH_SIZE,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => self::get_pending_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);

		foreach ( $query->posts as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			$file_path     = get_attached_file( $attachment_id );
			$mime_type     = get_post_mime_type( $attachment_id );

			if ( ! $file_path || ! TKMO_Converter::is_supported_mime( $mime_type ) ) {
				update_post_meta( $attachment_id, '_tk_webp_error', 1 );
				++$errors;
				continue;
			}

			$webp_path = TKMO_Converter::convert( $file_path, $mime_type, TKMO_WEBP_QUALITY );

			if ( ! $webp_path ) {
				update_post_meta( $attachment_id, '_tk_webp_error', 1 );
				++$errors;
				continue;
			}

			$upload_dir = wp_get_upload_dir();
			$webp_url   = str_replace(
				wp_normalize_path( $upload_dir['basedir'] ),
				$upload_dir['baseurl'],
				wp_normalize_path( $webp_path )
			);

			update_post_meta( $attachment_id, '_tk_webp_url', esc_url_raw( $webp_url ) );
			update_post_meta( $attachment_id, '_tk_webp_path', sanitize_text_field( $webp_path ) );
			++$converted;
		}

		$remaining_query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/jpg', 'image/png' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => self::get_pending_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);

		$remaining = (int) $remaining_query->found_posts;

		$extra_converted = 0;

		if ( 0 === $remaining ) {
			$extra_converted = $this->convert_orphan_files();
		}

		wp_send_json_success(
			array(
				'converted'       => $converted,
				'errors'          => $errors,
				'remaining'       => $remaining,
				'extra_converted' => $extra_converted,
				'done'            => 0 === $remaining,
				'stats'           => self::get_stats(),
			)
		);
	}

	/**
	 * Recursively scans the uploads directory for .png/.jpg/.jpeg files that
	 * have no matching .webp on disk (mainly thumbnails, which have no
	 * postmeta of their own and are therefore invisible to the attachment
	 * query above) and converts them.
	 *
	 * Runs only once the attachment batch is fully drained, so it is a
	 * one-shot sweep rather than something repeated on every AJAX tick.
	 *
	 * @return int Number of extra files converted.
	 */
	private function convert_orphan_files() {
		if ( ! TKMO_Converter::has_available_backend() ) {
			return 0;
		}

		$upload_dir = wp_get_upload_dir();
		$base_dir   = $upload_dir['basedir'];

		if ( ! is_dir( $base_dir ) ) {
			return 0;
		}

		$converted = 0;

		$extensions_to_mime = array(
			'png'  => 'image/png',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
		);

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$extension = strtolower( $file->getExtension() );

			if ( ! isset( $extensions_to_mime[ $extension ] ) ) {
				continue;
			}

			$source_path = $file->getPathname();
			$webp_path   = trailingslashit( dirname( $source_path ) ) . pathinfo( $source_path, PATHINFO_FILENAME ) . '.webp';

			if ( file_exists( $webp_path ) ) {
				continue;
			}

			$mime_type = $extensions_to_mime[ $extension ];
			$result    = TKMO_Converter::convert( $source_path, $mime_type, TKMO_WEBP_QUALITY );

			if ( $result ) {
				++$converted;
			}
		}

		return $converted;
	}

	/**
	 * Renders the admin page markup.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$stats = self::get_stats();
		?>
		<div class="wrap tkmo-admin-wrap">
			<h1><?php echo esc_html__( 'TK Media Optimizer', 'tk-media-optimizer' ); ?></h1>
			<p><?php echo esc_html__( 'Converte imagens PNG/JPG já existentes na biblioteca de mídia para WebP.', 'tk-media-optimizer' ); ?></p>

			<?php if ( ! TKMO_Converter::has_available_backend() ) : ?>
				<div class="notice notice-error">
					<p><?php echo esc_html__( 'Nenhum conversor (GD ou Imagick) disponível neste servidor. A conversão não pode ser executada.', 'tk-media-optimizer' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="tkmo-dashboard">
				<div class="tkmo-ring-wrap">
					<svg class="tkmo-ring" viewBox="0 0 120 120" width="140" height="140">
						<circle class="tkmo-ring-track" cx="60" cy="60" r="52" />
						<circle
							id="tkmo-ring-progress"
							class="tkmo-ring-progress"
							cx="60" cy="60" r="52"
							style="--tkmo-percent: <?php echo esc_attr( $stats['percent'] ); ?>"
						/>
					</svg>
					<div class="tkmo-ring-label">
						<span id="tkmo-ring-percent"><?php echo esc_html( $stats['percent'] ); ?></span><span>%</span>
					</div>
				</div>

				<div class="tkmo-stat-cards">
					<div class="tkmo-stat-card tkmo-stat-total">
						<span class="tkmo-stat-number" id="tkmo-stat-total"><?php echo esc_html( $stats['total'] ); ?></span>
						<span class="tkmo-stat-label"><?php echo esc_html__( 'Total de imagens', 'tk-media-optimizer' ); ?></span>
					</div>
					<div class="tkmo-stat-card tkmo-stat-converted">
						<span class="tkmo-stat-number" id="tkmo-stat-converted"><?php echo esc_html( $stats['converted'] ); ?></span>
						<span class="tkmo-stat-label"><?php echo esc_html__( 'Convertidas', 'tk-media-optimizer' ); ?></span>
					</div>
					<div class="tkmo-stat-card tkmo-stat-pending">
						<span class="tkmo-stat-number" id="tkmo-stat-pending"><?php echo esc_html( $stats['pending'] ); ?></span>
						<span class="tkmo-stat-label"><?php echo esc_html__( 'Pendentes', 'tk-media-optimizer' ); ?></span>
					</div>
					<div class="tkmo-stat-card tkmo-stat-errors">
						<span class="tkmo-stat-number" id="tkmo-stat-errors"><?php echo esc_html( $stats['errors'] ); ?></span>
						<span class="tkmo-stat-label"><?php echo esc_html__( 'Erros', 'tk-media-optimizer' ); ?></span>
					</div>
				</div>
			</div>

			<p>
				<button type="button" id="tkmo-start-batch" class="button button-primary" <?php disabled( ! TKMO_Converter::has_available_backend() ); ?>>
					<?php echo esc_html__( 'Converter todas as imagens existentes', 'tk-media-optimizer' ); ?>
				</button>
			</p>

			<div id="tkmo-progress-wrap" class="tkmo-progress-wrap" style="display:none;">
				<div class="tkmo-progress-track">
					<div id="tkmo-progress-bar" class="tkmo-progress-bar"></div>
				</div>
				<p id="tkmo-progress-status"></p>
			</div>
		</div>
		<?php
	}
}
