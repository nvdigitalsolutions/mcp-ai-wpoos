<?php
/**
 * WordPress Adapter — Assistant Service.
 *
 * @package  Nvoos\WordPress
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\AssistantServiceInterface;

/**
 * WordPress adapter for assistant operations.
 *
 * @since 2.0.0
 */
final class AssistantService implements AssistantServiceInterface
{
    /**
     * Legacy assistant service.
     *
     * @var \WP_MCP_AI_Assistant_Service|null
     */
    private $legacy;

    public function __construct()
    {
        if (\class_exists('WP_MCP_AI_Assistant_Service')) {
            $this->legacy = new \WP_MCP_AI_Assistant_Service();
        }
    }

    public function validate(int $assistantId, int $userId = 0): array
    {
        if ($this->legacy === null) {
            return ['valid' => false, 'error' => 'Assistant service unavailable'];
        }

        /** @var \WP_Post|\WP_Error $result */
        $result = $this->legacy->validate_assistant_access($assistantId, $userId);

        if (\is_wp_error($result)) {
            return ['valid' => false, 'error' => $result->get_error_message()];
        }

        return [
            'valid'     => true,
            'assistant' => [
                'id'     => $result->ID,
                'title'  => $result->post_title,
                'status' => $result->post_status,
            ],
        ];
    }

    public function getConfig(int $assistantId): array
    {
        if ($this->legacy === null || !\method_exists($this->legacy, 'get_assistant_config')) {
            return ['found' => false];
        }

        /** @var array|false $config */
        $config = $this->legacy->get_assistant_config($assistantId);

        if ($config === false || $config === null) {
            return ['found' => false];
        }

        return ['found' => true, 'config' => \is_array($config) ? $config : []];
    }

    public function getDefault(): array
    {
        if ($this->legacy === null || !\method_exists($this->legacy, 'get_default_assistant')) {
            return ['found' => false];
        }

        /** @var array|false $default */
        $default = $this->legacy->get_default_assistant();

        if ($default === false || $default === null) {
            return ['found' => false];
        }

        return \array_merge(['found' => true], \is_array($default) ? $default : []);
    }

    public function listForUser(int $userId = 0): array
    {
        if ($this->legacy === null || !\method_exists($this->legacy, 'get_assistants_for_user')) {
            return [];
        }

        /** @var array<int, array> $assistants */
        $assistants = $this->legacy->get_assistants_for_user($userId);

        return \is_array($assistants) ? $assistants : [];
    }
}
