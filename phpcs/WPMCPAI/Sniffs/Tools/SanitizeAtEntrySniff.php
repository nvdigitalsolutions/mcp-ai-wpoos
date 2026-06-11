<?php
/**
 * Sanitize-at-entry sniff for NV oOS tool classes.
 *
 * Phase P6 of the Unix Theory Compliance Enhancement Proposal codifies
 * Gate 1 — "All `$arguments` values are sanitised at the top of execute()
 * before any business logic." A common and high-severity Gate-1 violation
 * is to interpolate the raw `$arguments[...]` value directly into a string
 * (either inside a double-quoted/HEREDOC string or via the `.` concatenation
 * operator) without first running it through a sanitiser. This pattern is the
 * classic SQL-injection and HTML-injection vector.
 *
 * This sniff warns on exactly that smell. It is intentionally narrow so the
 * false-positive rate stays near zero — it does NOT attempt to detect every
 * sanitiser-less read of `$arguments`. Broader detection lives in the human
 * code-review checklist documented in `.context/security-checklist.md` and
 * the codification audit at
 * `docs/project/proposals/audits/P6-sanitize-escape-codification-2026-05.md`.
 *
 * Trigger:
 *   $sql = "SELECT * FROM wp_posts WHERE id = {$arguments['id']}";   ← warned
 *   $url = 'https://api.example.com/' . $arguments['endpoint'];     ← warned
 *
 * Not triggered by:
 *   $id  = absint( $arguments['id'] );                              ← sanitised
 *   $sql = $wpdb->prepare( '... WHERE id = %d', absint( $arguments['id'] ) );
 *   isset( $arguments['key'] )                                       ← key check
 *
 * Severity is set to 5 so the sniff stays silent under
 * `composer run lint:base` (which uses `--warning-severity=8`) but surfaces
 * under the default `composer run lint`, matching the P1 sniff convention.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

namespace WPMCPAI\Sniffs\Tools;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * Sniff: WPMCPAI.Tools.SanitizeAtEntry
 */
class SanitizeAtEntrySniff implements Sniff {

	/**
	 * Severity for this sniff. Matches the CanonicalReturnEnvelope sniff so
	 * the rule is silent under `lint:base` (warning-severity=8) and visible
	 * under default `composer run lint`.
	 */
	const SEVERITY = 5;

	/**
	 * Variable names that should be treated as tool entry-point payloads.
	 *
	 * The canonical name in the codebase is `$arguments` (the first param of
	 * `execute()`). `$args` is the conventional short alias used in a handful
	 * of helper closures. Other shapes are out of scope.
	 *
	 * @var string[]
	 */
	const ENTRY_VARS = array( '$arguments', '$args' );

	/**
	 * Path fragments that scope this sniff to tool implementations. Sanitise
	 * gate enforcement is a tool-class concern; helpers, services, and core
	 * REST plumbing are out of scope (they have their own validators).
	 *
	 * @var string[]
	 */
	const TOOL_PATH_FRAGMENTS = array(
		'/includes/tools/',
		'/addons/pro/includes/tools/',
	);

	/**
	 * Tokens this sniff observes.
	 *
	 * @return array<int|string>
	 */
	public function register() {
		return array(
			T_DOUBLE_QUOTED_STRING,
			T_HEREDOC,
			T_STRING_CONCAT,
		);
	}

	/**
	 * Process the observed token.
	 *
	 * @param File $phpcsFile The file being scanned.
	 * @param int  $stackPtr  Position of the trigger token in the stack.
	 * @return void
	 */
	public function process( File $phpcsFile, $stackPtr ) {
		// Only run inside tool-class files.
		if ( ! $this->is_tool_file( $phpcsFile->getFilename() ) ) {
			return;
		}

		$tokens = $phpcsFile->getTokens();
		$code   = $tokens[ $stackPtr ]['code'];

		if ( T_DOUBLE_QUOTED_STRING === $code || T_HEREDOC === $code ) {
			$this->check_string_interpolation( $phpcsFile, $stackPtr, $tokens[ $stackPtr ]['content'] );
			return;
		}

		if ( T_STRING_CONCAT === $code ) {
			$this->check_concatenation( $phpcsFile, $stackPtr );
		}
	}

