<?php

/**
 * Post a message to a Microsoft Teams channel.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Send_Teams_Message` (Proposal 164 section
 * 164.104). One `POST /teams/{team_id}/channels/{channel_id}/messages`.
 *
 * ## Corrections
 *
 * 1. **Both ids are validated** before they become path segments, where
 *    WordPress passed `sanitize_text_field()` output into the URL.
 * 2. **Success is any 2xx.** WordPress required exactly 201, which is what
 *    Graph sends; the broader check keeps a conforming proxy answer working.
 * 3. **The reply is projected** to the created message id, its timestamp and
 *    its web URL, where WordPress returned the Graph document.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class SendTeamsMessageTool extends GraphTool
{
    public function getSlug(): string
    {
        return 'send_teams_message';
    }

    public function getName(): string
    {
        return 'Send Teams Message';
    }

    public function getDescription(): string
    {
        return 'Post a text message to a Microsoft Teams channel. Returns the created message id, timestamp and web URL.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'token'      => array(
                    'type'        => 'string',
                    'description' => 'Microsoft Graph access token.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'team_id'    => array(
                    'type'        => 'string',
                    'description' => 'Team id as a GUID.',
                    'pattern'     => '^[0-9a-fA-F-]{36}$',
                ),
                'channel_id' => array(
                    'type'        => 'string',
                    'description' => 'Channel id, such as 19:abc123@thread.tacv2.',
                    'minLength'   => 1,
                ),
                'content'    => array(
                    'type'        => 'string',
                    'description' => 'Message text.',
                    'minLength'   => 1,
                ),
            ),
            'required'             => array('token', 'team_id', 'channel_id', 'content'),
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

        $teamId = $this->teamId($arguments);

        if (! is_string($teamId)) {
            return $teamId;
        }

        $channelId = $this->channelId($arguments);

        if (! is_string($channelId)) {
            return $channelId;
        }

        $content = $this->textArg($arguments, 'content');

        if ('' === $content) {
            return $this->errors->validationFailed(
                'Message content is required.',
                array('content' => array('Message content must be provided.'))
            );
        }

        try {
            $response = $this->apiPost(
                $token,
                '/teams/' . $teamId . '/channels/' . $channelId . '/messages',
                array(
                    'body' => array(
                        'content' => $content,
                    ),
                )
            );
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $row = $this->chatMessageRow($response);

        if ('' === $row['id']) {
            return $this->errors->create(
                'graph_invalid_response',
                'Microsoft Graph accepted the message but returned no message object.'
            );
        }

        return $this->success(
            'Message posted to the Teams channel.',
            array(
                'id'                => $row['id'],
                'created_date_time' => $row['created_date_time'],
                'web_url'           => $row['web_url'],
            )
        );
    }
}
