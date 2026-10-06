<?php
/**
 * Decision-scope-declaration sniff for decision-model dispatches.
 *
 * Errors whenever a `->decide()` or `->create_decision()` object-operator
 * call is not enclosed by a named function/method whose docblock declares
 * `@decision-domain <domain>` and `@decision-authority <authority>` with
 * values from WP_MCP_AI_Decision_Scope_Guard's constant lists.
 *
 * Rationale (docs/project/proposals/052-decision-scope-guard.md): decision
 * models (TypeSafe Jev) answer "one thought too many" questions — every
 * dispatch must declare WHAT is being decided (domain) and HOW MUCH
 * authority the verdict carries. The declaration is made by the integration
 * author (like Rust's `unsafe`) and enforced mechanically: a new integration
 * without a declaration is a CI build failure, so decision-model creep is
 * visible before it ships.
 *
 * Trigger:
 *   $client->decide( $state, $questions, $options );          ← missing tags
 *   $openrouter->create_decision( $state, $questions, $o );   ← missing tags
 *
 * Not triggered by:
 *   methods with @decision-domain + @decision-authority tags ← compliant
 *   files under tests/ (transport tests, not integrations)    ← skipped
 *   methods in classes implementing                             ← skipped
 *     Interface_WP_MCP_AI_Decision_Client (pure transport adapters)
 *   static dispatch (self::decide(...), Class::decide(...))    ← skipped
 *     (declaration belongs at the client-dispatch funnel)
 *
 * Escape hatch: // phpcs:ignore WPMCPAI.Decisions.ScopeDeclared -- reason
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

namespace WPMCPAI\Sniffs\Decisions;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * Sniff: WPMCPAI.Decisions.ScopeDeclared
 */
class ScopeDeclaredSniff implements Sniff {

	/**
	 * Decision-method names that dispatch to a decision model.
	 *
	 * @var string[]
	 */
	private $decision_methods = array( 'decide', 'create_decision' );

	/**
	 * Allowed @decision-domain values.
	 *
	 * KEEP IN SYNC with the WP_MCP_AI_Decision_Scope_Guard DOMAIN_* constants
	 * in includes/services/class-wp-mcp-ai-decision-scope-guard.php (the
	 * guard's unit tests pin the constant values).
	 *
	 * @var string[]
	 */
	private $allowed_domains = array( 'advisory', 'content', 'operations', 'verification' );

	/**
	 * Allowed @decision-authority values.
	 *
	 * KEEP IN SYNC with the WP_MCP_AI_Decision_Scope_Guard AUTHORITY_*
	 * constants in includes/services/class-wp-mcp-ai-decision-scope-guard.php.
	 *
	 * @var string[]
	 */
	private $allowed_authorities = array( 'inform', 'suggest', 'act' );

	/**
	 * Tokens this sniff observes.
	 *
	 * @return array<int|string>
	 */
	public function register() {
		return array( T_OBJECT_OPERATOR );
	}

