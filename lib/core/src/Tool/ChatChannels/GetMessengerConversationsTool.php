<?php

/**
 * List Messenger conversations.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Get_Messenger_Conversations` (Proposal 164
 * section 164.105). One `GET /me/conversations?platform=messenger`.
 *
 * ## Corrections
 *
 * 1. **The list is paginated by cursor.** WordPress sent no limit and returned
 *    whatever Graph gave, with no way to reach a second page. The port sends
 *    `limit` and returns `next_cursor` from `paging.cursors.after`.
 * 2. **`paging.next` is not returned.** Meta builds that URL with the access
 *    token inside it; handing it to a caller would leak the token. The cursor
 *    is the safe handle.
 * 3. **Rows are projected** to id and snippet, which is what the `fields`
 *    parameter asked for.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class GetMessengerConversationsTool extends MessengerTool
{
    private const DEFAULT_LIMIT = 25;
    private const MAX_LIMIT = 100;

    public function getSlug(): string
    {
        return 'get_messenger_conversations';
    }

    public function getName(): string
    {
        return 'Get Messenger Conversations';
    }

    public function getDescription(): string
    {
        return 'List Facebook Messenger conversations for the page: id and last-message snippet. Pass the returned next_cursor back as after to read the next page.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'access_token' => array(
                    'type'        => 'string',
                    'description' => 'Facebook Page access token.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'limit'        => array(
                    'type'        => 'integer',
                    'description' => 'How many conversations to return, 1-100. Defaults to 25.',
                    'minimum'     => 1,
                    'maximum'     => 100,
                ),
                'after'        => array(
                    'type'        => 'string',
                    'description' => 'next_cursor from a previous page.',
                    'minLength'   => 1,
                ),
            ),
            'required'             => array('access_token'),
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

        $limit = $this->limit($arguments['limit'] ?? null, self::DEFAULT_LIMIT, self::MAX_LIMIT);

        $query = array(
            'platform' => 'messenger',
            'fields'   => 'id,snippet',
            'limit'    => $limit,
        );

        $after = $this->textArg($arguments, 'after');

        if ('' !== $after) {
            $query['after'] = $after;
        }

        try {
            $response = $this->apiGet($token, '/me/conversations', $query);
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $rows = array();

        foreach (is_array($response['data'] ?? null) ? $response['data'] : array() as $conversation) {
            if (! is_array($conversation)) {
                continue;
            }

            $rows[] = array(
                'id'      => $this->text($conversation['id'] ?? ''),
                'snippet' => $this->text($conversation['snippet'] ?? ''),
            );
        }

        return $this->success(
            sprintf('Found %d conversation%s.', count($rows), 1 === count($rows) ? '' : 's'),
            array(
                'conversations' => $rows,
                'count'         => count($rows),
                'limit'         => $limit,
                'next_cursor'   => $this->nextCursor($response),
            )
        );
    }
}
