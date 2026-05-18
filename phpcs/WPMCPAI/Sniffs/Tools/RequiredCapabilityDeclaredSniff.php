<?php
/**
 * Required-capability-declared sniff for NV oOS tool classes.
 *
 * Phase 3 of the Unix Theory Compliance Enhancement Proposal: every concrete
 * tool class must declare its required WordPress capability so the capability
 * fence in `WP_MCP_AI_REST::build_tools_payload()` can filter low-privilege
 * users from destructive tools.
 *
 * A tool class satisfies the requirement when it has EITHER:
 *
 *   1. A `public function get_required_capability()` method in its body, OR
 *   2. A literal `'required_capability'` key in the array returned by its
 *      `get_definition()` method.
 *
 * Trigger (no method and no definition key):
 *   class WP_MCP_AI_Tool_Foo implements WP_MCP_AI_Tool_Interface {
 *       public function get_definition() { return array( 'name' => 'Foo' ); }
 *       public function execute( $arguments, $context ) { ... }
 *   }
 *
 * Not triggered when the method is declared:
 *   public function get_required_capability() { return 'edit_posts'; }
 *
 * Not triggered when the key is in get_definition():
 *   return array( 'required_capability' => 'manage_options', ... );
 *
 * Not triggered for abstract classes — they define the contract, not the
 * concrete capability value.
 *
 * Not triggered for classes that extend a base (rather than directly
 * implementing the interface), because they inherit the method from their
 * parent. The sniff focuses on classes that are the full owner of their own
 * capability declaration.
 *
 * Severity is 5 — silent under `composer run lint:base` (warning-severity=8),
 * visible under the default `composer run lint`, matching the P1/P6 sniff
 * convention.
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
 * Sniff: WPMCPAI.Tools.RequiredCapabilityDeclared
 */
class RequiredCapabilityDeclaredSniff implements Sniff {

	/**
	 * Severity — matches the existing P1 / P6 sniff convention.
	 */
	const SEVERITY = 5;

	/**
	 * Path fragments that scope this sniff to tool implementations.
	 *
	 * @var string[]
	 */
	const TOOL_PATH_FRAGMENTS = array(
		'/includes/tools/',
		'/addons/pro/includes/tools/',
	);

	/**
	 * The tool interface whose presence in the `implements` clause marks
	 * a class as a tool that must declare a capability.
	 */
	const TOOL_INTERFACE = 'WP_MCP_AI_Tool_Interface';

	/**
	 * Tokens this sniff observes.
	 *
	 * @return array<int|string>
	 */
	public function register() {
		return array( T_CLASS );
	}