	/**
	 * Process a T_OBJECT_OPERATOR token: confirm it precedes a
	 * decide()/create_decision() call, then require a declared scope on the
	 * enclosing function.
	 *
	 * @param File $phpcsFile The file being scanned.
	 * @param int  $stackPtr  Position of the T_OBJECT_OPERATOR token.
	 * @return void
	 */
	public function process( File $phpcsFile, $stackPtr ) {
		$tokens = $phpcsFile->getTokens();

		// Test files exercise the transports directly; declarations belong on
		// integrations, so the suites stay exempt.
		$filename = str_replace( '\\', '/', $phpcsFile->getFilename() );
		if ( false !== strpos( $filename, '/tests/' ) ) {
			return;
		}

		$method = $phpcsFile->findNext( T_STRING, $stackPtr + 1 );
		if ( false === $method || ! in_array( $tokens[ $method ]['content'], $this->decision_methods, true ) ) {
			return;
		}

		$open_paren = $phpcsFile->findNext( T_WHITESPACE, $method + 1, null, true );
		if ( false === $open_paren || T_OPEN_PARENTHESIS !== $tokens[ $open_paren ]['code'] ) {
			return; // Property access or method reference, not a dispatch.
		}

		$owner = $this->find_enclosing_callable( $phpcsFile, $stackPtr );
		if ( null === $owner ) {
			// Bare closure outside any named callable: no docblock can carry
			// the declaration, so the dispatch is undeclared by definition.
			$this->report( $phpcsFile, $method, null, null );

			return;
		}

		if ( $this->owner_class_implements_decision_contract( $phpcsFile, $owner ) ) {
			return; // Pure transport adapter; scope is declared at the callers.
		}

		$docblock = $this->get_attached_docblock( $phpcsFile, $owner );

		$domain    = null;
		$authority = null;
		if ( null !== $docblock ) {
			preg_match( '/@decision-domain\s+([a-z_]+)/i', $docblock, $domain_match );
			preg_match( '/@decision-authority\s+([a-z_]+)/i', $docblock, $authority_match );

			$domain    = isset( $domain_match[1] ) ? strtolower( $domain_match[1] ) : null;
			$authority = isset( $authority_match[1] ) ? strtolower( $authority_match[1] ) : null;
		}

		$domain_valid    = in_array( $domain, $this->allowed_domains, true );
		$authority_valid = in_array( $authority, $this->allowed_authorities, true );

		if ( $domain_valid && $authority_valid ) {
			return; // Declared and valid.
		}

		$this->report( $phpcsFile, $method, $domain, $authority );
	}

	/**
	 * Find the nearest enclosing named function/method, walking outward
	 * through closures (a closure cannot carry the declaration).
	 *
	 * @param File $phpcsFile The file being scanned.
	 * @param int  $stackPtr  Position of the dispatch token.
	 * @return int|null Token position of the enclosing T_FUNCTION, or null
	 *                  when only a bare closure (or nothing) encloses the call.
	 */
	private function find_enclosing_callable( File $phpcsFile, $stackPtr ) {
		$tokens = $phpcsFile->getTokens();

		for ( $i = $stackPtr - 1; $i >= 0; $i-- ) {
			if ( T_FUNCTION !== $tokens[ $i ]['code'] && T_CLOSURE !== $tokens[ $i ]['code'] ) {
				continue;
			}

			if ( ! isset( $tokens[ $i ]['scope_opener'], $tokens[ $i ]['scope_closer'] ) ) {
				continue;
			}

			if ( $tokens[ $i ]['scope_opener'] < $stackPtr && $tokens[ $i ]['scope_closer'] > $stackPtr ) {
				// The nearest enclosing function is the first match walking
				// backward; a T_FUNCTION is a named callable and is the owner.
				if ( T_FUNCTION === $tokens[ $i ]['code'] ) {
					return $i;
				}
			}
		}

		return null;
	}

	/**
	 * Whether the class enclosing the owner callable implements the
	 * decision-client contract (a transport adapter, not an integration).
	 *
	 * Walks outward from the owner callable and inspects the innermost
	 * enclosing class declaration — a named class or an anonymous adapter.
	 *
	 * @param File $phpcsFile The file being scanned.
	 * @param int  $owner     Token position of the enclosing T_FUNCTION.
	 * @return bool True when the enclosing class implements
	 *              Interface_WP_MCP_AI_Decision_Client.
	 */
	private function owner_class_implements_decision_contract( File $phpcsFile, $owner ) {
		$tokens = $phpcsFile->getTokens();

		for ( $i = $owner - 1; $i >= 0; $i-- ) {
			if ( T_ANON_CLASS !== $tokens[ $i ]['code'] && T_CLASS !== $tokens[ $i ]['code'] ) {
				continue;
			}

			if ( ! isset( $tokens[ $i ]['scope_opener'], $tokens[ $i ]['scope_closer'] ) ) {
				continue;
			}

			if ( $tokens[ $i ]['scope_opener'] < $owner && $tokens[ $i ]['scope_closer'] > $owner ) {
				// Innermost enclosing class wins: an anonymous adapter nested
				// inside an outer class is judged on its own declaration.
				return $this->class_declaration_implements_decision_contract( $phpcsFile, $i );
			}
		}

		return false;
	}

