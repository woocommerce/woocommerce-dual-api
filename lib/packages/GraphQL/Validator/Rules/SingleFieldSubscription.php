<?php declare(strict_types=1);

namespace Automattic\WooCommerce\Vendor\GraphQL\Validator\Rules;

use Automattic\WooCommerce\Vendor\GraphQL\Error\Error;
use Automattic\WooCommerce\Vendor\GraphQL\Language\AST\DirectiveNode;
use Automattic\WooCommerce\Vendor\GraphQL\Language\AST\FieldNode;
use Automattic\WooCommerce\Vendor\GraphQL\Language\AST\FragmentDefinitionNode;
use Automattic\WooCommerce\Vendor\GraphQL\Language\AST\FragmentSpreadNode;
use Automattic\WooCommerce\Vendor\GraphQL\Language\AST\InlineFragmentNode;
use Automattic\WooCommerce\Vendor\GraphQL\Language\AST\Node;
use Automattic\WooCommerce\Vendor\GraphQL\Language\AST\NodeKind;
use Automattic\WooCommerce\Vendor\GraphQL\Language\AST\OperationDefinitionNode;
use Automattic\WooCommerce\Vendor\GraphQL\Language\AST\SelectionSetNode;
use Automattic\WooCommerce\Vendor\GraphQL\Language\Visitor;
use Automattic\WooCommerce\Vendor\GraphQL\Language\VisitorOperation;
use Automattic\WooCommerce\Vendor\GraphQL\Type\Definition\AbstractType;
use Automattic\WooCommerce\Vendor\GraphQL\Type\Definition\Directive;
use Automattic\WooCommerce\Vendor\GraphQL\Type\Definition\ObjectType;
use Automattic\WooCommerce\Vendor\GraphQL\Type\Schema;
use Automattic\WooCommerce\Vendor\GraphQL\Validator\QueryValidationContext;

class SingleFieldSubscription extends ValidationRule
{
    public function getVisitor(QueryValidationContext $context): array
    {
        return [
            NodeKind::OPERATION_DEFINITION => static function (OperationDefinitionNode $node) use ($context): VisitorOperation {
                if ($node->operation === 'subscription') {
                    $schema = $context->getSchema();
                    $subscriptionType = $schema->getSubscriptionType();

                    if ($subscriptionType !== null) {
                        $operationName = $node->name->value ?? null;

                        // Collect fragment definitions from document
                        /** @var array<string, FragmentDefinitionNode> $fragments */
                        $fragments = [];
                        foreach ($context->getDocument()->definitions as $definition) {
                            if ($definition instanceof FragmentDefinitionNode) {
                                $fragments[$definition->name->value] = $definition;
                            }
                        }

                        // Collect fields by expanding fragments, also collecting forbidden directives
                        /** @var array<string, array<int, FieldNode>> $groupedFieldSet */
                        $groupedFieldSet = [];
                        /** @var array<string, true> $visitedFragments */
                        $visitedFragments = [];
                        /** @var array<int, DirectiveNode> $forbiddenDirectiveNodes */
                        $forbiddenDirectiveNodes = [];
                        self::collectFields(
                            $schema,
                            $subscriptionType,
                            $node->selectionSet,
                            $fragments,
                            $groupedFieldSet,
                            $visitedFragments,
                            $forbiddenDirectiveNodes
                        );

                        if ($forbiddenDirectiveNodes !== []) {
                            $context->reportError(new Error(
                                static::skipIncludeInOperation($operationName),
                                $forbiddenDirectiveNodes
                            ));

                            return Visitor::skipNode();
                        }

                        if (count($groupedFieldSet) > 1) {
                            $keys = array_keys($groupedFieldSet);
                            /** @var array<int, FieldNode> $extraFieldNodes */
                            $extraFieldNodes = [];
                            for ($keyIndex = 1, $keyCount = count($keys); $keyIndex < $keyCount; ++$keyIndex) {
                                foreach ($groupedFieldSet[$keys[$keyIndex]] as $fieldNode) {
                                    $extraFieldNodes[] = $fieldNode;
                                }
                            }

                            $context->reportError(new Error(
                                static::multipleFieldsInOperation($operationName),
                                $extraFieldNodes
                            ));
                        }

                        // Check for introspection fields
                        foreach ($groupedFieldSet as $fieldNodes) {
                            $fieldName = $fieldNodes[0]->name->value;
                            if ($fieldName[0] === '_' && ($fieldName[1] ?? '') === '_') {
                                $context->reportError(new Error(
                                    static::introspectionFieldInOperation($operationName),
                                    $fieldNodes
                                ));
                            }
                        }
                    }
                }

                return Visitor::skipNode();
            },
        ];
    }

