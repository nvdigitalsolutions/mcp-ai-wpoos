<?php
/**
 * Tool-description-guidance sniff for NV oOS tool classes.
 *
 * Warns whenever a class implements WP_MCP_AI_Tool_Interface but neither
 * implements WP_MCP_AI_Tool_Usage_Guidance_Interface nor embeds usage
 * guidance ("when to use" / "when NOT to use") in its get_description().
 *
 * Rationale (tool-description-engineering-proposal.md): tool descriptions
 * are prompts. Negative guidance is the single highest-leverage selection
 * signal on a ~1,585-tool surface (Block's MCP playbook, AWS tool-design
 * guidance, Anthropic tool-use docs). The optional interface keeps the
 * admin-facing description short while the registry appends a compact
 * [Usage: …] suffix to the model-facing payload.
 *
 * Trigger:
 *   class WP_MCP_AI_Tool_Example implements WP_MCP_AI_Tool_Interface { … }
 *   // no guidance interface, plain get_description()          ← warned
 *
 * Not triggered by:
 *   … implements WP_MCP_AI_Tool_Usage_Guidance_Interface       ← compliant
 *   get_description() containing "when to use"/"when NOT to use" ← compliant
 *   non-tool classes; WP_MCP_AI_Legacy_Tool_Wrapper;          ← skipped
 *   abstract tool base classes (shared scaffolding)           ← skipped
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
 * Sniff: WPMCPAI.Tools.ToolDescriptionGuidance
 */
class ToolDescriptionGuidanceSniff implements Sniff {

	/**
	 * Tokens this sniff observes.
	 *
	 * @return array<int|string>
	 */
	public function register() {
		return array( T_CLASS );
	}

	/**
	 * Process a T_CLASS token: resolve the declared interfaces, then decide
	 * whether the class is a tool lacking usage guidance.
	 *
	 * @param File $phpcsFile The file being scanned.
	 * @param int  $stackPtr  Position of the T_CLASS token in the stack.
	 * @return void
	 */
	public function process( File $phpcsFile, $stackPtr ) {
		$tokens = $phpcsFile->getTokens();

		// Abstract tool bases (e.g. WP_MCP_AI_Tool_DietPi_Base) are shared
		// scaffolding that concrete tools extend; they are never instantiated
		// or registered, so guidance belongs on the concrete classes only.
		// findPrevious( $type, $start, $end ) scans BACKWARD from $start to
		// $end, so the bounds must run from the token before T_CLASS outward.
		$abstract_ptr = $phpcsFile->findPrevious( T_ABSTRACT, $stackPtr - 1, max( 0, $stackPtr - 5 ) );
		if ( false !== $abstract_ptr ) {
			return;
		}

		$class_name = $phpcsFile->findNext( T_STRING, $stackPtr + 1 );
		if ( false === $class_name ) {
			return;
		}

		// The legacy wrapper is a single shared adapter for pre-interface tool
		// classes; its description source is the wrapped legacy class, so it is
		// exempt from guidance checks.
		if ( 'WP_MCP_AI_Legacy_Tool_Wrapper' === $tokens[ $class_name ]['content'] ) {
			return;
		}

		// Find the end of the class declaration line (open brace or semicolon).
		$declaration_end = $phpcsFile->findNext( array( T_OPEN_CURLY_BRACKET, T_SEMICOLON ), $stackPtr + 1 );
		if ( false === $declaration_end ) {
			return;
		}

		$implements_ptr = $phpcsFile->findNext( T_IMPLEMENTS, $stackPtr + 1, $declaration_end );
		if ( false === $implements_ptr ) {
			return; // Not a tool class (tools always declare interfaces).
		}

		// Collect interface names between T_IMPLEMENTS and the declaration end.
		$interfaces = array();
		for ( $i = $implements_ptr + 1; $i < $declaration_end; $i++ ) {
			if ( T_STRING !== $tokens[ $i ]['code'] ) {
				continue;
			}

			$name = $tokens[ $i ]['content'];

			// Skip strings that are part of a fully-qualified prefix walk; keep
			// only the short name segment before any backslash boundary.
			$interfaces[] = $name;
		}

		$is_tool             = false;
		$has_guidance_iface  = false;

		foreach ( $interfaces as $interface_name ) {
			if ( 'WP_MCP_AI_Tool_Interface' === $interface_name ) {
				$is_tool = true;
			}
			if ( 'WP_MCP_AI_Tool_Usage_Guidance_Interface' === $interface_name ) {
				$has_guidance_iface = true;
			}
		}

		if ( ! $is_tool ) {
			return;
		}

		if ( $has_guidance_iface ) {
			return; // Compliant via the optional interface.
		}

		// Fallback compliance path: inline guidance markers inside
		// get_description(). Scan only that method's body.
		if ( $this->has_inline_guidance( $phpcsFile, $tokens ) ) {
			return;
		}

		$phpcsFile->addWarning(
			'Tool classes should implement WP_MCP_AI_Tool_Usage_Guidance_Interface (or embed "when to use / when NOT to use" guidance in get_description()) so the model-facing payload carries selection hints. See docs/features/tool-description-guidelines.md.',
			$class_name,
			'MissingUsageGuidance'
		);
	}

	/**
	 * Detect inline usage-guidance markers inside the class's get_description()
	 * method body.
	 *
	 * @param File  $phpcsFile The file being scanned.
	 * @param array $tokens    Token stack for the file.
	 * @return bool True when get_description() embeds guidance markers.
	 */
	private function has_inline_guidance( File $phpcsFile, array $tokens ) {
		foreach ( $tokens as $ptr => $token ) {
			if ( T_FUNCTION !== $token['code'] ) {
				continue;
			}

			$name = $phpcsFile->findNext( T_STRING, $ptr + 1 );
			if ( false === $name || 'get_description' !== $tokens[ $name ]['content'] ) {
				continue;
			}

			if ( ! isset( $token['scope_opener'], $token['scope_closer'] ) ) {
				continue;
			}

			for ( $i = $token['scope_opener'] + 1; $i < $token['scope_closer']; $i++ ) {
				if ( T_CONSTANT_ENCAPSED_STRING !== $tokens[ $i ]['code'] ) {
					continue;
				}

				$haystack = strtolower( trim( $tokens[ $i ]['content'], "'\"" ) );
				foreach ( array( 'when to use', 'when not to use', 'do not use', 'usage:' ) as $marker ) {
					if ( false !== strpos( $haystack, $marker ) ) {
						return true;
					}
				}
			}

			return false; // Only the first get_description() counts.
		}

		return false;
	}
}
