<?php

/**
 * Read recent Twitter/X Direct Message events.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Get_Twitter_DMs` (Proposal 164 section 164.107).
 * Two routes: the account-wide event feed, and one conversation's events when
 * `dm_conversation_id` is supplied.
 *
 * ## Corrections
 *
 * 1. **The conversation id is validated, not sanitised.** WordPress ran
 *    `sanitize_text_field()` and interpolated the result into
 *    `/2/dm_conversations/{id}/dm_events`. The safe subset is enforced here and
 *    the value is encoded once.
 * 2. **The pagination token is checked against its documented floor.**
 *    `pagination_token` is base32hex and at least 16 characters; the floor
 *    catches an event id or a page size passed where a token belongs, which
 *    WordPress forwarded and the API answered with a 400.
 * 3. **Text is not the only field projected.** WordPress returned the provider
 *    document verbatim, so a caller had to know the v2 envelope to find the
 *    page token. The projection names the page cursor `next_token`.
 *
 * ## Adaptations
 *
 * `event_types` is fixed to `MessageCreate`, as WordPress fixed it: a caller
 * asking for DMs does not want `ParticipantsJoin` rows in the same list, and
 * the tool that would surface them does not exist. Say so in the description
 * rather than pretending the parameter is absent.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class GetTwitterDmsTool extends TwitterTool
{
    public function getSlug(): string
    {
        return 'get_twitter_dms';
    }

    public function getName(): string
    {
        return 'Get Twitter/X DMs';
    }

    public function getDescription(): string
    {
        return 'Retrieves recent Direct Message events for the authenticated user, newest first, optionally narrowed to one conversation. Only message events are returned, not join or leave events.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'bearer_token'       => array(
                    'type'        => 'string',
                    'description' => 'OAuth 2.0 user token carrying the dm.read scope. An app-only bearer token cannot read DMs.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'max_results'        => array(
                    'type'        => 'integer',
                    'description' => 'Events per page, 1-100. Defaults to 25.',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_DM_EVENTS,
                    'default'     => self::DEFAULT_DM_EVENTS,
                ),
                'dm_conversation_id' => array(
                    'type'        => 'string',
                    'description' => 'Narrows the read to one conversation. A one-to-one conversation id is two user ids joined by a hyphen; a group conversation id is numeric.',
                ),
                'pagination_token'   => array(
                    'type'        => 'string',
                    'description' => 'next_token from a previous page.',
                    'minLength'   => self::MIN_PAGINATION_TOKEN,
                ),
            ),
            'required'             => array('bearer_token'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $token = $this->bearer($arguments);

        if (! is_string($token)) {
            return $token;
        }

        $conversation = '';

        if ('' !== $this->textArg($arguments, 'dm_conversation_id')) {
            $conversation = $this->conversationId($arguments['dm_conversation_id'] ?? null);

            if ('' === $conversation) {
                return $this->errors->validationFailed(
                    'dm_conversation_id may contain only letters, digits, underscores and hyphens.',
                    array('dm_conversation_id' => array('Not a conversation id.'))
                );
            }
        }

        $pageToken = $this->paginationToken($arguments);

        if (! is_string($pageToken)) {
            return $pageToken;
        }

        $query = array(
            'max_results' => $this->limit(
                $arguments['max_results'] ?? null,
                self::DEFAULT_DM_EVENTS,
                self::MAX_DM_EVENTS
            ),
            'event_types' => 'MessageCreate',
        );

        if ('' !== $pageToken) {
            $query['pagination_token'] = $pageToken;
        }

        $url = self::API . ('' !== $conversation
            ? '/2/dm_conversations/' . rawurlencode($conversation) . '/dm_events'
            : '/2/dm_events')
            . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        try {
            $payload = $this->authorized('GET', $url, $token);
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $rows = is_array($payload['data'] ?? null) ? $payload['data'] : array();
        $events = array();

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $events[] = array(
                'id'              => $this->scalarText($row['id'] ?? null),
                'event_type'      => $this->scalarText($row['event_type'] ?? null),
                'text'            => $this->scalarText($row['text'] ?? null),
                'created_at'      => $this->scalarText($row['created_at'] ?? null),
                'sender_id'       => $this->scalarText($row['sender_id'] ?? null),
                'conversation_id' => $this->scalarText($row['dm_conversation_id'] ?? null),
            );
        }

        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : array();

        return $this->success(
            sprintf('Retrieved %d Twitter/X DM event(s).', count($events)),
            array(
                'conversation_id' => $conversation,
                'events'          => $events,
                'next_token'      => $this->scalarText($meta['next_token'] ?? null),
                'previous_token'  => $this->scalarText($meta['previous_token'] ?? null),
                'result_count'    => count($events),
            )
        );
    }
}
