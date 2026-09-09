<?php

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Api;

use Automattic\WooCommerce\Api\Infrastructure\GraphQLControllerBase;
use Automattic\WooCommerce\Api\Infrastructure\Main;

/**
 * Settings handling for the GraphQL API.
 *
 * Registers the "GraphQL" section under WooCommerce - Settings - Advanced.
 * Only active when Main::is_enabled() returns true (PHP 8.1+), so the
 * section is hidden when the requirement isn't met.
 */
class Settings {
	/**
	 * Identifier for the GraphQL section under the Advanced settings tab.
	 */
	public const SECTION_ID = 'graphql';

	/**
	 * Register the filter hooks that expose the GraphQL settings section.
	 */
	public function register(): void {
		add_filter( 'woocommerce_get_sections_advanced', array( $this, 'add_section' ) );
		add_filter( 'woocommerce_get_settings_advanced', array( $this, 'add_settings' ), 10, 2 );
	}

	/**
	 * Append the GraphQL section to the Advanced settings tab.
	 *
	 * @param array $sections Existing sections keyed by id.
	 * @return array
	 */
	public function add_section( array $sections ): array {
		if ( Main::is_enabled() ) {
			$sections[ self::SECTION_ID ] = __( 'GraphQL', 'woocommerce-dual-api' );
		}
		return $sections;
	}

	/**
	 * Provide the settings fields for the GraphQL section.
	 *
	 * @param array  $settings   Existing settings for the current section.
	 * @param string $section_id Current section id.
	 * @return array
	 */
	public function add_settings( array $settings, string $section_id ): array {
		if ( self::SECTION_ID !== $section_id || ! Main::is_enabled() ) {
			return $settings;
		}

		return array(
			array(
				'title' => __( 'GraphQL', 'woocommerce-dual-api' ),
				'desc'  => __( 'Configure the WooCommerce GraphQL API.', 'woocommerce-dual-api' ),
				'type'  => 'title',
				'id'    => 'woocommerce_graphql_options',
			),
			array(
				'title'   => __( 'Allow anonymous requests', 'woocommerce-dual-api' ),
				'desc'    => __( 'Process requests that carry no credentials. When off, callers must authenticate before any query is parsed, which also makes operations marked as public unreachable.', 'woocommerce-dual-api' ),
				'id'      => Main::OPTION_ANONYMOUS_REQUESTS_ALLOWED,
				'default' => 'yes',
				'type'    => 'checkbox',
			),
			array(
				'title'   => __( 'Enable GET endpoint', 'woocommerce-dual-api' ),
				'desc'    => __( 'Allow GraphQL queries over GET in addition to POST', 'woocommerce-dual-api' ),
				'id'      => Main::OPTION_GET_ENDPOINT_ENABLED,
				'default' => 'yes',
				'type'    => 'checkbox',
			),
			array(
				'title'             => __( 'Maximum query depth', 'woocommerce-dual-api' ),
				'desc'              => __( 'Reject queries whose selection nesting exceeds this depth.', 'woocommerce-dual-api' ),
				'id'                => Main::OPTION_MAX_QUERY_DEPTH,
				'default'           => (string) GraphQLControllerBase::DEFAULT_MAX_QUERY_DEPTH,
				'type'              => 'number',
				'custom_attributes' => array( 'min' => '1' ),
			),
			array(
				'title'             => __( 'Maximum query complexity', 'woocommerce-dual-api' ),
				'desc'              => __( 'Reject queries whose computed complexity score exceeds this value.', 'woocommerce-dual-api' ),
				'id'                => Main::OPTION_MAX_QUERY_COMPLEXITY,
				'default'           => (string) GraphQLControllerBase::DEFAULT_MAX_QUERY_COMPLEXITY,
				'type'              => 'number',
				'custom_attributes' => array( 'min' => '1' ),
			),
			array(
				'title'   => __( 'Enable OPcache-based caching', 'woocommerce-dual-api' ),
				'desc'    => __( 'Cache parsed queries on disk as PHP files so OPcache can serve them from shared memory. Falls back to the object cache when the filesystem is not writable.', 'woocommerce-dual-api' ),
				'id'      => Main::OPTION_OPCACHE_ENABLED,
				'default' => 'yes',
				'type'    => 'checkbox',
			),
			array(
				'title'   => __( 'Enable ObjectCache-based caching', 'woocommerce-dual-api' ),
				'desc'    => __( 'Cache parsed queries in the WP object cache', 'woocommerce-dual-api' ),
				'id'      => Main::OPTION_OBJECT_CACHE_ENABLED,
				'default' => 'yes',
				'type'    => 'checkbox',
			),
			array(
				'title'   => __( 'Enable APQ caching', 'woocommerce-dual-api' ),
				'desc'    => __( 'Cache parsed queries using the Apollo Automatic Persisted Queries protocol', 'woocommerce-dual-api' ),
				'id'      => Main::OPTION_APQ_ENABLED,
				'default' => 'yes',
				'type'    => 'checkbox',
			),
			array(
				'title'             => __( 'Parsed query cache TTL', 'woocommerce-dual-api' ),
				'desc'              => __( 'Time in seconds before cached parsed queries expire.', 'woocommerce-dual-api' ),
				'id'                => Main::OPTION_QUERY_CACHE_TTL,
				'default'           => (string) QueryCache::DEFAULT_CACHE_TTL,
				'type'              => 'number',
				'custom_attributes' => array( 'min' => '1' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'woocommerce_graphql_options',
			),
		);
	}
}
