<?php

/**
 * Add a member to a Google Chat space.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Add_Google_Chat_Space_Member` (Proposal 164
 * section 164.105). One `POST /v1/{space}/members`.
 *
 * ## Corrections
 *
 * 1. **An unsupported role is refused.** WordPress replaced anything outside
 *    ROLE_MEMBER and ROLE_MANAGER with ROLE_MEMBER, so a typo added a plain
 *    member where a manager was intended.
 * 2. **The space and member names are validated** before the first becomes a
 *    path and the second reaches the body.
 * 3. **The reply is projected** to the membership, where WordPress returned
 *    Google's document.
 *
 * The member type is inferred from the name prefix, as WordPress did: a
 * `users/` member is HUMAN and a `groups/` member is BOT.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class AddGoogleChatSpaceMemberTool extends GoogleChatTool
{
    /**
     * The membership roles the Chat API accepts.
     *
     * @var list<string>
     */
    private const ROLES = array('ROLE_MEMBER', 'ROLE_MANAGER');

    public function getSlug(): string
    {
        return 'add_google_chat_space_member';
    }

    public function getName(): string
    {
        return 'Add Google Chat Space Member';
    }

    public function getDescription(): string
    {
        return 'Add a user or Google Group to a Google Chat space as a member or manager. Returns the membership resource name, role and state.';
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
                'member_name'  => array(
                    'type'        => 'string',
                    'description' => 'Member resource name: users/{id} or groups/{id}.',
                    'pattern'     => '^(users|groups)/[A-Za-z0-9@._-]+$',
                ),
                'role'         => array(
                    'type'        => 'string',
                    'description' => 'Membership role. Defaults to ROLE_MEMBER.',
                    'enum'        => array('ROLE_MEMBER', 'ROLE_MANAGER'),
                    'default'     => 'ROLE_MEMBER',
                ),
            ),
            'required'             => array('access_token', 'space', 'member_name'),
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

        $space = $this->space($arguments);

        if (! is_string($space)) {
            return $space;
        }

        $member = $this->textArg($arguments, 'member_name');

        if (! $this->isMemberName($member)) {
            return $this->errors->validationFailed(
                'A member name is required, in the form users/{id} or groups/{id}.',
                array('member_name' => array('Invalid member name.'))
            );
        }

        $role = $this->textArg($arguments, 'role');

        if ('' === $role) {
            $role = 'ROLE_MEMBER';
        }

        if (! in_array($role, self::ROLES, true)) {
            return $this->errors->validationFailed(
                'The role must be ROLE_MEMBER or ROLE_MANAGER.',
                array('role' => array('Unsupported role.'))
            );
        }

        try {
            $response = $this->apiPost(
                $token,
                '/' . $space . '/members',
                array(
                    'member' => array(
                        'name' => $member,
                        'type' => 0 === strncmp($member, 'groups/', 7) ? 'BOT' : 'HUMAN',
                    ),
                    'role'   => $role,
                )
            );
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $membership = is_array($response['member'] ?? null) ? $response['member'] : array();

        return $this->success(
            'Member ' . $member . ' added to ' . $space . '.',
            array(
                'membership'          => $this->text($response['name'] ?? ''),
                'member_name'         => $this->text($membership['name'] ?? $member),
                'member_display_name' => $this->text($membership['displayName'] ?? ''),
                'role'                => $this->text($response['role'] ?? $role),
                'state'               => $this->text($response['state'] ?? ''),
            )
        );
    }
}
