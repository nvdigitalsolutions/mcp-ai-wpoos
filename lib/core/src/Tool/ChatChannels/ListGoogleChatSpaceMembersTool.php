<?php

/**
 * List a Google Chat space's members.
 *
 * Port of `WP_MCP_AI_Pro_Tool_List_Google_Chat_Space_Members` (Proposal 164
 * section 164.105). One `GET /v1/{space}/members` page.
 *
 * ## Corrections
 *
 * 1. **The space name is validated** before it becomes a path.
 * 2. **Rows are projected** to membership name, member name and display name,
 *    member type, role and state, where WordPress returned Google's membership
 *    objects verbatim.
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

class ListGoogleChatSpaceMembersTool extends GoogleChatTool
{
    public function getSlug(): string
    {
        return 'list_google_chat_space_members';
    }

    public function getName(): string
    {
        return 'List Google Chat Space Members';
    }

    public function getDescription(): string
    {
        return 'List the members of a Google Chat space: membership name, member name and display name, member type, role and state. Pass the returned next_page_token back as page_token.';
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
                    'description' => 'How many memberships to return, 1-1000. Defaults to 100.',
                    'minimum'     => 1,
                    'maximum'     => 1000,
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

        $size  = $this->limit($arguments['page_size'] ?? null, self::DEFAULT_PAGE_SIZE, self::MAX_PAGE_SIZE);
        $query = array('pageSize' => $size);

        $pageToken = $this->textArg($arguments, 'page_token');

        if ('' !== $pageToken) {
            $query['pageToken'] = $pageToken;
        }

        try {
            $response = $this->apiGet($token, '/' . $space . '/members', $query);
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $rows = array();

        foreach (is_array($response['memberships'] ?? null) ? $response['memberships'] : array() as $membership) {
            if (! is_array($membership)) {
                continue;
            }

            $member = is_array($membership['member'] ?? null) ? $membership['member'] : array();

            $rows[] = array(
                'name'                 => $this->text($membership['name'] ?? ''),
                'member_name'          => $this->text($member['name'] ?? ''),
                'member_display_name'  => $this->text($member['displayName'] ?? ''),
                'member_type'          => $this->text($member['type'] ?? ''),
                'role'                 => $this->text($membership['role'] ?? ''),
                'state'                => $this->text($membership['state'] ?? ''),
            );
        }

        return $this->success(
            sprintf('Found %d member%s in %s.', count($rows), 1 === count($rows) ? '' : 's', $space),
            array(
                'members'         => $rows,
                'count'           => count($rows),
                'space'           => $space,
                'page_size'       => $size,
                'next_page_token' => $this->nextPageToken($response),
            )
        );
    }
}
