<?php

/**
 * Broadcast one message to several chat channels.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Unified_Channel_Broadcast` (Proposal 164 section
 * 164.109). The last tool in the toolkit, and the only one that sends nothing
 * itself: it resolves each channel's sender and delegates.
 *
 * ## The seam
 *
 * WordPress reached into `WP_MCP_AI_Tool_Registry::get_instance()->get_tool()`.
 * That is the concrete registry, so the tool could not run against any other
 * host, and it is why the WordPress version had to duplicate the argument
 * mapping for seven senders by hand.
 *
 * The port depends on `ToolResolverInterface` — a domain contract that
 * `ToolRegistry` and `ToolScope` both implement — and calls `execute()` on the
 * resolved tool. Two things follow:
 *
 * - **The failure detail survives.** `ToolInvokerInterface` collapses every
 *   failure to `null`, which is right for the retrieval harness and useless
 *   here: a broadcast report that says "telegram: failed" without a reason is
 *   the same quality as no report. Resolving the tool and executing it directly
 *   keeps the provider's error envelope, which is reported per channel.
 * - **A host can narrow the fan-out.** Binding `ToolScope` instead of the full
 *   registry means a broadcast can only reach the tools the host allows, which
 *   is the right place for that policy.
 *
 * ## The correction of substance: `success` was an array
 *
 * WordPress returned
 * `array('success' => array(...per-channel...), 'failures' => array(...), 'summary' => ...)`.
 * `success` there is a list of channel results, not the boolean every other tool
 * in this family (and every caller reading the canonical envelope) expects. A
 * caller checking `$result['success'] === true` got `false` on a broadcast that
 * worked. The port returns the canonical envelope and puts the per-channel
 * report in `data`.
 *
 * ## When a broadcast is a failure
 *
 * A fan-out has three outcomes, and they are not two:
 *
 * - **Every channel sent** — success.
 * - **Some sent** — success, with the failures listed. Partial delivery is a
 *   legitimate result of sending to independent services, and the caller needs
 *   the detail rather than a blanket error.
 * - **None sent** — an error. Reporting `success: true` for a broadcast that
 *   reached nobody is the one outcome a caller must never have to infer from a
 *   nested counter.
 *
 * ## Other corrections
 *
 * 1. **An unknown channel name is refused before anything is sent.** WordPress
 *    reported it as one more per-channel failure, so a typo among six valid
 *    channels still broadcast to the six and looked like a partial success.
 * 2. **A duplicate channel is sent to once.** WordPress iterated the list as
 *    given, so `["telegram","telegram"]` sent twice and reported one result.
 * 3. **A channel with no credential entry is a per-channel failure**, as in
 *    WordPress, because the caller may list a channel it has not configured and
 *    still want the rest delivered.
 * 4. **A JSON-encoded credential string is decoded**, as in WordPress: the
 *    Schedule Manager stored them that way. A string that is not a JSON object
 *    is refused rather than cast.
 * 5. **Message length limits are the senders' business.** Telegram, Discord,
 *    Slack and WhatsApp cap text differently; duplicating those numbers here
 *    would drift from the tools that own them, so an over-long message is
 *    reported per channel by the sender that refused it.
 *
 * ## Deliberately not ported
 *
 * WordPress marked the tool as restricted from the public chat client, because
 * an assistant invoked from a public surface must not fan out on the site
 * owner's behalf. That is a host policy, not a tool behaviour, and this runtime
 * has no chat-client context to check. The description says the tool sends
 * outward to every channel named; a host that exposes it to untrusted callers
 * should gate it at the assistant or surface level.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\ToolResolverInterface;

class UnifiedChannelBroadcastTool extends ChatChannelTool
{
    /**
     * Per channel: the sender slug, the credential keys it needs, and the name
     * of the argument that carries the message.
     *
     * @var array<string, array{slug: string, keys: list<string>, text: string}>
     */
    private const CHANNELS = array(
        'telegram'    => array('slug' => 'send_telegram_message', 'keys' => array('token', 'chat_id'), 'text' => 'text'),
        'slack'       => array('slug' => 'send_slack_message', 'keys' => array('token', 'channel'), 'text' => 'text'),
        'discord'     => array('slug' => 'send_discord_message', 'keys' => array('token', 'channel_id'), 'text' => 'content'),
        'teams'       => array('slug' => 'send_teams_message', 'keys' => array('token', 'team_id', 'channel_id'), 'text' => 'content'),
        'messenger'   => array('slug' => 'send_messenger_message', 'keys' => array('access_token', 'recipient_id'), 'text' => 'message'),
        'whatsapp'    => array('slug' => 'send_whatsapp_message', 'keys' => array('access_token', 'phone_number_id', 'to'), 'text' => 'text'),
        'google_chat' => array('slug' => 'send_google_chat_message', 'keys' => array('webhook_url'), 'text' => 'text'),
    );

    public function __construct(
        ErrorFactoryInterface $e,
        HttpClientInterface $http,
        private readonly ToolResolverInterface $resolver,
    ) {
        parent::__construct($e, $http);
    }

    public function getSlug(): string
    {
        return 'unified_channel_broadcast';
    }

    public function getName(): string
    {
        return 'Unified Channel Broadcast';
    }

    public function getDescription(): string
    {
        return 'Sends one plain-text message to several chat channels at once: telegram, slack, discord, teams, messenger, whatsapp and google_chat. Credentials and targets for each channel are supplied by the caller. Sends real, outward messages to every channel named.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'message'     => array(
                    'type'        => 'string',
                    'description' => 'Plain-text body sent to every channel. Each channel enforces its own length limit, and an over-long message fails only on the channels that reject it.',
                    'minLength'   => 1,
                ),
                'channels'    => array(
                    'type'        => 'array',
                    'description' => 'Channels to send to. An unknown name refuses the call; a repeated name is sent to once.',
                    'items'       => array(
                        'type' => 'string',
                        'enum' => array('telegram', 'slack', 'discord', 'teams', 'messenger', 'whatsapp', 'google_chat'),
                    ),
                    'minItems'    => 1,
                ),
                'credentials' => array(
                    'type'        => 'object',
                    'description' => 'One object per channel, keyed by channel name, holding that channel\'s token and target. A JSON-encoded object is also accepted.',
                    'properties'  => array(
                        'telegram'    => array(
                            'type'       => 'object',
                            'properties' => array(
                                'token'   => array('type' => 'string'),
                                'chat_id' => array('type' => 'string'),
                            ),
                        ),
                        'slack'       => array(
                            'type'       => 'object',
                            'properties' => array(
                                'token'   => array('type' => 'string'),
                                'channel' => array('type' => 'string'),
                            ),
                        ),
                        'discord'     => array(
                            'type'       => 'object',
                            'properties' => array(
                                'token'      => array('type' => 'string'),
                                'channel_id' => array('type' => 'string'),
                            ),
                        ),
                        'teams'       => array(
                            'type'       => 'object',
                            'properties' => array(
                                'token'      => array('type' => 'string'),
                                'team_id'    => array('type' => 'string'),
                                'channel_id' => array('type' => 'string'),
                            ),
                        ),
                        'messenger'   => array(
                            'type'       => 'object',
                            'properties' => array(
                                'access_token' => array('type' => 'string'),
                                'recipient_id' => array('type' => 'string'),
                            ),
                        ),
                        'whatsapp'    => array(
                            'type'       => 'object',
                            'properties' => array(
                                'access_token'    => array('type' => 'string'),
                                'phone_number_id' => array('type' => 'string'),
                                'to'              => array('type' => 'string'),
                            ),
                        ),
                        'google_chat' => array(
                            'type'       => 'object',
                            'properties' => array(
                                'webhook_url' => array('type' => 'string'),
                            ),
                        ),
                    ),
                ),
            ),
            'required'             => array('message', 'channels', 'credentials'),
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

        $message = is_scalar($arguments['message'] ?? null) ? trim((string) $arguments['message']) : '';

        if ('' === $message) {
            return $this->errors->validationFailed(
                'A message is required to broadcast.',
                array('message' => array('Required.'))
            );
        }

        $channels = $this->channels($arguments['channels'] ?? null);

        if ($this->errors->isError($channels) || ! is_array($channels)) {
            return $channels;
        }

        $credentials = $this->credentials($arguments['credentials'] ?? null);

        if ($this->errors->isError($credentials) || ! is_array($credentials)) {
            return $credentials;
        }

        $report = array();
        $sent   = 0;

        foreach ($channels as $channel) {
            $row      = $this->send($channel, $message, $credentials, $context);
            $report[] = $row;

            if (true === $row['sent']) {
                $sent++;
            }
        }

        $failed = count($report) - $sent;

        $data = array(
            'text_length' => mb_strlen($message),
            'channels'    => $report,
            'summary'     => array(
                'total'  => count($report),
                'sent'   => $sent,
                'failed' => $failed,
            ),
        );

        if (0 === $sent) {
            return $this->errors->create(
                'channel_broadcast_failed',
                sprintf(
                    'The broadcast reached none of its %d channel(s); each failure is in the report.',
                    count($report)
                ),
                $data
            );
        }

        return $this->success(
            sprintf(
                'Broadcast sent to %d of %d channel(s).',
                $sent,
                count($report)
            ),
            $data
        );
    }

    /**
     * One channel, sent and reported.
     *
     * @param array<string, mixed> $credentials Credentials by channel.
     * @param array<string, mixed> $context     Execution context.
     *
     * @return array<string, mixed>
     */
    private function send(string $channel, string $message, array $credentials, array $context): array
    {
        $spec = self::CHANNELS[$channel];

        if (! array_key_exists($channel, $credentials)) {
            return $this->failed($channel, $spec['slug'], 'missing_credentials', 'No credentials were supplied for this channel.', array());
        }

        $channelCredentials = $this->channelCredentials($credentials[$channel]);

        if (null === $channelCredentials) {
            return $this->failed(
                $channel,
                $spec['slug'],
                'invalid_credentials',
                'Credentials for this channel must be an object of token and target values.',
                array()
            );
        }

        $missing = array();

        foreach ($spec['keys'] as $key) {
            if ('' === $this->credential($channelCredentials, $key)) {
                $missing[] = $key;
            }
        }

        if (array() !== $missing) {
            return $this->failed(
                $channel,
                $spec['slug'],
                'missing_credentials',
                'Missing ' . implode(', ', $missing) . ' for ' . $channel . '.',
                array('missing' => $missing)
            );
        }

        $tool = $this->resolver->get($spec['slug']);

        if (null === $tool) {
            return $this->failed(
                $channel,
                $spec['slug'],
                'tool_unavailable',
                'The ' . $spec['slug'] . ' tool is not registered in this runtime.',
                array()
            );
        }

        $arguments = array();

        foreach ($spec['keys'] as $key) {
            $arguments[$key] = $this->credential($channelCredentials, $key);
        }

        $arguments[$spec['text']] = $message;

        try {
            $result = $tool->execute($arguments, $context);
        } catch (\Throwable $error) {
            return $this->failed($channel, $spec['slug'], 'tool_failed', $error->getMessage(), array());
        }

        if ($this->errors->isError($result)) {
            return $this->failed(
                $channel,
                $spec['slug'],
                'send_failed',
                $this->errorMessage($result),
                $this->errorData($result)
            );
        }

        return array(
            'channel' => $channel,
            'tool'    => $spec['slug'],
            'sent'    => true,
            'error'   => null,
        );
    }

    /**
     * A per-channel failure row.
     *
     * @param array<string, mixed> $data Extra detail.
     *
     * @return array<string, mixed>
     */
    private function failed(string $channel, string $slug, string $code, string $message, array $data): array
    {
        return array(
            'channel' => $channel,
            'tool'    => $slug,
            'sent'    => false,
            'error'   => array(
                'code'    => $code,
                'message' => $message,
                'data'    => $data,
            ),
        );
    }

    /**
     * The channel list, deduplicated, or the canonical refusal.
     *
     * @param mixed $value Raw argument.
     *
     * @return list<string>|mixed
     */
    private function channels(mixed $value): mixed
    {
        if (! is_array($value) || array() === $value) {
            return $this->errors->validationFailed(
                'At least one channel is required.',
                array('channels' => array('Required.'))
            );
        }

        $channels = array();

        foreach ($value as $entry) {
            $channel = is_scalar($entry) ? trim((string) $entry) : '';

            if (! array_key_exists($channel, self::CHANNELS)) {
                return $this->errors->validationFailed(
                    sprintf(
                        'Channel %s is not supported. Use one of: %s.',
                        '' === $channel ? '(empty)' : '"' . $channel . '"',
                        implode(', ', array_keys(self::CHANNELS))
                    ),
                    array('channels' => array('Unsupported channel.'))
                );
            }

            if (! in_array($channel, $channels, true)) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }

    /**
     * The credential map, or the canonical refusal.
     *
     * @param mixed $value Raw argument.
     *
     * @return array<string, mixed>|mixed
     */
    private function credentials(mixed $value): mixed
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value   = is_array($decoded) ? $decoded : null;
        }

        if (! is_array($value) || array() === $value) {
            return $this->errors->validationFailed(
                'Credentials keyed by channel are required.',
                array('credentials' => array('Required.'))
            );
        }

        return $value;
    }

    /**
     * One channel's credential object, or null when it is not one.
     *
     * @return array<string, mixed>|null
     */
    private function channelCredentials(mixed $value): ?array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value   = is_array($decoded) ? $decoded : null;
        }

        return is_array($value) ? $value : null;
    }

    /**
     * The message from a delegated error envelope.
     *
     * @param mixed $result A result the error factory classifies as an error.
     */
    private function errorMessage(mixed $result): string
    {
        if (is_array($result) && is_array($result['error'] ?? null)) {
            $message = $result['error']['message'] ?? null;

            if (is_scalar($message) && '' !== trim((string) $message)) {
                return trim((string) $message);
            }
        }

        return 'The channel sender reported an error.';
    }

    /**
     * The detail from a delegated error envelope.
     *
     * @param mixed $result A result the error factory classifies as an error.
     *
     * @return array<string, mixed>
     */
    private function errorData(mixed $result): array
    {
        if (is_array($result) && is_array($result['error'] ?? null) && is_array($result['error']['data'] ?? null)) {
            return $result['error']['data'];
        }

        return array();
    }
}
