<?php
declare(strict_types=1);
namespace Nvoos\WordPress\Adapter;
use Nvoos\Core\Domain\Contract\VisionInferenceInterface;

final class VisionInference implements VisionInferenceInterface {
    private $legacy;
    public function __construct() { if (\class_exists('WP_MCP_AI_HF_Vision_Inference_Service')) { $this->legacy = new \WP_MCP_AI_HF_Vision_Inference_Service(); } }
    public function infer(string $imagePath, string $prompt = '', array $options = []): array {
        if (!$this->legacy || !\method_exists($this->legacy, 'infer')) {
            return ['success' => false, 'error' => 'Vision inference unavailable'];
        }
        try { $r = $this->legacy->infer($imagePath, $prompt, $options); return \is_wp_error($r) ? ['success' => false, 'error' => $r->get_error_message()] : \array_merge(['success' => true], \is_array($r) ? $r : []); }
        catch (\Throwable $e) { return ['success' => false, 'error' => $e->getMessage()]; }
    }
    public function getAvailableModels(): array { return $this->legacy && \method_exists($this->legacy, 'get_models') ? (array) $this->legacy->get_models() : []; }
    public function isAvailable(): bool { return $this->legacy !== null; }
}
