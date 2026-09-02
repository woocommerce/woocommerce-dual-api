<?php
/**
 * PHPUnit bootstrap file for the WooCommerce Dual API plugin.
 *
 * Replicates WooCommerce core's unit-test bootstrap as closely as a standalone
 * plugin can, so the tests run against the real WooCommerce test framework:
 *
 *  - the genuine `WC_Unit_Test_Case` / `WC_REST_Unit_Test_Case` base classes;
 *  - a `TestingContainer` swapped in for the runtime DI container, which replaces
 *    `LegacyProxy` with `MockableLegacyProxy`.
 *
 * The framework is loaded from a WooCommerce checkout located via the `WC_DIR`
 * environment variable or a set of fallback paths (see `locate_wc_dir()`). The
 * checkout must be a WooCommerce version that doesn't ship the dual API engine
 * in core (11.2 or newer, or a development build of it), otherwise the engine
 * classes would be defined twice.
 *
 * @package Automattic\WooCommerce\DualApi
 */

declare(strict_types=1);

use Automattic\WooCommerce\Api\Infrastructure\Main;
use Automattic\WooCommerce\Testing\Tools\TestingContainer;

/**
 * Class WC_Dual_Api_Unit_Tests_Bootstrap
 */
class WC_Dual_Api_Unit_Tests_Bootstrap {

	/**
	 * Directory where wordpress-tests-lib is installed.
	 *
	 * @var string
	 */
	private static $wp_tests_dir;

	/**
	 * This plugin's root directory.
	 *
	 * @var string
	 */
	private static $plugin_dir;

	/**
	 * WooCommerce plugin directory (the WooCommerce checkout).
	 *
	 * @var string
	 */
	private static $wc_dir;

	/**
	 * WooCommerce legacy tests directory ($wc_dir/tests/legacy).
	 *
	 * @var string
	 */
	private static $wc_tests_dir;

	/**
	 * WooCommerce tests root directory ($wc_dir/tests).
	 *
	 * @var string
	 */
	private static $wc_tests_root;

	/**
	 * Set up the unit testing environment.
	 */
	public static function init(): void {
		self::$plugin_dir = dirname( __DIR__ );

		self::$wc_dir = self::locate_wc_dir();
		if ( is_null( self::$wc_dir ) ) {
			echo 'Could not find WooCommerce. Set the WC_DIR environment variable to the path of a WooCommerce checkout (its plugins/woocommerce directory).' . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit( 1 );
		}
		echo 'WooCommerce found at: ' . self::$wc_dir . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		self::$wc_tests_root = self::$wc_dir . '/tests';
		self::$wc_tests_dir  = self::$wc_dir . '/tests/legacy';

		self::register_autoloader_for_testing_tools();

		// phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed
		ini_set( 'display_errors', 'on' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting, WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting
		error_reporting( E_ALL );

		// Ensure server variable is set for WP email functions.
		if ( ! isset( $_SERVER['SERVER_NAME'] ) ) {
			$_SERVER['SERVER_NAME'] = 'localhost';
		}

		self::$wp_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';

		if ( ! file_exists( self::$wp_tests_dir . '/includes/functions.php' ) ) {
			echo 'Could not find ' . self::$wp_tests_dir . '/includes/functions.php, have you run tests/bin/install-wp-tests.sh ?' . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit( 1 );
		}

		require_once self::$wp_tests_dir . '/includes/functions.php';

		tests_add_filter( 'muplugins_loaded', array( __CLASS__, 'load_plugins' ) );
		tests_add_filter( 'setup_theme', array( __CLASS__, 'install_wc' ) );

		if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
			define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', self::$plugin_dir . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php' );
		}

		// Fires muplugins_loaded (load_plugins) and setup_theme (install_wc).
		require_once self::$wp_tests_dir . '/includes/bootstrap.php';

		if ( ! defined( 'FS_METHOD' ) ) {
			define( 'FS_METHOD', 'direct' );
		}

		// Must run after WP's bootstrap so WP_UnitTestCase exists.
		self::includes();

