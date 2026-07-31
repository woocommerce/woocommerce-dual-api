<?php

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Api;

use Automattic\WooCommerce\Api\Infrastructure\Main;

/**
 * Bootstrap controller for the WooCommerce Dual API plugin.
 *
 * Once WooCommerce has loaded, the plugin runs in one of three modes:
 *
 * - Active: this WooCommerce version doesn't ship the dual API engine in
 *   core, so the plugin registers its own autoloader and boots the engine.
 *   Plugin activation is the on/off switch; there is no feature flag.
 *
 * - Dormant: this WooCommerce version still ships the engine in core
 *   (10.9-11.1). The plugin must not register its autoloader (its class
 *   names are the same as core's), so it stays inert and shows an admin
 *   notice instead; core's dual_code_graphql_api feature flag remains the
 *   on/off switch on these versions, and the notice says so. Consumer
 *   plugins work identically against core's copy, since their contract is
 *   the Automattic\WooCommerce\Api namespace and core provides it.
 *
 * - Inert with an admin notice: WooCommerce is missing or too old.
 *
 * "Does core ship the engine?" is a capability probe (class_exists on Main
 * before this plugin's autoloader is registered) rather than a version
 * check, so it works regardless of plugin load order (network-activated
 * WooCommerce loads before site plugins) and keeps working if the engine
 * is ever merged back into core.
 *
 * The main plugin file requires this class explicitly instead of relying
 * on the Composer autoloader: this is the component that decides whether
 * that autoloader may be registered at all. For the same reason, its FQCN
 * must not collide with anything that WooCommerce versions shipping the
 * engine in core provide under this namespace.
 */
class PluginLoader {

	/**
	 * Minimum WooCommerce version the plugin can run against.
	 *
	 * WooCommerce's DI container resolves classes under the
	 * Automattic\WooCommerce\ prefix from any autoloader starting with 9.5;
	 * older containers required explicit registration. Expected to be
	 * raised as the extraction progresses and compatibility is actually
	 * tested.
	 */
	const MINIMUM_WC_VERSION = '9.5';

	/**
	 * Option that enables the dual API feature in WooCommerce versions
	 * that ship the engine in core (managed by FeaturesController there).
	 * Only read by this plugin, to tailor the dormant-mode notice.
	 */
	const CORE_FEATURE_FLAG_OPTION = 'woocommerce_feature_dual_code_graphql_api_enabled';

	/**
	 * Admin notices queued for rendering.
	 *
	 * @var array[]
	 */
	private static $notices = array();

	/**
	 * Attach the bootstrap hooks. Called once from the main plugin file.
	 */
	public static function init(): void {
		add_action( 'plugins_loaded', array( __CLASS__, 'handle_plugins_loaded' ) );

		// When WooCommerce loads before this plugin (e.g. network-activated
		// WooCommerce with a site-activated plugin), woocommerce_loaded has
		// already fired by the time this file is included.
		if ( did_action( 'woocommerce_loaded' ) ) {
			self::handle_woocommerce_loaded();
		} else {
			add_action( 'woocommerce_loaded', array( __CLASS__, 'handle_woocommerce_loaded' ) );
		}
	}

	/**
	 * Warn when WooCommerce isn't active.
	 *
	 * The "Requires Plugins" header already prevents that situation on
	 * WordPress 6.5+; this covers older WordPress versions.
	 *
	 * @internal
	 */
	public static function handle_plugins_loaded(): void {
		if ( ! did_action( 'woocommerce_loaded' ) ) {
			self::add_admin_notice( 'error', __( 'WooCommerce Dual API requires WooCommerce to be installed and active.', 'woocommerce-dual-api' ) );
		}
	}

