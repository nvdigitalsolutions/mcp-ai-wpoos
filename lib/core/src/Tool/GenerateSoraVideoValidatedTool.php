<?php

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Service\Safety\ToolCapabilityFlagCatalogue;

/**
 * File header.
 *
 * @package WP_MCP_AI
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */
class GenerateSoraVideoValidatedTool extends GenerateSoraVideoTool implements ToolCapabilityFlagsInterface
{
    public function getSlug(): string
    {
        return 'generate_sora_video_validated';
    }
    public function getName(): string
    {
        return 'Generate Sora Video (Validated)';
    }

    /**
     * Capability flags — Proposal 149 §154.
     *
     * Delegates to its parent class, so it declares the parent's flags.
     * Reading them from the catalogue keeps the two in step.
     *
     * @return list<string>
     */
    public function getCapabilityFlags(): array
    {
        return ToolCapabilityFlagCatalogue::for('generate_sora_video');
    }
}
