<?php

/**
 * Read Apple Messages for Business conversation history through a provider.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Get_Apple_Messages` (Proposal 164 section
 * 164.108). One GET to the caller's MSP endpoint with the filters as query
 * parameters.
 *
 * See `AppleTool` for the platform model and the shared corrections. Two are
 * specific to this tool:
 *
 * 1. **The query is built from validated values.** WordPress passed
 *    `sanitize_text_field()` output into `add_query_arg()`, which is safe for
 *    the string but leaves an unbounded cursor; the cursors are bounded here and
 *    the whole query is RFC 3986 encoded.
 * 2. **`status` has a default.** The schema declares `open` and WordPress then
 *    omitted the parameter entirely when the caller passed nothing, so the
 *    provider chose — which is not the documented default.
 *
 * The response schema belongs to the caller's gateway, so the decoded document
 * is returned under `response` and any conventional collection is also surfaced
 * as `items`. See `GatewayTool` for why this family does not project to a
 * guessed field set.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class GetAppleMessagesTool extends AppleTool
{
    /**
     * The conversation statuses a provider may accept.
     *
     * @var list<string>
     */
    private const STATUSES = array('open', 'closed', 'all');

    public function getSlug(): string
    {
        return 'get_apple_messages';
    }

    public function getName(): string
    {
        return 'Get Apple Messages';
    }

    public function getDescription(): string
    {
        return 'Reads Apple Messages for Business conversation history through your Messaging Service Provider. Omit conversation_id to list recent conversations; supply it to read one thread.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'msp_api_url'     => array(
                    'type'        => 'string',
                    'description' => 'Your provider\'s public https endpoint for conversation history.',
                ),
                'api_key'         => array(
                    'type'        => 'string',
                    'description' => 'Bearer token issued by your provider.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'business_id'     => array(
                    'type'        => 'string',
                    'description' => 'Apple Messages for Business id issued during registration.',
                    'minLength'   => 1,
                    'maxLength'   => 128,
                ),
                'conversation_id' => array(
                    'type'        => 'string',
                    'description' => 'One thread to read. Omit to list conversations.',
                ),
                'limit'           => array(
                    'type'        => 'integer',
                    'description' => 'Items to return, 1-100. Defaults to 25.',
                    'minimum'     => 1,
                    'maximum'     => 100,
                    'default'     => 25,
                ),
                'after'           => array(
                    'type'        => 'string',
                    'description' => 'Cursor from a previous response: items after this point.',
                    'maxLength'   => 512,
                ),
                'before'          => array(
                    'type'        => 'string',
                    'description' => 'Cursor from a previous response: items before this point.',
                    'maxLength'   => 512,
                ),
                'status'          => array(
                    'type'        => 'string',
                    'description' => 'Conversation status filter. Defaults to open.',
                    'enum'        => self::STATUSES,
                    'default'     => 'open',
                ),
            ),
            'required'             => array('msp_api_url', 'api_key', 'business_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $url = $this->gatewayUrl($arguments, 'msp_api_url', 'provider endpoint');

        if (! is_string($url)) {
            return $url;
        }

        $token = $this->gatewayKey($arguments);

        if (! is_string($token)) {
            return $token;
        }

        $business = $this->businessId($arguments);

        if (! is_string($business)) {
            return $business;
        }

        $status = $this->textArg($arguments, 'status');

        if ('' === $status) {
            $status = 'open';
        }

        if (! in_array($status, self::STATUSES, true)) {
            return $this->errors->validationFailed(
                'status must be open, closed or all.',
                array('status' => array('Unsupported status.'))
            );
        }

        $query = array(
            'businessId' => $business,
            'limit'      => $this->limit($arguments['limit'] ?? null, 25, 100),
            'status'     => $status,
        );

        $conversation = $this->opaque($arguments, 'conversation_id');

        if ('' !== $conversation) {
            $query['conversationId'] = $conversation;
        }

        foreach (array('after', 'before') as $cursor) {
            $value = $this->opaque($arguments, $cursor);

            if ('' !== $value) {
                $query[$cursor] = $value;
            }
        }

        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $endpoint = $url . (false === strpos($url, '?') ? '?' : '&') . $queryString;

        try {
            $response = $this->gatewayGet($endpoint, $token);
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $items = $this->items($response);

        return $this->success(
            sprintf('Retrieved %d Apple Messages item(s).', count($items)),
            array(
                'business_id'     => $business,
                'conversation_id' => $conversation,
                'status'          => $status,
                'items'           => $items,
                'count'           => count($items),
                'response'        => $response,
            )
        );
    }
}
