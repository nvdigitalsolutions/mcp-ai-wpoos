<?php

/**
 * Create a Slack channel.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Create_Slack_Channel` (Proposal 164 section
 * 164.100). One `conversations.create` call; the channel name is validated by
 * Slack, not here, so the provider's own `invalid_name_*` code reaches the
 * caller unchanged.
 *
 * ## Corrections
 *
 * 1. **`is_private` is parsed, not cast.** WordPress cast the argument with
 *    `(bool)`, which turns the string `"false"` into `true` — a caller that
 *    sends form-encoded booleans would create a private channel believing it
 *    asked for a public one. The port parses booleans properly.
 * 2. **The created channel is projected and required.** WordPress returned the
 *    document as-is, so an `ok: true` answer without a channel looked like a
 *    success. The reply carries the id, name, privacy and creation time, and a
 *    missing channel is reported as malformed.
 * 3. **`num_members` is null, not zero.** Slack omits the member count on a
 *    freshly created channel; reporting zero would be a fabricated fact.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class CreateSlackChannelTool extends SlackTool
{
    public function getSlug(): string
    {
        return 'create_slack_channel';
    }

    public function getName(): string
    {
        return 'Create Slack Channel';
    }

    public function getDescription(): string
    {
        return 'Create a public or private Slack channel. Names may contain lowercase letters, numbers, hyphens and underscores and are at most 80 characters. The token needs a channel-management scope.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'token'      => array(
                    'type'        => 'string',
                    'description' => 'Slack bot token (xoxb-) or user token (xoxp-) used for authentication.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'name'       => array(
                    'type'        => 'string',
                    'description' => 'Channel name: lowercase letters, numbers, hyphens and underscores, at most 80 characters.',
                    'minLength'   => 1,
                    'maxLength'   => 80,
                ),
                'is_private' => array(
                    'type'        => 'boolean',
                    'description' => 'Create a private channel. Defaults to false.',
                ),
            ),
            'required'             => array('token', 'name'),
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

        $name = $this->textArg($arguments, 'name');

        if ('' === $name) {
            return $this->errors->validationFailed(
                'A channel name is required.',
                array('name' => array('A channel name is required.'))
            );
        }

        $payload = array('name' => $name);

        if (array_key_exists('is_private', $arguments)) {
            $payload['is_private'] = $this->boolParam($arguments, 'is_private');
        }

        try {
            $response = $this->apiPost($token, 'conversations.create', $payload);
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $channel = is_array($response['channel'] ?? null) ? $response['channel'] : array();
        $row     = $this->channelRow($channel);

        if (array() === $row || '' === $row['id']) {
            return $this->errors->create(
                'slack_invalid_response',
                'Slack created the channel but returned no channel object.'
            );
        }

        return $this->success(
            'Created Slack channel ' . ('' !== $row['name'] ? $row['name'] : $name) . '.',
            array(
                'id'          => $row['id'],
                'name'        => '' !== $row['name'] ? $row['name'] : $name,
                'is_private'  => $row['is_private'],
                'created'     => is_numeric($channel['created'] ?? null)
                    ? (int) $channel['created']
                    : null,
                'num_members' => $row['num_members'],
            )
        );
    }
}