	/**
	 * Decide the run mode, now that WooCommerce is fully loaded.
	 *
	 * @internal
	 */
	public static function handle_woocommerce_loaded(): void {
		// Capability probe. It must run before the plugin's autoloader is
		// registered, so that the class can only resolve through
		// WooCommerce's own autoloaders: a hit means this WooCommerce
		// version ships the dual API engine itself.
		if ( class_exists( Main::class ) ) {
			self::enter_dormant_mode();
			return;
		}

		if ( version_compare( WC_VERSION, self::MINIMUM_WC_VERSION, '<' ) ) {
			self::add_admin_notice(
				'error',
				sprintf(
					/* translators: 1: minimum required WooCommerce version, 2: installed WooCommerce version */
					__( 'WooCommerce Dual API requires WooCommerce %1$s or newer. This site is running WooCommerce %2$s, so the plugin is inactive.', 'woocommerce-dual-api' ),
					self::MINIMUM_WC_VERSION,
					WC_VERSION
				)
			);
			return;
		}

		$autoload_path = dirname( WC_DUAL_API_PLUGIN_FILE ) . '/vendor/autoload.php';
		if ( ! is_readable( $autoload_path ) ) {
			self::add_admin_notice( 'error', __( 'The WooCommerce Dual API plugin is incomplete: its autoloader is missing. If this is a development checkout, run "composer install".', 'woocommerce-dual-api' ) );
			return;
		}

		require $autoload_path;

		// Not a probe this time: the autoloader registered on the line
		// above is expected to provide the class. Guards against an
		// incomplete build.
		if ( ! class_exists( Main::class ) ) {
			self::add_admin_notice( 'error', __( 'The WooCommerce Dual API plugin is incomplete: the API sources are missing.', 'woocommerce-dual-api' ) );
			return;
		}

		Main::register();
	}

	/**
	 * Dormant mode: core ships the engine, so the plugin only explains how
	 * the API is switched on and off on this WooCommerce version.
	 */
	private static function enter_dormant_mode(): void {
		$flag_enabled = 'yes' === get_option( self::CORE_FEATURE_FLAG_OPTION );

		$message = $flag_enabled
			? sprintf(
				/* translators: %s: installed WooCommerce version */
				__( 'WooCommerce %s already includes the dual API engine, so the WooCommerce Dual API plugin is dormant: the API is provided and controlled by WooCommerce itself.', 'woocommerce-dual-api' ),
				WC_VERSION
			)
			: sprintf(
				/* translators: 1: installed WooCommerce version, 2: feature flag option name */
				__( 'WooCommerce %1$s already includes the dual API engine, so the WooCommerce Dual API plugin is dormant — and the corresponding WooCommerce feature is currently disabled. Set the "%2$s" option to "yes", or update WooCommerce, to use the API.', 'woocommerce-dual-api' ),
				WC_VERSION,
				self::CORE_FEATURE_FLAG_OPTION
			);

		self::add_admin_notice( $flag_enabled ? 'info' : 'warning', $message );
	}

	/**
	 * Queue an admin notice.
	 *
	 * @param string $type    Notice type: 'error', 'warning' or 'info'.
	 * @param string $message Notice text, already translated.
	 */
	private static function add_admin_notice( string $type, string $message ): void {
		if ( ! is_admin() ) {
			return;
		}

		if ( empty( self::$notices ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'handle_admin_notices' ) );
		}

		self::$notices[] = array(
			'type'    => $type,
			'message' => $message,
		);
	}

	/**
	 * Render the queued admin notices.
	 *
	 * Informational notices are only shown on the plugins screen, where
	 * the user is looking at plugin status anyway; warnings and errors are
	 * shown on every admin screen.
	 *
	 * @internal
	 */
	public static function handle_admin_notices(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_id = is_null( $screen ) ? '' : $screen->id;

		foreach ( self::$notices as $notice ) {
			if ( 'info' === $notice['type'] && 'plugins' !== $screen_id ) {
				continue;
			}

			printf(
				'<div class="notice notice-%1$s"><p>%2$s</p></div>',
				esc_attr( $notice['type'] ),
				esc_html( $notice['message'] )
			);
		}
	}
}