		// Must be the last step, after WooCommerce has loaded and its container is initialized.
		self::initialize_dependency_injection();

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting, WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting
		error_reporting( error_reporting() & ~E_DEPRECATED );
	}

	/**
	 * Locate the WooCommerce plugin directory.
	 *
	 * @return string|null The WooCommerce directory path, or null if not found.
	 */
	private static function locate_wc_dir(): ?string {
		$env = getenv( 'WC_DIR' );
		if ( $env && file_exists( $env . '/woocommerce.php' ) ) {
			return $env;
		}

		$candidates = array(
			rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress/wp-content/plugins/woocommerce',
			dirname( self::$plugin_dir ) . '/woocommerce/plugins/woocommerce',
			dirname( self::$plugin_dir ) . '/woocommerce',
		);

		foreach ( $candidates as $path ) {
			if ( file_exists( $path . '/woocommerce.php' ) ) {
				return $path;
			}
		}

		return null;
	}

	/**
	 * Register an autoloader for the `Automattic\WooCommerce\Testing\Tools` namespace,
	 * resolved from the WooCommerce checkout's `tests/Tools` directory.
	 */
	private static function register_autoloader_for_testing_tools(): void {
		$base_dir = self::$wc_tests_root . '/Tools/';

		spl_autoload_register(
			function ( $class_name ) use ( $base_dir ) {
				$prefix = 'Automattic\\WooCommerce\\Testing\\Tools\\';
				$len    = strlen( $prefix );
				if ( strncmp( $prefix, $class_name, $len ) !== 0 ) {
					return;
				}

				$relative_class = substr( $class_name, $len );
				$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';
				if ( file_exists( $file ) ) {
					require $file;
				}
			}
		);
	}

	/**
	 * Load WooCommerce and boot the dual API engine.
	 *
	 * Hooked to `muplugins_loaded`.
	 *
	 * The engine is booted directly through Main::register() rather than by
	 * loading the plugin's main file: PluginLoader's job is to decide whether
	 * the engine may run in a given WordPress installation (WooCommerce version
	 * floor, in-core engine probe), which is beside the point when testing the
	 * engine itself, and the probe would always report the engine as already
	 * loadable here because the plugin's own autoloader is registered by
	 * PHPUnit. The plugin's constants are defined so code paths that read them
	 * behave as in a real install.
	 */
	public static function load_plugins(): void {
		define( 'WC_TAX_ROUNDING_MODE', 'auto' );
		define( 'WC_USE_TRANSACTIONS', false );
		define( 'WC_DUAL_API_VERSION', 'tests' );
		define( 'WC_DUAL_API_PLUGIN_FILE', self::$plugin_dir . '/woocommerce-dual-api.php' );

		require_once self::$wc_dir . '/woocommerce.php';

		Main::register();
	}

	/**
	 * Install WooCommerce after the test environment and WooCommerce have been loaded.
	 *
	 * Hooked to `setup_theme`.
	 */
	public static function install_wc(): void {
		define( 'WP_UNINSTALL_PLUGIN', true );
		define( 'WC_REMOVE_ALL_DATA', true );
		include self::$wc_dir . '/uninstall.php';

		WC_Install::install();

		// Reload capabilities after install, see https://core.trac.wordpress.org/ticket/28374.
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_roles();

		echo esc_html( 'Installing WooCommerce...' . PHP_EOL );
	}

	/**
	 * Load the WooCommerce test framework: factories, mocks, base test cases and helpers.
	 *
	 * Mirrors the relevant subset of WooCommerce core's `WC_Unit_Tests_Bootstrap::includes()`.
	 */
	public static function includes(): void {
		$framework = self::$wc_tests_dir . '/framework';
		$helpers   = $framework . '/helpers';

		require_once $framework . '/class-wc-unit-test-factory.php';
		require_once $framework . '/class-wc-mock-session-handler.php';
		require_once $framework . '/class-wc-mock-wc-data.php';
		require_once $framework . '/class-wc-mock-wc-object-query.php';
		require_once $framework . '/class-wc-mock-payment-gateway.php';
		require_once $framework . '/class-wc-mock-enhanced-payment-gateway.php';
		require_once $framework . '/class-wc-payment-token-stub.php';
		require_once $framework . '/vendor/class-wp-test-spy-rest-server.php';

		require_once self::$wc_tests_dir . '/includes/wp-http-testcase.php';
		require_once $framework . '/class-wc-unit-test-case.php';
		require_once $framework . '/class-wc-rest-unit-test-case.php';

		require_once $helpers . '/class-wc-helper-product.php';
		require_once $helpers . '/class-wc-helper-coupon.php';
		require_once $helpers . '/class-wc-helper-fee.php';
		require_once $helpers . '/class-wc-helper-shipping.php';
		require_once $helpers . '/class-wc-helper-customer.php';
		require_once $helpers . '/class-wc-helper-order.php';
		require_once $helpers . '/class-wc-helper-shipping-zones.php';
		require_once $helpers . '/class-wc-helper-payment-token.php';
		require_once $helpers . '/class-wc-helper-settings.php';
	}

	/**
	 * Replace the runtime DI container with a `TestingContainer`.
	 *
	 * WooCommerce has already initialized DI as part of its load; the read-only
	 * `Container` stores the inner container in a private property, which is
	 * swapped here via reflection. `TestingContainer` replaces the `LegacyProxy`
	 * instance with a `MockableLegacyProxy`.
	 *
	 * @throws \Exception When the `Container` class no longer has a 'container' property.
	 */
	private static function initialize_dependency_injection(): void {
		try {
			$inner_container_property = new \ReflectionProperty( \Automattic\WooCommerce\Container::class, 'container' );
		} catch ( ReflectionException $ex ) {
			throw new \Exception( "Error when trying to get the private 'container' property from the " . \Automattic\WooCommerce\Container::class . ' class using reflection during unit testing bootstrap, has the property been removed or renamed?' );
		}

		$inner_container_property->setAccessible( true );

		$container       = wc_get_container();
		$inner_container = $inner_container_property->getValue( $container );
		$inner_container = new TestingContainer( $inner_container );
		$inner_container_property->setValue( $container, $inner_container );

		$GLOBALS['wc_container'] = $inner_container;
	}
}

WC_Dual_Api_Unit_Tests_Bootstrap::init();
