<?php
declare(strict_types=1);
namespace Nvoos\WordPress\Adapter;
use Nvoos\Core\Domain\Contract\TokenUsageInterface;

final class TokenUsage implements TokenUsageInterface
{
    public function trackUsage(int $userId, string $modelId, int $promptTokens, int $completionTokens, array $metadata = []): void {
        if (\class_exists('WP_MCP_AI_Token_Usage_Service')) {
            try {
                $s = new \WP_MCP_AI_Token_Usage_Service();
                if (\method_exists($s, 'track')) { $s->track($userId, $modelId, $promptTokens, $completionTokens, $metadata); }
            } catch (\Throwable $e) {}
        }
    }

    public function getUserUsage(int $userId, string $startDate, string $endDate): array {
        if (\class_exists('WP_MCP_AI_Token_Usage_Service')) {
            try {
                $s = new \WP_MCP_AI_Token_Usage_Service();
                if (\method_exists($s, 'get_user_usage')) {
                    $r = $s->get_user_usage($userId, $startDate, $endDate);
                    return \is_array($r) ? $r : [];
                }
            } catch (\Throwable $e) {}
        }
        return [];
    }

    public function getModelUsage(string $modelId, string $startDate, string $endDate): array {
        if (\class_exists('WP_MCP_AI_Token_Usage_Service')) {
            try {
                $s = new \WP_MCP_AI_Token_Usage_Service();
                if (\method_exists($s, 'get_model_usage')) {
                    $r = $s->get_model_usage($modelId, $startDate, $endDate);
                    return \is_array($r) ? $r : [];
                }
            } catch (\Throwable $e) {}
        }
        return [];
    }
}
