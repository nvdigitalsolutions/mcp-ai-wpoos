<?php
/**
 * WordPress Adapter — Semantic Compressor.
 *
 * Bridges {@see SemanticCompressorInterface} to the legacy
 * {@see WP_MCP_AI_Semantic_Compressor} implementation.
 *
 * This adapter follows the Strangler Fig pattern: it delegates to the legacy
 * implementation while callers depend only on the domain contract. When the
 * pure domain service is ready, this adapter will swap its delegate without
 * affecting any call site.
 *
 * @package  Nvoos\WordPress
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\SemanticCompressorInterface;

/**
 * WordPress adapter for the Semantic Compressor.
 *
 * @since 2.0.0
 */
final class SemanticCompressor implements SemanticCompressorInterface
{
    /**
     * Legacy compressor instance.
     *
     * @var \WP_MCP_AI_Semantic_Compressor|null
     */
    private $legacy;

    /**
     * Constructor.
     */
    public function __construct()
    {
        if (\class_exists('WP_MCP_AI_Semantic_Compressor')) {
            $this->legacy = \WP_MCP_AI_Semantic_Compressor::get_instance();
        }
    }

    /**
     * {@inheritDoc}
     */
    public function compress(string $text, int $aggressiveness = 2, int $maxTokens = 0): array
    {
        $originalBytes = \strlen($text);

        if ($this->legacy !== null) {
            $compressed = $this->legacy->compress($text, [
                'aggressiveness'     => \max(1, \min(3, $aggressiveness)),
                'skip_code_blocks'   => true,
                'preserve_specifics' => true,
            ]);
        } else {
            // Graceful degradation: return text unchanged when legacy unavailable.
            $compressed = $text;
        }

        $compressedBytes = \strlen((string) $compressed);
        $tokensEstimate  = $this->estimateTokens((string) $compressed);

        return [
            'compressed'        => (string) $compressed,
            'original_bytes'    => $originalBytes,
            'compressed_bytes'  => $compressedBytes,
            'compression_ratio' => $originalBytes > 0
                ? \round($compressedBytes / $originalBytes, 4)
                : 1.0,
            'tokens_estimate'   => $tokensEstimate,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function estimateTokens(string $text): int
    {
        if ($this->legacy !== null) {
            return $this->legacy->estimate_tokens($text);
        }

        // Fallback: 4 chars per token heuristic.
        if ($text === '') {
            return 0;
        }

        return (int) \ceil(\strlen($text) / 4);
    }

    /**
     * {@inheritDoc}
     */
    public function isValidAggressiveness(int $level): bool
    {
        return $level >= 1 && $level <= 3;
    }
}
