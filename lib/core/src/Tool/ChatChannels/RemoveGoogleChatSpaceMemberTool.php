<?php

/**
 * Remove a member from a Google Chat space.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Remove_Google_Chat_Space_Member` (Proposal 164
 * section 164.105). One `DELETE /v1/{membership}`.
 *
 * ## Corrections
 *
 * 1. **The membership name is validated** before it becomes a path, where
 *    WordPress used the same check but reported a generic error afterwards.
 * 2. **The reply says what was removed.** WordPress returned Google's empty
 *    body, so a caller could not tell whether the membership named was the one
 *    that went.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class RemoveGoogleChatSpaceMemberTool extends GoogleChatTool
{
    public function getSlug(): string
    {
        return 'remove_google_chat_space_member';
    }

    public function getName(): string
    {
        return 'Remove Google Chat Space Member';
    }

    public function getDescription(): string
    {
        return 'Remove one membership from a Google Chat space. Takes the membership resource name spaces/{id}/members/{id} and reports what was removed.';
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
                'membership'   => array(
                    'type'        => 'string',
                    'description' => 'Membership resource name, spaces/{id}/members/{id}.',
                    'pattern'     => '^spaces/[A-Za-z0-9_-]+/members/[A-Za-z0-9_-]+$',
                ),
            ),
            'required'             => array('access_token', 'membership'),
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

        $membership = $this->membership($arguments);

        if (! is_string($membership)) {
            return $membership;
        }

        try {
            $this->apiDelete($token, '/' . $membership);
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        return $this->success(
            'Removed ' . $membership . '.',
            array(
                'membership' => $membership,
                'removed'    => true,
            )
        );
    }
}