	/**
	 * Scan a class declaration (between the class token and its body open
	 * brace) for `implements Interface_WP_MCP_AI_Decision_Client`.
	 *
	 * @param File $phpcsFile The file being scanned.
	 * @param int  $class_ptr Position of the T_CLASS / T_ANON_CLASS token.
	 * @return bool True when the declaration lists the decision contract.
	 */
	private function class_declaration_implements_decision_contract( File $phpcsFile, $class_ptr ) {
		$tokens = $phpcsFile->getTokens();

		$decl_end = isset( $tokens[ $class_ptr ]['scope_opener'] ) ? $tokens[ $class_ptr ]['scope_opener'] : false;
		if ( false === $decl_end ) {
			return false;
		}

		$implements = $phpcsFile->findNext( T_IMPLEMENTS, $class_ptr + 1, $decl_end );
		if ( false === $implements ) {
			return false;
		}

		for ( $i = $implements + 1; $i < $decl_end; $i++ ) {
			if ( T_STRING === $tokens[ $i ]['code'] && 'Interface_WP_MCP_AI_Decision_Client' === $tokens[ $i ]['content'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return the docblock attached to the owner callable, or null.
	 *
	 * Only whitespace and visibility/static/final/abstract modifiers may sit
	 * between the docblock close and the function token — anything else means
	 * the comment is not attached.
	 *
	 * @param File $phpcsFile The file being scanned.
	 * @param int  $owner     Token position of the enclosing T_FUNCTION.
	 * @return string|null The reassembled docblock text, or null.
	 */
	private function get_attached_docblock( File $phpcsFile, $owner ) {
		$tokens = $phpcsFile->getTokens();

		$close = $phpcsFile->findPrevious( T_DOC_COMMENT_CLOSE_TAG, $owner - 1, max( 0, $owner - 100 ) );
		if ( false === $close ) {
			return null;
		}

		$allowed_between = array(
			T_WHITESPACE => T_WHITESPACE,
			T_PUBLIC     => T_PUBLIC,
			T_PROTECTED  => T_PROTECTED,
			T_PRIVATE    => T_PRIVATE,
			T_STATIC     => T_STATIC,
			T_ABSTRACT   => T_ABSTRACT,
			T_FINAL      => T_FINAL,
		);

		for ( $i = $close + 1; $i < $owner; $i++ ) {
			if ( ! isset( $allowed_between[ $tokens[ $i ]['code'] ] ) ) {
				return null; // Not attached (e.g. inside an argument list).
			}
		}

		$open = isset( $tokens[ $close ]['comment_opener'] ) ? $tokens[ $close ]['comment_opener'] : null;
		if ( null === $open ) {
			return null;
		}

		return $phpcsFile->getTokensAsString( $open, $close - $open + 1 );
	}

	/**
	 * Emit the missing/invalid declaration error for a dispatch.
	 *
	 * @param File        $phpcsFile The file being scanned.
	 * @param int         $method    Token position of the dispatch method name.
	 * @param string|null $domain    Declared domain value (may be null).
	 * @param string|null $authority Declared authority value (may be null).
	 * @return void
	 */
	private function report( File $phpcsFile, $method, $domain, $authority ) {
		$tokens = $phpcsFile->getTokens();

		$domain_valid    = in_array( $domain, $this->allowed_domains, true );
		$authority_valid = in_array( $authority, $this->allowed_authorities, true );

		if ( ! $domain_valid && ! $authority_valid ) {
			$message = 'Decision dispatch (%s()) must declare its scope: add "@decision-domain <domain>" and "@decision-authority <authority>" to the enclosing method docblock. Allowed domains: %s; allowed authorities: %s. See docs/project/proposals/052-decision-scope-guard.md.';
		} elseif ( ! $domain_valid ) {
			$message = 'Invalid @decision-domain on decision dispatch (%s()). Allowed domains: %s. See docs/project/proposals/052-decision-scope-guard.md.';
		} else {
			$message = 'Invalid @decision-authority on decision dispatch (%s()). Allowed authorities: %s. See docs/project/proposals/052-decision-scope-guard.md.';
		}

		$phpcsFile->addError(
			sprintf(
				$message,
				$tokens[ $method ]['content'],
				implode( ', ', $this->allowed_domains ),
				implode( ', ', $this->allowed_authorities )
			),
			$method,
			'UndeclaredDecisionScope'
		);
	}
}
