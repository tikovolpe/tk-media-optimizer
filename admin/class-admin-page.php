<?php
/**
 * Admin page: bulk-convert existing media (and their thumbnails) to WebP.
 *
 * Everything on this page works off the physical uploads directory rather
 * than the attachments table. WordPress writes thumbnail files
 * (foto-300x300.png, foto-1024x576.png, ...) to disk without registering
 * them as attachments, so a DB-driven scan under-counts and skips them.
 * A recursive disk scan is the only source of truth for "which images
 * actually exist and which already have a .webp sibling".
 *
 * @package TK_Media_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TKMO_Admin_Page
 *
 * Adds a "TK Media Optimizer" page under Tools with a disk-driven,
 * paginated AJAX batch converter and a live dashboard.
 */
class TKMO_Admin_Page {

	/**
	 * Singleton instance.
	 *
	 * @var TKMO_Admin_Page|null
	 */
	private static $instance = null;

	/**
	 * Number of files processed per AJAX request (keeps each request short
	 * enough to avoid PHP/web-server timeouts on large libraries).
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
	 * Transient holding the pending file queue between AJAX ticks, so a
	 * batch can resume where it left off if a request is interrupted.
	 *
	 * @var string
	 */
	const QUEUE_TRANSIENT = 'tkmo_batch_queue';

