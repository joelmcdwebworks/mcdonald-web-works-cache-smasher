<?php
/**
 * Plugin Name:       McDonald Web Works Cache Smasher
 * Plugin URI:        https://github.com/joelmcdwebworks/mcdonald-web-works-cache-smasher
 * Description:       Adds a Smash Cache admin bar button that purges Cloudflare, Nginx Helper, and Redis Object Cache, and updates Prevent Browser Caching versions.
 * Version:           1.0.2
 * Requires at least: 5.0
 * Tested up to:      7.1
 * Requires PHP:      7.4
 * Author:            McDonald Web Works
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mcdonald-web-works-cache-smasher
 * Update URI:        https://github.com/joelmcdwebworks/mcdonald-web-works-cache-smasher
 *
 * @package MWW_Cache_Smasher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MWW_CACHE_SMASHER_VERSION', '1.0.2' );
define( 'MWW_CACHE_SMASHER_FILE', __FILE__ );
define( 'MWW_CACHE_SMASHER_DIR', plugin_dir_path( __FILE__ ) );

require_once MWW_CACHE_SMASHER_DIR . 'includes/class-mww-cache-smasher.php';

/**
 * Start the plugin after the cache plugins have registered their hooks.
 */
function mww_cache_smasher_bootstrap() {
	$plugin = new MWW_Cache_Smasher();
	$plugin->init();
}

add_action( 'plugins_loaded', 'mww_cache_smasher_bootstrap' );

/**
 * Check GitHub for a newer release.
 *
 * Updates come from published releases on the plugin repository. The release
 * asset named mcdonald-web-works-cache-smasher.zip is the package WordPress installs.
 */
function mww_cache_smasher_updates() {
	$library = MWW_CACHE_SMASHER_DIR . 'vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php';

	if ( ! is_readable( $library ) ) {
		return;
	}

	require_once $library;

	$update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/joelmcdwebworks/mcdonald-web-works-cache-smasher/',
		MWW_CACHE_SMASHER_FILE,
		'mcdonald-web-works-cache-smasher'
	);

	$update_checker->setBranch( 'main' );
	$update_checker->getVcsApi()->enableReleaseAssets( '/^mcdonald-web-works-cache-smasher\.zip$/' );
}

add_action( 'plugins_loaded', 'mww_cache_smasher_updates' );
