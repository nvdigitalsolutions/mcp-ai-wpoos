<?php

/**
 * Delete a calendar event permanently.
 *
 * Port of `WP_MCP_AI_Tool_Delete_Event` (Proposal 164 section 164.84). The
 * record is removed through `ContentStoreInterface`, so the store's delete rule
 * is the effective gate.
 *
 * ## What WordPress did
 *
 * It declared `edit_posts` and then checked `delete_posts`, then deleted any id
 * that carried the event type without asking whether the caller could touch it.
 * The port keeps the declaration and the check (a host may evaluate the
 * declared capability) and adds the store's `delete` rule, which is the check
 * that actually protects another author's event.
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
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;
use Nvoos\Core\Tool\AbstractTool;

class DeleteEventTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'delete_event';
    }

    public function getName(): string
    {
        return 'Delete Event';
    }

    public function getDescription(): string
    {
        return 'Deletes a calendar event permanently. This cannot be undone.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'event_id' => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Event ID to delete (required).',
                ),
            ),
            'required'             => array('event_id'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return CapabilityFlagVocabulary::LOCAL_DESTRUCTIVE;
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'delete_posts');

        $userId  = (int) ( $context['user_id'] ?? 0 );
        $eventId = max(0, $this->intParam($arguments, 'event_id', 0));

        if (0 === $eventId) {
            return $this->errors->create('missing_id', 'Event ID is required.');
        }

        $item = $this->content->find($eventId);

        if (! $item instanceof ContentItem || CalendarEventDefinition::TYPE !== $item->type) {
            return $this->errors->create('invalid_event', 'Event not found.');
        }

        if (! $this->content->userCanAccess($eventId, $userId, 'delete')) {
            return $this->errors->forbidden('You do not have permission to delete this event.');
        }

        try {
            $this->content->delete($eventId, $userId);
        } catch (AccessDeniedException $error) {
            return $this->errors->forbidden($error->getMessage());
        } catch (NotFoundException $error) {
            return $this->errors->create('invalid_event', $error->getMessage());
        } catch (\Throwable $error) {
            return $this->errors->create('delete_failed', $error->getMessage());
        }

        return $this->success(
            'Event deleted successfully.',
            array(
                'event_id' => $eventId,
                'deleted'  => true,
            )
        );
    }
}
