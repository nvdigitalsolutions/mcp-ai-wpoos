<?php
/**
 * Semantic Compressor — Caveman Compression for LLM Contexts.
 *
 * Lossless semantic compression that strips grammar, connectives, and filler
 * words while preserving facts, numbers, and technical terms.
 *
 * Based on the Caveman Compression specification v1.0:
 * {@link https://github.com/wilpel/caveman-compression}
 *
 * @credit   William Peltomäki — original Caveman Compression algorithm (MIT)
 * @link     https://github.com/wilpel/caveman-compression/blob/main/SPEC.md
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

/**
 * Compresses text for reduced LLM context consumption.
 *
 * Implementations may use different compression strategies (Caveman,
 * extractive summarization, etc.). The contract specifies the behavior
 * contract — inputs and outputs — without prescribing the algorithm.
 *
 * @since 2.0.0
 */
interface SemanticCompressorInterface
{
    /**
     * Compress text using semantic compression rules.
     *
     * @param string $text          Raw input text.
     * @param int    $aggressiveness Compression level (1=conservative, 2=balanced, 3=aggressive).
     * @param int    $maxTokens     Maximum token budget for compressed output (0 = no limit).
     *
     * @return array{compressed: string, original_bytes: int, compressed_bytes: int, compression_ratio: float, tokens_estimate: int}
     */
    public function compress(string $text, int $aggressiveness = 2, int $maxTokens = 0): array;

    /**
     * Estimate token count for a given text using character/token heuristic.
     *
     * @param string $text Text to estimate.
     *
     * @return int Estimated token count.
     */
    public function estimateTokens(string $text): int;

    /**
     * Return whether the given aggressiveness level is valid.
     *
     * @param int $level Aggressiveness level to check.
     *
     * @return bool
     */
    public function isValidAggressiveness(int $level): bool;
}
