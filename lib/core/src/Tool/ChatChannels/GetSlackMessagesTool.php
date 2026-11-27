<?php

/**
 * Read a Slack conversation's history.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Get_Slack_Messages` (Proposal 164 section
 * 164.100). One `conversations.history` page, newest first, with both the
 * cursor and the timestamp bounds Slack offers.
 *
 * ## Corrections
 *
 * 1. **The limit is always sent and clamped to 999.** WordPress discarded a
 *    limit outside 1-1000, which silently meant "Slack's default"; Slack's own
 *    documented maximum is 999, so 1000 was never valid.
 * 2. **Rows are projected.** Each message keeps its timestamp, type, subtype,
 *    author (`user` for people, `bot_id` for apps), text, thread parent and
 *    reply count. WordPress forwarded Slack's document, whose remaining keys
 *    (attachments, reactions, edited) are not part of this tool's contract.
 * 3. **`has_more` is always a boolean.** When the key is absent WordPress
 *    returned nothing, leaving the caller to distinguish "no more pages" from
 *    "unknown".
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class GetSlackMessagesTool extends SlackTool
{
    public function getSlug(): string
    {
        return 'get_slack_messages';
    }

    public function getName(): string
    {
        return 'Get Slack Messages';
    }

    public function getDescription(): string
    {
        return 'Read one page of a Slack conversation\'s history, newest first: timestamp, author, text, thread parent and reply count. Accepts a cursor and optional oldest/latest Unix-timestamp bounds.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'token'   => array(
                    'type'        => 'string',
                    'description' => 'Slack bot token (xoxb-) or user token (xoxp-) used for authentication.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'channel' => array(
                    'type'        => 'string',
                    'description' => 'Conversation ID to read, as returned by get_slack_channels.',
                    'minLength'   => 1,
                ),
                'cursor'  => array(
                    'type'        => 'string',
                    'description' => 'next_cursor from a previous page.',
                    'minLength'   => 1,
                ),
                'limit'   => array(
                    'type'        => 'integer',
                    'description' => 'Page size, 1-999. Defaults to 100.',
                    'minimum'     => 1,
                    'maximum'     => 999,
                ),
                'oldest'  => array(
                    'type'        => 'string',
                    'description' => 'Only messages after this Unix timestamp, e.g. 1512085950.000216.',
                    'minLength'   => 1,
                ),
                'latest'  => array(
                    'type'        => 'string',
                    'description' => 'Only messages before this Unix timestamp.',
                    'minLength'   => 1,
                ),
            ),
            'required'             => array('token', 'channel'),
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

        $channel = $this->textArg($arguments, 'channel');

        if ('' === $channel) {
            return $this->errors->validationFailed(
                'A channel identifier is required.',
                array('channel' => array('A channel identifier is required.'))
            );
        }

        $query = array(
            'channel' => $channel,
            'limit'   => $this->limit($arguments['limit'] ?? null, self::DEFAULT_LIMIT, self::MAX_LIMIT),
        );

        foreach (array('cursor', 'oldest', 'latest') as $key) {
            $value = $this->textArg($arguments, $key);

            if ('' !== $value) {
                $query[$key] = $value;
            }
        }

        try {
            $response = $this->apiGet($token, 'conversations.history', $query);
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $messages = is_array($response['messages'] ?? null) ? $response['messages'] : array();
        $rows     = array();

        foreach ($messages as $message) {
            $row = $this->messageRow($message);

            if (array() !== $row) {
                $rows[] = $row;
            }
        }

        return $this->success(
            sprintf('Found %d message%s in %s.', count($rows), 1 === count($rows) ? '' : 's', $channel),
            array(
                'messages'    => $rows,
                'count'       => count($rows),
                'has_more'    => (bool) ($response['has_more'] ?? false),
                'next_cursor' => $this->nextCursor($response),
            )
        );
    }

    /**
     * One message, projected to the documented fields.
     *
     * @param mixed $message One entry from `messages`.
     *
     * @return array<string, mixed>
     */
    private function messageRow(mixed $message): array
    {
        if (! is_array($message)) {
            return array();
        }

        $ts      = $this->text($message['ts'] ?? '');
        $thread  = $this->text($message['thread_ts'] ?? '');
        $type    = $this->text($message['type'] ?? 'message');
        $subtype = $this->text($message['subtype'] ?? '');

        return array(
            'ts'          => $ts,
            'type'        => '' !== $type ? $type : 'message',
            'subtype'     => '' !== $subtype ? $subtype : null,
            'user'        => $this->text($message['user'] ?? ''),
            'bot_id'      => $this->text($message['bot_id'] ?? ''),
            'text'        => $this->text($message['text'] ?? ''),
            'thread_ts'   => '' !== $thread ? $thread : null,
            'reply_count' => is_numeric($message['reply_count'] ?? null)
                ? (int) $message['reply_count']
                : 0,
        );
    }
}
