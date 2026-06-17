<?php
/**
 * Lint Fixer: Add missing docblocks to standard tool methods.
 *
 * Scans all PHP files under addons/pro/includes/tools/ and adds
 * PHPDoc blocks for common tool interface methods that are missing
 * documentation: execute(), get_slug(), get_name(), get_description(),
 * get_required_capability(), get_parameters_schema(), get_capability_flags(),
 * get_definition(), get_unavailable_reason(), is_available().
 *
 * Respects the existing indentation style (tabs or spaces) found in
 * each file so that no formatting drift is introduced.
 *
 * Usage: php bin/fix-lint-missing-docblocks.php
 *
 * @since 1.x
 * @internal Development utility — not shipped in production builds.
 */

$tools_dir = dirname( __DIR__ ) . '/addons/pro/includes/tools/';

if ( ! is_dir( $tools_dir ) ) {
	fwrite( STDERR, "Tools directory not found: $tools_dir\n" );
	exit( 1 );
}

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $tools_dir, RecursiveDirectoryIterator::SKIP_DOTS )
);

$files = [];
foreach ( $iterator as $file ) {
	if ( 'php' === $file->getExtension() ) {
		$files[] = $file->getPathname();
	}
}

// ── Method doc templates ────────────────────────────────────
$method_docs = [
	'execute'                 => [
		'Execute the tool.',
		'array',
		'@param array $arguments Tool arguments.' . "\n" . ' * @param array $context   Execution context.',
	],
	'get_required_capability' => [ 'Get the required capability.', 'string', '' ],
	'is_available'            => [ 'Check if the tool is available.', 'bool', '' ],
	'get_slug'                => [ 'Get the tool slug.', 'string', '' ],
	'get_name'                => [ 'Get the tool name.', 'string', '' ],
	'get_description'         => [ 'Get the tool description.', 'string', '' ],
	'get_parameters_schema'   => [ 'Get the parameters schema.', 'array', '' ],
	'get_capability_flags'    => [ 'Get capability flags for this tool.', 'array', '' ],
	'get_definition'          => [ 'Get the tool definition.', 'array', '' ],
	'get_unavailable_reason'  => [ 'Get the reason the tool is unavailable.', 'string', '' ],
];

// ── Process each file ───────────────────────────────────────
$added  = 0;
$total  = count( $files );

foreach ( $files as $filepath ) {
	$content  = file_get_contents( $filepath );
	$original = $content;
	$changed  = false;

	foreach ( $method_docs as $func_name => $cfg ) {
		list( $desc, $return_type, $extra ) = $cfg;

		$pattern = '/^(\s*)((?:public |protected |private )?function\s+'
			. preg_quote( $func_name, '/' ) . '\s*\()/m';

		preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE );

		// Walk in reverse so string offsets stay valid.
		for ( $i = count( $matches[1] ) - 1; $i >= 0; $i-- ) {
			$indent     = $matches[1][ $i ][0];
			$func_start = $matches[2][ $i ][1];

			// Skip if a docblock already precedes this function.
			$before = substr( $content, max( 0, $func_start - 300 ), 300 );
			if ( preg_match( '/\/\*\*[\s\S]*?\*\/\s*$/m', $before ) ) {
				continue;
			}

			// Build the replacement docblock.
			$doc  = "$indent/**\n";
			$doc .= "$indent * $desc\n";
			if ( '' !== $extra ) {
				foreach ( explode( "\n", $extra ) as $el ) {
					if ( '' !== trim( $el ) ) {
						$doc .= "$indent * $el\n";
					}
				}
			}
			$doc .= "$indent *\n";
			$doc .= "$indent * @return $return_type\n";
			$doc .= "$indent */\n";

			$content  = substr_replace( $content, $doc, $func_start, 0 );
			$changed  = true;
			$added++;
		}
	}

	if ( $changed && $content !== $original ) {
		file_put_contents( $filepath, $content );
	}
}

echo "Added $added docblock(s) across $total tool files.\n";
