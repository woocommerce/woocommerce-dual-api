<?php
/**
 * Plugin Name: WooCommerce Dual API
 * Description: Experimental code-first dual (PHP + GraphQL) API engine for WooCommerce, extracted from WooCommerce core.
 * Version: 0.1.0
 * Author: Automattic
 * Author URI: https://woocommerce.com
 * Text Domain: woocommerce-dual-api
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * License: GPLv3
 * Update URI: false
 *
 * @package Automattic\WooCommerce\DualApi
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

// Emergency kill switch: define as true in wp-config.php to prevent the
// plugin from doing anything at all.
if ( defined( 'WC_DUAL_API_DISABLED' ) && WC_DUAL_API_DISABLED ) {
	return;
}

define( 'WC_DUAL_API_VERSION', '0.1.0' );
define( 'WC_DUAL_API_PLUGIN_FILE', __FILE__ );

// The "Requires PHP" header blocks activation on older PHP; this guard covers
// sites whose PHP was downgraded after the plugin was activated.
if ( PHP_VERSION_ID < 80100 ) {
	add_action(
		'admin_notices',
		function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: PHP version of the site */
						__( 'WooCommerce Dual API requires PHP 8.1 or newer. This site is running PHP %s, so the plugin is inactive.', 'woocommerce-dual-api' ),
						PHP_VERSION
					)
				)
			);
		}
	);

	return;
}

require __DIR__ . '/includes/class-wc-dual-api-loader.php';

WC_Dual_API_Loader::init();
