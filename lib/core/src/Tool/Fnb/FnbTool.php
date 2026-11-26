<?php

/**
 * Shared ground for the Food & Beverage toolkit ports.
 *
 * The WordPress base (`WP_MCP_AI_Fnb_Tool_Base`) declares `read` as the
 * capability, flags `pro, read-only, cacheable, local-only, idempotent`, and a
 * `guard()` that re-checks the capability. Two of those carry over unchanged:
 * the capability (`getRequiredCapability()`), and the read-only/idempotent pair.
 *
 * **`local-only` does not carry over.** WordPress reads its tables from a Drive
 * connection or from posts; this runtime reads them through
 * `TabularReaderInterface`, which the Laravel adapter binds to the media
 * worker — an HTTP call. Declaring `local-only` would tell `NecessityGate` that
 * no request leaves the host, which is not true. `external-api` is not declared
 * either: the call only reads, and `CapabilityFlagVocabulary` treats an outbound
 * read as safe (`hasOutboundMutation()` asks for `write`/`state-changing`).
 *
 * `cacheable` is kept because `FnbTableSource` caches a resolved table for five
 * minutes, exactly as the WordPress transient does.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Fnb;

use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Tool\AbstractTool;

abstract class FnbTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * WordPress declares `read` for every F&B tool. The registry checks it
     * before `execute()` runs; `authorize()` here asserts the user is present.
     */
    public function getRequiredCapability(): string
    {
        return 'read';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('read-only', 'idempotent', 'cacheable');
    }
}
