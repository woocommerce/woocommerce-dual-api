<?php

declare(strict_types=1);

// Refuse to run outside the CLI: this script wipes and regenerates the
// output directory, so a misconfigured web server that accidentally
// serves this file could destroy the checked-in output on every hit.
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit;
}

$options = getopt(
	'h',
	array(
		'help',
		'no-linter',
		'api-dir:',
		'autogen-dir:',
		'api-namespace:',
		'autogen-namespace:',
		'text-domain:',
		'composer-working-dir:',
		'phpcbf-path:',
	)
);

$usage = <<<'TXT'
Usage: php build-api.php --api-dir=PATH --autogen-dir=PATH --api-namespace=NS --autogen-namespace=NS --text-domain=DOMAIN [options]

Generates the GraphQL layer for a code API. Plugins normally don't call this
script directly: their own bin/build-api.php calls ApiBuilder::run_for_plugin(),
which derives every value below from the conventional plugin layout. This
script is the low-level entry point for non-conventional layouts (for example
the test fixture of the WooCommerce Dual API plugin itself).

Required:
  --api-dir=PATH              Directory containing code-API classes to scan.
  --autogen-dir=PATH          Directory where generated code is written.
                              WARNING: this directory is wiped on every run.
  --api-namespace=NAMESPACE   PSR-4 namespace that maps to --api-dir.
  --autogen-namespace=NS      PSR-4 namespace that maps to --autogen-dir.
  --text-domain=DOMAIN        Text domain the generated code translates its
                              descriptions with (the consuming plugin's own).

Options:
  --composer-working-dir=DIR  Directory where "composer dump-autoload" runs
                              after generation. Omitted = the autoloader is
                              not regenerated (needed only when the generated
                              namespace isn't already covered by a PSR-4
                              prefix in the plugin's composer.json).
  --phpcbf-path=PATH          Path to a phpcbf executable used to format
                              generated files. Default: the copy installed
                              with this plugin's development dependencies;
                              when none is found the pass is skipped.
  --no-linter                 Skip the phpcbf pass entirely.
  -h, --help                  Show this message.

TXT;

if ( isset( $options['h'] ) || isset( $options['help'] ) ) {
	echo $usage;
	exit( 0 );
}

// getopt() yields false for a flag given without a value and an array for a
// repeated flag; neither is acceptable for these options.
$string_option = static fn( string $flag ): ?string => is_string( $options[ $flag ] ?? null ) ? $options[ $flag ] : null;

$required_flags = array( 'api-dir', 'autogen-dir', 'api-namespace', 'autogen-namespace', 'text-domain' );
$missing_flags  = array_filter( $required_flags, static fn( string $flag ): bool => is_null( $string_option( $flag ) ) );
if ( count( $missing_flags ) > 0 ) {
	fwrite( STDERR, 'Error: missing or invalid required option(s): --' . implode( ', --', $missing_flags ) . "\n\n" );
	fwrite( STDERR, $usage );
	exit( 2 );
}

if ( PHP_VERSION_ID < 80100 ) {
	fwrite(
		STDERR,
		sprintf(
			"Error: PHP 8.1 or later is required to run the API build script. Current version: %s.\n",
			PHP_VERSION
		)
	);
	exit( 2 );
}

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\WooCommerce\Api\Infrastructure\DesignTime\ApiBuilder;

$skip_linter = isset( $options['no-linter'] );

$builder = new ApiBuilder(
	(string) $string_option( 'api-dir' ),
	(string) $string_option( 'autogen-dir' ),
	(string) $string_option( 'api-namespace' ),
	(string) $string_option( 'autogen-namespace' ),
	(string) $string_option( 'text-domain' ),
	$string_option( 'composer-working-dir' ),
	$string_option( 'phpcbf-path' ),
);
$builder->build( $skip_linter );
