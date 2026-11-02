<?php
/**
 * Admin bar control that clears the site caches.
 *
 * @package MWW_Cache_Smasher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Smash Cache and runs each cache plugin's own purge or version bump.
 */
class MWW_Cache_Smasher {

	const QUERY_ARG    = 'mww_cache_smasher';
	const NONCE_ACTION = 'mww_cache_smasher';

	/**
	 * Hook the admin bar, the click handler, and the result notice.
	 */
	public function init() {
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ), 10001 );
		add_action( 'init', array( $this, 'handle_request' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notice' ) );
		add_action( 'wp_footer', array( $this, 'render_front_notice' ) );
	}

	/**
	 * Administrators and Editors, plus network super admins.
	 *
	 * @return bool
	 */
	public function current_user_can_smash() {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$allowed = is_super_admin();

		if ( ! $allowed ) {
			$roles   = (array) wp_get_current_user()->roles;
			$allowed = in_array( 'administrator', $roles, true ) || in_array( 'editor', $roles, true );
		}

		/**
		 * Filters whether the current user may smash caches.
		 *
		 * @param bool $allowed Whether the current user may smash caches.
		 */
		return (bool) apply_filters( 'mww_cache_smasher_user_can_smash', $allowed );
	}

	/**
	 * Add Smash Cache and remove the other cache plugins' toolbar items.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar.
	 */
	public function admin_bar_menu( $wp_admin_bar ) {
		$wp_admin_bar->remove_node( 'nginx-helper-purge-all' );
		$wp_admin_bar->remove_node( 'redis-cache' );
		$wp_admin_bar->remove_node( 'redis-cache-flush' );
		$wp_admin_bar->remove_node( 'redis-cache-metrics' );
		$wp_admin_bar->remove_node( 'redis-cache-info' );
		$wp_admin_bar->remove_node( 'redis-cache-info-details' );
		$wp_admin_bar->remove_node( 'pbc_update_css_js' );

		if ( ! $this->current_user_can_smash() ) {
			return;
		}

		$smash_url = wp_nonce_url(
			add_query_arg( self::QUERY_ARG, '1' ),
			self::NONCE_ACTION
		);

		$wp_admin_bar->add_node(
			array(
				'id'    => 'mww-cache-smasher',
				'title' => __( 'Smash Cache', 'mcdonald-web-works-cache-smasher' ),
				'href'  => $smash_url,
				'meta'  => array(
					'title'   => __( 'Smash Cache', 'mcdonald-web-works-cache-smasher' ),
					'onclick' => 'return confirm("Smash Cache will purge Cloudflare, Nginx Helper, and Redis Object Cache, and update Prevent Browser Caching versions. Continue?");',
				),
			)
		);
	}

	/**
	 * Run the smash when the admin bar link is followed.
	 */
	public function handle_request() {
		if ( ! isset( $_GET[ self::QUERY_ARG ] ) ) {
			return;
		}

		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( ! $this->current_user_can_smash() ) {
			wp_die(
				esc_html__( 'You do not have permission to smash caches.', 'mcdonald-web-works-cache-smasher' ),
				esc_html__( 'Smash Cache', 'mcdonald-web-works-cache-smasher' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::NONCE_ACTION );

		$report = $this->smash();

		set_transient( $this->report_key(), $report, 2 * MINUTE_IN_SECONDS );

		wp_safe_redirect( remove_query_arg( array( self::QUERY_ARG, '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Admin notice after a smash.
	 */
	public function render_admin_notice() {
		if ( ! is_admin() ) {
			return;
		}

		$this->render_notice( 'admin' );
	}

	/**
	 * Front-end notice after a smash. The admin bar is visible on the front end.
	 */
	public function render_front_notice() {
		if ( is_admin() ) {
			return;
		}

		$this->render_notice( 'front' );
	}

	/**
	 * Bump asset versions, then clear object cache, page cache, and the CDN.
	 *
	 * Versions are updated first so rebuilt pages reference the new assets.
	 * Cloudflare is last so the edge fetches the origin after Nginx is empty.
	 *
	 * @return array<int,array{label:string,ok:bool,message:string}>
	 */
	private function smash() {
		return array(
			$this->smash_prevent_browser_caching(),
			$this->smash_redis(),
			$this->smash_nginx(),
			$this->smash_cloudflare(),
		);
	}

	/**
	 * Update CSS, JS, and image versions without Prevent Browser Caching's page-cache purge.
	 *
	 * @return array{label:string,ok:bool,message:string}
	 */
	private function smash_prevent_browser_caching() {
		$label = __( 'Prevent Browser Caching', 'mcdonald-web-works-cache-smasher' );

		if ( ! class_exists( 'Prevent_Browser_Caching' ) ) {
			return $this->result( $label, false, __( 'Plugin is not active.', 'mcdonald-web-works-cache-smasher' ) );
		}

		try {
			$result = Prevent_Browser_Caching::instance()->bump_versions( true );
		} catch ( \Throwable $e ) {
			$this->log_error( 'Prevent Browser Caching', $e );

			return $this->result( $label, false, __( 'Version update failed.', 'mcdonald-web-works-cache-smasher' ) );
		}

		if ( ! is_array( $result ) || empty( $result['time'] ) ) {
			return $this->result( $label, false, __( 'Version update did not return a new version.', 'mcdonald-web-works-cache-smasher' ) );
		}

		return $this->result( $label, true, __( 'Asset versions updated.', 'mcdonald-web-works-cache-smasher' ) );
	}

	/**
	 * Flush the Redis object cache through wp_cache_flush().
	 *
	 * @return array{label:string,ok:bool,message:string}
	 */
	private function smash_redis() {
		$label = __( 'Redis Object Cache', 'mcdonald-web-works-cache-smasher' );

		if ( ! function_exists( 'redis_object_cache' ) ) {
			return $this->result( $label, false, __( 'Plugin is not active.', 'mcdonald-web-works-cache-smasher' ) );
		}

		$status = redis_object_cache()->get_redis_status();

		if ( null === $status ) {
			return $this->result( $label, false, __( 'Object cache drop-in is not enabled.', 'mcdonald-web-works-cache-smasher' ) );
		}

		if ( true !== $status ) {
			return $this->result( $label, false, __( 'Redis is not connected.', 'mcdonald-web-works-cache-smasher' ) );
		}

		if ( ! function_exists( 'wp_cache_flush' ) ) {
			return $this->result( $label, false, __( 'Object cache flush is unavailable.', 'mcdonald-web-works-cache-smasher' ) );
		}

		try {
			$flushed = wp_cache_flush();
		} catch ( \Throwable $e ) {
			$this->log_error( 'Redis Object Cache', $e );

			return $this->result( $label, false, __( 'Flush failed.', 'mcdonald-web-works-cache-smasher' ) );
		}

		if ( ! $flushed ) {
			return $this->result( $label, false, __( 'Flush failed.', 'mcdonald-web-works-cache-smasher' ) );
		}

		return $this->result( $label, true, __( 'Object cache flushed.', 'mcdonald-web-works-cache-smasher' ) );
	}

	/**
	 * Purge the entire Nginx Helper cache, including from the front end.
	 *
	 * @return array{label:string,ok:bool,message:string}
	 */
	private function smash_nginx() {
		$label = __( 'Nginx Helper', 'mcdonald-web-works-cache-smasher' );

		if ( ! has_action( 'rt_nginx_helper_purge_all' ) ) {
			return $this->result( $label, false, __( 'Plugin is not active.', 'mcdonald-web-works-cache-smasher' ) );
		}

		try {
			do_action( 'rt_nginx_helper_purge_all' );
		} catch ( \Throwable $e ) {
			$this->log_error( 'Nginx Helper', $e );

			return $this->result( $label, false, __( 'Purge failed.', 'mcdonald-web-works-cache-smasher' ) );
		}

		return $this->result( $label, true, __( 'All cache purged.', 'mcdonald-web-works-cache-smasher' ) );
	}

	/**
	 * Purge the entire Cloudflare zone through the Cloudflare plugin's API client.
	 *
	 * @return array{label:string,ok:bool,message:string}
	 */
	private function smash_cloudflare() {
		$label = __( 'Cloudflare', 'mcdonald-web-works-cache-smasher' );

		if ( ! defined( 'CLOUDFLARE_PLUGIN_DIR' ) || ! class_exists( '\Cloudflare\APO\WordPress\Hooks', false ) ) {
			return $this->result( $label, false, __( 'Plugin is not active.', 'mcdonald-web-works-cache-smasher' ) );
		}

		require_once MWW_CACHE_SMASHER_DIR . 'includes/class-mww-cache-smasher-cloudflare.php';

		if ( ! class_exists( 'MWW_Cache_Smasher_Cloudflare', false ) ) {
			return $this->result( $label, false, __( 'Cloudflare purge class could not be loaded.', 'mcdonald-web-works-cache-smasher' ) );
		}

		try {
			$purger = new MWW_Cache_Smasher_Cloudflare();
			$result = $purger->purge_everything();
		} catch ( \Throwable $e ) {
			$this->log_error( 'Cloudflare', $e );

			return $this->result( $label, false, __( 'Purge failed.', 'mcdonald-web-works-cache-smasher' ) );
		}

		if ( ! is_array( $result ) || ! isset( $result['ok'], $result['message'] ) ) {
			return $this->result( $label, false, __( 'Purge failed.', 'mcdonald-web-works-cache-smasher' ) );
		}

		return $this->result( $label, (bool) $result['ok'], (string) $result['message'] );
	}

	/**
	 * @param string $label   Cache name.
	 * @param bool   $ok      Whether that cache was cleared or updated.
	 * @param string $message Result sentence.
	 * @return array{label:string,ok:bool,message:string}
	 */
	private function result( $label, $ok, $message ) {
		return array(
			'label'   => $label,
			'ok'      => (bool) $ok,
			'message' => $message,
		);
	}

	/**
	 * Print the one-time result list, then delete it.
	 *
	 * @param string $context `admin` or `front`.
	 */
	private function render_notice( $context ) {
		if ( ! $this->current_user_can_smash() ) {
			return;
		}

		$report = get_transient( $this->report_key() );

		if ( ! is_array( $report ) || empty( $report ) ) {
			return;
		}

		delete_transient( $this->report_key() );

		$all_ok = true;

		foreach ( $report as $row ) {
			if ( empty( $row['ok'] ) ) {
				$all_ok = false;
				break;
			}
		}

		$class = $all_ok ? 'notice-success' : 'notice-warning';

		if ( 'admin' === $context ) {
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p><strong>' . esc_html__( 'Smash Cache', 'mcdonald-web-works-cache-smasher' ) . '</strong></p><ul style="list-style:disc;margin-left:1.5em;">';
		} else {
			$border = $all_ok ? '#00a32a' : '#dba617';
			echo '<div id="mww-cache-smasher-notice" style="position:fixed;z-index:99999;top:46px;right:16px;max-width:360px;padding:12px 16px;background:#fff;border-left:4px solid ' . esc_attr( $border ) . ';box-shadow:0 2px 8px rgba(0,0,0,.15);font:13px/1.4 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Oxygen-Sans,Ubuntu,Cantarell,\'Helvetica Neue\',sans-serif;">';
			echo '<p style="margin:0 0 8px;"><strong>' . esc_html__( 'Smash Cache', 'mcdonald-web-works-cache-smasher' ) . '</strong></p><ul style="margin:0;padding-left:1.2em;">';
		}

		foreach ( $report as $row ) {
			if ( empty( $row['label'] ) || ! isset( $row['message'] ) ) {
				continue;
			}

			printf(
				'<li><strong>%s:</strong> %s</li>',
				esc_html( $row['label'] ),
				esc_html( $row['message'] )
			);
		}

		echo '</ul></div>';

		if ( 'front' === $context ) {
			echo '<script>setTimeout(function(){var n=document.getElementById("mww-cache-smasher-notice");if(n&&n.parentNode){n.parentNode.removeChild(n);}},4000);</script>';
		}
	}

	/**
	 * Per-user transient key. Set after the Redis flush so the flush does not delete it.
	 *
	 * @return string
	 */
	private function report_key() {
		return 'mww_cs_report_' . get_current_user_id();
	}

	/**
	 * @param string     $cache Cache label for the log line.
	 * @param \Throwable $e     Caught failure.
	 */
	private function log_error( $cache, $e ) {
		error_log(
			sprintf(
				'McDonald Web Works Cache Smasher: %s failed: %s',
				$cache,
				$e->getMessage()
			)
		);
	}
}
