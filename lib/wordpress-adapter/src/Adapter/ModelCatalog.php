<?php
/**
 * WordPress Adapter — Model Catalog.
 *
 * @package  Nvoos\WordPress
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\ModelCatalogInterface;

/**
 * WordPress adapter for model catalog operations.
 *
 * @since 2.0.0
 */
final class ModelCatalog implements ModelCatalogInterface
{
    /**
     * Token budget manager (for model limits lookup).
     *
     * @var \WP_MCP_AI_Token_Budget_Manager|null
     */
    private $tokenBudget;

    public function __construct()
    {
        if (\class_exists('WP_MCP_AI_Token_Budget_Manager')) {
            $this->tokenBudget = new \WP_MCP_AI_Token_Budget_Manager();
        }
    }

    public function getModelsForProvider(string $provider, array $args = []): array
    {
        if (!\class_exists('WP_MCP_AI_Model_Service')) {
            return [];
        }

        $service = new \WP_MCP_AI_Model_Service();

        /** @var array<string, string> $models */
        $models = $service->get_models_for_provider($provider, $args);

        return \is_array($models) ? $models : [];
    }

    public function getAllModels(): array
    {
        if (!\class_exists('WP_MCP_AI_Model_Service')) {
            return [];
        }

        $providers = ['openai', 'anthropic', 'gemini', 'deepseek', 'ollama', 'huggingface'];
        $all = [];

        $service = new \WP_MCP_AI_Model_Service();

        foreach ($providers as $provider) {
            $models = $service->get_models_for_provider($provider);
            foreach ($models as $id => $name) {
                $all[$id] = [
                    'provider'     => $provider,
                    'name'         => $name,
                    'capabilities' => [],
                ];
            }
        }

        return $all;
    }

    public function modelExists(string $modelId): bool
    {
        $models = $this->getAllModels();

        return isset($models[$modelId]);
    }

    public function getModelTokenLimit(string $modelId): int
    {
        if ($this->tokenBudget === null) {
            return 0;
        }

        if (!\method_exists($this->tokenBudget, 'get_model_limit')) {
            return 0;
        }

        /** @var int|float $limit */
        $limit = $this->tokenBudget->get_model_limit($modelId);

        return (int) $limit;
    }

    public function discover(array $providers = []): array
    {
        if (!\class_exists('WP_MCP_AI_Model_Discovery_Service')) {
            return [
                'additions'     => [],
                'sunsets'       => [],
                'price_changes' => [],
                'errors'        => [],
                'status'        => 'unavailable',
            ];
        }

        $service = new \WP_MCP_AI_Model_Discovery_Service();

        /** @var array $result */
        $result = $service->run($providers, ['persist' => false]);

        return \is_array($result) ? $result : [
            'additions'     => [],
            'sunsets'       => [],
            'price_changes' => [],
            'errors'        => [],
            'status'        => 'error',
        ];
    }
}
