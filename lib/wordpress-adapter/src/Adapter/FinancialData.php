<?php
declare(strict_types=1);
namespace Nvoos\WordPress\Adapter;
use Nvoos\Core\Domain\Contract\FinancialDataInterface;

final class FinancialData implements FinancialDataInterface {
    private $legacy;
    public function __construct() { if (\class_exists('WP_MCP_AI_YFinance_Service')) { $this->legacy = \WP_MCP_AI_YFinance_Service::get_instance(); } }
    public function getQuote(string $symbol): array {
        if (!$this->legacy || !\method_exists($this->legacy, 'get_quote')) { return ['success' => false, 'error' => 'Financial data unavailable']; }
        try { $r = $this->legacy->get_quote($symbol); return \is_wp_error($r) ? ['success' => false, 'error' => $r->get_error_message()] : \array_merge(['success' => true], \is_array($r) ? $r : []); }
        catch (\Throwable $e) { return ['success' => false, 'error' => $e->getMessage()]; }
    }
    public function getHistory(string $symbol, string $period = '1mo'): array {
        if (!$this->legacy || !\method_exists($this->legacy, 'get_history')) { return ['success' => false]; }
        try { $r = $this->legacy->get_history($symbol, $period); return \is_wp_error($r) ? ['success' => false] : \array_merge(['success' => true], \is_array($r) ? $r : []); }
        catch (\Throwable $e) { return ['success' => false]; }
    }
    public function search(string $query): array {
        if (!$this->legacy || !\method_exists($this->legacy, 'search')) { return []; }
        try { $r = $this->legacy->search($query); return \is_array($r) ? $r : []; }
        catch (\Throwable $e) { return []; }
    }
    public function isAvailable(): bool { return $this->legacy !== null && \method_exists($this->legacy, 'is_enabled') && $this->legacy->is_enabled(); }
}
