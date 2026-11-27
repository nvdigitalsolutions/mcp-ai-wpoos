<?php

/**
 * A refused chat-channel API call.
 *
 * Proposal 164 section 164.100. Every provider in the family answers errors in
 * its own shape — Slack with HTTP 200 and `ok: false`, Graph with a nested
 * `error.message`, Telegram with `ok: false` and a numeric `error_code` — and a
 * transport failure answers none at all. The exception keeps the core code,
 * the HTTP status, the provider's own code and the decoded payload apart so one
 * `failure()` can build the canonical envelope for all of them.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

final class ChatChannelApiException extends \RuntimeException
{
    /**
     * @param string               $message      A caller-facing message.
     * @param string               $errorCode    The core error code, e.g. `slack_api_error`.
     * @param int                  $statusCode   HTTP status, 0 when no response arrived.
     * @param string               $providerCode The provider's own error code or string.
     * @param array<string, mixed> $payload      The decoded provider body, for diagnostics.
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'chat_channel_api_error',
        public readonly int $statusCode = 0,
        public readonly string $providerCode = '',
        public readonly array $payload = array(),
    ) {
        parent::__construct($message);
    }
}
