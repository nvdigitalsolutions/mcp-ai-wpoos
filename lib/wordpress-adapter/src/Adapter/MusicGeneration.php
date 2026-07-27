<?php
declare(strict_types=1);
namespace Nvoos\WordPress\Adapter;
use Nvoos\Core\Domain\Contract\MusicGenerationInterface;

final class MusicGeneration implements MusicGenerationInterface {
    private $legacy;
    public function __construct() { if (\class_exists('WP_MCP_AI_Jukebox_Service')) { $this->legacy = new \WP_MCP_AI_Jukebox_Service(); } }
    public function generate(string $prompt, array $options = []): array {
        if (!$this->legacy || !\method_exists($this->legacy, 'generate')) {
            return ['success' => false, 'error' => 'Music generation unavailable'];
        }
        try { $r = $this->legacy->generate($prompt, $options); return \is_wp_error($r) ? ['success' => false, 'error' => $r->get_error_message()] : \array_merge(['success' => true], \is_array($r) ? $r : ['job_id' => (string) $r]); }
        catch (\Throwable $e) { return ['success' => false, 'error' => $e->getMessage()]; }
    }
    public function checkStatus(string $jobId): array { return ['status' => 'unknown']; }
    public function isAvailable(): bool { return $this->legacy !== null && \method_exists($this->legacy, 'check_installation'); }
    public function getSupportedModels(): array { return ['1b_lyrics','5b','5b_lyrics']; }
}