	/**
	 * Decide whether the file is a tool implementation.
	 *
	 * @param string $filename Absolute file path reported by PHPCS.
	 * @return bool
	 */
	private function is_tool_file( $filename ) {
		// Normalise Windows-style separators so the path-fragment match works
		// uniformly across PHPCS runs on any host OS.
		$normalised = str_replace( '\\', '/', $filename );
		foreach ( self::TOOL_PATH_FRAGMENTS as $fragment ) {
			if ( false !== strpos( $normalised, $fragment ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Warn if a double-quoted string or HEREDOC interpolates `$arguments[...]`.
	 *
	 * @param File   $phpcsFile The file being scanned.
	 * @param int    $stackPtr  Position of the string token.
	 * @param string $content   Raw token content.
	 * @return void
	 */
	private function check_string_interpolation( File $phpcsFile, $stackPtr, $content ) {
		foreach ( self::ENTRY_VARS as $var ) {
			// Match `$arguments[` (simple syntax) and `{$arguments['key']}` (complex).
			$escaped = preg_quote( $var, '/' );
			if ( preg_match( '/' . $escaped . '\s*\[/', $content ) ) {
				$phpcsFile->addWarning(
					sprintf(
						'Tool input %s[...] is interpolated into a string without an explicit sanitiser. Assign it to a sanitised local first (e.g. $id = absint( %s[\'id\'] )), or pass it through $wpdb->prepare() with a placeholder. See docs/project/proposals/audits/P6-sanitize-escape-codification-2026-05.md.',
						$var,
						$var
					),
					$stackPtr,
					'InterpolatedArguments',
					array(),
					self::SEVERITY
				);
				return;
			}
		}
	}

	/**
	 * Warn on `'...' . $arguments[...]` concatenation patterns.
	 *
	 * @param File $phpcsFile The file being scanned.
	 * @param int  $stackPtr  Position of the T_STRING_CONCAT token.
	 * @return void
	 */
	private function check_concatenation( File $phpcsFile, $stackPtr ) {
		$tokens = $phpcsFile->getTokens();

		// Look at the next non-whitespace token; if it's our entry variable
		// followed by `[`, that is direct, un-sanitised concatenation.
		$next = $phpcsFile->findNext( T_WHITESPACE, $stackPtr + 1, null, true );
		if ( false === $next ) {
			return;
		}

		if ( T_VARIABLE !== $tokens[ $next ]['code'] ) {
			return;
		}

		if ( ! in_array( $tokens[ $next ]['content'], self::ENTRY_VARS, true ) ) {
			return;
		}

		$bracket = $phpcsFile->findNext( T_WHITESPACE, $next + 1, null, true );
		if ( false === $bracket || T_OPEN_SQUARE_BRACKET !== $tokens[ $bracket ]['code'] ) {
			return;
		}

		// Skip if the enclosing call is a recognised sanitiser / escaper.
		if ( $this->is_within_safe_wrapper( $phpcsFile, $stackPtr ) ) {
			return;
		}

		$phpcsFile->addWarning(
			sprintf(
				'Tool input %s[...] is concatenated into a string without an explicit sanitiser. Assign it to a sanitised local first, or use $wpdb->prepare() with a placeholder. See docs/project/proposals/audits/P6-sanitize-escape-codification-2026-05.md.',
				$tokens[ $next ]['content']
			),
			$next,
			'ConcatenatedArguments',
			array(),
			self::SEVERITY
		);
	}

	/**
	 * Walk outward from $stackPtr looking for an enclosing function call whose
	 * name appears in the canonical sanitiser / escaper allow-list. Used to
	 * suppress warnings inside e.g. `esc_url( 'https://x.test/' . $args['p'] )`.
	 *
	 * @param File $phpcsFile The file being scanned.
	 * @param int  $stackPtr  Position of the T_STRING_CONCAT token.
	 * @return bool True when the surrounding call is a recognised safe wrapper.
	 */
	private function is_within_safe_wrapper( File $phpcsFile, $stackPtr ) {
		$tokens = $phpcsFile->getTokens();

		// PHPCS records the parenthesis owner on every '(' token; walk up to
		// the innermost '(' and inspect the function name preceding it.
		if ( empty( $tokens[ $stackPtr ]['nested_parenthesis'] ) ) {
			return false;
		}

		// Iterate from innermost to outermost; the first recognised sanitiser
		// is enough to suppress the warning.
		$parens = array_keys( $tokens[ $stackPtr ]['nested_parenthesis'] );
		// In PHPCS, the array keys are the opener positions; values are closer
		// positions. We want innermost-first.
		$parens = array_reverse( $parens );

		foreach ( $parens as $opener ) {
			$prev = $phpcsFile->findPrevious( T_WHITESPACE, $opener - 1, null, true );
			if ( false === $prev ) {
				continue;
			}

			if ( T_STRING !== $tokens[ $prev ]['code'] ) {
				continue;
			}

			$name = strtolower( $tokens[ $prev ]['content'] );

			// `prepare` is only a safe wrapper when invoked as a method
			// (e.g. `$wpdb->prepare( … )` or `WPDB::prepare( … )`). A bare
			// `prepare( … )` call is some other custom function and must
			// not suppress the warning.
			if ( 'prepare' === $name ) {
				$before = $phpcsFile->findPrevious( T_WHITESPACE, $prev - 1, null, true );
				if ( false === $before ) {
					continue;
				}
				if ( T_OBJECT_OPERATOR !== $tokens[ $before ]['code']
					&& T_DOUBLE_COLON !== $tokens[ $before ]['code'] ) {
					continue;
				}
			}

			if ( $this->is_safe_wrapper_name( $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Allow-list of known safe wrappers. Includes the canonical WordPress
	 * sanitisers / escapers plus the project's repeated helpers.
	 *
	 * @param string $name Lower-cased function name.
	 * @return bool
	 */
	private function is_safe_wrapper_name( $name ) {
		static $allow_list = array(
			// Sanitisers.
			'absint'                  => true,
			'intval'                  => true,
			'floatval'                => true,
			'sanitize_text_field'     => true,
			'sanitize_textarea_field' => true,
			'sanitize_email'          => true,
			'sanitize_key'            => true,
			'sanitize_title'          => true,
			'sanitize_file_name'      => true,
			'sanitize_user'           => true,
			'sanitize_html_class'     => true,
			'sanitize_hex_color'      => true,
			'sanitize_mime_type'      => true,
			'sanitize_meta'           => true,
			'wp_kses'                 => true,
			'wp_kses_post'            => true,
			'wp_kses_data'            => true,
			'wp_strip_all_tags'       => true,
			'wp_unslash'              => true,
			'rest_sanitize_boolean'   => true,
			// Escapers (acceptable as part of an exit gate).
			'esc_html'                => true,
			'esc_attr'                => true,
			'esc_url'                 => true,
			'esc_url_raw'             => true,
			'esc_textarea'            => true,
			'esc_js'                  => true,
			'esc_sql'                 => true,
			'wp_json_encode'          => true,
			// Prepared-query helper — invocation itself enforces sanitisation
			// via placeholders; warnings inside `$wpdb->prepare(...)` are
			// suppressed because they're behind a recognised wrapper.
			'prepare'                 => true,
		);

		return isset( $allow_list[ $name ] );
	}
}
