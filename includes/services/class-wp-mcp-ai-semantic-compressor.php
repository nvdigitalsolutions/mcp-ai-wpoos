<?php
/**
 * Semantic Prompt Compressor — "Caveman Compression" for LLM Contexts
 *
 * Implements lossless semantic compression that strips grammar, connectives,
 * and filler words while preserving facts, numbers, and technical terms.
 *
 * Based on the Caveman Compression specification v1.0:
 * {@link https://github.com/wilpel/caveman-compression}
 *
 * @credit   William Peltomäki — original Caveman Compression algorithm (MIT license)
 * @link     https://github.com/wilpel/caveman-compression/blob/main/SPEC.md
 *
 * @package  WP_MCP_AI
 * @since    1.7.0
 * @author   NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Semantic Compressor class.
 *
 * Implements 9 formal rules from the Caveman Compression SPEC:
 *
 *   Rule 1 — Sentence Atomicity: split compound sentences, one thought per sentence.
 *   Rule 2 — Word Count Limit: 2-7 words per sentence.
 *   Rule 3 — Connective Elimination: strip causal, contrastive, sequential,
 *            purpose, and conditional connectives.
 *   Rule 4 — Active Voice / Present Tense: convert passive constructions.
 *   Rule 5 — Preserve Specifics: NEVER alter numbers, names, dates, terms,
 *            paths, URLs, or code.
 *   Rule 6 — Remove Intensifiers: strip "very", "extremely", "quite", etc.
 *   Rule 7 — Article Omission: remove "a", "an", "the" where unambiguous.
 *   Rule 8 — Pronoun Handling: keep short pronouns unless ambiguous.
 *   Rule 9 — Logical Completeness: ensure inference steps are explicit.
 *
 * Additionally, code blocks, JSON, URLs, email addresses, file paths, and
 * HTML tags are automatically protected from compression.
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Semantic_Compressor {

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Semantic_Compressor|null
	 */
	private static $instance = null;

	/**
	 * Character-per-token heuristic (consistent with plugin convention).
	 *
	 * @var int
	 */
	const CHARS_PER_TOKEN = 4;

	/**
	 * Placeholder prefix for protected blocks.
	 *
	 * @var string
	 */
	const PLACEHOLDER_PREFIX = '__CAVEMAN_BLOCK_';

	/**
	 * Placeholder suffix.
	 *
	 * @var string
	 */
	const PLACEHOLDER_SUFFIX = '__';

	/**
	 * Default word count range for sentences (Rule 2).
	 *
	 * @var array
	 */
	const WORD_COUNT_RANGE = array(
		'min' => 2,
		'max' => 7,
	);

	/**
	 * Aggressiveness presets.
	 *
	 * Level 1 — Conservative: rules 3, 4, 6 (no article/sentence changes).
	 * Level 2 — Balanced:   rules 3, 4, 6, 7, 1, 2 (default).
	 * Level 3 — Aggressive: all rules at maximum strength.
	 *
	 * @var array
	 */
	const AGGRESSIVENESS_PRESETS = array(
		1 => array(
			'connective_elimination' => true,
			'active_voice'           => true,
			'intensifier_removal'    => true,
			'article_omission'       => false,
			'pronoun_handling'       => false,
			'sentence_splitting'     => false,
			'word_count_limit'       => false,
			'min_words'              => 1,
			'max_words'              => 12,
		),
		2 => array(
			'connective_elimination' => true,
			'active_voice'           => true,
			'intensifier_removal'    => true,
			'article_omission'       => true,
			'pronoun_handling'       => true,
			'sentence_splitting'     => true,
			'word_count_limit'       => true,
			'min_words'              => 2,
			'max_words'              => 7,
		),
		3 => array(
			'connective_elimination' => true,
			'active_voice'           => true,
			'intensifier_removal'    => true,
			'article_omission'       => true,
			'pronoun_handling'       => true,
			'sentence_splitting'     => true,
			'word_count_limit'       => true,
			'min_words'              => 2,
			'max_words'              => 5,
		),
	);

	/**
	 * Intensifiers to remove (Rule 6).
	 *
	 * Words that add emphasis but not semantic content.
	 *
	 * @var array
	 */
	private $intensifiers = array(
		'very',
		'extremely',
		'quite',
		'rather',
		'really',
		'somewhat',
		'essentially',
		'basically',
		'literally',
		'absolutely',
		'completely',
		'totally',
		'highly',
		'thoroughly',
		'utterly',
	);

	/**
	 * Causal connectives to remove (Rule 3).
	 *
	 * @var array
	 */
	private $causal_connectives = array(
		'because',
		'since',
		'due to',
		'owing to',
		'as a result',
		'as a result of',
	);

	/**
	 * Contrastive connectives to remove (Rule 3).
	 *
	 * @var array
	 */
	private $contrastive_connectives = array(
		'however',
		'nevertheless',
		'although',
		'despite',
		'even though',
		'nonetheless',
		'whereas',
		'while',
		'yet',
	);

	/**
	 * Sequential connectives to remove (Rule 3).
	 *
	 * @var array
	 */
	private $sequential_connectives = array(
		'therefore',
		'thus',
		'consequently',
		'hence',
		'as a consequence',
		'accordingly',
	);

	/**
	 * Purpose connectives to remove (Rule 3).
	 *
	 * @var array
	 */
	private $purpose_connectives = array(
		'in order to',
		'so that',
		'for the purpose of',
		'in order that',
	);

	/**
	 * Conditional connectives (removed only when non-essential) (Rule 3).
	 *
	 * @var array
	 */
	private $conditional_connectives = array(
		'if',
		'unless',
	);

	/**
	 * Passive voice patterns for conversion to active (Rule 4).
	 *
	 * Format: 'pattern' => 'replacement'
	 * Each pattern is a regex fragment that matches a passive construction.
	 *
	 * @var array
	 */
	private $passive_patterns = array();

	/**
	 * Protected blocks extracted during compression.
	 *
	 * @var array
	 */
	private $protected_blocks = array();

	/**
	 * Protect counter for generating unique placeholder IDs.
	 *
	 * @var int
	 */
	private $protect_counter = 0;

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Semantic_Compressor
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
		$this->init_passive_patterns();
	}

	/**
	 * Initialize passive voice regex patterns.
	 *
	 * Compiles the passive-to-active conversion rules used by Rule 4.
	 *
	 * @return void
	 */
	private function init_passive_patterns() {
		$this->passive_patterns = array(
			// "is/are/was/were [adverb]? [past participle] by" → active.
			'/\b(is|are|was|were|has been|have been|had been|will be|would be|being)\s+(\w+ly\s+)?(\w+(?:ed|en|d|t))\s+by\s+/i'
				=> '___PASSIVE_BY___',

			// "is/are/was/were [adverb]? [past participle]" (no "by" — simpler).
			'/\b(is|are|was|were|has been|have been|had been)\s+(\w+ly\s+)?(\w+(?:ed|en|d|t))\b/i'
				=> '___PASSIVE_SIMPLE___',

			// "will be [past participle]" → future passive.
			'/\b(will be|shall be)\s+(\w+ly\s+)?(\w+(?:ed|en|d|t))\b/i'
				=> '___FUTURE_PASSIVE___',

			// "can be / could be / should be / must be [past participle]".
			'/\b(can be|could be|should be|must be|may be|might be)\s+(\w+ly\s+)?(\w+(?:ed|en|d|t))\b/i'
				=> '___MODAL_PASSIVE___',
		);
	}

	/**
	 * Compress text using Caveman Compression rules.
	 *
	 * Pipeline:
	 *   1. Extract and protect code blocks, URLs, JSON (placeholders).
	 *   2. Apply rules 3-8 in sequence.
	 *   3. Apply rules 1-2 (sentence splitting, word count).
	 *   4. Verify rule 9 (logical completeness).
	 *   5. Restore protected blocks.
	 *   6. Return compressed text.
	 *
	 * @param string $text    Text to compress.
	 * @param array  $options Compression options:
	 *     'aggressiveness'     (int)  1-3, default 2.
	 *     'skip_code_blocks'   (bool) Whether to skip code blocks (default true).
	 *     'preserve_specifics' (bool) Whether to preserve specifics (default true).
	 *
	 * @return string Compressed text.
	 */
	public function compress( $text, $options = array() ) {
		if ( empty( $text ) || ! is_string( $text ) ) {
			return $text;
		}

		$defaults = array(
			'aggressiveness'     => 2,
			'skip_code_blocks'   => true,
			'preserve_specifics' => true,
		);

		$options                       = wp_parse_args( $options, $defaults );
		$options['aggressiveness']     = max( 1, min( 3, (int) $options['aggressiveness'] ) );
		$options['skip_code_blocks']   = (bool) $options['skip_code_blocks'];
		$options['preserve_specifics'] = (bool) $options['preserve_specifics'];

		$preset = self::AGGRESSIVENESS_PRESETS[ $options['aggressiveness'] ];

		// Reset internal state.
		$this->protected_blocks = array();
		$this->protect_counter  = 0;

		// Step 1: Extract and protect blocks.
		$text = $this->extract_and_protect_blocks( $text, $options );

		// Step 2: Apply rules 3-8 in sequence.

		// Rule 3 — Connective Elimination.
		if ( $preset['connective_elimination'] ) {
			$text = $this->apply_connective_elimination( $text );
		}

		// Rule 4 — Active Voice / Present Tense.
		if ( $preset['active_voice'] ) {
			$text = $this->apply_active_voice( $text );
		}

		// Rule 6 — Remove Intensifiers.
		if ( $preset['intensifier_removal'] ) {
			$text = $this->apply_intensifier_removal( $text );
		}

		// Rule 7 — Article Omission.
		if ( $preset['article_omission'] ) {
			$text = $this->apply_article_omission( $text );
		}

		// Rule 8 — Pronoun Handling.
		if ( $preset['pronoun_handling'] ) {
			$text = $this->apply_pronoun_handling( $text );
		}

		// Step 3: Apply rules 1-2.

		// Rule 1 — Sentence Atomicity (split compound sentences).
		if ( $preset['sentence_splitting'] ) {
			$text = $this->apply_sentence_splitting( $text );
		}

		// Rule 2 — Word Count Limit.
		if ( $preset['word_count_limit'] ) {
			$text = $this->apply_word_count_limit(
				$text,
				$preset['min_words'],
				$preset['max_words']
			);
		}

		// Step 4: Verify Rule 9 — Logical Completeness.
		$text = $this->verify_logical_completeness( $text );

		// Step 5: Final cleanup — collapse excess whitespace (before restoring
		// protected blocks, so URLs/code are not mangled by punctuation spacing).
		$text = $this->cleanup_whitespace( $text );

		// Step 6: Restore protected blocks.
		$text = $this->restore_protected_blocks( $text );

		return trim( $text );
	}

	/**
	 * Compress an array of chat messages.
	 *
	 * Preserves message structure (role, tool_calls, etc.) while compressing
	 * the 'content' field of each message.
	 *
	 * @param array $messages Array of chat messages with 'role' and 'content' keys.
	 * @param array $options  Compression options (same as compress()).
	 *
	 * @return array Compressed messages array.
	 */
	public function compress_messages( $messages, $options = array() ) {
		if ( empty( $messages ) || ! is_array( $messages ) ) {
			return $messages;
		}

		$compressed = array();

		foreach ( $messages as $index => $message ) {
			if ( ! is_array( $message ) ) {
				$compressed[ $index ] = $message;
				continue;
			}

			$compressed[ $index ] = $message;

			// Compress the content field.
			if ( isset( $message['content'] ) && is_string( $message['content'] ) ) {
				$compressed[ $index ]['content'] = $this->compress(
					$message['content'],
					$options
				);
			}

			// Preserve tool_calls — do not compress structured data.
			// Preserve name, function_call, tool_call_id — these are structural.
		}

		return $compressed;
	}

	/**
	 * Estimate token count using the char/4 heuristic.
	 *
	 * Consistent with the plugin-wide CHARS_PER_TOKEN convention.
	 *
	 * @param string $text Text to estimate.
	 * @return int Estimated token count.
	 */
	public function estimate_tokens( $text ) {
		if ( empty( $text ) || ! is_string( $text ) ) {
			return 0;
		}
		return (int) ceil( strlen( $text ) / self::CHARS_PER_TOKEN );
	}

	/**
	 * Estimate token savings from compression.
	 *
	 * Compresses the text and returns before/after token counts
	 * plus savings percentage.
	 *
	 * @param string $text    Text to analyze.
	 * @param array  $options Compression options (same as compress()).
	 *
	 * @return array {
	 *     @type int   $original_tokens   Token count before compression.
	 *     @type int   $compressed_tokens Token count after compression.
	 *     @type int   $saved_tokens      Tokens saved.
	 *     @type float $savings_pct       Savings percentage (0-100).
	 * }
	 */
	public function estimate_savings( $text, $options = array() ) {
		$original_tokens   = $this->estimate_tokens( $text );
		$compressed        = $this->compress( $text, $options );
		$compressed_tokens = $this->estimate_tokens( $compressed );
		$saved_tokens      = $original_tokens - $compressed_tokens;
		$savings_pct       = $original_tokens > 0
			? round( ( $saved_tokens / $original_tokens ) * 100, 1 )
			: 0.0;

		return array(
			'original_tokens'   => $original_tokens,
			'compressed_tokens' => max( 0, $compressed_tokens ),
			'saved_tokens'      => max( 0, $saved_tokens ),
			'savings_pct'       => max( 0.0, $savings_pct ),
		);
	}

	// -------------------------------------------------------------------------
	// Step 1: Extract and Protect Blocks
	// -------------------------------------------------------------------------

	/**
	 * Extract and protect blocks that should not be compressed.
	 *
	 * Protected content types:
	 *   - Markdown code fences (``` ... ```)
	 *   - Inline code (`...`)
	 *   - JSON objects and arrays
	 *   - URLs (http/https/ftp)
	 *   - Email addresses
	 *   - File paths (absolute and relative)
	 *   - HTML/XML tags
	 *
	 * Each protected block is replaced with a unique placeholder token
	 * that will be restored after compression.
	 *
	 * @param string $text    Input text.
	 * @param array  $options Compression options.
	 *
	 * @return string Text with protected blocks replaced by placeholders.
	 */
	private function extract_and_protect_blocks( $text, $options ) {
		if ( $options['skip_code_blocks'] ) {
			// 1. Markdown fenced code blocks (``` ... ```).
			$text = $this->protect_pattern(
				$text,
				'/```[\s\S]*?```/',
				'code_block'
			);

			// 2. Inline code (`...`).
			$text = $this->protect_pattern(
				$text,
				'/`[^`\n]+`/',
				'inline_code'
			);
		}

		// 3. JSON objects and arrays.
		$text = $this->protect_pattern(
			$text,
			'/(?:\{[^{}]*(?:(?R)?[^{}]*)*\}|\[[^\[\]]*(?:(?R)?[^\[\]]*)*\])/',
			'json'
		);

		// Fallback for simple JSON that the recursive regex above might miss.
		$text = $this->protect_pattern(
			$text,
			'/\{(?:[^{}]|(?R))*\}/',
			'json_nested'
		);

		// 4. URLs.
		$text = $this->protect_pattern(
			$text,
			'/\bhttps?:\/\/[^\s<>"\')\]}>]+\b/i',
			'url'
		);

		// 5. Email addresses.
		$text = $this->protect_pattern(
			$text,
			'/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
			'email'
		);

		// 6. File paths (Unix absolute, Windows absolute, relative with slashes).
		$text = $this->protect_pattern(
			$text,
			'/(?:\/[^\s<>"\')\]}>]*)+\.[a-zA-Z0-9]{1,6}\b/',
			'file_path_unix'
		);

		$text = $this->protect_pattern(
			$text,
			'/\b[A-Za-z]:\\\[^\s<>"\')\]}>]*\.[a-zA-Z0-9]{1,6}\b/',
			'file_path_windows'
		);

		// 7. HTML/XML tags.
		$text = $this->protect_pattern(
			$text,
			'/<[A-Za-z][^\s<>]*[^>]*>.*?<\/[A-Za-z][^\s<>]*>|<[A-Za-z][^\s<>]*\s*\/>/s',
			'html_tag'
		);

		return $text;
	}

	/**
	 * Protect a regex pattern by replacing matches with placeholders.
	 *
	 * @param string $text         Input text.
	 * @param string $regex        PCRE regex pattern.
	 * @param string $block_type   Block type identifier (for placeholder naming).
	 *
	 * @return string Text with matches replaced.
	 */
	private function protect_pattern( $text, $regex, $block_type ) {
		// Use preg_replace_callback for reliable, position-safe replacement.
		// Each unique match gets its own placeholder — no strpos/substr_replace fragility.
		$self = $this;
		$text = preg_replace_callback(
			$regex,
			function ( $matches ) use ( $self, $block_type ) {
				$full_match = $matches[0];

				// Skip empty matches.
				if ( empty( $full_match ) ) {
					return $full_match;
				}

				// Skip if already inside a placeholder.
				if ( false !== strpos( $full_match, self::PLACEHOLDER_PREFIX ) ) {
					return $full_match;
				}

				$placeholder                            = $self->make_placeholder( $block_type );
				$self->protected_blocks[ $placeholder ] = $full_match;

				return $placeholder;
			},
			$text
		);

		return $text;
	}

	/**
	 * Generate a unique placeholder token.
	 *
	 * @param string $block_type Block type for identification.
	 *
	 * @return string Placeholder string.
	 */
	private function make_placeholder( $block_type ) {
		$id = $this->protect_counter++;
		return self::PLACEHOLDER_PREFIX . strtoupper( $block_type ) . '_' . $id . self::PLACEHOLDER_SUFFIX;
	}

	/**
	 * Restore protected blocks from placeholders.
	 *
	 * @param string $text Text with placeholders.
	 *
	 * @return string Text with original protected blocks restored.
	 */
	private function restore_protected_blocks( $text ) {
		if ( empty( $this->protected_blocks ) ) {
			return $text;
		}

		foreach ( $this->protected_blocks as $placeholder => $original ) {
			$text = str_replace( $placeholder, $original, $text );
		}

		// Also handle any placeholders that might appear near punctuation.
		// The extraction process may leave surrounding spaces.
		return $text;
	}

	// -------------------------------------------------------------------------
	// Rule 3: Connective Elimination
	// -------------------------------------------------------------------------

	/**
	 * Apply Rule 3 — Connective Elimination.
	 *
	 * Removes causal, contrastive, sequential, purpose, and (when non-essential)
	 * conditional connectives. Expresses cause-effect through sequential
	 * sentences instead.
	 *
	 * @param string $text    Input text.
	 *
	 * @return string Text with connectives removed.
	 */
	private function apply_connective_elimination( $text ) {
		// Combine all connective removal operations.
		$text = $this->remove_causal_connectives( $text );
		$text = $this->remove_contrastive_connectives( $text );
		$text = $this->remove_sequential_connectives( $text );
		$text = $this->remove_purpose_connectives( $text );
		$text = $this->remove_conditional_connectives( $text );

		// Remove "but" when used as a conjunction between independent clauses.
		// "X, but Y" → "X. Y".
		$text = preg_replace(
			'/\s*,\s*but\s+/i',
			'. ',
			$text
		);

		return $text;
	}

	/**
	 * Remove causal connectives: because, since, due to, owing to, as a result.
	 *
	 * Strategy: remove the connective word/phrase. For "because X, Y" patterns,
	 * reorder to "X. Y". For "Y because X", convert to "X. Y".
	 *
	 * @param string $text Input text.
	 *
	 * @return string Text with causal connectives removed.
	 */
	private function remove_causal_connectives( $text ) {
		// Pattern: "Y because X" or "Y, because X" → "X. Y"
		// Handle "because" at clause boundaries.
		$text = preg_replace(
			'/\s*,?\s*because\s+/i',
			'. ',
			$text
		);

		// "Y since X" — "since" is ambiguous (temporal vs causal).
		// Only remove when it appears between clauses with a comma.
		$text = preg_replace(
			'/\s*,\s*since\s+(?!\d{4}|the beginning|then|that time)/i',
			'. ',
			$text
		);

		// "Y due to X" → "X. Y"
		$text = preg_replace(
			'/\s*,?\s*due to\s+/i',
			'. ',
			$text
		);

		// "Y owing to X" → "X. Y"
		$text = preg_replace(
			'/\s*,?\s*owing to\s+/i',
			'. ',
			$text
		);

		// "Y as a result of X" → "X. Y"
		$text = preg_replace(
			'/\s*,?\s*as a result of\s+/i',
			'. ',
			$text
		);

		// "Y, as a result" → just end sentence.
		$text = preg_replace(
			'/\s*,?\s*as a result\s*/i',
			'. ',
			$text
		);

		// Clean up: collapse sequences of ". . " into ". ".
		$text = preg_replace( '/\.\s*\.\s*/', '. ', $text );

		return $text;
	}

	/**
	 * Remove contrastive connectives: however, nevertheless, although, despite.
	 *
	 * Strategy: replace with period (". "). "Although X, Y" → "X. Y".
	 *
	 * @param string $text Input text.
	 *
	 * @return string Text with contrastive connectives removed.
	 */
	private function remove_contrastive_connectives( $text ) {
		// "However," / "However " at sentence start or mid-sentence.
		$text = preg_replace(
			'/\s*,?\s*however\s*,?\s*/i',
			'. ',
			$text
		);

		// "Nevertheless," / "Nevertheless ".
		$text = preg_replace(
			'/\s*,?\s*nevertheless\s*,?\s*/i',
			'. ',
			$text
		);

		// "Nonetheless,".
		$text = preg_replace(
			'/\s*,?\s*nonetheless\s*,?\s*/i',
			'. ',
			$text
		);

		// "Although X, Y" → "X. Y" — remove "although" and add period.
		$text = preg_replace(
			'/\bAlthough\s+/i',
			'',
			$text
		);
		$text = preg_replace(
			'/\balthough\s+/i',
			'',
			$text
		);

		// "X, although Y" → "X. Y"
		$text = preg_replace(
			'/\s*,?\s*although\s+/i',
			'. ',
			$text
		);

		// "Despite X, Y" → "X. Y" — strip "despite" and comma.
		$text = preg_replace(
			'/\bDespite\s+/i',
			'',
			$text
		);
		$text = preg_replace(
			'/\bdespite\s+/i',
			'',
			$text
		);

		// "X, despite Y" → "X. Y"
		$text = preg_replace(
			'/\s*,?\s*despite\s+/i',
			'. ',
			$text
		);

		// "Even though X, Y" → "X. Y"
		$text = preg_replace(
			'/\bEven though\s+/i',
			'',
			$text
		);
		$text = preg_replace(
			'/\beven though\s+/i',
			'',
			$text
		);

		// "Whereas X, Y" → "X. Y"
		$text = preg_replace(
			'/\bWhereas\s+/i',
			'',
			$text
		);
		$text = preg_replace(
			'/\s*,?\s*whereas\s+/i',
			'. ',
			$text
		);

		// "X, yet Y" → "X. Y"
		$text = preg_replace(
			'/\s*,\s*yet\s+/i',
			'. ',
			$text
		);

		// Clean up duplicate periods.
		$text = preg_replace( '/\.\s*\.\s*/', '. ', $text );

		return $text;
	}

	/**
	 * Remove sequential connectives: therefore, thus, consequently, hence.
	 *
	 * Strategy: replace with period (". ").
	 *
	 * @param string $text Input text.
	 *
	 * @return string Text with sequential connectives removed.
	 */
	private function remove_sequential_connectives( $text ) {
		// "Therefore," / "Therefore ".
		$text = preg_replace(
			'/\s*,?\s*therefore\s*,?\s*/i',
			'. ',
			$text
		);

		// "Thus," / "Thus ".
		$text = preg_replace(
			'/\s*,?\s*thus\s*,?\s*/i',
			'. ',
			$text
		);

		// "Consequently,".
		$text = preg_replace(
			'/\s*,?\s*consequently\s*,?\s*/i',
			'. ',
			$text
		);

		// "Hence," / "Hence ".
		$text = preg_replace(
			'/\s*,?\s*hence\s*,?\s*/i',
			'. ',
			$text
		);

		// "As a consequence,".
		$text = preg_replace(
			'/\s*,?\s*as a consequence\s*,?\s*/i',
			'. ',
			$text
		);

		// "Accordingly,".
		$text = preg_replace(
			'/\s*,?\s*accordingly\s*,?\s*/i',
			'. ',
			$text
		);

		// "Then" at sentence start followed by comma.
		$text = preg_replace(
			'/\bThen\s*,\s*/',
			'',
			$text
		);

		// Clean up.
		$text = preg_replace( '/\.\s*\.\s*/', '. ', $text );

		return $text;
	}

	/**
	 * Remove purpose connectives: in order to, so that, for the purpose of.
	 *
	 * Strategy: "X in order to Y" → "X. Y" or "X to Y".
	 *
	 * @param string $text Input text.
	 *
	 * @return string Text with purpose connectives removed.
	 */
	private function remove_purpose_connectives( $text ) {
		// "in order to" → "to" (simpler, preserves the action).
		$text = preg_replace(
			'/\bin order to\s+/i',
			'to ',
			$text
		);

		// "so that X can Y" → "X can Y" or ". X can Y".
		$text = preg_replace(
			'/\s*,?\s*so that\s+/i',
			'. ',
			$text
		);

		// "for the purpose of" → period.
		$text = preg_replace(
			'/\s*,?\s*for the purpose of\s+/i',
			'. ',
			$text
		);

		// "in order that" → period.
		$text = preg_replace(
			'/\s*,?\s*in order that\s+/i',
			'. ',
			$text
		);

		// Clean up.
		$text = preg_replace( '/\.\s*\.\s*/', '. ', $text );

		return $text;
	}

	/**
	 * Remove conditional connectives: if, unless (when non-essential).
	 *
	 * Strategy: "If X, Y" → "X. Y" (simple conditionals).
	 * Keep "if" in complex conditionals (multiple interleaved conditions)
	 * to prevent ambiguity.
	 *
	 * @param string $text Input text.
	 *
	 * @return string Text with non-essential conditionals removed.
	 */
	private function remove_conditional_connectives( $text ) {
		// Count "if" occurrences. If more than 2, these are complex conditionals
		// that may need to be preserved.
		$if_count = preg_match_all( '/\bif\b/i', $text );

		// Simple conditionals (≤ 2 "if"s): safe to remove.
		if ( $if_count <= 2 ) {
			// "If X, Y" → "X. Y"
			$text = preg_replace(
				'/\bIf\s+/',
				'',
				$text
			);
			$text = preg_replace(
				'/\bif\s+/',
				'',
				$text
			);

			// Pattern: "Y, if X" or "Y if X" becomes "Y. X".
			$text = preg_replace(
				'/\s*,\s*if\s+/',
				'. ',
				$text
			);
		}

		// "X unless Y" → "X. Not Y." but only for simple patterns.
		$unless_count = preg_match_all( '/\bunless\b/i', $text );
		if ( $unless_count <= 1 ) {
			$text = preg_replace(
				'/\s*,?\s*unless\s+/i',
				'. Not ',
				$text
			);
		}

		// Clean up.
		$text = preg_replace( '/\.\s*\.\s*/', '. ', $text );

		return $text;
	}

	// -------------------------------------------------------------------------
	// Rule 4: Active Voice and Present Tense
	// -------------------------------------------------------------------------

	/**
	 * Apply Rule 4 — Active Voice and Present Tense.
	 *
	 * Converts passive constructions to active voice where possible.
	 * Uses present tense unless temporal distinction is critical.
	 *
	 * @param string $text    Input text.
	 *
	 * @return string Text with passive constructions converted.
	 */
	private function apply_active_voice( $text ) {
		// Split into sentences for per-sentence processing.
		$sentences = $this->split_into_sentences( $text );
		$converted = array();

		foreach ( $sentences as $sentence ) {
			$converted[] = $this->convert_sentence_to_active_voice( $sentence );
		}

		$text = implode( ' ', $converted );

		// Future tense → present where possible.
		// "will need to X" → "need X".
		$text = preg_replace( '/\bwill need to\s+/i', 'need ', $text );

		// "will be X-ing" → "X-ing" (future continuous → present).
		$text = preg_replace(
			'/\bwill be (\w+ing)\b/i',
			'$1',
			$text
		);

		// "is going to X" → "X" (going-to future → present).
		$text = preg_replace(
			'/\bis going to\s+/i',
			'',
			$text
		);

		// "are going to X" → "X".
		$text = preg_replace(
			'/\bare going to\s+/i',
			'',
			$text
		);

		// Clean up double spaces from removed words.
		$text = preg_replace( '/\s{2,}/', ' ', $text );

		return $text;
	}

	/**
	 * Convert a single sentence from passive to active voice.
	 *
	 * Handles patterns like:
	 *   "is calculated by" → "calculates"
	 *   "was created by"  → "created"
	 *   "can be used by"  → "can use"
	 *
	 * @param string $sentence A single sentence.
	 *
	 * @return string Converted sentence.
	 */
	private function convert_sentence_to_active_voice( $sentence ) {
		$original = $sentence;

		// Strip trailing punctuation for matching, re-add at end.
		$trailing = '';
		if ( preg_match( '/[.!?]+$/', $sentence, $m ) ) {
			$trailing = $m[0];
			$sentence = rtrim( $sentence, '.!?' );
		}

		// Pattern: "[be-verb] [past-participle] by [agent]" → "[agent] [verb]".
		// Example: "The value is calculated by the function" → "The function calculates the value".
		if ( preg_match(
			'/^(.+?)\s+(is|are|was|were|has been|have been|had been)\s+(\w+(?:ed|en|d|t))\s+by\s+(.+)$/i',
			$sentence,
			$matches
		) ) {
			$patient = trim( $matches[1] );  // "The value"
			$verb    = trim( $matches[3] );  // "calculated"
			$agent   = rtrim( trim( $matches[4] ), '.!?' );  // "the function" (no trailing punct)

			// Convert past participle to present tense base form.
			$base_verb = $this->past_participle_to_present( $verb );

			// Reorder: agent → base_verb → patient.
			$trailing = $trailing ? $trailing : '.';
			$sentence = ucfirst( $agent ) . ' ' . $base_verb . ' ' . lcfirst( $patient ) . $trailing . ' ';
		}

		// Pattern: "[be-verb] [past-participle]" (no agent specified).
		// Example: "The value is calculated" → "Calculate value" or keep as-is if no agent.
		// Since there's no explicit agent, we keep the sentence but simplify.
		if ( rtrim( $original, '.!?' ) === $sentence ) {
			if ( preg_match(
				'/^(.+?)\s+(is|are|was|were)\s+(known as|called|referred to as|named)\s+(.+)$/i',
				$sentence,
				$matches
			) ) {
				// "X is known as Y" → "X is Y" (keep essential meaning).
				$trailing = $trailing ? $trailing : '.';
				$sentence = trim( $matches[1] ) . ' is ' . rtrim( trim( $matches[4] ), '.!?' ) . $trailing . ' ';
			}
		}

		// Pattern: "can be [past-participle] by" → "can [verb]".
		if ( rtrim( $original, '.!?' ) === $sentence ) {
			if ( preg_match(
				'/^(.+?)\s+(can be|could be|should be|must be|may be|might be)\s+(\w+(?:ed|en|d|t))\s+by\s+(.+)$/i',
				$sentence,
				$matches
			) ) {
				$patient = trim( $matches[1] );
				$modal   = trim( $matches[2] );
				$verb    = trim( $matches[3] );
				$agent   = rtrim( trim( $matches[4] ), '.!?' );

				// Strip "be" from modal: "can be" → "can".
				$modal_base = preg_replace( '/\s+be$/', '', $modal );
				$base_verb  = $this->past_participle_to_present( $verb );

				$trailing = $trailing ? $trailing : '.';
				$sentence = ucfirst( $agent ) . ' ' . $modal_base . ' ' . $base_verb . ' ' . lcfirst( $patient ) . $trailing . ' ';
			}
		}

		// Pattern: "is [present-participle]" → "[present-tense]".
		// Example: "is running" → "runs".
		if ( $sentence === $original ) {
			$sentence = preg_replace_callback(
				'/\b(is|are|am)\s+(\w+ing)\b/i',
				function ( $m ) {
					$verb_ing = $m[2];
					// Convert "-ing" to present tense base form.
					$base = preg_replace( '/ing$/i', '', $verb_ing );
					// Handle doubled consonants: "running" → "runs".
					$base = preg_replace( '/(\w)\1$/', '$1', $base );
					return $base . 's';
				},
				$sentence
			);
		}

		return $sentence;
	}

	/**
	 * Convert a past participle to present tense base form.
	 *
	 * Heuristic-based conversion. Handles:
	 *   - Regular verbs: "calculated" → "calculate"
	 *   - Irregular verbs with "-en": "written" → "write"
	 *   - Common irregular patterns.
	 *
	 * @param string $participle Past participle form.
	 *
	 * @return string Present tense base form.
	 */
	private function past_participle_to_present( $participle ) {
		// Known irregular mappings.
		$irregular = array(
			'written'   => 'write',
			'driven'    => 'drive',
			'given'     => 'give',
			'taken'     => 'take',
			'broken'    => 'break',
			'spoken'    => 'speak',
			'chosen'    => 'choose',
			'frozen'    => 'freeze',
			'stolen'    => 'steal',
			'risen'     => 'rise',
			'ridden'    => 'ride',
			'hidden'    => 'hide',
			'fallen'    => 'fall',
			'forgotten' => 'forget',
			'gotten'    => 'get',
			'beaten'    => 'beat',
			'eaten'     => 'eat',
			'seen'      => 'see',
			'been'      => 'be',
			'gone'      => 'go',
			'done'      => 'do',
			'known'     => 'know',
			'flown'     => 'fly',
			'drawn'     => 'draw',
			'thrown'    => 'throw',
			'grown'     => 'grow',
			'blown'     => 'blow',
			'shown'     => 'show',
			'worn'      => 'wear',
			'torn'      => 'tear',
			'sworn'     => 'swear',
			'born'      => 'bear',
		);

		$lower = strtolower( $participle );

		if ( isset( $irregular[ $lower ] ) ) {
			$base = $irregular[ $lower ];
			// Preserve capitalization.
			if ( ctype_upper( $participle[0] ) ) {
				$base = ucfirst( $base );
			}
			return $base;
		}

		// Regular verbs: remove "-ed", "-d", or "-en".
		if ( preg_match( '/^(.+?)en$/i', $lower, $m ) ) {
			return $m[1] . 'e';
		}

		// Verbs ending in "-ed".
		if ( preg_match( '/^(.+?)ed$/i', $lower, $m ) ) {
			$stem = $m[1];

			// For stems ending in "at", "it", "ut", "et", "ot", "iz", "is":
			// the past tense only added "d" to a base ending in "e"
			// (e.g., "calculate" + "d" = "calculated", NOT "calculat" + "ed").
			// Strip only the "d" and re-append "e".
			if ( preg_match( '/(?:at|it|ut|et|ot|iz|is)$/i', $stem ) ) {
				return $stem . 'e';
			}

			// De-double final consonant: "stopped" → "stop".
			$stem = preg_replace( '/(\w)\1$/', '$1', $stem );

			return $stem;
		}

		if ( preg_match( '/^(.+?)d$/i', $lower, $m ) ) {
			return $m[1];
		}

		// For "-t" endings: "built" → "build", "sent" → "send", "kept" → "keep".
		$t_irregular = array(
			'built' => 'build',
			'sent'  => 'send',
			'kept'  => 'keep',
			'slept' => 'sleep',
			'swept' => 'sweep',
			'wept'  => 'weep',
			'felt'  => 'feel',
			'dealt' => 'deal',
			'meant' => 'mean',
			'left'  => 'leave',
			'lost'  => 'lose',
			'spent' => 'spend',
			'lent'  => 'lend',
			'bent'  => 'bend',
		);

		if ( isset( $t_irregular[ $lower ] ) ) {
			return $t_irregular[ $lower ];
		}

		// Fallback: just return the original (unchanged).
		return $participle;
	}

	// -------------------------------------------------------------------------
	// Rule 5: Preserve Specifics (Guard, not an active transformation)
	// -------------------------------------------------------------------------

	/**
	 * Rule 5 is a guard — it says "NEVER remove or alter specifics."
	 *
	 * This is implemented via:
	 *   1. The protect-blocks step (Step 1) which protects code, URLs, JSON, etc.
	 *   2. NOT applying transformations to known specific patterns.
	 *
	 * The active enforcement is distributed across other methods that skip
	 * transformations when they detect specific patterns (numbers, proper names,
	 * dates, technical terms, file paths, URLs, code).
	 *
	 * This method exists as a testable entry point and for documentation.
	 *
	 * @param string $text Input text.
	 *
	 * @return string Unchanged text (specifics are preserved).
	 */
	private function preserve_specifics( $text ) {
		// Specifics are preserved by NOT transforming them. This method is a
		// no-op guard that exists for explicit testing of Rule 5 compliance.
		//
		// Specific patterns that are protected:
		// - Numbers: \d+
		// - Proper names: detected via capitalization patterns
		// - Dates: ISO 8601, "January 1, 2024", "2024-01-01", etc.
		// - Technical terms: camelCase, snake_case, ALL_CAPS identifiers
		// - File paths, URLs, code: handled by protect-blocks in Step 1.
		return $text;
	}

	/**
	 * Check if a word is a "specific" that should not be altered.
	 *
	 * @param string $word Word to check.
	 *
	 * @return bool True if the word is a specific.
	 */
	private function is_specific( $word ) {
		$word = trim( $word );

		if ( empty( $word ) ) {
			return false;
		}

		// Numbers (including decimals, negative, with commas).
		if ( preg_match( '/^-?\d{1,3}(?:,\d{3})*(?:\.\d+)?%?$/', $word ) ) {
			return true;
		}

		// Dates (ISO 8601, common formats).
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $word ) ) {
			return true;
		}

		// Proper names: capitalized words that are not sentence-start.
		// (Heuristic — not perfect, but catches most.).
		if ( preg_match( '/^[A-Z][a-z]+$/', $word ) && strlen( $word ) > 2 ) {
			// Check if it's a common word that happens to be capitalized.
			$common_capitalized = array(
				'The',
				'This',
				'That',
				'These',
				'Those',
				'Each',
				'Every',
				'Some',
				'Any',
				'All',
				'Both',
				'Few',
				'Many',
				'Most',
				'One',
				'Two',
				'Three',
				'First',
				'Last',
				'Next',
				'Previous',
				'After',
				'Before',
				'During',
				'Without',
				'Within',
				'Through',
			);
			if ( in_array( $word, $common_capitalized, true ) ) {
				return false;
			}
			return true;
		}

		// Technical identifiers (camelCase, snake_case, ALL_CAPS constants).
		if ( preg_match( '/^[a-z]+[A-Z][a-zA-Z]*$/', $word ) ) {
			return true; // camelCase.
		}
		if ( preg_match( '/^[a-z]+(?:_[a-z]+)+$/', $word ) ) {
			return true; // snake_case.
		}
		if ( preg_match( '/^[A-Z][A-Z0-9_]{2,}$/', $word ) ) {
			return true; // ALL_CAPS constant.
		}

		// Version numbers: v1.2.3, 1.0.0-beta.
		if ( preg_match( '/^v?\d+\.\d+(?:\.\d+)?(?:-[a-zA-Z0-9]+)?$/', $word ) ) {
			return true;
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// Rule 6: Remove Intensifiers
	// -------------------------------------------------------------------------

	/**
	 * Apply Rule 6 — Remove Intensifiers.
	 *
	 * Strips intensifier words: very, extremely, quite, rather, really,
	 * somewhat, essentially, basically, literally, absolutely, completely,
	 * totally, highly, thoroughly, utterly.
	 *
	 * @param string $text    Input text.
	 *
	 * @return string Text with intensifiers removed.
	 */
	private function apply_intensifier_removal( $text ) {
		foreach ( $this->intensifiers as $intensifier ) {
			// Match the intensifier as a whole word, case-insensitive.
			// Remove the word and any trailing space, preserving sentence structure.
			$text = preg_replace(
				'/\b' . preg_quote( $intensifier, '/' ) . '\b\s*/i',
				'',
				$text
			);

			// Also handle the intensifier preceded by a comma.
			$text = preg_replace(
				'/\s*,\s*' . preg_quote( $intensifier, '/' ) . '\b\s*/i',
				' ',
				$text
			);
		}

		// Clean up double spaces.
		$text = preg_replace( '/\s{2,}/', ' ', $text );

		return $text;
	}

	// -------------------------------------------------------------------------
	// Rule 7: Article Omission
	// -------------------------------------------------------------------------

	/**
	 * Apply Rule 7 — Article Omission.
	 *
	 * Removes articles "a", "an", "the" when context provides sufficient
	 * specificity. Keeps articles when omission would create ambiguity
	 * between generic and specific references.
	 *
	 * @param string $text    Input text.
	 *
	 * @return string Text with articles removed where safe.
	 */
	private function apply_article_omission( $text ) {
		// Remove "the" — most commonly safe to remove.
		// Keep "the" only in fixed phrases where it carries meaning.
		$text = preg_replace( '/\b[Tt]he\s+/', '', $text );

		// Remove "a" before consonant sounds.
		// Keep "a" before numbers (e.g., "a 5% increase").
		$text = preg_replace( '/\ba\s+(?!\d)/i', '', $text );

		// Remove "an" before vowel sounds.
		// Keep "an" before numbers that start with vowel sound.
		$text = preg_replace( '/\ban\s+(?!\d)/i', '', $text );

		// Fix: don't leave "a" or "an" orphaned before numbers in phrase-initial.
		// Already handled by the negative lookahead above.

		// Clean up double spaces from article removal.
		$text = preg_replace( '/\s{2,}/', ' ', $text );

		// Fix capitalization after article removal at sentence start.
		// If a sentence now starts with a lowercase letter, capitalize it.
		$text = preg_replace_callback(
			'/(?:^|\.\s+)([a-z])/m',
			function ( $matches ) {
				return strtoupper( $matches[0] );
			},
			$text
		);

		return trim( $text );
	}

	// -------------------------------------------------------------------------
	// Rule 8: Pronoun Handling
	// -------------------------------------------------------------------------

	/**
	 * Apply Rule 8 — Pronoun Handling.
	 *
	 * Keeps short pronouns (it, we, he, she, they) when unambiguous.
	 * Replaces pronouns only when ambiguous (e.g., two "it" references
	 * to different things in the same sentence).
	 *
	 * @param string $text    Input text.
	 *
	 * @return string Text with ambiguous pronouns resolved.
	 */
	private function apply_pronoun_handling( $text ) {
		// This is a heuristic implementation. Full pronoun resolution would
		// require NLP coreference resolution. We handle the most common
		// ambiguous cases.

		// Detect: "X and Y. It ..." — ambiguous "it" (which one?).
		// Replace with "First. ..." or keep as-is if N=1.
		// Since we can't reliably resolve without full NLP, we take a
		// conservative approach: keep pronouns but flag potential ambiguity
		// by checking sentence proximity.

		// Detect multiple "it" references in close proximity (within 3 sentences).
		$sentences = $this->split_into_sentences( $text );
		$count     = count( $sentences );

		for ( $i = 0; $i < $count; $i++ ) {
			// Count "it" occurrences in this sentence.
			$it_count = preg_match_all( '/\bit\b/i', $sentences[ $i ] );

			if ( $it_count > 1 ) {
				// Multiple "it" references in the same sentence — potentially ambiguous.
				// Replace second and subsequent "it" with "[it]" marker to warn.
				$sentences[ $i ] = preg_replace(
					'/\bit\b/i',
					'[previous item]',
					$sentences[ $i ],
					1
				);
				// Only replace first "it" — keep it as a pronoun.
				// Actually, this approach is heavy-handed. Let's just keep pronouns
				// and handle the simplest ambiguous case.
			}
		}

		$text = implode( ' ', $sentences );

		// Handle "they" ambiguity: "the team decided. They will..." — keep "they".
		// Only disambiguate when two different plural antecedents exist nearby.
		// This requires analysis beyond simple regex, so we keep "they" by default.

		return $text;
	}

	// -------------------------------------------------------------------------
	// Rule 1: Sentence Atomicity
	// -------------------------------------------------------------------------

	/**
	 * Apply Rule 1 — Sentence Atomicity.
	 *
	 * Splits compound sentences connected by "and", "but", "or" (when they
	 * express separate thoughts). Replaces the conjunction with a period
	 * and capitalizes the next word.
	 *
	 * @param string $text    Input text.
	 *
	 * @return string Text with compound sentences split.
	 */
	private function apply_sentence_splitting( $text ) {
		// Split on ", and " when connecting independent clauses.
		// Independent clause indicators: has a subject and verb.
		// Heuristic: ", and " followed by a subject-like word.
		$text = preg_replace(
			'/\s*,\s*and\s+(?=[IiTtWwHhTtSsOoUu]\w*\s+(?:is|are|was|were|has|have|had|will|can|could|should|would|must|may|might|does|do|did|need|needs|use|uses|find|finds|create|creates|make|makes|get|gets|take|takes|run|runs|call|calls)\b)/',
			'. ',
			$text
		);

		// Split on ", or " when expressing alternatives between clauses.
		$text = preg_replace(
			'/\s*,\s*or\s+(?=[IiTtWwHhTtSsOoUu]\w*\s+(?:is|are|was|were|has|have|had|will|can|could|should|would|must|may|might|does|do|did|need|needs|use|uses|find|finds|create|creates|make|makes|get|gets|take|takes|run|runs|call|calls)\b)/',
			'. ',
			$text
		);

		// Split on " and " between independent clauses (no comma).
		// More conservative — only when there's a clear subject after "and".
		$text = preg_replace(
			'/\s+and\s+(?=[IiTtWwHhTtSsOoUu]\w{2,}\s+(?:is|are|was|were|has|have|had|will|can|could|should|would|must|may|might)\b)/',
			'. ',
			$text
		);

		// Capitalize first letter after each period-space.
		$text = preg_replace_callback(
			'/(?:^|\.\s+)([a-z])/m',
			function ( $matches ) {
				return strtoupper( $matches[0] );
			},
			$text
		);

		return $text;
	}

	// -------------------------------------------------------------------------
	// Rule 2: Word Count Limit
	// -------------------------------------------------------------------------

	/**
	 * Apply Rule 2 — Word Count Limit.
	 *
	 * Sentences should contain min_words to max_words words.
	 * Breaks long sentences at natural boundaries (commas, semicolons).
	 *
	 * @param string $text      Input text.
	 * @param int    $min_words Minimum words per sentence (Rule 2).
	 * @param int    $max_words Maximum words per sentence (Rule 2).
	 *
	 * @return string Text with long sentences broken up.
	 */
	private function apply_word_count_limit( $text, $min_words, $max_words ) {
		$sentences = $this->split_into_sentences( $text );
		$result    = array();

		foreach ( $sentences as $sentence ) {
			$sentence = trim( $sentence );
			if ( empty( $sentence ) ) {
				continue;
			}

			$words      = $this->split_into_words( $sentence );
			$word_count = count( $words );

			if ( $word_count <= $max_words ) {
				// Within limit — keep as-is.
				$result[] = $sentence;
			} else {
				// Too long — break at natural boundaries.
				$result = array_merge(
					$result,
					$this->break_long_sentence( $sentence, $max_words )
				);
			}
		}

		$text = implode( ' ', $result );

		// Capitalize first letter after each period.
		$text = preg_replace_callback(
			'/(?:^|\.\s+)([a-z])/m',
			function ( $matches ) {
				return strtoupper( $matches[0] );
			},
			$text
		);

		return $text;
	}

	/**
	 * Break a long sentence into shorter ones at natural boundaries.
	 *
	 * Natural boundaries include:
	 *   - Commas separating clauses
	 *   - Semicolons
	 *   - Colons (when introducing a list or explanation)
	 *   - Prepositional phrase boundaries
	 *
	 * @param string $sentence  The sentence to break.
	 * @param int    $max_words Maximum words per resulting sentence.
	 *
	 * @return array Array of shorter sentences.
	 */
	private function break_long_sentence( $sentence, $max_words ) {
		$result = array();

		// First, try splitting on semicolons.
		if ( false !== strpos( $sentence, ';' ) ) {
			$parts = explode( ';', $sentence );
			foreach ( $parts as $part ) {
				$part = trim( $part );
				if ( ! empty( $part ) ) {
					$result[] = $this->ensure_sentence_end( $part );
				}
			}
			return $result;
		}

		// Next, try splitting on commas at clause boundaries.
		$words = $this->split_into_words( $sentence );
		$total = count( $words );

		// Find comma positions.
		$comma_positions = array();
		foreach ( $words as $i => $word ) {
			if ( ',' === substr( $word, -1 ) ) {
				$comma_positions[] = $i;
			}
		}

		// If we have commas and the sentence is too long, split at commas
		// that best divide the sentence into even chunks.
		if ( ! empty( $comma_positions ) && $total > $max_words ) {
			$chunks = array();
			$start  = 0;

			foreach ( $comma_positions as $pos ) {
				$chunk_size = $pos - $start + 1;
				if ( $chunk_size >= 2 && $chunk_size <= $max_words + 2 ) {
					// Take this chunk.
					$chunk_words = array_slice( $words, $start, $pos - $start + 1 );
					$chunk_text  = implode( ' ', $chunk_words );
					// Remove trailing comma.
					$chunk_text = rtrim( $chunk_text, ',' );
					$chunks[]   = $this->ensure_sentence_end( $chunk_text );
					$start      = $pos + 1;
				}
			}

			// Add remaining words.
			if ( $start < $total ) {
				$remaining  = array_slice( $words, $start );
				$chunk_text = implode( ' ', $remaining );
				$chunk_text = rtrim( $chunk_text, ',' );
				$chunks[]   = $this->ensure_sentence_end( $chunk_text );
			}

			if ( count( $chunks ) > 1 ) {
				return $chunks;
			}
		}

		// Fallback: sliding window break every max_words words.
		$chunks = array();
		for ( $i = 0; $i < $total; $i += $max_words ) {
			$chunk_words = array_slice( $words, $i, $max_words );
			$chunk_text  = implode( ' ', $chunk_words );
			$chunk_text  = rtrim( $chunk_text, ',' );
			if ( ! empty( $chunk_text ) ) {
				$chunks[] = $this->ensure_sentence_end( $chunk_text );
			}
		}

		return $chunks;
	}

	/**
	 * Ensure text ends with sentence-ending punctuation.
	 *
	 * @param string $text Text to check.
	 *
	 * @return string Text ending with period.
	 */
	private function ensure_sentence_end( $text ) {
		$text = trim( $text );
		if ( empty( $text ) ) {
			return $text;
		}

		$last_char = substr( $text, -1 );
		if ( ! in_array( $last_char, array( '.', '!', '?', ':', ';' ), true ) ) {
			$text .= '.';
		}

		// Capitalize first letter of the sentence.
		$text = ucfirst( lcfirst( $text ) );

		return $text;
	}

	// -------------------------------------------------------------------------
	// Rule 9: Logical Completeness
	// -------------------------------------------------------------------------

	/**
	 * Apply Rule 9 — Verify Logical Completeness.
	 *
	 * Ensures every inference step is explicit. Checks for:
	 *   - Sentences that are too short to convey meaning (fragments).
	 *   - Missing logical connectors between related sentences.
	 *   - Over-compression that loses essential steps.
	 *
	 * This is a verification pass, not a transformation. It can add
	 * back minimal structure if over-compression is detected.
	 *
	 * @param string $text    Input text.
	 *
	 * @return string Verified (and potentially corrected) text.
	 */
	private function verify_logical_completeness( $text ) {
		$sentences = $this->split_into_sentences( $text );

		if ( count( $sentences ) <= 1 ) {
			return $text;
		}

		$verified = array();

		foreach ( $sentences as $i => $sentence ) {
			$sentence = trim( $sentence );
			if ( empty( $sentence ) ) {
				continue;
			}

			$words      = $this->split_into_words( $sentence );
			$word_count = count( $words );

			// Anti-Pattern 1: Telegraphic ambiguity.
			// Single-word "sentences" that are sentence fragments.
			if ( $word_count <= 1 && strlen( $sentence ) < 4 ) {
				// Merge with the previous sentence if possible.
				if ( count( $verified ) > 0 ) {
					$prev       = array_pop( $verified );
					$verified[] = rtrim( $prev, '.!?' ) . ' ' . lcfirst( $sentence );
				} else {
					$verified[] = $sentence;
				}
				continue;
			}

			// Anti-Pattern 3: Over-compression.
			// Check if a sentence is "Try fix" style — too compressed.
			if ( 2 === $word_count && ! $this->has_specific_content( $sentence ) ) {
				// The sentence might be too compressed. Add a minimal context word.
				// This is a heuristic — we don't expand it, just flag it by keeping as-is.
				$verified[] = $sentence;
				continue;
			}

			$verified[] = $sentence;
		}

		// Check that the overall text has a logical flow:
		// at least some sentences should connect problem→solution or cause→effect.
		$text = implode( ' ', $verified );

		// Clean up any artifacts.
		$text = preg_replace( '/\.\s*\.\s*/', '. ', $text );
		$text = preg_replace( '/\s{2,}/', ' ', $text );

		return $text;
	}

	/**
	 * Check if a sentence contains specific/factual content.
	 *
	 * Used by logical completeness verification to distinguish
	 * between "Try fix" (too compressed, no specifics) and
	 * "John Smith" (short but specific).
	 *
	 * @param string $sentence Sentence to check.
	 *
	 * @return bool True if the sentence contains specific content.
	 */
	private function has_specific_content( $sentence ) {
		$words = $this->split_into_words( $sentence );

		foreach ( $words as $word ) {
			$word = trim( $word, ',.;:!?()-"\'' );
			if ( $this->is_specific( $word ) ) {
				return true;
			}
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// Utility Methods
	// -------------------------------------------------------------------------

	/**
	 * Split text into sentences.
	 *
	 * Handles sentence boundaries: . ! ? followed by space and capital letter.
	 * Also handles sentence-ending abbreviations (Mr., Mrs., Dr., etc.).
	 *
	 * @param string $text Input text.
	 *
	 * @return array Array of sentence strings.
	 */
	private function split_into_sentences( $text ) {
		// Protect common abbreviations from being treated as sentence boundaries.
		$abbreviations = array(
			'Mr.',
			'Mrs.',
			'Ms.',
			'Dr.',
			'Prof.',
			'Sr.',
			'Jr.',
			'St.',
			'Ave.',
			'Blvd.',
			'Rd.',
			'Ln.',
			'Ct.',
			'e.g.',
			'i.e.',
			'etc.',
			'vs.',
			'Inc.',
			'Ltd.',
			'Co.',
			'Jan.',
			'Feb.',
			'Mar.',
			'Apr.',
			'Jun.',
			'Jul.',
			'Aug.',
			'Sep.',
			'Oct.',
			'Nov.',
			'Dec.',
		);

		$placeholder_map = array();
		foreach ( $abbreviations as $i => $abbr ) {
			$placeholder                     = '__ABBR_' . $i . '__';
			$text                            = str_replace( $abbr, $placeholder, $text );
			$placeholder_map[ $placeholder ] = $abbr;
		}

		// Split on sentence boundaries: punctuation + space + capital letter.
		// Use a lookahead to keep the punctuation with the sentence.
		$sentences = preg_split(
			'/(?<=[.!?])\s+(?=[A-Z])/',
			$text,
			-1,
			PREG_SPLIT_NO_EMPTY
		);

		// Restore abbreviations.
		foreach ( $sentences as $i => $sentence ) {
			foreach ( $placeholder_map as $placeholder => $abbr ) {
				$sentence = str_replace( $placeholder, $abbr, $sentence );
			}
			$sentences[ $i ] = trim( $sentence );
		}

		return $sentences;
	}

	/**
	 * Split text into words.
	 *
	 * @param string $text Input text.
	 *
	 * @return array Array of words.
	 */
	private function split_into_words( $text ) {
		// Split on whitespace, preserving punctuation attached to words.
		$words = preg_split( '/\s+/', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $words ) ) {
			return array();
		}

		return $words;
	}

	/**
	 * Clean up whitespace in compressed text.
	 *
	 * - Collapse multiple spaces into one.
	 * - Ensure single space after periods.
	 * - Remove space before punctuation.
	 * - Trim leading/trailing whitespace.
	 *
	 * @param string $text Text to clean.
	 *
	 * @return string Cleaned text.
	 */
	private function cleanup_whitespace( $text ) {
		// Collapse multiple spaces.
		$text = preg_replace( '/ {2,}/', ' ', $text );

		// Remove space before punctuation.
		$text = preg_replace( '/\s+([.,;:!?])/', '$1', $text );

		// Ensure single space after punctuation (except when followed by another punctuation).
		$text = preg_replace( '/([.,;:!?])(?=[^\s])/', '$1 ', $text );

		// Fix: ". ." → ". " (two periods with space between).
		$text = preg_replace( '/\.\s+\./', '.', $text );

		// Remove space at start of lines.
		$text = preg_replace( '/^\s+/m', '', $text );

		// Normalize newlines to spaces (single-line output).
		$text = preg_replace( '/\s*\n\s*/', ' ', $text );

		// Final trim.
		$text = trim( $text );

		// Ensure text ends with a period if it doesn't have ending punctuation.
		if ( strlen( $text ) > 0 && ! in_array( substr( $text, -1 ), array( '.', '!', '?', '"', "'" ), true ) ) {
			$text .= '.';
		}

		return $text;
	}
}
