<?php

/**
 * Create (or replace) a Pro Schedule definition.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Create_Pro_Schedule` (Proposal 164 section
 * 164.80). The tool builds and validates a definition; a host's scheduler owns
 * execution. The id is derived, not random, so re-creating an identical `task`
 * schedule updates it — the WordPress upsert behaviour.
 *
 * ## Pinned and corrected
 *
 * - **Every schedule tool gates on `manage_options`**, not the `edit_posts` the
 *   WordPress classes declare: their `execute()` checks `manage_options` first,
 *   so that is the effective rule.
 * - **The four declared types are the vocabulary.** The manager also supports a
 *   `workflow_builder` type verified against a WordPress option store; that
 *   store does not exist here, and the tool's own schema never offered the
 *   type, so it is not accepted rather than half-verified.
 * - **`notify_email` is validated whenever supplied.** WordPress validated only
 *   when failure notifications were enabled; an invalid address is refused
 *   either way here.
 * - **Delivery is stored, not performed.** `result_delivery` and the notify
 *   channel credentials are kept for the host's runner, because the tool group
 *   does not deliver anything itself.
 * - **`next_run` is the stored timestamp**, not a WordPress cron lookup; a
 *   disabled schedule reports null.
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

class CreateProScheduleTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly Schedule $schedules,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'create_pro_schedule';
    }

    public function getName(): string
    {
        return 'Create Pro Schedule';
    }

    public function getDescription(): string
    {
        return 'Creates a named, managed scheduled task with retry logic, failure notifications, execution history, and enable/disable control.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'schedule_type'              => array(
                    'type'        => 'string',
                    'enum'        => Schedule::TYPES,
                    'description' => 'Type: "task" (hook), "workflow" (tool chain), "assistant_run", or "channel_broadcast". Defaults to "task".',
                    'default'     => Schedule::TYPE_TASK,
                ),
                'hook'                       => array(
                    'type'        => 'string',
                    'description' => 'Action hook to fire (required for "task").',
                ),
                'workflow_steps'             => array(
                    'type'        => 'array',
                    'description' => 'Ordered tool calls for "workflow". Each step: {tool_slug, arguments, label}.',
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'tool_slug' => array('type' => 'string'),
                            'arguments' => array('type' => 'object'),
                            'label'     => array('type' => 'string'),
                        ),
                        'required'   => array('tool_slug'),
                    ),
                ),
                'assistant_config'           => array(
                    'type'        => 'object',
                    'description' => 'Configuration for "assistant_run": {assistant_id, message, context}.',
                    'properties'  => array(
                        'assistant_id' => array('type' => 'integer'),
                        'message'      => array('type' => 'string'),
                        'context'      => array('type' => 'object'),
                    ),
                ),
                'broadcast_config'           => array(
                    'type'        => 'object',
                    'description' => 'Required for "channel_broadcast": {message, channels, credentials}.',
                    'properties'  => array(
                        'message'     => array('type' => 'string'),
                        'channels'    => array(
                            'type'  => 'array',
                            'items' => array('type' => 'string', 'enum' => ScheduleDefinition::CHANNELS),
                        ),
                        'credentials' => array('type' => 'object'),
                    ),
                ),
                'name'                       => array(
                    'type'        => 'string',
                    'description' => 'Human-readable label for this schedule.',
                ),
                'description'                => array(
                    'type'        => 'string',
                    'description' => 'Optional description of what this schedule does.',
                ),
                'schedule'                   => array(
                    'type'        => 'string',
                    'enum'        => ScheduleInterval::slugs(),
                    'description' => 'Recurrence interval. Use "single" for a one-time run.',
                    'default'     => ScheduleInterval::SINGLE,
                ),
                'timestamp'                  => array(
                    'type'        => 'integer',
                    'description' => 'Unix timestamp for the first (or only) execution. Defaults to 60 seconds out for one-shot runs, or one full interval for recurring ones.',
                ),
                'args'                       => array(
                    'type'        => 'array',
                    'items'       => array('type' => 'string'),
                    'description' => 'Positional arguments passed to the action hook (task only).',
                ),
                'enabled'                    => array(
                    'type'        => 'boolean',
                    'description' => 'Whether the schedule is active immediately. Defaults to true.',
                    'default'     => true,
                ),
                'priority'                   => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => 10,
                    'description' => 'Display priority (1 = highest, 10 = lowest). Defaults to 5.',
                    'default'     => 5,
                ),
                'tags'                       => array(
                    'type'        => 'array',
                    'items'       => array('type' => 'string'),
                    'description' => 'Tags for categorising schedules.',
                ),
                'notify_on_failure'          => array(
                    'type'        => 'boolean',
                    'description' => 'Send a failure notification when the schedule fails. Defaults to false.',
                    'default'     => false,
                ),
                'notify_email'               => array(
                    'type'        => 'string',
                    'description' => 'Email address(es) for failure notifications. Comma-separate multiple recipients.',
                ),
                'notify_channels'            => array(
                    'type'        => 'array',
                    'items'       => array('type' => 'string', 'enum' => ScheduleDefinition::CHANNELS),
                    'description' => 'Chat channels to notify on failure.',
                ),
                'notify_channel_credentials' => array(
                    'type'        => 'object',
                    'description' => 'Per-channel credentials for failure notifications.',
                ),
                'result_delivery'            => array(
                    'type'        => 'object',
                    'description' => 'Delivery configuration for successful and failed runs, consumed by the host runner.',
                ),
                'max_retries'                => array(
                    'type'        => 'integer',
                    'minimum'     => 0,
                    'maximum'     => 5,
                    'description' => 'Maximum automatic retries on failure (0–5). Defaults to 0.',
                    'default'     => 0,
                ),
                'retry_delay'                => array(
                    'type'        => 'integer',
                    'minimum'     => 60,
                    'description' => 'Seconds before each retry. Minimum 60. Defaults to 300.',
                    'default'     => 300,
                ),
                'timeout'                    => array(
                    'type'        => 'integer',
                    'minimum'     => 0,
                    'description' => 'Maximum execution time in seconds. 0 = no limit.',
                    'default'     => 0,
                ),
                'callback_url'               => array(
                    'type'        => 'string',
                    'description' => 'Webhook URL that receives run results.',
                ),
                'callback_secret'            => array(
                    'type'        => 'string',
                    'description' => 'HMAC-SHA256 signing secret for the callback.',
                ),
                'display'                    => array(
                    'type'        => 'object',
                    'description' => 'Display/widget-binding settings consumed by the result renderer.',
                ),
            ),
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
        return array('requires-capability', 'state-changing', 'write', 'background-only');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $userId  = (int) ( $context['user_id'] ?? 0 );
        $type    = ScheduleDefinition::type($arguments['schedule_type'] ?? Schedule::TYPE_TASK);
        $errors  = array();

        $hook             = '';
        $workflowSteps    = array();
        $assistantConfig  = array();
        $broadcastConfig  = array();

        if (Schedule::TYPE_TASK === $type) {
            $hook = Text::sanitizeKey($this->stringParam($arguments, 'hook'));

            if ('' === $hook) {
                $errors['hook'] = array('A hook name is required for task-type schedules.');
            }
        } elseif (Schedule::TYPE_WORKFLOW === $type) {
            $workflowSteps = ScheduleDefinition::workflowSteps($arguments['workflow_steps'] ?? array());

            if (array() === $workflowSteps) {
                $errors['workflow_steps'] = array('At least one valid workflow step is required.');
            }

            $hook = 'wp_mcp_ai_pro_workflow_run';
        } elseif (Schedule::TYPE_ASSISTANT_RUN === $type) {
            $assistantConfig = isset($arguments['assistant_config']) && is_array($arguments['assistant_config'])
                ? $arguments['assistant_config']
                : array();

            $assistantId = isset($assistantConfig['assistant_id']) ? abs((int) $assistantConfig['assistant_id']) : 0;
            $message     = isset($assistantConfig['message']) && is_scalar($assistantConfig['message'])
                ? Text::multiline((string) $assistantConfig['message'])
                : '';

            if ($assistantId <= 0) {
                $errors['assistant_config'] = array('An assistant_id is required for assistant_run-type schedules.');
            } elseif ('' === $message) {
                $errors['assistant_config'] = array('A message is required for assistant_run-type schedules.');
            }

            $assistantConfig = array(
                'assistant_id' => $assistantId,
                'message'      => $message,
                'context'      => isset($assistantConfig['context']) && is_array($assistantConfig['context'])
                    ? $assistantConfig['context']
                    : array(),
            );
            $hook            = 'wp_mcp_ai_pro_assistant_run';
        } else {
            $broadcastConfig = isset($arguments['broadcast_config']) && is_array($arguments['broadcast_config'])
                ? $arguments['broadcast_config']
                : array();

            $message   = isset($broadcastConfig['message']) && is_scalar($broadcastConfig['message'])
                ? Text::multiline((string) $broadcastConfig['message'])
                : '';
            $channels  = array();
            $rawChans  = isset($broadcastConfig['channels']) && is_array($broadcastConfig['channels'])
                ? $broadcastConfig['channels']
                : array();

            foreach ($rawChans as $channel) {
                $channel = is_scalar($channel) ? Text::sanitizeKey((string) $channel) : '';

                if (in_array($channel, ScheduleDefinition::CHANNELS, true)) {
                    $channels[] = $channel;
                }
            }

            $credentials = isset($broadcastConfig['credentials']) && is_array($broadcastConfig['credentials'])
                ? $broadcastConfig['credentials']
                : array();

            if ('' === $message) {
                $errors['broadcast_config'] = array('A message is required for channel_broadcast-type schedules.');
            } elseif (array() === $channels) {
                $errors['broadcast_config'] = array('At least one valid channel is required.');
            } elseif (array() === $credentials) {
                $errors['broadcast_config'] = array('Channel credentials are required.');
            }

            $broadcastConfig = array(
                'message'     => $message,
                'channels'    => array_values(array_unique($channels)),
                'credentials' => $credentials,
            );
            $hook            = 'wp_mcp_ai_pro_channel_broadcast';
        }

        $cadence = Text::sanitizeKey($this->stringParam($arguments, 'schedule', ScheduleInterval::SINGLE));

        if (! ScheduleInterval::isValid($cadence)) {
            $errors['schedule'] = array('The schedule interval is not registered.');
        }

        $now       = time();
        $timestamp = abs($this->intParam($arguments, 'timestamp', 0));

        if (0 === $timestamp) {
            $timestamp = ScheduleDefinition::defaultTimestamp($cadence, $now);
        }

        if ($timestamp < $now) {
            $errors['timestamp'] = array('Schedule timestamp must be in the future.');
        }

        $notify = $this->boolParam($arguments, 'notify_on_failure', false);
        $email  = '';

        if (isset($arguments['notify_email']) && is_scalar($arguments['notify_email'])) {
            $normalised = ScheduleDefinition::emails((string) $arguments['notify_email']);

            if (null === $normalised) {
                $errors['notify_email'] = array('One or more notification addresses are not valid email addresses.');
            } else {
                $email = $normalised;
            }
        }

        $callbackUrl = '';

        if (isset($arguments['callback_url']) && is_scalar($arguments['callback_url'])) {
            $validUrl = ScheduleDefinition::callbackUrl((string) $arguments['callback_url']);

            if (null === $validUrl) {
                $errors['callback_url'] = array('The callback URL is not a valid HTTP(S) URL.');
            } else {
                $callbackUrl = $validUrl;
            }
        }

        if (array() !== $errors) {
            return $this->errors->validationFailed('The schedule cannot be created.', $errors);
        }

        $args = array();

        if (isset($arguments['args']) && is_array($arguments['args'])) {
            foreach ($arguments['args'] as $arg) {
                if (is_scalar($arg)) {
                    $args[] = (string) $arg;
                }
            }
        }

        $name = $this->stringParam($arguments, 'name');

        if ('' === $name) {
            $name = $hook;
        }

        $id       = ScheduleDefinition::idFor($type, $hook, $args, $name, $timestamp);
        $existing = $this->schedules->find($id);
        $record   = array(
            'id'                         => $id,
            'name'                       => $name,
            'description'                => isset($arguments['description']) && is_scalar($arguments['description'])
                ? Text::multiline((string) $arguments['description'])
                : '',
            'schedule_type'              => $type,
            'hook'                       => $hook,
            'args'                       => $args,
            'workflow_steps'             => $workflowSteps,
            'assistant_config'           => $assistantConfig,
            'broadcast_config'           => $broadcastConfig,
            'display'                    => ScheduleDefinition::display($arguments['display'] ?? array()),
            'schedule'                   => $cadence,
            'timestamp'                  => $timestamp,
            'enabled'                    => $this->boolParam($arguments, 'enabled', true),
            'priority'                   => ScheduleDefinition::priority($arguments['priority'] ?? 5),
            'tags'                       => ScheduleDefinition::tags($arguments['tags'] ?? array()),
            'timeout'                    => ScheduleDefinition::timeout($arguments['timeout'] ?? 0),
            'callback_url'               => $callbackUrl,
            'callback_secret'            => $this->stringParam($arguments, 'callback_secret'),
            'notify_on_failure'          => $notify,
            'notify_email'               => $email,
            'notify_channels'            => $this->channels($arguments['notify_channels'] ?? array()),
            'notify_channel_credentials' => isset($arguments['notify_channel_credentials']) && is_array($arguments['notify_channel_credentials'])
                ? $arguments['notify_channel_credentials']
                : array(),
            'result_delivery'            => $this->delivery($arguments['result_delivery'] ?? array()),
            'max_retries'                => ScheduleDefinition::maxRetries($arguments['max_retries'] ?? 0),
            'retry_delay'                => ScheduleDefinition::retryDelay($arguments['retry_delay'] ?? 300),
            'retry_count'                => $existing['retry_count'] ?? 0,
            'last_run_status'            => $existing['last_run_status'] ?? Schedule::STATUS_NEVER,
            'last_run_time'              => $existing['last_run_time'] ?? 0,
            'last_run_duration'          => $existing['last_run_duration'] ?? 0,
            'last_error'                 => $existing['last_error'] ?? '',
            'run_count'                  => $existing['run_count'] ?? 0,
            'created_at'                 => $existing['created_at'] ?? $now,
            'created_by'                 => $existing['created_by'] ?? $userId,
            'updated_at'                 => $now,
            'updated_by'                 => $userId,
        );

        try {
            $stored = $this->schedules->save($record);
        } catch (\Throwable $error) {
            return $this->errors->create('schedule_create_failed', $error->getMessage());
        }

        $nextRun = ScheduleDefinition::nextRun($stored);

        return $this->success(
            'Pro schedule created successfully.',
            array(
                'schedule_id' => $stored['id'],
                'name'        => $stored['name'],
                'hook'        => $stored['hook'],
                'schedule'    => $stored['schedule'],
                'enabled'     => $stored['enabled'],
                'next_run'    => null === $nextRun ? null : ScheduleDefinition::iso($nextRun),
                'message'     => 'Pro schedule created successfully.',
            )
        );
    }

    /**
     * @return list<string>
     */
    private function channels(mixed $raw): array
    {
        if (! is_array($raw)) {
            return array();
        }

        $channels = array();

        foreach ($raw as $channel) {
            $clean = is_scalar($channel) ? Text::sanitizeKey((string) $channel) : '';

            if (in_array($clean, ScheduleDefinition::CHANNELS, true)) {
                $channels[] = $clean;
            }
        }

        return array_values(array_unique($channels));
    }

    /**
     * Keep the two top-level delivery phases when they are arrays.
     *
     * @return array<string, mixed>
     */
    private function delivery(mixed $raw): array
    {
        if (! is_array($raw)) {
            return array();
        }

        $delivery = array();

        foreach (array('on_success', 'on_failure') as $phase) {
            if (isset($raw[ $phase ]) && is_array($raw[ $phase ])) {
                $delivery[ $phase ] = $raw[ $phase ];
            }
        }

        return $delivery;
    }
}
