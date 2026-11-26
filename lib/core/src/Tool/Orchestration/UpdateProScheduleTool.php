<?php

/**
 * Partially update a Pro Schedule definition.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Update_Pro_Schedule` (Proposal 164 section
 * 164.80). Only the supplied fields change; `tags` and `workflow_steps` replace
 * their lists, `assistant_config` merges, and `display` merges before it is
 * re-sanitised.
 *
 * ## Corrected
 *
 * - **The record's `timestamp` is the next run.** WordPress stored the first
 *   timestamp once and then read liveness from a WP-cron event; with no cron
 *   event here, the re-arm decision is written into the record. The decision
 *   itself is WordPress's: an explicit timestamp wins; a disabled schedule is
 *   left unarmed; a consumed one-shot is not re-armed by a metadata-only save;
 *   otherwise a cadence change or a re-enable starts one interval from now.
 * - **`workflow_steps` on a non-workflow schedule is refused** by name, as the
 *   WordPress manager does.
 * - **A callback URL must be HTTP(S)**, not merely URL-shaped.
 *
 * ## Pinned
 *
 * - **The schedule type cannot be changed**, because the WordPress tool does
 *   not offer it and the id was derived from the original type.
 * - **An invalid `notify_email` is refused** whenever supplied.
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

class UpdateProScheduleTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly Schedule $schedules,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'update_pro_schedule';
    }

    public function getName(): string
    {
        return 'Update Pro Schedule';
    }

    public function getDescription(): string
    {
        return 'Updates an existing named pro schedule. Supports partial updates: only provided fields are modified.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'schedule_id'       => array(
                    'type'        => 'string',
                    'description' => 'Unique ID of the schedule to update.',
                    'minLength'   => 1,
                ),
                'name'              => array(
                    'type'        => 'string',
                    'description' => 'New human-readable label.',
                ),
                'description'       => array(
                    'type'        => 'string',
                    'description' => 'New description.',
                ),
                'schedule'          => array(
                    'type'        => 'string',
                    'enum'        => ScheduleInterval::slugs(),
                    'description' => 'New recurrence interval.',
                ),
                'timestamp'         => array(
                    'type'        => 'integer',
                    'description' => 'New next-run Unix timestamp. Must be in the future.',
                ),
                'enabled'           => array(
                    'type'        => 'boolean',
                    'description' => 'Enable or disable the schedule.',
                ),
                'priority'          => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => 10,
                    'description' => 'New display priority (1–10).',
                ),
                'tags'              => array(
                    'type'        => 'array',
                    'items'       => array('type' => 'string'),
                    'description' => 'Replace all tags with this list.',
                ),
                'notify_on_failure' => array(
                    'type'        => 'boolean',
                    'description' => 'Toggle failure notifications.',
                ),
                'notify_email'      => array(
                    'type'        => 'string',
                    'description' => 'New failure notification email(s).',
                ),
                'max_retries'       => array(
                    'type'        => 'integer',
                    'minimum'     => 0,
                    'maximum'     => 5,
                    'description' => 'New maximum retry count (0–5).',
                ),
                'retry_delay'       => array(
                    'type'        => 'integer',
                    'minimum'     => 60,
                    'description' => 'New retry delay in seconds.',
                ),
                'timeout'           => array(
                    'type'        => 'integer',
                    'minimum'     => 0,
                    'description' => 'New maximum execution time in seconds.',
                ),
                'callback_url'      => array(
                    'type'        => 'string',
                    'description' => 'New webhook callback URL. Empty string removes it.',
                ),
                'callback_secret'   => array(
                    'type'        => 'string',
                    'description' => 'New HMAC signing secret. Empty string removes it.',
                ),
                'display'           => array(
                    'type'        => 'object',
                    'description' => 'Display/widget settings, merged with the stored settings.',
                ),
                'workflow_steps'    => array(
                    'type'        => 'array',
                    'description' => 'Replace the tool chain of a workflow-type schedule.',
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'tool_slug' => array('type' => 'string'),
                            'arguments' => array('type' => 'object'),
                            'label'     => array('type' => 'string'),
                        ),
                    ),
                ),
                'assistant_config'  => array(
                    'type'        => 'object',
                    'description' => 'Updated assistant_run configuration, merged with the stored config.',
                ),
                'result_delivery'   => array(
                    'type'        => 'object',
                    'description' => 'Delivery configuration, merged with the stored configuration.',
                ),
            ),
            'required'             => array('schedule_id'),
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
        return array('requires-capability', 'state-changing', 'write');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $userId     = (int) ( $context['user_id'] ?? 0 );
        $scheduleId = $this->stringParam($arguments, 'schedule_id');

        if ('' === $scheduleId) {
            return $this->errors->create('missing_id', 'A schedule_id is required.');
        }

        $existing = $this->schedules->find($scheduleId);

        if (null === $existing) {
            return $this->errors->create(
                'not_found',
                sprintf('Schedule "%s" not found.', $scheduleId)
            );
        }

        $updated  = $existing;
        $errors   = array();
        $explicit = isset($arguments['timestamp']);

        if (isset($arguments['name'])) {
            $updated['name'] = $this->stringParam($arguments, 'name');
        }

        if (isset($arguments['description']) && is_scalar($arguments['description'])) {
            $updated['description'] = Text::multiline((string) $arguments['description']);
        }

        if (isset($arguments['enabled'])) {
            $updated['enabled'] = $this->boolParam($arguments, 'enabled', (bool) $existing['enabled']);
        }

        if (isset($arguments['priority'])) {
            $updated['priority'] = ScheduleDefinition::priority($arguments['priority'], (int) $existing['priority']);
        }

        if (isset($arguments['tags'])) {
            $updated['tags'] = ScheduleDefinition::tags($arguments['tags']);
        }

        if (isset($arguments['notify_on_failure'])) {
            $updated['notify_on_failure'] = $this->boolParam($arguments, 'notify_on_failure', false);
        }

        if (isset($arguments['notify_email']) && is_scalar($arguments['notify_email'])) {
            $normalised = ScheduleDefinition::emails((string) $arguments['notify_email']);

            if (null === $normalised) {
                $errors['notify_email'] = array('One or more notification addresses are not valid email addresses.');
            } else {
                $updated['notify_email'] = $normalised;
            }
        }

        if (isset($arguments['notify_channels'])) {
            $updated['notify_channels'] = $this->channels($arguments['notify_channels']);
        }

        if (isset($arguments['notify_channel_credentials']) && is_array($arguments['notify_channel_credentials'])) {
            $updated['notify_channel_credentials'] = $arguments['notify_channel_credentials'];
        }

        if (isset($arguments['broadcast_config']) && is_array($arguments['broadcast_config'])) {
            $updated['broadcast_config'] = $arguments['broadcast_config'];
        }

        if (isset($arguments['workflow_steps'])) {
            if (Schedule::TYPE_WORKFLOW !== $existing['schedule_type']) {
                $errors['workflow_steps'] = array('workflow_steps can only be updated on workflow-type schedules.');
            } else {
                $steps = ScheduleDefinition::workflowSteps($arguments['workflow_steps']);

                if (array() === $steps) {
                    $errors['workflow_steps'] = array('No valid workflow steps were provided.');
                } else {
                    $updated['workflow_steps'] = $steps;
                }
            }
        }

        if (isset($arguments['assistant_config']) && is_array($arguments['assistant_config'])) {
            $config = array_merge(
                is_array($existing['assistant_config']) ? $existing['assistant_config'] : array(),
                $arguments['assistant_config']
            );

            $assistantId = isset($config['assistant_id']) ? abs((int) $config['assistant_id']) : 0;
            $message     = isset($config['message']) && is_scalar($config['message'])
                ? Text::multiline((string) $config['message'])
                : '';

            if ($assistantId <= 0) {
                $errors['assistant_config'] = array('An assistant_id is required for assistant_run-type schedules.');
            } elseif ('' === $message) {
                $errors['assistant_config'] = array('A message is required for assistant_run-type schedules.');
            } else {
                $updated['assistant_config'] = array(
                    'assistant_id' => $assistantId,
                    'message'      => $message,
                    'context'      => isset($config['context']) && is_array($config['context']) ? $config['context'] : array(),
                );
            }
        }

        if (isset($arguments['max_retries'])) {
            $updated['max_retries'] = ScheduleDefinition::maxRetries($arguments['max_retries']);
        }

        if (isset($arguments['retry_delay'])) {
            $updated['retry_delay'] = ScheduleDefinition::retryDelay($arguments['retry_delay']);
        }

        if (isset($arguments['timeout'])) {
            $updated['timeout'] = ScheduleDefinition::timeout($arguments['timeout']);
        }

        if (isset($arguments['callback_url']) && is_scalar($arguments['callback_url'])) {
            $url = ScheduleDefinition::callbackUrl((string) $arguments['callback_url']);

            if (null === $url) {
                $errors['callback_url'] = array('The callback URL is not a valid HTTP(S) URL.');
            } else {
                $updated['callback_url'] = $url;
            }
        }

        if (isset($arguments['callback_secret']) && is_scalar($arguments['callback_secret'])) {
            $updated['callback_secret'] = $this->stringParam($arguments, 'callback_secret');
        }

        if (isset($arguments['display']) && is_array($arguments['display'])) {
            $display = $arguments['display'];

            if (isset($display['widget_defaults']) && is_array($display['widget_defaults'])) {
                $display['widget_defaults'] = array_merge(
                    is_array($existing['display']['widget_defaults'] ?? null) ? $existing['display']['widget_defaults'] : array(),
                    $display['widget_defaults']
                );
            }

            $updated['display'] = ScheduleDefinition::display(
                array_merge($existing['display'], $display)
            );
        }

        if (isset($arguments['result_delivery']) && is_array($arguments['result_delivery'])) {
            $existingDelivery    = is_array($existing['result_delivery']) ? $existing['result_delivery'] : array();
            $updated['result_delivery'] = array_replace_recursive($existingDelivery, $arguments['result_delivery']);
        }

        $intervalChanged = false;

        if (isset($arguments['schedule'])) {
            $cadence = Text::sanitizeKey((string) $arguments['schedule']);

            if (! ScheduleInterval::isValid($cadence)) {
                $errors['schedule'] = array('The schedule interval is not registered.');
            } else {
                $updated['schedule'] = $cadence;
                $intervalChanged     = $cadence !== (string) $existing['schedule'];
            }
        }

        if ($explicit) {
            $timestamp = abs($this->intParam($arguments, 'timestamp', 0));

            if ($timestamp < time()) {
                $errors['timestamp'] = array('Schedule timestamp must be in the future.');
            } else {
                $updated['timestamp'] = $timestamp;
            }
        }

        if (array() !== $errors) {
            return $this->errors->validationFailed('The schedule cannot be updated.', $errors);
        }

        $now       = time();
        $turningOn = empty($existing['enabled']) && ! empty($updated['enabled']);

        if ($explicit) {
            // Already set and validated.
        } elseif (empty($updated['enabled'])) {
            $updated['timestamp'] = 0;
        } elseif (ScheduleInterval::SINGLE === (string) $updated['schedule'] && (int) $updated['run_count'] > 0 && ! $turningOn) {
            $updated['timestamp'] = 0;
        } elseif ($intervalChanged || $turningOn || (int) $updated['timestamp'] <= 0) {
            $updated['timestamp'] = ScheduleDefinition::defaultTimestamp((string) $updated['schedule'], $now);
        }

        $updated['updated_at'] = $now;
        $updated['updated_by'] = $userId;

        try {
            $stored = $this->schedules->save($updated);
        } catch (\Throwable $error) {
            return $this->errors->create('schedule_update_failed', $error->getMessage());
        }

        $nextRun = ScheduleDefinition::nextRun($stored);

        return $this->success(
            'Pro schedule updated successfully.',
            array(
                'schedule_id' => $stored['id'],
                'name'        => $stored['name'],
                'hook'        => $stored['hook'],
                'schedule'    => $stored['schedule'],
                'enabled'     => $stored['enabled'],
                'next_run'    => null === $nextRun ? null : ScheduleDefinition::iso($nextRun),
                'message'     => 'Pro schedule updated successfully.',
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
}
