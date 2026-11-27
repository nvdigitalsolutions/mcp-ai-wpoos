<?php

/**
 * List Slack conversations.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Get_Slack_Channels` (Proposal 164 section
 * 164.100). One `conversations.list` page, cursor in and cursor out.
 *
 * ## Corrections
 *
 * 1. **The limit is always sent.** WordPress only appended `limit` when the
 *    argument was between 1 and 1000, so 1000 and 0 were discarded and Slack's
 *    own default applied without the caller or the reply saying so. The port
 *    clamps to Slack's real ceiling of 999 and sends the effective value.
 * 2. **A row is projected, not forwarded.** Each channel keeps its id, name,
 *    privacy and archive flags and its member count. A direct message has no
 *    name and no member count, so those are `''` and `null` rather than absent
 *    keys the caller has to test for.
 * 3. **`types` is sent only when asked for.** WordPress documented a default of
 *    `public_channel` and never sent it; Slack's own default is the same, so
 *    the effective behaviour is unchanged and the reply no longer claims a
 *    filter the request did not carry.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class GetSlackChannelsTool extends SlackTool
{
    public function getSlug(): string
    {
        return 'get_slack_channels';
    }

    public function getName(): string
    {
        return 'Get Slack Channels';
    }

    public function getDescription(): string
    {
        return 'List Slack conversations the token can see: id, name, privacy, archive state and member count, one cursor-paginated page at a time. Use the returned next_cursor to fetch the next page.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'token'  => array(
                    'type'        => 'string',
                    'description' => 'Slack bot token (xoxb-) or user token (xoxp-) used for authentication.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'types'  => array(
                    'type'        => 'string',
                    'description' => 'Comma-separated conversation types: public_channel, private_channel, mpim, im. Slack defaults to public_channel.',
                ),
                'cursor' => array(
                    'type'        => 'string',
                    'description' => 'next_cursor from a previous page.',
                    'minLength'   => 1,
                ),
                'limit'  => array(
                    'type'        => 'integer',
                    'description' => 'Page size, 1-999. Defaults to 100.',
                    'minimum'     => 1,
                    'maximum'     => 999,
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

        $query = array(
            'limit' => $this->limit($arguments['limit'] ?? null, self::DEFAULT_LIMIT, self::MAX_LIMIT),
        );

        $types = $this->textArg($arguments, 'types');

        if ('' !== $types) {
            $query['types'] = $types;
        }

        $cursor = $this->textArg($arguments, 'cursor');

        if ('' !== $cursor) {
            $query['cursor'] = $cursor;
        }

        try {
            $response = $this->apiGet($token, 'conversations.list', $query);
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $channels = is_array($response['channels'] ?? null) ? $response['channels'] : array();
        $rows     = array();

        foreach ($channels as $channel) {
            $row = $this->channelRow($channel);

            if (array() !== $row) {
                $rows[] = $row;
            }
        }

        return $this->success(
            sprintf('Found %d Slack channel%s.', count($rows), 1 === count($rows) ? '' : 's'),
            array(
                'channels'    => $rows,
                'count'       => count($rows),
                'next_cursor' => $this->nextCursor($response),
            )
        );
    }
}