	/**
	 * Option storing the absolute paths of files that failed conversion.
	 * Keyed by path so lookups and de-duplication are O(1).
	 *
	 * @var string
	 */
	const ERRORS_OPTION = 'tkmo_conversion_errors';

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
	 * Enqueues the admin assets only on this plugin's page.
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
					'error'      => esc_html__( 'Erro.', 'tk-media-optimizer' ),
					'conn_error' => esc_html__( 'Erro de conexão.', 'tk-media-optimizer' ),
					'converted'  => esc_html__( 'Convertida', 'tk-media-optimizer' ),
					'pending'    => esc_html__( 'Pendente', 'tk-media-optimizer' ),
					'errors'     => esc_html__( 'Erro', 'tk-media-optimizer' ),
					'root'       => esc_html__( '(raiz)', 'tk-media-optimizer' ),
				),
			)
		);
	}

	/**
	 * Extensions eligible for conversion, mapped to their mime type.
	 *
	 * @return array<string,string>
	 */
	private static function get_supported_extensions() {
		return array(
			'png'  => 'image/png',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
		);
	}

	/**
	 * Returns the .webp sibling path for a given source image path.
	 *
	 * @param string $path Absolute path to the source image.
	 * @return string
	 */
	private static function webp_path_for( $path ) {
		return trailingslashit( dirname( $path ) ) . pathinfo( $path, PATHINFO_FILENAME ) . '.webp';
	}

	/**
	 * Returns the persisted map of failed-conversion paths.
	 *
	 * @return array<string,int>
	 */
	private static function get_error_map() {
		$map = get_option( self::ERRORS_OPTION, array() );

		return is_array( $map ) ? $map : array();
	}

	/**
	 * Builds a new RecursiveIteratorIterator over the uploads directory.
	 *
	 * @param string $base_dir Uploads base directory.
	 * @return RecursiveIteratorIterator
	 */
	private static function build_iterator( $base_dir ) {
		return new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
	}

	/**
	 * Recursively scans the entire uploads directory and reports the real
	 * on-disk state of every PNG/JPG/JPEG (originals AND thumbnails).
	 *
	 * A file is:
	 *  - converted, if a .webp sibling exists on disk;
	 *  - errors,    if it has no .webp but is listed in the errors option;
	 *  - pending,   otherwise.
	 *
	 * When $with_groups is true, per-folder tallies are collected so the
	 * dashboard can render a grouped table.
	 *
	 * @param bool $with_groups Whether to collect per-folder tallies.
	 * @return array{total:int,converted:int,pending:int,errors:int,original_bytes:int,webp_bytes:int,saved_bytes:int,percent:int,groups:array}
	 */
	private static function scan_disk( $with_groups = false ) {
		$stats = array(
			'total'          => 0,
			'converted'      => 0,
			'pending'        => 0,
			'errors'         => 0,
			'original_bytes' => 0,
			'webp_bytes'     => 0,
			'saved_bytes'    => 0,
			'percent'        => 0,
			'groups'         => array(),
		);

		$upload_dir = wp_get_upload_dir();
		$base_dir   = $upload_dir['basedir'];

		if ( ! is_dir( $base_dir ) ) {
			return $stats;
		}

		$extensions = self::get_supported_extensions();
		$error_map  = self::get_error_map();
		$base_len   = strlen( trailingslashit( wp_normalize_path( $base_dir ) ) );
		$groups     = array();

		foreach ( self::build_iterator( $base_dir ) as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$extension = strtolower( $file->getExtension() );

			if ( ! isset( $extensions[ $extension ] ) ) {
				continue;
			}

			$path      = $file->getPathname();
			$webp_path = self::webp_path_for( $path );

			if ( file_exists( $webp_path ) ) {
				$status                   = 'converted';
				$stats['original_bytes'] += (int) $file->getSize();
				$stats['webp_bytes']     += (int) self::safe_filesize( $webp_path );
			} elseif ( isset( $error_map[ $path ] ) ) {
				$status = 'errors';
			} else {
				$status = 'pending';
			}

			++$stats[ $status ];
			++$stats['total'];

			if ( $with_groups ) {
				$relative = substr( wp_normalize_path( $path ), $base_len );
				$folder   = trim( dirname( $relative ), '/.' );
				$folder   = '' === $folder ? '/' : $folder;

				if ( ! isset( $groups[ $folder ] ) ) {
					$groups[ $folder ] = array(
						'converted' => 0,
						'pending'   => 0,
						'errors'    => 0,
					);
				}

				++$groups[ $folder ][ $status ];
			}
		}

		$stats['saved_bytes'] = max( 0, $stats['original_bytes'] - $stats['webp_bytes'] );
		$stats['percent']     = $stats['total'] > 0 ? (int) round( ( $stats['converted'] / $stats['total'] ) * 100 ) : 0;

		if ( $with_groups ) {
			ksort( $groups );
			$stats['groups'] = $groups;
		}

		return $stats;
	}

	/**
	 * filesize() wrapper that never emits a warning for a vanished file.
	 *
	 * @param string $path Absolute file path.
	 * @return int
	 */
	private static function safe_filesize( $path ) {
		if ( ! is_file( $path ) ) {
			return 0;
		}

		$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return false === $size ? 0 : (int) $size;
	}

	/**
	 * Walks the uploads directory and returns the absolute paths of every
	 * eligible image that still lacks a .webp sibling.
	 *
	 * @return string[]
	 */
	private static function build_pending_queue() {
		$queue      = array();
		$upload_dir = wp_get_upload_dir();
		$base_dir   = $upload_dir['basedir'];

		if ( ! is_dir( $base_dir ) ) {
			return $queue;
		}

		$extensions = self::get_supported_extensions();

		foreach ( self::build_iterator( $base_dir ) as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$extension = strtolower( $file->getExtension() );

			if ( ! isset( $extensions[ $extension ] ) ) {
				continue;
			}

			$path = $file->getPathname();

			if ( file_exists( self::webp_path_for( $path ) ) ) {
				continue;
			}

			$queue[] = $path;
		}

		return $queue;
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
	 * AJAX: returns the current dashboard stats (with grouped folder data).
	 *
	 * @return void
	 */
	public function ajax_get_stats() {
		$this->verify_ajax_request();

		wp_send_json_success( self::scan_disk( true ) );
	}

	/**
	 * AJAX: builds the pending queue from disk, resets prior error state,
	 * stores the queue in a transient and reports the total to process.
	 *
	 * @return void
	 */
	public function ajax_scan_pending() {
		$this->verify_ajax_request();

		set_time_limit( 300 );

		// Fresh run: clear old error flags so previously-failed files retry.
		update_option( self::ERRORS_OPTION, array(), false );

		$queue = self::build_pending_queue();

		set_transient( self::QUEUE_TRANSIENT, $queue, HOUR_IN_SECONDS );

		wp_send_json_success( array( 'total' => count( $queue ) ) );
	}

	/**
	 * AJAX: converts the next BATCH_SIZE files from the queued list and
	 * reports progress plus refreshed disk stats.
	 *
	 * @return void
	 */
	public function ajax_convert_batch() {
		$this->verify_ajax_request();

		set_time_limit( 300 );

		if ( ! TKMO_Converter::has_available_backend() ) {
			delete_transient( self::QUEUE_TRANSIENT );

			wp_send_json_success(
				array(
					'converted' => 0,
					'failed'    => 0,
					'remaining' => 0,
					'done'      => true,
					'message'   => esc_html__( 'Nenhum conversor (GD ou Imagick) disponível neste servidor.', 'tk-media-optimizer' ),
					'stats'     => self::scan_disk( true ),
				)
			);
		}

		$queue = get_transient( self::QUEUE_TRANSIENT );

		if ( ! is_array( $queue ) ) {
			$queue = self::build_pending_queue();
		}

		$extensions = self::get_supported_extensions();
		$error_map  = self::get_error_map();
		$batch      = array_splice( $queue, 0, self::BATCH_SIZE );
		$converted  = 0;
		$failed     = 0;

		foreach ( $batch as $path ) {
			$path      = wp_normalize_path( $path );
			$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

			if ( ! isset( $extensions[ $extension ] ) || ! file_exists( $path ) ) {
				continue;
			}

			// Already converted by a concurrent run/upload hook: count it.
			if ( file_exists( self::webp_path_for( $path ) ) ) {
				++$converted;
				unset( $error_map[ $path ] );
				continue;
			}

			$result = TKMO_Converter::convert( $path, $extensions[ $extension ], TKMO_WEBP_QUALITY );

			if ( $result ) {
				++$converted;
				unset( $error_map[ $path ] );
			} else {
				++$failed;
				$error_map[ $path ] = 1;
			}
		}

		update_option( self::ERRORS_OPTION, $error_map, false );

		$remaining = count( $queue );
		$done      = 0 === $remaining;

		if ( $done ) {
			delete_transient( self::QUEUE_TRANSIENT );
		} else {
			set_transient( self::QUEUE_TRANSIENT, $queue, HOUR_IN_SECONDS );
		}

		wp_send_json_success(
			array(
				'converted' => $converted,
				'failed'    => $failed,
				'remaining' => $remaining,
				'done'      => $done,
				'stats'     => self::scan_disk( true ),
			)
		);
	}

	/**
	 * Renders one dashboard stat card.
	 *
	 * @param string $modifier CSS modifier suffix (total|converted|pending|errors).
	 * @param string $id       Element id for the number span.
	 * @param int    $value    Current value.
	 * @param string $label    Human label.
	 * @return void
	 */
	private function render_stat_card( $modifier, $id, $value, $label ) {
		?>
		<div class="tkmo-stat-card tkmo-stat-<?php echo esc_attr( $modifier ); ?>">
			<span class="tkmo-stat-number" id="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( (string) $value ); ?></span>
			<span class="tkmo-stat-label"><?php echo esc_html( $label ); ?></span>
		</div>
		<?php
	}

	/**
	 * Renders the grouped folder table body rows from scan groups.
	 *
	 * @param array $groups Per-folder tallies from scan_disk().
	 * @return void
	 */
	private function render_groups_rows( $groups ) {
		if ( empty( $groups ) ) {
			?>
			<tr class="tkmo-table-empty">
				<td colspan="4"><?php echo esc_html__( 'Nenhuma imagem encontrada na pasta de uploads.', 'tk-media-optimizer' ); ?></td>
			</tr>
			<?php
			return;
		}

		foreach ( $groups as $folder => $counts ) {
			$label = '/' === $folder ? esc_html__( '(raiz)', 'tk-media-optimizer' ) : $folder;
			?>
			<tr>
				<td class="tkmo-col-folder"><?php echo esc_html( $label ); ?></td>
				<td class="tkmo-col-converted"><span class="tkmo-badge tkmo-badge-converted">✓ <?php echo esc_html( (string) $counts['converted'] ); ?></span></td>
				<td class="tkmo-col-pending"><span class="tkmo-badge tkmo-badge-pending">⚠ <?php echo esc_html( (string) $counts['pending'] ); ?></span></td>
				<td class="tkmo-col-errors"><span class="tkmo-badge tkmo-badge-errors">✗ <?php echo esc_html( (string) $counts['errors'] ); ?></span></td>
			</tr>
			<?php
		}
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

		$stats       = self::scan_disk( true );
		$has_backend = TKMO_Converter::has_available_backend();
		$saved_human = size_format( $stats['saved_bytes'], 1 );
		$saved_human = $saved_human ? $saved_human : '0 B';
		?>
		<div class="wrap tkmo-admin-wrap">
			<h1><?php echo esc_html__( 'TK Media Optimizer', 'tk-media-optimizer' ); ?></h1>
			<p><?php echo esc_html__( 'Converte imagens PNG/JPG (e todos os thumbnails gerados) da pasta de uploads para WebP.', 'tk-media-optimizer' ); ?></p>

			<?php if ( ! $has_backend ) : ?>
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
							style="--tkmo-percent: <?php echo esc_attr( (string) $stats['percent'] ); ?>"
						/>
					</svg>
					<div class="tkmo-ring-label">
						<span id="tkmo-ring-percent"><?php echo esc_html( (string) $stats['percent'] ); ?></span><span>%</span>
					</div>
				</div>

				<div class="tkmo-stat-cards">
					<?php
					$this->render_stat_card( 'total', 'tkmo-stat-total', $stats['total'], esc_html__( 'Imagens em disco', 'tk-media-optimizer' ) );
					$this->render_stat_card( 'converted', 'tkmo-stat-converted', $stats['converted'], esc_html__( 'Convertidas', 'tk-media-optimizer' ) );
					$this->render_stat_card( 'pending', 'tkmo-stat-pending', $stats['pending'], esc_html__( 'Pendentes', 'tk-media-optimizer' ) );
					$this->render_stat_card( 'errors', 'tkmo-stat-errors', $stats['errors'], esc_html__( 'Erros', 'tk-media-optimizer' ) );
					?>
				</div>
			</div>

			<div class="tkmo-savings">
				<span class="tkmo-savings-label"><?php echo esc_html__( 'Espaço economizado (estimado):', 'tk-media-optimizer' ); ?></span>
				<span class="tkmo-savings-value" id="tkmo-savings-value"><?php echo esc_html( $saved_human ); ?></span>
			</div>

			<p>
				<button type="button" id="tkmo-start-batch" class="button button-primary" <?php disabled( ! $has_backend ); ?>>
					<?php echo esc_html__( 'Converter todas as imagens existentes', 'tk-media-optimizer' ); ?>
				</button>
			</p>

			<div id="tkmo-progress-wrap" class="tkmo-progress-wrap" style="display:none;">
				<div class="tkmo-progress-track">
					<div id="tkmo-progress-bar" class="tkmo-progress-bar"></div>
				</div>
				<p id="tkmo-progress-status"></p>
			</div>

			<h2 class="tkmo-table-title"><?php echo esc_html__( 'Status por pasta', 'tk-media-optimizer' ); ?></h2>
			<table class="widefat striped tkmo-table">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Pasta', 'tk-media-optimizer' ); ?></th>
						<th><?php echo esc_html__( 'Convertidas', 'tk-media-optimizer' ); ?></th>
						<th><?php echo esc_html__( 'Pendentes', 'tk-media-optimizer' ); ?></th>
						<th><?php echo esc_html__( 'Erros', 'tk-media-optimizer' ); ?></th>
					</tr>
				</thead>
				<tbody id="tkmo-table-body">
					<?php $this->render_groups_rows( $stats['groups'] ); ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
