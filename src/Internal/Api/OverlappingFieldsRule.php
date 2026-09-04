<?php

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Api;

use Automattic\WooCommerce\Vendor\GraphQL\Error\Error;
use Automattic\WooCommerce\Vendor\GraphQL\Language\AST\FieldNode;
use Automattic\WooCommerce\Vendor\GraphQL\Language\AST\NodeKind;
use Automattic\WooCommerce\Vendor\GraphQL\Language\AST\SelectionSetNode;
use Automattic\WooCommerce\Vendor\GraphQL\Language\Printer;
use Automattic\WooCommerce\Vendor\GraphQL\Language\Visitor;
use Automattic\WooCommerce\Vendor\GraphQL\Type\Definition\FieldDefinition;
use Automattic\WooCommerce\Vendor\GraphQL\Type\Definition\Type;
use Automattic\WooCommerce\Vendor\GraphQL\Validator\QueryValidationContext;
use Automattic\WooCommerce\Vendor\GraphQL\Validator\Rules\OverlappingFieldsCanBeMerged;

/**
 * OverlappingFieldsCanBeMerged validation rule whose work stays bounded by
 * the size of the document.
 *
 * The stock rule compares every pair of fields that share a response name.
 * It deduplicates repeated fields first, but its fingerprint identifies a
 * field's selection set by object identity, so a field with a sub-selection
 * repeated N times is never deduplicated and costs N²/2 comparisons. And when
 * the comparison budget runs out, the stock rule keeps looping and turns each
 * further pair into a conflict error, so a query a few kilobytes long could
 * take minutes to validate and produce millions of errors, before any
 * resolver had a chance to authorize the caller.
 *
 * Two changes: fields are fingerprinted structurally (by their printed
 * source), so identical repetitions collapse into one comparison; and
 * exhausting the comparison budget aborts the rule with a single error.
 */
class OverlappingFieldsRule extends OverlappingFieldsCanBeMerged {
	/**
	 * Message of the single error reported when the comparison budget runs out.
	 */
	public const TOO_COMPLEX_MESSAGE = 'Too many field comparisons, query is too complex to validate.';

	/**
	 * Memoized structural fingerprint of each field node, keyed by the
	 * node's spl_object_id(). Selection sets nested inside a repeated field
	 * are fingerprinted again when their own conflicts are collected, so the
	 * memo keeps the printing linear in the size of the document.
	 *
	 * @var array<int, string>
	 */
	private array $fingerprints = array();

	/**
	 * Reset the per-document state, then wrap the stock SELECTION_SET
	 * callback so that running out of comparisons reports one error and
	 * stops the rule instead of propagating an exception.
	 *
	 * @param QueryValidationContext $context The validation context.
	 * @return array The visitor definition.
	 */
	public function getVisitor( QueryValidationContext $context ): array {
		$this->fingerprints = array();

		$visitor  = parent::getVisitor( $context );
		$callback = $visitor[ NodeKind::SELECTION_SET ] ?? null;
		if ( ! is_callable( $callback ) ) {
			return $visitor;
		}

		$visitor[ NodeKind::SELECTION_SET ] = function ( SelectionSetNode $selection_set ) use ( $context, $callback ) {
			try {
				return $callback( $selection_set );
			} catch ( TooManyFieldComparisonsException $e ) {
				$context->reportError( new Error( self::TOO_COMPLEX_MESSAGE, array( $selection_set ) ) );
				return Visitor::stop();
			}
		};

		return $visitor;
	}

	/**
	 * Fingerprint a field by its parent type and printed source, so that
	 * structurally identical fields, sub-selections included, deduplicate to
	 * one. Two such fields can never conflict with each other, and any
	 * conflict one of them has with a third field the other has too.
	 *
	 * @param array{Type|null, FieldNode, FieldDefinition|null} $field The field info: parent type, field node, field definition.
	 * @return string The fingerprint.
	 */
	protected function fieldFingerprint( array $field ): string {
		list( $parent_type, $ast ) = $field;

		$node_id = spl_object_id( $ast );
		if ( ! isset( $this->fingerprints[ $node_id ] ) ) {
			$parent_type_id                 = is_null( $parent_type ) ? '' : spl_object_id( $parent_type );
			$this->fingerprints[ $node_id ] = $parent_type_id . ':' . Printer::doPrint( $ast );
		}

		return $this->fingerprints[ $node_id ];
	}

	/**
	 * Abort the search once the comparison budget is spent, instead of
	 * reporting every further pair as a conflict like the stock rule does.
	 *
	 * The stock method increments the count itself, so the check here is
	 * against the count before this comparison: when it's below the limit the
	 * stock check can't trigger, and when it's at the limit this throws first.
	 *
	 * @param QueryValidationContext                            $context                              The validation context.
	 * @param bool                                              $parent_fields_are_mutually_exclusive Whether the parent fields can't both apply.
	 * @param string                                            $response_name                        The response name the two fields share.
	 * @param array{Type|null, FieldNode, FieldDefinition|null} $field1                               The first field info: parent type, field node, field definition.
	 * @param array{Type|null, FieldNode, FieldDefinition|null} $field2                               The second field info.
	 * @return ?array The conflict, or null when the fields can be merged.
	 * @throws TooManyFieldComparisonsException When the comparison budget is spent.
	 */
	protected function findConflict(
		QueryValidationContext $context,
		bool $parent_fields_are_mutually_exclusive,
		string $response_name,
		array $field1,
		array $field2
	): ?array {
		if ( $this->comparisonCount >= $this->comparisonLimit ) {
			throw new TooManyFieldComparisonsException();
		}

		return parent::findConflict( $context, $parent_fields_are_mutually_exclusive, $response_name, $field1, $field2 );
	}
}
