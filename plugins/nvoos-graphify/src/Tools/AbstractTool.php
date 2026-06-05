<?php
/**
 * Abstract base class for built-in and addon tools.
 *
 * Provides sensible defaults so tool authors only need to implement
 * the specific logic.
 *
 * @since   1.0.0
 * @package NvoosGraphify
 */

declare(strict_types=1);

namespace NvoosGraphify\Tools;

use NvoosGraphify\Contracts\Tool;

/**
 * Abstract tool base.
 *
 * @since 1.0.0
 */
abstract class AbstractTool implements Tool
{
    /**
     * @since 1.0.0
     * @return array<string,bool>
     */
    public function getCapabilityFlags(): array
    {
        return array();
    }
}
