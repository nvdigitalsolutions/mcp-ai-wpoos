<?php
declare(strict_types=1);
namespace Nvoos\WordPress\Adapter;
use Nvoos\Core\Domain\Contract\ContextCompressionInterface;

final class ContextCompression implements ContextCompressionInterface
{
    private $legacy;
    private $compressor;

    public function __construct() {
        if (\class_exists('WP_MCP_AI_Context_Compression_Service')) {
            $this->legacy = \WP_MCP_AI_Context_Compression_Service::get_instance();
        }
        if (\class_exists('WP_MCP_AI_Semantic_Compressor')) {
            $this->compressor = \WP_MCP_AI_Semantic_Compressor::get_instance();
        }
    }

    public function compress(string $content, array $options = []): array {
        if ($this->legacy && \method_exists($this->legacy, 'compress_context')) {
            $r = $this->legacy->compress_context($content, $options);
            return \is_array($r) ? $r : ['success' => false, 'compressed' => $content];
        }
        if ($this->compressor) {
            $c = $this->compressor->compress($content, ['aggressiveness' => $options['aggressiveness'] ?? 2]);
            return ['success' => true, 'compressed' => $c, 'method' => 'semantic'];
        }
        return ['success' => false, 'compressed' => $content];
    }

    public function chunk(string $content, int $chunkSize = 500, float $overlapRatio = 0.15): array {
        if ($this->legacy && \method_exists($this->legacy, 'chunk_content')) {
            $r = $this->legacy->chunk_content($content, $chunkSize, $overlapRatio);
            return \is_array($r) ? $r : [];
        }
        return [['index' => 0, 'content' => $content, 'tokens' => $this->estimateTokens($content)]];
    }

    public function estimateTokens(string $text): int {
        if ($this->compressor) { return $this->compressor->estimate_tokens($text); }
        return $text === '' ? 0 : (int) \ceil(\strlen($text) / 4);
    }
}
