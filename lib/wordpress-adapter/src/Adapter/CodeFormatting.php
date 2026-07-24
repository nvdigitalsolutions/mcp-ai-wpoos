<?php
declare(strict_types=1);
namespace Nvoos\WordPress\Adapter;
use Nvoos\Core\Domain\Contract\CodeFormattingInterface;

final class CodeFormatting implements CodeFormattingInterface {
    private $legacy;
    public function __construct() { if (\class_exists('WP_MCP_AI_Prettier_Service')) { $this->legacy = new \WP_MCP_AI_Prettier_Service(); } }
    public function format(string $code, string $language = 'php', array $options = []): array {
        if (!$this->legacy || !\method_exists($this->legacy, 'format')) {
            return ['success' => false, 'error' => 'Code formatting unavailable'];
        }
        try { $r = $this->legacy->format($code, $language, $options); return \is_wp_error($r) ? ['success' => false, 'error' => $r->get_error_message()] : ['success' => true, 'formatted' => (string) $r]; }
        catch (\Throwable $e) { return ['success' => false, 'error' => $e->getMessage()]; }
    }
    public function isAvailable(): bool { return $this->legacy !== null && \method_exists($this->legacy, 'is_available') && $this->legacy->is_available(); }
    public function getSupportedLanguages(): array { return ['php','js','ts','css','html','json','md','yaml','xml','sql','python','ruby','java','go','rust']; }
}
