<?php
declare(strict_types=1);
namespace Nvoos\WordPress\Adapter;
use Nvoos\Core\Domain\Contract\OcrServiceInterface;

final class OcrService implements OcrServiceInterface {
    private $legacy;
    public function __construct() { if (\class_exists('WP_MCP_AI_OCR_Service')) { $this->legacy = new \WP_MCP_AI_OCR_Service(); } }
    public function extractText(string $filePath, array $options = []): array {
        if (!$this->legacy || !\method_exists($this->legacy, 'extract_text')) {
            return ['success' => false, 'error' => 'OCR service unavailable'];
        }
        try { $r = $this->legacy->extract_text($filePath, $options); return \is_wp_error($r) ? ['success' => false, 'error' => $r->get_error_message()] : \array_merge(['success' => true], \is_array($r) ? $r : ['text' => (string) $r]); }
        catch (\Throwable $e) { return ['success' => false, 'error' => $e->getMessage()]; }
    }
    public function getAvailableProviders(): array { return $this->legacy && \method_exists($this->legacy, 'get_providers') ? (array) $this->legacy->get_providers() : []; }
    public function isAvailable(): bool { return $this->legacy !== null; }
}