    /**
     * @param array<string, FragmentDefinitionNode> $fragments
     * @param array<string, array<int, FieldNode>> $groupedFieldSet
     * @param array<string, true> $visitedFragments
     * @param array<int, DirectiveNode> $forbiddenDirectiveNodes
     *
     * @throws \Exception
     */
    private static function collectFields(
        Schema $schema,
        ObjectType $runtimeType,
        SelectionSetNode $selectionSet,
        array $fragments,
        array &$groupedFieldSet,
        array &$visitedFragments,
        array &$forbiddenDirectiveNodes
    ): void {
        foreach ($selectionSet->selections as $selection) {
            if ($selection instanceof FieldNode) {
                foreach ($selection->directives as $directive) {
                    $directiveName = $directive->name->value;
                    if ($directiveName === Directive::SKIP_NAME || $directiveName === Directive::INCLUDE_NAME) {
                        $forbiddenDirectiveNodes[] = $directive;
                    }
                }

                $responseKey = $selection->alias->value ?? $selection->name->value;
                if (! isset($groupedFieldSet[$responseKey])) {
                    $groupedFieldSet[$responseKey] = [];
                }

                $groupedFieldSet[$responseKey][] = $selection;
            } elseif ($selection instanceof InlineFragmentNode) {
                if (! self::doesFragmentConditionMatch($schema, $selection, $runtimeType)) {
                    continue;
                }

                self::collectFields(
                    $schema,
                    $runtimeType,
                    $selection->selectionSet,
                    $fragments,
                    $groupedFieldSet,
                    $visitedFragments,
                    $forbiddenDirectiveNodes
                );
            } elseif ($selection instanceof FragmentSpreadNode) {
                $fragmentName = $selection->name->value;
                if (isset($visitedFragments[$fragmentName])) {
                    continue;
                }

                $visitedFragments[$fragmentName] = true;

                if (! isset($fragments[$fragmentName])) {
                    continue;
                }

                $fragment = $fragments[$fragmentName];
                if (! self::doesFragmentConditionMatch($schema, $fragment, $runtimeType)) {
                    continue;
                }

                self::collectFields(
                    $schema,
                    $runtimeType,
                    $fragment->selectionSet,
                    $fragments,
                    $groupedFieldSet,
                    $visitedFragments,
                    $forbiddenDirectiveNodes
                );
            }
        }
    }

    /**
     * @param InlineFragmentNode|FragmentDefinitionNode $fragment
     *
     * @throws \Exception
     */
    private static function doesFragmentConditionMatch(Schema $schema, Node $fragment, ObjectType $type): bool
    {
        $typeConditionNode = $fragment->typeCondition;
        if ($typeConditionNode === null) {
            return true;
        }

        $conditionalType = $schema->getType($typeConditionNode->name->value);
        if ($conditionalType === $type) {
            return true;
        }

        if ($conditionalType instanceof AbstractType) {
            return $schema->isSubType($conditionalType, $type);
        }

        return false;
    }

    public static function multipleFieldsInOperation(?string $operationName): string
    {
        if ($operationName === null) {
            return 'Anonymous Subscription must select only one top level field.';
        }

        return "Subscription \"{$operationName}\" must select only one top level field.";
    }

    public static function introspectionFieldInOperation(?string $operationName): string
    {
        if ($operationName === null) {
            return 'Anonymous Subscription must not select an introspection top level field.';
        }

        return "Subscription \"{$operationName}\" must not select an introspection top level field.";
    }

    public static function skipIncludeInOperation(?string $operationName): string
    {
        if ($operationName === null) {
            return 'Anonymous Subscription must not use `@skip` or `@include` directives in the top level selection.';
        }

        return "Subscription \"{$operationName}\" must not use `@skip` or `@include` directives in the top level selection.";
    }
}
