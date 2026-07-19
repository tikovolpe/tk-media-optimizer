<?php
/**
 * Plugin Name:       TK Media Optimizer
 * Plugin URI:        https://tikovolpe.com.br
 * Description:       Converte automaticamente imagens para WebP no upload. Desenvolvido pela Tiko Volpe Studio.
 * Version:           1.0.4
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Tiko Volpe Studio
 * Author URI:        https://tikovolpe.com.br
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tk-media-optimizer
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TKMO_VERSION', '1.0.4' );
define( 'TKMO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TKMO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TKMO_PLUGIN_FILE', __FILE__ );
define( 'TKMO_WEBP_QUALITY', 82 );
define( 'TKMO_GITHUB_REPO', 'tikovolpe/tk-media-optimizer' );

require_once TKMO_PLUGIN_DIR . 'includes/plugin-update-checker/load-v5p7.php';

/**
 * Wires the GitHub-based auto-updater so the plugin shows update
 * notifications and can be updated from Dashboard > Updates, sourcing
 * releases from the public tikovolpe/tk-media-optimizer repo.
 *
 * @return void
 */
function tkmo_init_updater() {
	\YahnisElsts\PluginUpdateChecker\v5p7\PucFactory::buildUpdateChecker(
		'https://github.com/' . TKMO_GITHUB_REPO . '/',
		TKMO_PLUGIN_FILE,
		'tk-media-optimizer'
	);
}
add_action( 'plugins_loaded', 'tkmo_init_updater' );

/**
 * Bootstraps the plugin once all files are loaded.
 */
function tkmo_init() {
	require_once TKMO_PLUGIN_DIR . 'includes/class-converter.php';
	require_once TKMO_PLUGIN_DIR . 'includes/class-hooks.php';

	TKMO_Hooks::get_instance();

	if ( is_admin() ) {
		require_once TKMO_PLUGIN_DIR . 'admin/class-admin-page.php';
		TKMO_Admin_Page::get_instance();
	}
}
add_action( 'plugins_loaded', 'tkmo_init' );
