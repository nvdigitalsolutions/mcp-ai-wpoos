<?php

/**
 * Supersede a released controlled document.
 *
 * `released → superseded`. ISO 9001 §7.5.3 requires obsolete information to be
 * prevented from unintended use, and supersession is the precise case: a new
 * revision has replaced this one, so this one must stop being current without
 * being destroyed. `superseded_by` names the replacement so the trail points
 * forward as well as back.
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

class QmsSupersedeDocumentTool extends AbstractQmsTransitionTool
{
    public function getSlug(): string
    {
        return 'qms_supersede_document';
    }

    public function getName(): string
    {
        return 'QMS: Supersede Document';
    }

    public function getDescription(): string
    {
        return 'Mark a released document as superseded by a replacement. The document is no longer current but remains readable for audit.';
    }

    protected function target(): string
    {
        return DocumentLifecycle::SUPERSEDED;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function extraProperties(): array
    {
        return array(
            'superseded_by' => array(
                'type' => 'string',
                'description' => 'Corpus-relative path of the revision that replaces this one.',
            ),
        );
    }

    /**
     * @param array<string, mixed> $arguments The originating arguments.
     *
     * @return array<string, mixed>
     */
    protected function meta(array $arguments): array
    {
        $replacement = $arguments['superseded_by'] ?? '';

        return is_string($replacement) && '' !== trim($replacement)
            ? array('superseded_by' => $replacement)
            : array();
    }
}
