<?php

/**
 * List Google Chat spaces.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Get_Google_Chat_Spaces` (Proposal 164 section
 * 164.105). One `GET /v1/spaces` page.
 *
 * ## Corrections
 *
 * 1. **Rows are projected** to resource name, display name and type, where
 *    WordPress returned Google's space objects verbatim.
 * 2. **`page_size` uses the numeric clamp**, so a non-numeric value means the
 *    default rather than `absint()` and then one.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class GetGoogleChatSpacesTool extends GoogleChatTool
{
    public function getSlug(): string
    {
        return 'get_google_chat_spaces';
    }

    public function getName(): string
    {
        return 'Get Google Chat Spaces';
    }

    public function getDescription(): string
    {
        return 'List Google Chat spaces the bot can see: resource name, display name and type. Pass the returned next_page_token back as page_token.';
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
                'page_size'    => array(
                    'type'        => 'integer',
                    'description' => 'How many spaces to return, 1-1000. Defaults to 100.',
                    'minimum'     => 1,
                    'maximum'     => 1000,
                ),
                'page_token'   => array(
                    'type'        => 'string',
                    'description' => 'next_page_token from a previous page.',
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

        $size  = $this->limit($arguments['page_size'] ?? null, self::DEFAULT_PAGE_SIZE, self::MAX_PAGE_SIZE);
        $query = array('pageSize' => $size);

        $pageToken = $this->textArg($arguments, 'page_token');

        if ('' !== $pageToken) {
            $query['pageToken'] = $pageToken;
        }

        try {
            $response = $this->apiGet($token, '/spaces', $query);
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $rows = array();

        foreach (is_array($response['spaces'] ?? null) ? $response['spaces'] : array() as $space) {
            $row = $this->spaceRow($space);

            if (array() !== $row) {
                $rows[] = $row;
            }
        }

        return $this->success(
            sprintf('Found %d space%s.', count($rows), 1 === count($rows) ? '' : 's'),
            array(
                'spaces'         => $rows,
                'count'          => count($rows),
                'page_size'      => $size,
                'next_page_token' => $this->nextPageToken($response),
            )
        );
    }
}
