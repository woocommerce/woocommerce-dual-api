<?php

declare(strict_types=1);

namespace Automattic\WooCommerce\Tests\Internal\Api;

use Automattic\WooCommerce\Internal\Api\OverlappingFieldsRule;
use Automattic\WooCommerce\Vendor\GraphQL\Error\Error;
use Automattic\WooCommerce\Vendor\GraphQL\Language\Parser;
use Automattic\WooCommerce\Vendor\GraphQL\Type\Definition\ObjectType;
use Automattic\WooCommerce\Vendor\GraphQL\Type\Definition\Type;
use Automattic\WooCommerce\Vendor\GraphQL\Type\Schema;
use Automattic\WooCommerce\Vendor\GraphQL\Validator\DocumentValidator;
use WC_Unit_Test_Case;

/**
 * Tests for {@see OverlappingFieldsRule}.
 *
 * The rule is exercised on its own through DocumentValidator against a small
 * hand-built schema. A deliberately small comparison budget makes the tests
 * deterministic: a document validates cleanly only if the rule needs fewer
 * comparisons than the budget.
 */
class OverlappingFieldsRuleTest extends WC_Unit_Test_Case {
	/**
	 * Build the test schema.
	 *
	 * type Query { widget(id: Int): Widget }
	 * type Widget { id: Int, name: String }
	 */
	private function build_schema(): Schema {
		$widget = new ObjectType(
			array(
				'name'   => 'Widget',
				'fields' => array(
					'id'   => Type::int(),
					'name' => Type::string(),
				),
			)
		);

		$query = new ObjectType(
			array(
				'name'   => 'Query',
				'fields' => array(
					'widget' => array(
						'type' => $widget,
						'args' => array( 'id' => Type::int() ),
					),
				),
			)
		);

		return new Schema( array( 'query' => $query ) );
	}

	/**
	 * Validate a document with the rule alone.
	 *
	 * @param string                $query The GraphQL document.
	 * @param OverlappingFieldsRule $sut   The rule instance.
	 * @return Error[] The validation errors.
	 */
	private function validate( string $query, OverlappingFieldsRule $sut ): array {
		return DocumentValidator::validate( $this->build_schema(), Parser::parse( $query ), array( $sut ) );
	}

	/**
	 * Build a selection repeating `widget(id: <id>) { id }` for each given id.
	 *
	 * @param int[] $ids The `id` argument of each repetition.
	 */
	private function repeated_widgets( array $ids ): string {
		return '{ ' . implode( ' ', array_map( static fn( int $id ): string => "widget(id: $id) { id }", $ids ) ) . ' }';
	}

	/**
	 * @testdox a field with a sub-selection repeated many times is compared once, not once per pair.
	 */
	public function test_identical_repeated_fields_are_deduplicated(): void {
		$sut = new OverlappingFieldsRule( 100 );

		$errors = $this->validate( $this->repeated_widgets( array_fill( 0, 2000, 1 ) ), $sut );

		$this->assertSame( array(), $errors, '2000 identical fields must not need more than 100 comparisons.' );
	}

	/**
	 * @testdox structurally identical fields written differently are still deduplicated.
	 */
	public function test_deduplication_is_structural(): void {
		$sut = new OverlappingFieldsRule( 100 );

		$errors = $this->validate( '{ ' . str_repeat( 'widget(id:1){id} widget( id: 1 ) { id } ', 500 ) . '}', $sut );

		$this->assertSame( array(), $errors );
	}

	/**
	 * @testdox fields that differ are still compared and a real conflict is still reported.
	 */
	public function test_real_conflicts_are_still_reported(): void {
		$sut = new OverlappingFieldsRule();

		$errors = $this->validate( $this->repeated_widgets( array( 1, 2 ) ), $sut );

		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'Fields "widget" conflict because they have differing arguments', $errors[0]->getMessage() );
	}

	/**
	 * @testdox mergeable fields with different sub-selections produce no error.
	 */
	public function test_mergeable_fields_with_different_sub_selections_pass(): void {
		$sut = new OverlappingFieldsRule();

		$errors = $this->validate( '{ widget(id: 1) { id } widget(id: 1) { name } }', $sut );

		$this->assertSame( array(), $errors );
	}

	/**
	 * @testdox spending the comparison budget reports a single error instead of one per remaining pair.
	 */
	public function test_exhausting_the_comparison_budget_reports_a_single_error(): void {
		$sut = new OverlappingFieldsRule( 10 );

		// 20 distinct fields sharing a response name: 190 pairs, every one of them a conflict.
		$errors = $this->validate( $this->repeated_widgets( range( 1, 20 ) ), $sut );

		$this->assertCount( 1, $errors );
		$this->assertSame( OverlappingFieldsRule::TOO_COMPLEX_MESSAGE, $errors[0]->getMessage() );
	}

	/**
	 * @testdox the budget also covers comparisons made through fragment spreads.
	 */
	public function test_budget_covers_fragment_comparisons(): void {
		$sut = new OverlappingFieldsRule( 10 );

		$query = '{ ...A ...B } '
			. 'fragment A on Query ' . $this->repeated_widgets( range( 1, 10 ) ) . ' '
			. 'fragment B on Query ' . $this->repeated_widgets( range( 11, 20 ) );

		$errors = $this->validate( $query, $sut );

		$this->assertCount( 1, $errors );
		$this->assertSame( OverlappingFieldsRule::TOO_COMPLEX_MESSAGE, $errors[0]->getMessage() );
	}

	/**
	 * @testdox a rule instance that aborted on one document validates the next one normally.
	 */
	public function test_state_is_reset_between_documents(): void {
		$sut = new OverlappingFieldsRule( 10 );
		$this->validate( $this->repeated_widgets( range( 1, 20 ) ), $sut );

		$errors = $this->validate( '{ widget(id: 1) { id name } }', $sut );

		$this->assertSame( array(), $errors );
	}
}
