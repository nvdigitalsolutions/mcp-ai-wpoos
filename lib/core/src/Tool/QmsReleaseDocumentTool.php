<?php

/**
 * Release a controlled document into use.
 *
 * `approved → released`. This is the transition that makes a document **current**
 * under ISO 9001 §7.5.3, so it clears any review due date: the review just
 * happened as part of approval, and leaving a stale date would misreport the next
 * obligation.
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

class QmsReleaseDocumentTool extends AbstractQmsTransitionTool
{
    public function getSlug(): string
    {
        return 'qms_release_document';
    }

    public function getName(): string
    {
        return 'QMS: Release Document';
    }

    public function getDescription(): string
    {
        return 'Release an approved document so it becomes current. Clears the review due date and records the release time.';
    }

    protected function target(): string
    {
        return DocumentLifecycle::RELEASED;
    }
}
