<?php
/**
 * Purge wrapper around the official Cloudflare plugin.
 *
 * Loaded only after Cloudflare\APO\WordPress\Hooks exists.
 *
 * @package MWW_Cache_Smasher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\Cloudflare\APO\WordPress\Hooks', false ) ) {
	return;
}

/**
 * Calls the same zone purge the Cloudflare plugin uses for purge-everything.
 */
class MWW_Cache_Smasher_Cloudflare extends Cloudflare\APO\WordPress\Hooks {

	/**
	 * Purge the entire Cloudflare zone.
	 *
	 * Mirrors Hooks::purgeCacheEverything(), and returns a result the admin
	 * notice can show. The parent method logs and returns void.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function purge_everything() {
		if ( ! $this->isPluginSpecificCacheEnabled() && ! $this->isAutomaticPlatformOptimizationEnabled() ) {
			return array(
				'ok'      => false,
				'message' => __( 'Purge did not run. The Cloudflare plugin only purges when Automatic Platform Optimization or plugin-specific cache is enabled.', 'mcdonald-web-works-cache-smasher' ),
			);
		}

		$domains = $this->integrationAPI->getDomainList();

		if ( empty( $domains ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Purge did not run. The Cloudflare plugin has no domain configured.', 'mcdonald-web-works-cache-smasher' ),
			);
		}

		$zone_tag = $this->api->getZoneTag( $domains[0] );

		if ( empty( $zone_tag ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Purge did not run. The Cloudflare plugin could not find a zone for this domain.', 'mcdonald-web-works-cache-smasher' ),
			);
		}

		if ( $this->api->zonePurgeCache( $zone_tag ) ) {
			return array(
				'ok'      => true,
				'message' => __( 'Zone cache purged.', 'mcdonald-web-works-cache-smasher' ),
			);
		}

		return array(
			'ok'      => false,
			'message' => __( 'Cloudflare rejected the purge request.', 'mcdonald-web-works-cache-smasher' ),
		);
	}
}