	/**
	 * Inspect each class declaration and warn when the capability contract is
	 * missing.
	 *
	 * @param File $phpcsFile The file being scanned.
	 * @param int  $stackPtr  Position of the T_CLASS token in the stack.
	 * @return void
	 */
	public function process( File $phpcsFile, $stackPtr ) {
		// Only scan files in the tool directories.
		if ( ! $this->is_tool_file( $phpcsFile->getFilename() ) ) {
			return;
		}

		$tokens = $phpcsFile->getTokens();

		// Skip abstract classes — they define a contract, not a concrete cap.
		$prev = $phpcsFile->findPrevious( T_WHITESPACE, $stackPtr - 1, null, true );
		if ( false !== $prev && T_ABSTRACT === $tokens[ $prev ]['code'] ) {
			return;
		}

		// We need a resolvable class body.
		if ( ! isset( $tokens[ $stackPtr ]['scope_opener'], $tokens[ $stackPtr ]['scope_closer'] ) ) {
			return;
		}

		$opener = $tokens[ $stackPtr ]['scope_opener'];
		$closer = $tokens[ $stackPtr ]['scope_closer'];

		// Only process classes that directly implement WP_MCP_AI_Tool_Interface.
		// Classes that merely *extend* a base inherit capability from their parent
		// and do not need to re-declare it; the base class is responsible.
		if ( ! $this->directly_implements_tool_interface( $phpcsFile, $stackPtr, $opener ) ) {
			return;
		}

		// Requirement 1 — a public get_required_capability() method exists.
		if ( $this->has_method( $phpcsFile, $opener, $closer, 'get_required_capability' ) ) {
			return;
		}

		// Requirement 2 — get_definition() contains a 'required_capability' key.
		if ( $this->has_required_capability_in_definition( $phpcsFile, $opener, $closer ) ) {
			return;
		}

		// Resolve the class name for a helpful warning message.
		$name_ptr   = $phpcsFile->findNext( T_STRING, $stackPtr + 1, $opener );
		$class_name = ( false !== $name_ptr ) ? $tokens[ $name_ptr ]['content'] : 'unknown';

		$phpcsFile->addWarning(
			'Tool class %s does not declare get_required_capability() and get_definition() contains no literal \'required_capability\' key. Every tool must be capability-fenced. Add: public function get_required_capability() { return \'edit_posts\'; }. See docs/proposals/UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md §2.3.',
			$stackPtr,
			'MissingCapabilityDeclaration',
			array( $class_name ),
			self::SEVERITY
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns true when the file path contains a known tool-directory fragment.
	 *
	 * @param string $filename Absolute path reported by PHPCS.
	 * @return bool
	 */
	private function is_tool_file( $filename ) {
		$normalised = str_replace( '\\', '/', $filename );
		foreach ( self::TOOL_PATH_FRAGMENTS as $fragment ) {
			if ( false !== strpos( $normalised, $fragment ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns true when the class declaration's `implements` clause contains
	 * WP_MCP_AI_Tool_Interface as a literal token.
	 *
	 * @param File $phpcsFile The file being scanned.
	 * @param int  $stackPtr  Position of the T_CLASS token.
	 * @param int  $opener    Position of the class body opener `{`.
	 * @return bool
	 */
	private function directly_implements_tool_interface( File $phpcsFile, $stackPtr, $opener ) {
		$tokens = $phpcsFile->getTokens();

		// Walk from the class keyword to the opening brace, looking for
		// `implements` followed by the interface name.
		$found_implements = false;
		for ( $i = $stackPtr + 1; $i < $opener; $i++ ) {
			$code = $tokens[ $i ]['code'];

			if ( T_IMPLEMENTS === $code ) {
				$found_implements = true;
				continue;
			}

			// The extends clause comes before implements; if we hit 'extends'
			// without first seeing 'implements', keep scanning.
			if ( T_EXTENDS === $code ) {
				continue;
			}

			if ( $found_implements && T_STRING === $code
				&& self::TOOL_INTERFACE === $tokens[ $i ]['content'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns true when the class body contains a function named $method_name
	 * at the direct (non-nested) level.
	 *
	 * To avoid descending into inner anonymous classes or closures, the scan
	 * stops at any nested `{` that belongs to a scope (i.e. the method body
	 * of an earlier method). It relies on PHPCS-resolved scope_opener /
	 * scope_closer pairs to skip over method bodies.
	 *
	 * @param File   $phpcsFile   The file being scanned.
	 * @param int    $class_open  Class body opener position.
	 * @param int    $class_close Class body closer position.
	 * @param string $method_name Method name to look for.
	 * @return bool
	 */
	private function has_method( File $phpcsFile, $class_open, $class_close, $method_name ) {
		$tokens = $phpcsFile->getTokens();
		$i      = $class_open + 1;

		while ( $i < $class_close ) {
			$code = $tokens[ $i ]['code'];

			// Skip over nested scopes (inner method bodies, closures, etc.).
			if ( ( T_FUNCTION === $code || T_CLOSURE === $code )
				&& isset( $tokens[ $i ]['scope_opener'], $tokens[ $i ]['scope_closer'] ) ) {

				// Check the function name before deciding to skip.
				$name_ptr = $phpcsFile->findNext( T_STRING, $i + 1, $tokens[ $i ]['scope_opener'] );
				if ( false !== $name_ptr && $method_name === $tokens[ $name_ptr ]['content'] ) {
					return true;
				}

				// Jump past this method's body so we don't re-enter it.
				$i = $tokens[ $i ]['scope_closer'] + 1;
				continue;
			}

			++$i;
		}

		return false;
	}

	/**
	 * Returns true when the class's `get_definition()` method body contains a
	 * literal string token equal to `'required_capability'`.
	 *
	 * @param File $phpcsFile   The file being scanned.
	 * @param int  $class_open  Class body opener position.
	 * @param int  $class_close Class body closer position.
	 * @return bool
	 */
	private function has_required_capability_in_definition( File $phpcsFile, $class_open, $class_close ) {
		$tokens = $phpcsFile->getTokens();

		// Find the get_definition method.
		$method_start = null;
		$method_end   = null;
		$i            = $class_open + 1;

		while ( $i < $class_close ) {
			$code = $tokens[ $i ]['code'];

			if ( T_FUNCTION === $code
				&& isset( $tokens[ $i ]['scope_opener'], $tokens[ $i ]['scope_closer'] ) ) {

				$name_ptr = $phpcsFile->findNext( T_STRING, $i + 1, $tokens[ $i ]['scope_opener'] );
				if ( false !== $name_ptr && 'get_definition' === $tokens[ $name_ptr ]['content'] ) {
					$method_start = $tokens[ $i ]['scope_opener'];
					$method_end   = $tokens[ $i ]['scope_closer'];
					break;
				}

				// Skip over other methods.
				$i = $tokens[ $i ]['scope_closer'] + 1;
				continue;
			}

			++$i;
		}

		if ( null === $method_start || null === $method_end ) {
			return false;
		}

		// Scan the method body for a 'required_capability' string token.
		for ( $j = $method_start + 1; $j < $method_end; $j++ ) {
			if ( T_CONSTANT_ENCAPSED_STRING !== $tokens[ $j ]['code'] ) {
				continue;
			}
			$key = trim( $tokens[ $j ]['content'], "'\"" );
			if ( 'required_capability' === $key ) {
				return true;
			}
		}

		return false;
	}
}
