<?php

/**
 * Mark a controlled document obsolete.
 *
 * The one transition that can be reached from several states — an approved
 * document that was never issued, a released one being withdrawn, or a superseded
 * one whose retention period has ended. `obsolete` is **terminal**: a document that
 * must come back into use is a new revision, not a reopened record, because
 * reopening a withdrawn revision would let it re-enter use, which is exactly what
 * ISO 9001 §7.5.3 forbids.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Qms\DocumentLifecycle;

class QmsMarkObsoleteTool extends AbstractQmsTransitionTool
{
    public function getSlug(): string
    {
        return 'qms_mark_obsolete';
    }

    public function getName(): string
    {
        return 'QMS: Mark Obsolete';
    }

    public function getDescription(): string
    {
        return 'Withdraw a controlled document permanently. Reachable from approved, released or superseded; the record stays readable and the state is terminal.';
    }

    protected function target(): string
    {
        return DocumentLifecycle::OBSOLETE;
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('requires-capability', 'state-changing', 'destructive', 'write');
    }
}
