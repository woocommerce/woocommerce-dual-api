<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Api;

use Automattic\WooCommerce\Api\Infrastructure\GraphQLControllerBase;
use Automattic\WooCommerce\Api\Infrastructure\Main;
use WC_Unit_Test_Case;

/**
 * Tests for the static configuration accessors of {@see GraphQLControllerBase}:
 * the query depth and complexity limits read from the GraphQL settings.
 */
class GraphQLControllerTest extends WC_Unit_Test_Case {
	/**
	 * Clean up GraphQL options between tests.
	 */
	public function tearDown(): void {
		delete_option( Main::OPTION_MAX_QUERY_LENGTH );
		delete_option( Main::OPTION_MAX_QUERY_DEPTH );
		delete_option( Main::OPTION_MAX_QUERY_COMPLEXITY );
		parent::tearDown();
	}

	/**
	 * @testdox get_max_query_length returns the default when the option is unset.
	 */
	public function test_get_max_query_length_returns_default_when_option_unset(): void {
		delete_option( Main::OPTION_MAX_QUERY_LENGTH );
		$this->assertSame(
			GraphQLControllerBase::DEFAULT_MAX_QUERY_LENGTH,
			GraphQLControllerBase::get_max_query_length()
		);
	}

	/**
	 * @testdox get_max_query_length returns the option value when it is a positive integer.
	 */
	public function test_get_max_query_length_returns_option_value_when_positive(): void {
		update_option( Main::OPTION_MAX_QUERY_LENGTH, '4096' );
		$this->assertSame( 4096, GraphQLControllerBase::get_max_query_length() );
	}

	/**
	 * @testdox get_max_query_length falls back to the default when the option is empty, zero, or negative.
	 * @dataProvider provider_non_positive_option_values
	 *
	 * @param string $value The non-positive option value.
	 */
	public function test_get_max_query_length_falls_back_on_non_positive( string $value ): void {
		update_option( Main::OPTION_MAX_QUERY_LENGTH, $value );
		$this->assertSame(
			GraphQLControllerBase::DEFAULT_MAX_QUERY_LENGTH,
			GraphQLControllerBase::get_max_query_length()
		);
	}

	/**
	 * @testdox get_max_query_depth returns the default when the option is unset.
	 */
	public function test_get_max_query_depth_returns_default_when_option_unset(): void {
		delete_option( Main::OPTION_MAX_QUERY_DEPTH );
		$this->assertSame(
			GraphQLControllerBase::DEFAULT_MAX_QUERY_DEPTH,
			GraphQLControllerBase::get_max_query_depth()
		);
	}

	/**
	 * @testdox get_max_query_depth returns the option value when it is a positive integer.
	 */
	public function test_get_max_query_depth_returns_option_value_when_positive(): void {
		update_option( Main::OPTION_MAX_QUERY_DEPTH, '7' );
		$this->assertSame( 7, GraphQLControllerBase::get_max_query_depth() );
	}

	/**
	 * @testdox get_max_query_depth falls back to the default when the option is empty, zero, or negative.
	 * @dataProvider provider_non_positive_option_values
	 *
	 * @param string $value The non-positive option value.
	 */
	public function test_get_max_query_depth_falls_back_on_non_positive( string $value ): void {
		update_option( Main::OPTION_MAX_QUERY_DEPTH, $value );
		$this->assertSame(
			GraphQLControllerBase::DEFAULT_MAX_QUERY_DEPTH,
			GraphQLControllerBase::get_max_query_depth()
		);
	}

	/**
	 * @testdox get_max_query_complexity returns the default when the option is unset.
	 */
	public function test_get_max_query_complexity_returns_default_when_option_unset(): void {
		delete_option( Main::OPTION_MAX_QUERY_COMPLEXITY );
		$this->assertSame(
			GraphQLControllerBase::DEFAULT_MAX_QUERY_COMPLEXITY,
			GraphQLControllerBase::get_max_query_complexity()
		);
	}

	/**
	 * @testdox get_max_query_complexity returns the option value when it is a positive integer.
	 */
	public function test_get_max_query_complexity_returns_option_value_when_positive(): void {
		update_option( Main::OPTION_MAX_QUERY_COMPLEXITY, '500' );
		$this->assertSame( 500, GraphQLControllerBase::get_max_query_complexity() );
	}

	/**
	 * @testdox get_max_query_complexity falls back to the default when the option is empty, zero, or negative.
	 * @dataProvider provider_non_positive_option_values
	 *
	 * @param string $value The non-positive option value.
	 */
	public function test_get_max_query_complexity_falls_back_on_non_positive( string $value ): void {
		update_option( Main::OPTION_MAX_QUERY_COMPLEXITY, $value );
		$this->assertSame(
			GraphQLControllerBase::DEFAULT_MAX_QUERY_COMPLEXITY,
			GraphQLControllerBase::get_max_query_complexity()
		);
	}

	/**
	 * Non-positive values that the getters should replace with the default.
	 *
	 * @return array<string, array{string}>
	 */
	public function provider_non_positive_option_values(): array {
		return array(
			'empty string' => array( '' ),
			'zero'         => array( '0' ),
			'negative'     => array( '-5' ),
		);
	}
}
