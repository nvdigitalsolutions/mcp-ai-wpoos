<?php

/**
 * Send a Telegram message, splitting it at Telegram's own limit.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Send_Telegram_Message` (Proposal 164 section
 * 164.102). One `sendMessage` per chunk; a message under 4,096 characters is
 * one call.
 *
 * ## Corrections
 *
 * 1. **An unsupported `parse_mode` is refused.** WordPress silently dropped a
 *    value outside Markdown, MarkdownV2 and HTML, so a caller that asked for
 *    `markdown` got unformatted text and no signal.
 * 2. **A partial send reports what went through.** WordPress's chunk loop
 *    returned an error that named the failed chunk but not the message ids of
 *    the chunks already delivered, so a retry could duplicate them. The error
 *    carries the delivered ids.
 * 3. **A success without a message id is malformed.** Telegram always returns
 *    the sent message; its absence is reported rather than returned as an
 *    empty success.
 *
 * ## Adaptations
 *
 * The WordPress kses pass is dropped. Telegram's HTML parse mode is a supported
 * input, and the core has no sanitiser for a provider's markup dialect; the
 * text is sent as given. The chunker is kept as it was: paragraph boundaries
 * first, then lines, then a hard split at the last space near the limit — and a
 * hard-split chunk is sent without `parse_mode`, because the split can cut a
 * markup entity in half and Telegram would reject it.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class SendTelegramMessageTool extends TelegramTool
{
    /**
     * The parse modes Telegram accepts.
     *
     * @var list<string>
     */
    private const PARSE_MODES = array('Markdown', 'MarkdownV2', 'HTML');

    public function getSlug(): string
    {
        return 'send_telegram_message';
    }

    public function getName(): string
    {
        return 'Send Telegram Message';
    }

    public function getDescription(): string
    {
        return 'Send a text message to a Telegram chat, group or channel. Messages longer than 4,096 characters are split at paragraph, line or word boundaries and delivered as several messages.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'token'                    => array(
                    'type'        => 'string',
                    'description' => 'Telegram bot token.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'chat_id'                  => array(
                    'type'        => array('string', 'integer'),
                    'description' => 'Numeric chat id or a channel username such as @channelname.',
                ),
                'text'                     => array(
                    'type'        => 'string',
                    'description' => 'Message text.',
                    'minLength'   => 1,
                ),
                'parse_mode'               => array(
                    'type'        => 'string',
                    'description' => 'Formatting mode for the text. Omit to send plain text.',
                    'enum'        => array('Markdown', 'MarkdownV2', 'HTML'),
                ),
                'disable_web_page_preview' => array(
                    'type'        => 'boolean',
                    'description' => 'Disable link previews in the sent message.',
                    'default'     => false,
                ),
                'chunk'                    => array(
                    'type'        => 'boolean',
                    'description' => 'Split a message longer than 4,096 characters into several messages. Defaults to true.',
                    'default'     => true,
                ),
            ),
            'required'             => array('token', 'chat_id', 'text'),
            'additionalProperties' => false,
        );
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array(
            'external-api',
            'network-dependent',
            'requires-credentials',
            'state-changing',
            'write',
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $token = $this->token($arguments);

        if (! is_string($token)) {
            return $token;
        }

        $chatId = $this->chatId($arguments['chat_id'] ?? null);

        if ('' === $chatId) {
            return $this->errors->validationFailed(
                'A chat id is required.',
                array('chat_id' => array('A chat id is required.'))
            );
        }

        $text = $this->textArg($arguments, 'text');

        if ('' === $text) {
            return $this->errors->validationFailed(
                'Message text is required.',
                array('text' => array('Message text must be provided.'))
            );
        }

        $parseMode = $this->textArg($arguments, 'parse_mode');

        if ('' !== $parseMode && ! in_array($parseMode, self::PARSE_MODES, true)) {
            return $this->errors->validationFailed(
                'The parse mode must be Markdown, MarkdownV2 or HTML.',
                array('parse_mode' => array('Unsupported parse mode.'))
            );
        }

        $preview = $this->boolParam($arguments, 'disable_web_page_preview');
        $chunk   = array_key_exists('chunk', $arguments) ? $this->boolParam($arguments, 'chunk') : true;

        $chunks = $chunk && \mb_strlen($text) > self::MAX_MESSAGE_LENGTH
            ? $this->split($text, self::MAX_MESSAGE_LENGTH)
            : array(array('text' => $text, 'hard' => false));

        $sent = array();

        foreach ($chunks as $index => $part) {
            try {
                $response = $this->apiPost(
                    $token,
                    'sendMessage',
                    $this->payload($chatId, $part['text'], $part['hard'] ? '' : $parseMode, $preview)
                );
            } catch (ChatChannelApiException $error) {
                if (1 === count($chunks)) {
                    return $this->failure($error);
                }

                return $this->errors->create(
                    'telegram_chunk_error',
                    sprintf(
                        'Part %d of %d was refused after %d part(s) were delivered: %s',
                        $index + 1,
                        count($chunks),
                        count($sent),
                        $error->getMessage()
                    ),
                    $this->chunkErrorData($error, $index, count($chunks), $sent)
                );
            }

            $message = $this->sentMessage($this->result($response));

            if (0 === $message['message_id']) {
                return $this->errors->create(
                    'telegram_invalid_response',
                    'Telegram accepted the message but returned no message object.'
                );
            }

            $sent[] = $message;
        }

        if (1 === count($sent)) {
            return $this->success('Message sent to chat ' . $chatId . '.', $sent[0] + array('chunks_sent' => 1));
        }

        return $this->success(
            sprintf('Message sent to chat %s in %d parts.', $chatId, count($sent)),
            array(
                'chunks_sent' => count($sent),
                'messages'    => $sent,
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $chatId, string $text, string $parseMode, bool $preview): array
    {
        $payload = array(
            'chat_id' => $chatId,
            'text'    => $text,
        );

        if ('' !== $parseMode) {
            $payload['parse_mode'] = $parseMode;
        }

        if ($preview) {
            $payload['disable_web_page_preview'] = true;
        }

        return $payload;
    }

    /**
     * A sent message, projected.
     *
     * @return array<string, mixed>
     */
    private function sentMessage(mixed $result): array
    {
        if (! is_array($result)) {
            return array(
                'message_id' => 0,
                'chat_id'    => '',
                'date'       => 0,
                'text'       => '',
            );
        }

        $chat = is_array($result['chat'] ?? null) ? $result['chat'] : array();

        return array(
            'message_id' => is_numeric($result['message_id'] ?? null) ? (int) $result['message_id'] : 0,
            'chat_id'    => $this->chatId($chat['id'] ?? ''),
            'date'       => is_numeric($result['date'] ?? null) ? (int) $result['date'] : 0,
            'text'       => $this->text($result['text'] ?? ''),
        );
    }

    /**
     * The failure of a chunked send, with what was already delivered.
     *
     * @param list<array<string, mixed>> $sent
     *
     * @return array<string, mixed>
     */
    private function chunkErrorData(ChatChannelApiException $error, int $index, int $total, array $sent): array
    {
        $data = array(
            'chunk'        => $index + 1,
            'total_chunks' => $total,
            'sent'         => $sent,
        );

        if ($error->statusCode > 0) {
            $data['status'] = $error->statusCode;
        }

        if ('' !== $error->providerCode) {
            $data['code'] = $error->providerCode;
        }

        if ($error->retryAfter > 0) {
            $data['retry_after'] = $error->retryAfter;
        }

        return $data;
    }

    /**
     * Split text into Telegram-sized parts.
     *
     * Prefers paragraph boundaries, then line boundaries, and falls back to a
     * hard split at the last space near the limit. A hard-split part may have
     * cut a markup entity in half, so it is flagged and sent without
     * `parse_mode`.
     *
     * @return list<array{text: string, hard: bool}>
     */
    private function split(string $text, int $limit): array
    {
        foreach (array("\n\n", "\n") as $separator) {
            $blocks = explode($separator, $text);
            $longest = 0;

            foreach ($blocks as $block) {
                $longest = max($longest, \mb_strlen($block));
            }

            if ($longest > $limit) {
                continue;
            }

            $parts   = array();
            $current = '';

            foreach ($blocks as $block) {
                if ('' === $current) {
                    $current = $block;
                } elseif (\mb_strlen($current . $separator . $block) <= $limit) {
                    $current .= $separator . $block;
                } else {
                    $parts[] = array('text' => trim($current), 'hard' => false);
                    $current = $block;
                }
            }

            if ('' !== $current) {
                $parts[] = array('text' => trim($current), 'hard' => false);
            }

            return $parts;
        }

        $parts     = array();
        $remaining = $text;

        while (\mb_strlen($remaining) > $limit) {
            $window = \mb_substr($remaining, 0, $limit);
            $cut    = \mb_strrpos($window, ' ');

            if (false === $cut || $cut < (int) floor($limit / 2)) {
                $cut = $limit;
            }

            $parts[]   = array('text' => trim(\mb_substr($remaining, 0, $cut)), 'hard' => true);
            $remaining = ltrim(\mb_substr($remaining, $cut));
        }

        if ('' !== trim($remaining)) {
            $parts[] = array('text' => trim($remaining), 'hard' => true);
        }

        return $parts;
    }
}
