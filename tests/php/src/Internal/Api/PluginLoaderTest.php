<?php

declare(strict_types=1);

namespace Automattic\WooCommerce\Tests\Internal\Api;

use Automattic\WooCommerce\Internal\Api\PluginLoader;
use WC_Unit_Test_Case;

/**
 * Tests for {@see PluginLoader} — the plugin bootstrap.
 *
 * In this test process the plugin's autoloader is always registered, so the
 * in-core engine probe in handle_woocommerce_loaded() always reports the engine
 * as present and the loader always takes the dormant path. That is the path
 * covered here; the active path is exercised by every other test in the suite,
 * since the bootstrap boots the engine the same way the loader would.
 */
class PluginLoaderTest extends WC_Unit_Test_Case {
	/**
	 * Set up: an administrator looking at the Plugins screen.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->reset_notices();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'plugins' );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		$this->reset_notices();
		delete_option( PluginLoader::CORE_FEATURE_FLAG_OPTION );
		set_current_screen( 'front' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * @testdox When core ships the engine and its feature flag is off, the loader goes dormant with a warning that names the flag option.
	 */
	public function test_dormant_mode_warns_when_core_flag_is_off(): void {
		delete_option( PluginLoader::CORE_FEATURE_FLAG_OPTION );

		PluginLoader::handle_woocommerce_loaded();

		$output = $this->render_notices();
		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( PluginLoader::CORE_FEATURE_FLAG_OPTION, $output );
	}

	/**
	 * @testdox When core ships the engine and its feature flag is on, the loader goes dormant with an informational notice.
	 */
	public function test_dormant_mode_informs_when_core_flag_is_on(): void {
		update_option( PluginLoader::CORE_FEATURE_FLAG_OPTION, 'yes' );

		PluginLoader::handle_woocommerce_loaded();

		$output = $this->render_notices();
		$this->assertStringContainsString( 'notice-info', $output );
		$this->assertStringContainsString( 'dormant', $output );
	}

	/**
	 * @testdox Informational notices are only rendered on the Plugins screen.
	 */
	public function test_info_notices_only_render_on_plugins_screen(): void {
		update_option( PluginLoader::CORE_FEATURE_FLAG_OPTION, 'yes' );
		PluginLoader::handle_woocommerce_loaded();

		set_current_screen( 'dashboard' );

		$this->assertSame( '', $this->render_notices() );
	}

	/**
	 * @testdox Warnings are rendered on every admin screen.
	 */
	public function test_warnings_render_on_any_admin_screen(): void {
		delete_option( PluginLoader::CORE_FEATURE_FLAG_OPTION );
		PluginLoader::handle_woocommerce_loaded();

		set_current_screen( 'dashboard' );

		$this->assertStringContainsString( 'notice-warning', $this->render_notices() );
	}

	/**
	 * @testdox Notices are hidden from users who cannot activate plugins.
	 */
	public function test_notices_hidden_from_users_without_activate_plugins(): void {
		PluginLoader::handle_woocommerce_loaded();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( '', $this->render_notices() );
	}

	/**
	 * @testdox handle_plugins_loaded queues nothing once WooCommerce has loaded.
	 */
	public function test_handle_plugins_loaded_is_silent_when_woocommerce_is_loaded(): void {
		PluginLoader::handle_plugins_loaded();

		$this->assertSame( '', $this->render_notices() );
	}

	/**
	 * Render the queued notices and return the markup.
	 */
	private function render_notices(): string {
		ob_start();
		PluginLoader::handle_admin_notices();
		return (string) ob_get_clean();
	}

	/**
	 * Empty the loader's static notice queue.
	 */
	private function reset_notices(): void {
		$property = new \ReflectionProperty( PluginLoader::class, 'notices' );
		$property->setAccessible( true );
		$property->setValue( null, array() );
	}
}
