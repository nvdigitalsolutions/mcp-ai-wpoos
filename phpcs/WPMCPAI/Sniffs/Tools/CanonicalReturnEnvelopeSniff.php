<?php
/**
 * Canonical-return-envelope sniff for NV oOS tool classes.
 *
 * Warns whenever a `return` statement returns an array literal that contains
 * `'success' => false` — the canonical envelope (Unix Theory Compliance
 * Proposal §2.2) forbids that shape on the failure path. Failures must use
 * `WP_Error` instead, so observability subscribers, the agentic loop, and
 * the LLM all see a single, consistent error contract.
 *
 * Trigger:
 *   return array( 'success' => false, ... );      ← warned
 *   return [ 'success' => false, ... ];           ← warned
 *
 * Not triggered by:
 *   return array( 'success' => true,  ... );      ← canonical success shape
 *   return new WP_Error( 'code', ... );           ← canonical failure shape
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
 * Sniff: WPMCPAI.Tools.CanonicalReturnEnvelope
 */
class CanonicalReturnEnvelopeSniff implements Sniff {

	/**
	 * Tokens this sniff observes.
	 *
	 * @return array<int|string>
	 */
	public function register() {
		return array( T_RETURN );
	}

	/**
	 * Process a T_RETURN token and inspect the array literal (if any) that
	 * follows it for a `'success' => false` key/value pair.
	 *
	 * @param File $phpcsFile The file being scanned.
	 * @param int  $stackPtr  Position of the T_RETURN token in the stack.
	 * @return void
	 */
	public function process( File $phpcsFile, $stackPtr ) {
		$tokens = $phpcsFile->getTokens();

		// Find the end of the return statement so we don't accidentally scan
		// into the next statement.
		$end_of_statement = $phpcsFile->findNext( T_SEMICOLON, $stackPtr + 1 );
		if ( false === $end_of_statement ) {
			return;
		}

		// Locate an array opener within the return expression.
		$array_ptr = $phpcsFile->findNext(
			array( T_ARRAY, T_OPEN_SHORT_ARRAY ),
			$stackPtr + 1,
			$end_of_statement
		);
		if ( false === $array_ptr ) {
			return;
		}

		// Resolve the array's open/close boundaries.
		if ( T_ARRAY === $tokens[ $array_ptr ]['code'] ) {
			$open  = isset( $tokens[ $array_ptr ]['parenthesis_opener'] )
				? $tokens[ $array_ptr ]['parenthesis_opener']
				: null;
			$close = isset( $tokens[ $array_ptr ]['parenthesis_closer'] )
				? $tokens[ $array_ptr ]['parenthesis_closer']
				: null;
		} else {
			$open  = $array_ptr;
			$close = isset( $tokens[ $array_ptr ]['bracket_closer'] )
				? $tokens[ $array_ptr ]['bracket_closer']
				: null;
		}

		if ( null === $open || null === $close ) {
			return;
		}

		// Walk the array contents looking for a 'success' key followed by => false.
		for ( $i = $open + 1; $i < $close; $i++ ) {
			if ( T_CONSTANT_ENCAPSED_STRING !== $tokens[ $i ]['code'] ) {
				continue;
			}

			// Strip surrounding quotes; the sniff matches both 'success' and "success".
			$key = trim( $tokens[ $i ]['content'], "'\"" );
			if ( 'success' !== $key ) {
				continue;
			}

			// Confirm the next non-whitespace token is `=>`.
			$arrow = $phpcsFile->findNext( T_WHITESPACE, $i + 1, $close, true );
			if ( false === $arrow || T_DOUBLE_ARROW !== $tokens[ $arrow ]['code'] ) {
				continue;
			}

			// Confirm the value is the literal `false`.
			$value = $phpcsFile->findNext( T_WHITESPACE, $arrow + 1, $close, true );
			if ( false === $value ) {
				continue;
			}

			if ( T_FALSE !== $tokens[ $value ]['code'] ) {
				continue;
			}

			$phpcsFile->addWarning(
				'Tool return envelopes must not use "success => false". Return new WP_Error( $code, $message [, $extra] ) on the failure path instead. See docs/project/proposals/UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md §2.2.',
				$i,
				'SuccessFalseArray'
			);
		}
	}
}
