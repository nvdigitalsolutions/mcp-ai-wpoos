<?php
/**
 * WordPress Adapter — File Orchestration Service.
 *
 * @package  Nvoos\WordPress
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\FileOrchestrationInterface;

/**
 * Provider-agnostic file orchestration adapter.
 *
 * Delegates to provider-specific orchestration services (OpenAI, Gemini, etc.)
 * while presenting a unified interface.
 *
 * @since 2.0.0
 */
final class FileOrchestration implements FileOrchestrationInterface
{
    private int $maxPollingAttempts = 60;
    private int $pollingDelay = 5;
    private string $provider;

    /**
     * Constructor.
     *
     * @param string $provider Provider slug (openai, gemini, etc.).
     */
    public function __construct(string $provider = 'openai')
    {
        $this->provider = $provider;
    }

    public function uploadFile(string $filePath, string $mimeType, array $options = []): array
    {
        $service = $this->resolveProviderService();
        if ($service === null) {
            return [
                'success' => false,
                'error'   => 'No file orchestration service available for provider: ' . $this->provider,
            ];
        }

        try {
            /** @var array|object $result */
            $result = $service->upload_file($filePath, $mimeType, $options);

            if (\is_wp_error($result)) {
                return [
                    'success' => false,
                    'error'   => $result->get_error_message(),
                ];
            }

            return \array_merge(['success' => true], \is_array($result) ? $result : []);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    public function pollStatus(string $fileId): array
    {
        $service = $this->resolveProviderService();
        if ($service === null || !\method_exists($service, 'poll_file_status')) {
            return ['status' => 'unknown', 'error' => 'Service unavailable'];
        }

        try {
            /** @var array|object $result */
            $result = $service->poll_file_status($fileId);

            if (\is_wp_error($result)) {
                return ['status' => 'error', 'error' => $result->get_error_message()];
            }

            return \array_merge(['status' => 'unknown'], \is_array($result) ? $result : []);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    public function deleteFile(string $fileId): array
    {
        $service = $this->resolveProviderService();
        if ($service === null || !\method_exists($service, 'cleanup_file')) {
            return ['success' => false, 'error' => 'Service unavailable'];
        }

        try {
            /** @var bool|object $result */
            $result = $service->cleanup_file($fileId);

            if (\is_wp_error($result)) {
                return ['success' => false, 'error' => $result->get_error_message()];
            }

            return ['success' => true];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function setMaxPollingAttempts(int $attempts): void
    {
        $this->maxPollingAttempts = \max(1, $attempts);
    }

    public function setPollingDelay(int $seconds): void
    {
        $this->pollingDelay = \max(1, $seconds);
    }

    /**
     * Resolve the provider-specific orchestration service.
     *
     * @return object|null
     */
    private function resolveProviderService(): ?object
    {
        switch ($this->provider) {
            case 'openai':
                if (\class_exists('WP_MCP_AI_OpenAI_File_Service')) {
                    return new \WP_MCP_AI_OpenAI_File_Service();
                }
                break;

            case 'gemini':
            case 'google':
                if (\class_exists('WP_MCP_AI_Gemini_File_Service')) {
                    return new \WP_MCP_AI_Gemini_File_Service();
                }
                break;
        }

        return null;
    }
}
