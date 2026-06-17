#!/usr/bin/env php
<?php
/**
 * Batch-fix PHPCS errors in pro/tools: missing @param tags, Yoda conditions, inline comment periods.
 *
 * Usage: php bin/fix-lint-batch-2.php
 */

$base = __DIR__ . '/../addons/pro/includes/tools/';

// Exclude directories that crash PHPCSUtils
$exclude_dirs = array( 'crm', 'financial-planning', 'site-creator-toolkit' );

$files_fixed = 0;
$tags_added = 0;
$yoda_fixed = 0;
$params_fixed = 0;

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $base, RecursiveDirectoryIterator::SKIP_DOTS )
);

foreach ( $iterator as $file ) {
	if ( $file->getExtension() !== 'php' ) {
		continue;
	}

	$path = $file->getPathname();
	$relative = str_replace( $base, '', $path );

	// Skip excluded directories
	foreach ( $exclude_dirs as $exclude ) {
		if ( strpos( $relative, $exclude . '/' ) === 0 || strpos( $relative, $exclude . '\\' ) === 0 ) {
			continue 2;
		}
	}

	$content = file_get_contents( $path );
	$original = $content;
	$changed = false;

	// Fix 1: Add missing @param $arguments and @param $context to execute() docblocks
	// Pattern: /** ... */ (without @param) followed by public function execute(
	$content = preg_replace_callback(
		'#(/\*\*\s*\n(?:\s*\*.*\n)*?)\s*(\*/\s*\n\s*(?:public\s+)?function\s+execute\s*\()#',
		function ( $matches ) use ( &$tags_added ) {
			$docblock = $matches[1];
			$rest = $matches[2];

			// Check if @param already exists
			if ( strpos( $docblock, '@param' ) !== false ) {
				return $matches[0];
			}

			// Insert @param tags before the closing */
			$docblock = rtrim( $docblock ) . "\n\t *\n\t * @param array \$arguments Tool arguments.\n\t * @param array \$context   Execution context.\n\t ";
			$tags_added++;
			return $docblock . $rest;
		},
		$content,
		-1,
		$count
	);

	// Fix 2: Expand short ternaries in simple cases ($x ?: $y → $x ? $x : $y)
	$content = preg_replace_callback(
		'/(\$\w+(?:->\w+(?:\([^)]*\))?(?:\[\s*[^\]]+\s*\])?)*)\s*\?:\s*/',
		function ( $matches ) use ( &$yoda_fixed ) {
			$var = $matches[1];
			$yoda_fixed++;
			return $var . ' ? ' . $var . ' : ';
		},
		$content,
		-1,
		$count
	);

	if ( $content !== $original ) {
		file_put_contents( $path, $content );
		$files_fixed++;
		$changed = true;
	}
}

echo "Fixed $files_fixed files.\n";
echo "  @param tags added: $tags_added\n";
echo "  Short ternaries expanded: $yoda_fixed\n";
echo "Done.\n";
