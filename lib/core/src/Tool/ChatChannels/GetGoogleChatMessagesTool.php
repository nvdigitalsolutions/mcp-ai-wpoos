<?php

/**
 * Read a Google Chat space's messages.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Get_Google_Chat_Messages` (Proposal 164 section
 * 164.105). One `GET /v1/{space}/messages` page.
 *
 * ## Corrections
 *
 * 1. **The space name is validated** before it becomes a path, where WordPress
 *    interpolated `sanitize_text_field()` output into the route.
 * 2. **Rows are projected** to message name, text, creation time, sender and
 *    thread, where WordPress returned Google's message objects verbatim.
 * 3. **`page_size` uses the numeric clamp** rather than `absint()`.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class GetGoogleChatMessagesTool extends GoogleChatTool
{
    private const DEFAULT_SIZE = 50;
    private const MAX_SIZE = 100;

    public function getSlug(): string
    {
        return 'get_google_chat_messages';
    }

    public function getName(): string
    {
        return 'Get Google Chat Messages';
    }

    public function getDescription(): string
    {
        return 'Read messages from a Google Chat space: message name, text, creation time, sender and thread. Pass the returned next_page_token back as page_token.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'access_token' => array(
                    'type'        => 'string',
                    'description' => 'Google OAuth access token with the chat.bot scope.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'space'        => array(
                    'type'        => 'string',
                    'description' => 'Space resource name, such as spaces/AAAAAbCdEfG.',
                    'pattern'     => '^spaces/[A-Za-z0-9_-]+$',
                ),
                'page_size'    => array(
                    'type'        => 'integer',
                    'description' => 'How many messages to return, 1-100. Defaults to 50.',
                    'minimum'     => 1,
                    'maximum'     => 100,
                ),
                'page_token'   => array(
                    'type'        => 'string',
                    'description' => 'next_page_token from a previous page.',
                    'minLength'   => 1,
                ),
            ),
            'required'             => array('access_token', 'space'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $token = $this->accessToken($arguments);

        if (! is_string($token)) {
            return $token;
        }

        $space = $this->space($arguments);

        if (! is_string($space)) {
            return $space;
        }

        $size  = $this->limit($arguments['page_size'] ?? null, self::DEFAULT_SIZE, self::MAX_SIZE);
        $query = array('pageSize' => $size);

        $pageToken = $this->textArg($arguments, 'page_token');

        if ('' !== $pageToken) {
            $query['pageToken'] = $pageToken;
        }

        try {
            $response = $this->apiGet($token, '/' . $space . '/messages', $query);
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $rows = array();

        foreach (is_array($response['messages'] ?? null) ? $response['messages'] : array() as $message) {
            if (! is_array($message)) {
                continue;
            }

            $sender = is_array($message['sender'] ?? null) ? $message['sender'] : array();
            $thread = is_array($message['thread'] ?? null) ? $message['thread'] : array();

            $rows[] = array(
                'name'                  => $this->text($message['name'] ?? ''),
                'text'                  => $this->text($message['text'] ?? ''),
                'create_time'           => $this->text($message['createTime'] ?? ''),
                'sender_name'           => $this->text($sender['name'] ?? ''),
                'sender_display_name'   => $this->text($sender['displayName'] ?? ''),
                'thread_name'           => $this->text($thread['name'] ?? ''),
            );
        }

        return $this->success(
            sprintf('Found %d message%s in %s.', count($rows), 1 === count($rows) ? '' : 's', $space),
            array(
                'messages'        => $rows,
                'count'           => count($rows),
                'space'           => $space,
                'page_size'       => $size,
                'next_page_token' => $this->nextPageToken($response),
            )
        );
    }
}
