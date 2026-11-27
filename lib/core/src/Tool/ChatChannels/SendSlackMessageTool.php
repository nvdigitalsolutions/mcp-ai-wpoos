<?php

/**
 * Post a message to Slack.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Send_Slack_Message` (Proposal 164 section
 * 164.100). One `chat.postMessage` call; the reply is the message timestamp
 * that a threaded reply or a permalink needs.
 *
 * ## Corrections
 *
 * 1. **A successful call must return a timestamp.** WordPress returned the
 *    provider document as-is, so an `ok: true` answer without a `ts` looked
 *    like a success with nothing in it. Slack always sends one; its absence is
 *    reported as a malformed response instead.
 * 2. **`text` and `blocks` are not alternatives.** Slack uses `text` as the
 *    notification fallback when blocks are present, which is why the argument
 *    stays required rather than becoming optional.
 *
 * ## Adaptations
 *
 * The per-slug capability and timeout filters, the event log and the
 * multisite membership check are dropped: the first two are WordPress
 * extension points with no core equivalent, the third is the adapter's
 * authorization seam, and logging is the host's concern.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class SendSlackMessageTool extends SlackTool
{
    public function getSlug(): string
    {
        return 'send_slack_message';
    }

    public function getName(): string
    {
        return 'Send Slack Message';
    }

    public function getDescription(): string
    {
        return 'Post a message to a Slack channel, private group or direct message, optionally in a thread and optionally with Block Kit blocks. Returns the message timestamp (ts), which a threaded reply or a permalink needs.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'token'     => array(
                    'type'        => 'string',
                    'description' => 'Slack bot token (xoxb-) or user token (xoxp-) used for authentication.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'channel'   => array(
                    'type'        => 'string',
                    'description' => 'Channel ID, direct message ID, or channel name such as #general.',
                    'minLength'   => 1,
                ),
                'text'      => array(
                    'type'        => 'string',
                    'description' => 'Message text. When blocks are also sent, Slack uses this as the notification fallback.',
                    'minLength'   => 1,
                ),
                'blocks'    => array(
                    'type'        => 'array',
                    'description' => 'Optional Block Kit blocks for rich formatting.',
                    'items'       => array('type' => 'object'),
                ),
                'thread_ts' => array(
                    'type'        => 'string',
                    'description' => 'Optional timestamp of a parent message, to post this message as a reply in that thread.',
                    'minLength'   => 1,
                ),
            ),
            'required'             => array('token', 'channel', 'text'),
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

        $channel = $this->textArg($arguments, 'channel');

        if ('' === $channel) {
            return $this->errors->validationFailed(
                'A target channel is required.',
                array('channel' => array('A channel identifier is required.'))
            );
        }

        $text = $this->textArg($arguments, 'text');

        if ('' === $text) {
            return $this->errors->validationFailed(
                'Message text is required.',
                array('text' => array('Message text must be provided.'))
            );
        }

        $payload = array(
            'channel' => $channel,
            'text'    => $text,
        );

        $blocks = $this->arrayParam($arguments, 'blocks');

        if (array() !== $blocks) {
            $payload['blocks'] = $blocks;
        }

        $threadTs = $this->textArg($arguments, 'thread_ts');

        if ('' !== $threadTs) {
            $payload['thread_ts'] = $threadTs;
        }

        try {
            $response = $this->apiPost($token, 'chat.postMessage', $payload);
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $ts = $this->text($response['ts'] ?? '');

        if ('' === $ts) {
            return $this->errors->create(
                'slack_invalid_response',
                'Slack accepted the message but returned no timestamp.'
            );
        }

        $message = is_array($response['message'] ?? null) ? $response['message'] : array();
        $posted  = $this->text($message['text'] ?? '');
        $parent  = $this->text($message['thread_ts'] ?? '');
        $where   = $this->text($response['channel'] ?? '');

        return $this->success(
            'Message posted to ' . $channel . '.',
            array(
                'channel'   => '' !== $where ? $where : $channel,
                'ts'        => $ts,
                'text'      => '' !== $posted ? $posted : $text,
                'thread_ts' => '' !== $parent ? $parent : null,
            )
        );
    }
}
