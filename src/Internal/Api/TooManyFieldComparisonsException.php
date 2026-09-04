<?php

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Api;

/**
 * Internal sentinel raised by {@see OverlappingFieldsRule::findConflict()}
 * when the rule's comparison budget is spent.
 *
 * Thrown to unwind the rule's recursive conflict search in one go; caught by
 * the rule's own visitor, which reports a single validation error and stops
 * visiting. Never surfaced on the wire.
 *
 * @internal
 */
final class TooManyFieldComparisonsException extends \RuntimeException {
}
