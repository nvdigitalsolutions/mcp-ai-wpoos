<?php

/**
 * Block a time range so no appointment can be booked in it.
 *
 * Port of `WP_MCP_AI_Tool_Block_Time_Slot` (Proposal 164 section 164.83). A
 * block is an `mcp_blocked_time` record with the same `_start_time`/`_end_time`
 * pair appointments use, so one overlap test serves both.
 *
 * ## Corrections
 *
 * - **The span is validated.** WordPress checked only that both strings were
 *   non-empty, then stored whatever it was given; a reversed or unparseable
 *   range became a block that never matched a slot. The port requires a real
 *   calendar span with the end after the start.
 * - **Times are stored in UTC**, like every other calendar record, and a
 *   relative expression is refused.
 *
 * ## What a block does and does not do
 *
 * `check_availability` and `get_available_slots` consult blocks; a booking made
 * through `create_appointment` does not. That is WordPress's division too, and
 * it is deliberate: a block is an availability signal, and refusing a write on
 * it would need a rule about overrides that neither runtime has.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Calendar;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\ValidationException;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class BlockTimeSlotTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'block_time_slot';
    }

    public function getName(): string
    {
        return 'Block Time Slot';
    }

    public function getDescription(): string
    {
        return 'Blocks a time range so availability checks and slot listings exclude it.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'start_time' => array(
                    'type'        => 'string',
                    'description' => 'Start of the block (Y-m-d H:i:s UTC or ISO 8601).',
                ),
                'end_time'   => array(
                    'type'        => 'string',
                    'description' => 'End of the block (Y-m-d H:i:s UTC or ISO 8601). Must be after the start.',
                ),
                'reason'     => array(
                    'type'        => 'string',
                    'description' => 'Reason for blocking.',
                ),
            ),
            'required'             => array('start_time', 'end_time'),
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
        return CapabilityFlagVocabulary::LOCAL_WRITE;
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $userId = (int) ( $context['user_id'] ?? 0 );
        $span   = AppointmentDefinition::span(
            $arguments['start_time'] ?? null,
            $arguments['end_time'] ?? null,
            null
        );

        if (null === $span) {
            return $this->errors->validationFailed(
                'The blocked range is not valid.',
                array(
                    'start_time' => array('Use Y-m-d H:i:s (UTC) or ISO 8601, with the end after the start.'),
                )
            );
        }

        [$start, $end] = $span;
        $reason        = isset($arguments['reason']) && is_scalar($arguments['reason'])
            ? Text::multiline((string) $arguments['reason'])
            : '';

        try {
            $item = $this->content->create(
                new CreateContentCommand(
                    title: sprintf('Blocked: %1$s to %2$s', $start, $end),
                    type: AvailabilityDefinition::BLOCKED_TYPE,
                    authorId: $userId,
                    status: 'publish',
                    meta: array(
                        '_start_time' => $start,
                        '_end_time'   => $end,
                        '_reason'     => $reason,
                        '_blocked_by' => $userId,
                    ),
                )
            );
        } catch (AccessDeniedException $error) {
            return $this->errors->forbidden($error->getMessage());
        } catch (ValidationException $error) {
            return $this->errors->validationFailed(
                $error->getMessage(),
                $error->hasFieldErrors() ? $error->errors : array()
            );
        } catch (\Throwable $error) {
            return $this->errors->create('block_write_failed', $error->getMessage());
        }

        return $this->success(
            'Time slot blocked successfully.',
            array(
                'blocked_id' => $item->id,
                'start_time' => $start,
                'end_time'   => $end,
                'reason'     => $reason,
                'message'    => 'Time slot blocked successfully.',
            )
        );
    }
}
