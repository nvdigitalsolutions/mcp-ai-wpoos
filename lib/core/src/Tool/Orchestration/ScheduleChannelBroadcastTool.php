<?php

/**
 * Schedule a channel broadcast.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Schedule_Channel_Broadcast` (Proposal 164
 * section 164.81). A convenience wrapper around the channel-broadcast schedule
 * type: a message, the channels and their credentials, and when to send it.
 *
 * ## Delegation, not duplication
 *
 * WordPress assembled a schedule payload and called the manager directly. The
 * port delegates to {@see CreateProScheduleTool}, so the id rule, the display
 * defaults and the timestamp validation exist once. That also means an
 * identical broadcast message is an upsert on the same id, exactly as the
 * underlying tool already documents.
 *
 * ## Capability
 *
 * The WordPress tool declared `edit_posts` and then checked `manage_options`,
 * so the effective gate is the one the port declares.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Orchestration;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ScheduleStoreInterface as Schedule;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\ValueObject\ScheduleInterval;
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class ScheduleChannelBroadcastTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly CreateProScheduleTool $schedules,
        private readonly Schedule $store,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'schedule_channel_broadcast';
    }

    public function getName(): string
    {
        return 'Schedule Channel Broadcast';
    }

    public function getDescription(): string
    {
        return 'Schedules a message to be sent to one or more chat channels (Telegram, Slack, Discord, Teams, Messenger, WhatsApp) on a recurring or one-off basis.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'name'        => array(
                    'type'        => 'string',
                    'description' => 'Human-readable label for this broadcast schedule.',
                ),
                'message'     => array(
                    'type'        => 'string',
                    'description' => 'Message text to broadcast. Supports markdown on platforms that render it (Telegram, Slack, Discord).',
                ),
                'channels'    => array(
                    'type'        => 'array',
                    'items'       => array(
                        'type' => 'string',
                        'enum' => ScheduleDefinition::CHANNELS,
                    ),
                    'description' => 'Channel slugs to send the broadcast to.',
                ),
                'credentials' => array(
                    'type'        => 'object',
                    'description' => 'Credentials object keyed by channel slug.',
                ),
                'schedule'    => array(
                    'type'        => 'string',
                    'enum'        => ScheduleInterval::slugs(),
                    'description' => 'Recurrence interval. Use "single" for a one-time broadcast.',
                    'default'     => ScheduleInterval::SINGLE,
                ),
                'timestamp'   => array(
                    'type'        => 'integer',
                    'description' => 'Unix timestamp for the first (or only) send. Defaults to 60 seconds from now.',
                ),
                'enabled'     => array(
                    'type'        => 'boolean',
                    'description' => 'Whether this broadcast is active. Defaults to true.',
                    'default'     => true,
                ),
                'tags'        => array(
                    'type'        => 'array',
                    'items'       => array('type' => 'string'),
                    'description' => 'Optional tags for categorising this broadcast.',
                ),
            ),
            'required'             => array('message', 'channels', 'credentials'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'manage_options';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array(
            'async',
            'background-only',
            'deferred-result',
            'requires-capability',
            'state-changing',
            'write',
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $message     = isset($arguments['message']) && is_scalar($arguments['message'])
            ? Text::multiline((string) $arguments['message'])
            : '';
        $credentials = isset($arguments['credentials']) && is_array($arguments['credentials'])
            ? $arguments['credentials']
            : array();
        $channels    = array();

        if (isset($arguments['channels']) && is_array($arguments['channels'])) {
            foreach ($arguments['channels'] as $channel) {
                $clean = is_scalar($channel) ? Text::sanitizeKey((string) $channel) : '';

                if (in_array($clean, ScheduleDefinition::CHANNELS, true) && ! in_array($clean, $channels, true)) {
                    $channels[] = $clean;
                }
            }
        }

        if ('' === $message) {
            return $this->errors->create('missing_message', 'A message is required.');
        }

        if (array() === $channels) {
            return $this->errors->create('missing_channels', 'At least one channel must be specified.');
        }

        if (array() === $credentials) {
            return $this->errors->create('missing_credentials', 'Channel credentials are required.');
        }

        $name = $this->stringParam($arguments, 'name');

        if ('' === $name) {
            $name = sprintf('Channel Broadcast to %s', implode(', ', $channels));
        }

        $payload = array(
            'schedule_type'    => Schedule::TYPE_CHANNEL_BROADCAST,
            'broadcast_config' => array(
                'message'     => $message,
                'channels'    => $channels,
                'credentials' => $credentials,
            ),
            'name'             => $name,
            'schedule'         => $this->stringParam($arguments, 'schedule', ScheduleInterval::SINGLE),
            'enabled'          => $this->boolParam($arguments, 'enabled', true),
            'tags'             => $arguments['tags'] ?? array(),
        );

        if (isset($arguments['timestamp'])) {
            $payload['timestamp'] = $this->intParam($arguments, 'timestamp', 0);
        }

        $created = $this->schedules->execute($payload, $context);

        if ($this->errors->isError($created) || ! is_array($created)) {
            return $created;
        }

        $scheduleId = (string) ( $created['data']['schedule_id'] ?? '' );
        $stored     = '' !== $scheduleId ? $this->store->find($scheduleId) : null;
        $effective  = null !== $stored && isset($stored['broadcast_config']['channels'])
            ? $stored['broadcast_config']['channels']
            : $channels;

        $created['message']            = 'Channel broadcast scheduled successfully.';
        $created['data']['channels']   = $effective;
        $created['data']['message']    = $created['message'];

        return $created;
    }
}
