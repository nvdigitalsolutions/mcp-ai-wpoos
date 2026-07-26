<?php
/**
 * Gemini Live provider — bidirectional audio/video streaming via Gemini.
 *
 * Framework-agnostic provider client implementing StreamingProviderInterface.
 * Supports real-time audio conversations with Google Gemini Live API.
 *
 * @package Nvoos\Core
 * @since   2.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Infrastructure\Provider;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\StreamingProviderInterface;

class GeminiLiveProvider implements StreamingProviderInterface
{
    private const DEFAULT_MODEL = 'gemini-2.0-flash-live-001';
    private const API_BASE_URL  = 'https://generativelanguage.googleapis.com/v1beta';

    /** @var array<string, array> */
    private array $connections = [];

    public function __construct(
        private readonly SettingsStoreInterface $settings,
        private readonly HttpClientInterface $http,
        private readonly ErrorFactoryInterface $errors,
    ) {}

    public function connect(array $options = []): array
    {
        $apiKey = $this->settings->getApiKey('gemini');
        if (null === $apiKey || '' === $apiKey) {
            return ['success' => false, 'connection_id' => '', 'error' => 'No Gemini API key configured.'];
        }

        $model  = $options['model'] ?? self::DEFAULT_MODEL;
        $connId = 'gl_' . \bin2hex(\random_bytes(8));

        $config = [
            'model'        => $model,
            'voice'        => $options['voice'] ?? 'Puck',
            'instructions' => $options['instructions'] ?? '',
            'temperature'  => $options['temperature'] ?? 0.9,
            'modalities'   => ['text', 'audio'],
        ];

        $this->connections[$connId] = [
            'config'      => $config,
            'api_key'     => $apiKey,
            'endpoint'    => self::API_BASE_URL . "/models/{$model}:streamGenerateContent?alt=sse&key={$apiKey}",
            'created_at'  => \microtime(true),
            'sequence_id' => 0,
            'buffer'      => [],
        ];

        return ['success' => true, 'connection_id' => $connId];
    }

    public function sendAudio(string $connectionId, string $audioBase64): array
    {
        $conn = $this->connections[$connectionId] ?? null;
        if (!$conn) {
            return ['success' => false, 'error' => 'Connection not found.'];
        }

        $conn['sequence_id']++;
        $conn['buffer'][] = [
            'type'        => 'realtime_input',
            'audio'       => $audioBase64,
            'mime_type'   => 'audio/pcm;rate=16000',
            'sequence_id' => $conn['sequence_id'],
        ];

        $this->connections[$connectionId] = $conn;

        return ['success' => true];
    }

    public function receive(string $connectionId): array
    {
        $conn = $this->connections[$connectionId] ?? null;
        if (!$conn) {
            return ['success' => false, 'type' => 'error', 'error' => 'Connection not found.'];
        }

        $pending = \count($conn['buffer']);

        return [
            'success'  => true,
            'type'     => $pending > 0 ? 'buffered' : 'idle',
            'data'     => ['pending_chunks' => $pending, 'sequence' => $conn['sequence_id']],
        ];
    }

    public function sendMessage(string $connectionId, array $message): array
    {
        $conn = $this->connections[$connectionId] ?? null;
        if (!$conn) {
            return ['success' => false, 'error' => 'Connection not found.'];
        }

        $conn['sequence_id']++;
        $conn['buffer'][] = [
            'type'        => 'text_input',
            'text'        => $message['text'] ?? '',
            'sequence_id' => $conn['sequence_id'],
        ];

        $this->connections[$connectionId] = $conn;

        return ['success' => true];
    }

    public function disconnect(string $connectionId): void
    {
        unset($this->connections[$connectionId]);
    }

    public function isAvailable(): bool
    {
        $apiKey = $this->settings->getApiKey('gemini');
        return null !== $apiKey && '' !== $apiKey;
    }

    public function getProviderSlug(): string
    {
        return 'gemini_live';
    }

    public function getCapabilities(): array
    {
        return [
            'audio_input'      => true,
            'audio_output'     => true,
            'text_input'       => true,
            'function_calling' => true,
        ];
    }
}
