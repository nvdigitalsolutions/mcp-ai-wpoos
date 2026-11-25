<?php

/**
 * Submit a controlled document for review.
 *
 * `draft → review`. ISO 9001 §7.5.2 requires documented information to be reviewed
 * for "suitability and adequacy" before it is approved, so submission is the point
 * at which a document leaves the author's control and enters the review record.
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

class QmsSubmitForReviewTool extends AbstractQmsTransitionTool
{
    public function getSlug(): string
    {
        return 'qms_submit_for_review';
    }

    public function getName(): string
    {
        return 'QMS: Submit for Review';
    }

    public function getDescription(): string
    {
        return 'Move a draft controlled document into review. Records who submitted it and why in the append-only audit trail.';
    }

    protected function target(): string
    {
        return DocumentLifecycle::REVIEW;
    }
}
