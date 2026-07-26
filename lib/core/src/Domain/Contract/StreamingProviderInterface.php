<?php
/**
 * Streaming Provider — domain contract for realtime/voice AI providers.
 *
 * Abstracts bidirectional streaming (WebSocket, SSE, gRPC streams) so
 * voice/realtime providers follow the same Ports & Adapters pattern as
 * text-based providers.
 *
 * @package Nvoos\Core
 * @since   2.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface StreamingProviderInterface
{
    /**
     * Connect to the provider's streaming endpoint.
     *
     * @param array{model?: string, voice?: string, instructions?: string, temperature?: float} $options
     *
     * @return array{success: bool, connection_id: string, error?: string}
     */
    public function connect(array $options = []): array;

    /**
     * Send audio data to the provider.
     *
     * @param string $connectionId Connection identifier from connect().
     * @param string $audioBase64  Base64-encoded audio chunk.
     *
     * @return array{success: bool, error?: string}
     */
    public function sendAudio(string $connectionId, string $audioBase64): array;

    /**
     * Receive the next response from the provider.
     *
     * @param string $connectionId
     *
     * @return array{success: bool, type: string, data?: mixed, error?: string}
     *   type is one of: 'audio', 'text', 'transcript', 'function_call', 'done', 'error'
     */
    public function receive(string $connectionId): array;

    /**
     * Send a text message (for interruption, function results, etc.).
     *
     * @param string $connectionId
     * @param array  $message       Message payload.
     *
     * @return array{success: bool, error?: string}
     */
    public function sendMessage(string $connectionId, array $message): array;

    /**
     * Disconnect and clean up a streaming connection.
     */
    public function disconnect(string $connectionId): void;

    /**
     * Whether this provider is configured and available.
     */
    public function isAvailable(): bool;

    /**
     * Get the provider slug (e.g. 'openai_realtime', 'gemini_live').
     */
    public function getProviderSlug(): string;

    /**
     * Get supported features for this provider.
     *
     * @return array{audio_input: bool, audio_output: bool, text_input: bool, function_calling: bool}
     */
    public function getCapabilities(): array;
}
