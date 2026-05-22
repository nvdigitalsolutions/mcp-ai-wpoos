<?php
/**
 * Lint Fixer: Fix inline comments, Yoda conditions, and common formatting.
 *
 * Processes PHP files under addons/pro/includes/tools/ and applies
 * safe, targeted fixes for the most common PHPCS violations:
 *
 *   1. Inline comments missing trailing periods.
 *   2. Yoda conditions in control structures.
 *   3. Single-line block comment closers moved to their own line.
 *   4. get_capability_flags() docblocks.
 *
 * This script is intentionally conservative — it only patches
 * patterns that are safe to transform mechanically.
 *
 * Usage: php bin/fix-lint-common.php
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

$counts = [
	'inline_periods' => 0,
	'yoda'           => 0,
	'comment_closer' => 0,
	'cap_flags'      => 0,
];
$files_touched = 0;

foreach ( $files as $filepath ) {
	$content  = file_get_contents( $filepath );
	$original = $content;
	$changed  = false;

	// ── 1. Inline comment periods ─────────────────────────
	$lines = explode( "\n", $content );
	$new   = [];
	foreach ( $lines as $line ) {
		if ( preg_match( '/^(\s*\/\/\s+)([A-Z][A-Za-z].*?)(\s*)$/', $line, $m ) ) {
			$comment = rtrim( $m[2] );
			if (
				! preg_match( '/[.!?:]$/', $comment )
				&& ! preg_match( '/^https?:/', $comment )
				&& ! preg_match( '/phpcs:/i', $comment )
				&& strlen( $comment ) > 6
				&& str_word_count( $comment ) >= 2
			) {
				$line = $m[1] . $comment . '.';
				$counts['inline_periods']++;
				$changed = true;
			}
		}
		$new[] = $line;
	}
	if ( $new !== $lines ) {
		$content = implode( "\n", $new );
	}

	// ── 2. Yoda conditions ────────────────────────────────
	$prev = $content;
	$content = preg_replace(
		'/^(\s*(?:if|while|elseif)\s*\()(\$[a-zA-Z_]\w*)\s*(===|!==)\s*([\'"][^\'"]+[\'"])\s*(\))/m',
		'$1$4 $3 $2$5',
		$content
	);
	if ( $content !== $prev ) {
		$counts['yoda'] += substr_count( $prev, 'if (' ) - substr_count( $content, 'if (' ); // rough
		$changed = true;
	}

	// ── 3. Block comment closer on new line ────────────────
	$prev = $content;
	$content = preg_replace(
		'/^(\s*)\/\*\s+([^*\n]+?)\s*\*\/\s*$/m',
		"$1/* $2 */\n$1 */",
		$content
	);
	if ( $content !== $prev ) {
		$counts['comment_closer']++;
		$changed = true;
	}

	// ── 4. get_capability_flags() docblock ─────────────────
	if ( preg_match( '/\bfunction get_capability_flags\s*\(/', $content ) ) {
		$before = substr( $content, 0, strpos( $content, 'function get_capability_flags' ) );
		if ( ! preg_match( '/\/\*\*[\s\S]*?\*\/\s*$/m', substr( $before, -200 ) ) ) {
			$content = preg_replace(
				'/(^(\s*))(public |protected |private )?function get_capability_flags\s*\(/m',
				"$1/**\n$1 * Get capability flags for this tool.\n$1 *\n$1 * @return array\n$1 */\n$1$3function get_capability_flags(",
				$content
			);
			$counts['cap_flags']++;
			$changed = true;
		}
	}

	if ( $changed && $content !== $original ) {
		file_put_contents( $filepath, $content );
		$files_touched++;
	}
}

echo 'Fixed ' . count( $files ) . " files scanned, $files_touched touched.\n";
echo "  inline_periods: {$counts['inline_periods']}\n";
echo "  yoda:           {$counts['yoda']}\n";
echo "  comment_closer: {$counts['comment_closer']}\n";
echo "  cap_flags:      {$counts['cap_flags']}\n";
