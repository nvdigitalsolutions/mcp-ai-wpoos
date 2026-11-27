<?php

/**
 * Create a Google Chat space.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Create_Google_Chat_Space` (Proposal 164 section
 * 164.105). One `POST /v1/spaces`.
 *
 * ## Corrections
 *
 * 1. **An unsupported space type is refused.** WordPress replaced anything
 *    outside SPACE, GROUP_CHAT and DIRECT_MESSAGE with SPACE, so a typo created
 *    a space of the wrong kind and said nothing.
 * 2. **The reply is projected** to the new space's name, display name and type.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class CreateGoogleChatSpaceTool extends GoogleChatTool
{
    /**
     * The space types the Chat API accepts on create.
     *
     * @var list<string>
     */
    private const TYPES = array('SPACE', 'GROUP_CHAT', 'DIRECT_MESSAGE');

    public function getSlug(): string
    {
        return 'create_google_chat_space';
    }

    public function getName(): string
    {
        return 'Create Google Chat Space';
    }

    public function getDescription(): string
    {
        return 'Create a Google Chat space with a display name, of type SPACE, GROUP_CHAT or DIRECT_MESSAGE. Returns the new space resource name and display name.';
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
                'display_name' => array(
                    'type'        => 'string',
                    'description' => 'Space display name.',
                    'minLength'   => 1,
                ),
                'space_type'   => array(
                    'type'        => 'string',
                    'description' => 'Space type. Defaults to SPACE.',
                    'enum'        => array('SPACE', 'GROUP_CHAT', 'DIRECT_MESSAGE'),
                    'default'     => 'SPACE',
                ),
            ),
            'required'             => array('access_token', 'display_name'),
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

        $token = $this->accessToken($arguments);

        if (! is_string($token)) {
            return $token;
        }

        $name = $this->textArg($arguments, 'display_name');

        if ('' === $name) {
            return $this->errors->validationFailed(
                'A display name is required.',
                array('display_name' => array('A display name is required.'))
            );
        }

        $type = $this->textArg($arguments, 'space_type');

        if ('' === $type) {
            $type = 'SPACE';
        }

        if (! in_array($type, self::TYPES, true)) {
            return $this->errors->validationFailed(
                'The space type must be SPACE, GROUP_CHAT or DIRECT_MESSAGE.',
                array('space_type' => array('Unsupported space type.'))
            );
        }

        try {
            $response = $this->apiPost(
                $token,
                '/spaces',
                array(
                    'spaceType'   => $type,
                    'displayName' => $name,
                )
            );
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $row = $this->spaceRow($response);

        if ('' === $row['name']) {
            return $this->errors->create(
                'google_chat_invalid_response',
                'Google Chat created the space but returned no space object.'
            );
        }

        return $this->success('Created space ' . $row['name'] . '.', $row);
    }
}
