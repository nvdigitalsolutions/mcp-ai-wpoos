<?php

/**
 * Read pending Telegram updates.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Get_Telegram_Updates` (Proposal 164 section
 * 164.102). One `getUpdates` call; the offset is the acknowledgement cursor —
 * passing the returned `next_offset` confirms everything up to and including
 * the last delivered update.
 *
 * ## Corrections
 *
 * 1. **Updates are flattened, not forwarded.** WordPress returned Telegram's
 *    update objects as-is, whose shape depends on the update type and nests the
 *    message under `callback_query.message` for callbacks. Each row here is
 *    update id, type, message id, chat id, date, text and sender, whatever the
 *    type; Telegram's document stays in the provider layer.
 * 2. **`next_offset` is returned.** Without it a caller had to know that the
 *    rule is "last update id plus one".
 * 3. **A non-numeric limit falls back to the default, not to one.** WordPress
 *    ran `absint()`, so `"twenty"` became 0 and then 1; here a value that is
 *    not numeric means "not specified" and the default of 100 applies.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class GetTelegramUpdatesTool extends TelegramTool
{
    public function getSlug(): string
    {
        return 'get_telegram_updates';
    }

    public function getName(): string
    {
        return 'Get Telegram Updates';
    }

    public function getDescription(): string
    {
        return 'Read pending Telegram updates for the bot, flattened to update id, type, message id, chat id, date, text and sender. Pass next_offset as offset to acknowledge the batch. Fails while a webhook is set.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'token'  => array(
                    'type'        => 'string',
                    'description' => 'Telegram bot token.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'offset' => array(
                    'type'        => 'integer',
                    'description' => 'Identifier of the first update to return; use next_offset from the previous call to acknowledge.',
                    'minimum'     => 0,
                    'default'     => 0,
                ),
                'limit'  => array(
                    'type'        => 'integer',
                    'description' => 'How many updates to return, 1-100. Defaults to 100.',
                    'minimum'     => 1,
                    'maximum'     => 100,
                    'default'     => 100,
                ),
            ),
            'required'             => array('token'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $token = $this->token($arguments);

        if (! is_string($token)) {
            return $token;
        }

        $offset = array_key_exists('offset', $arguments) && is_numeric($arguments['offset'])
            ? max(0, (int) $arguments['offset'])
            : 0;

        $limit = $this->limit($arguments['limit'] ?? null, self::DEFAULT_LIMIT, self::MAX_LIMIT);

        try {
            $response = $this->apiGet(
                $token,
                'getUpdates',
                array(
                    'offset' => $offset,
                    'limit'  => $limit,
                )
            );
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $updates = $this->result($response);
        $rows    = array();
        $lastId  = 0;

        if (is_array($updates)) {
            foreach ($updates as $update) {
                $row = $this->updateRow($update);

                if (array() !== $row) {
                    $rows[] = $row;
                    $lastId = max($lastId, $row['update_id']);
                }
            }
        }

        return $this->success(
            sprintf('Found %d update%s.', count($rows), 1 === count($rows) ? '' : 's'),
            array(
                'updates'     => $rows,
                'count'       => count($rows),
                'offset'      => $offset,
                'limit'       => $limit,
                'next_offset' => array() !== $rows ? $lastId + 1 : null,
            )
        );
    }

    /**
     * One update, flattened to the documented fields.
     *
     * The carrier key — `message`, `edited_message`, `callback_query`,
     * `channel_post`, … — is reported as `type`. For a callback query the
     * message sits one level down, and the sender sits on the query.
     *
     * @param mixed $update One Telegram update.
     *
     * @return array<string, mixed>
     */
    private function updateRow(mixed $update): array
    {
        if (! is_array($update)) {
            return array();
        }

        $type = '';

        foreach ($update as $key => $value) {
            if ('update_id' !== $key) {
                $type = (string) $key;
                break;
            }
        }

        if ('' === $type) {
            return array();
        }

        $payload = is_array($update[$type] ?? null) ? $update[$type] : array();
        $message = is_array($payload['message'] ?? null) ? $payload['message'] : $payload;

        $from = is_array($payload['from'] ?? null)
            ? $payload['from']
            : (is_array($message['from'] ?? null) ? $message['from'] : array());

        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : array();

        $text = $message['text'] ?? ($payload['data'] ?? '');

        return array(
            'update_id'     => is_numeric($update['update_id'] ?? null) ? (int) $update['update_id'] : 0,
            'type'          => $type,
            'message_id'    => is_numeric($message['message_id'] ?? null) ? (int) $message['message_id'] : null,
            'chat_id'       => is_scalar($chat['id'] ?? null) ? (string) $chat['id'] : null,
            'date'          => is_numeric($message['date'] ?? null) ? (int) $message['date'] : null,
            'text'          => $this->text($text),
            'from_id'       => is_scalar($from['id'] ?? null) ? (string) $from['id'] : '',
            'from_username' => $this->text($from['username'] ?? ''),
        );
    }
}
