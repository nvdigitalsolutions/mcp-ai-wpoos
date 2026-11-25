<?php

/**
 * Approve a controlled document.
 *
 * `review → approved`. Approval is deliberately **not** release: an approved
 * document that has not been issued is not current (ISO 9001 §7.5.3), and keeping
 * the two steps apart is what lets an organisation approve a revision now and
 * issue it on a date of its choosing — or not at all.
 *
 * A rejection takes the same route in reverse, `review → draft`, through
 * `qms_submit_for_review`'s sibling transition; the reason is recorded either way.
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

class QmsApproveDocumentTool extends AbstractQmsTransitionTool
{
    public function getSlug(): string
    {
        return 'qms_approve_document';
    }

    public function getName(): string
    {
        return 'QMS: Approve Document';
    }

    public function getDescription(): string
    {
        return 'Approve a document in review. Approval records the decision and who made it; the document is not current until it is released.';
    }

    protected function target(): string
    {
        return DocumentLifecycle::APPROVED;
    }
}
