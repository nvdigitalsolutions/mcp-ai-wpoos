<?php

/**
 * Resolver double for a single tool.
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Tool;

use Nvoos\Core\Domain\Contract\ToolInterface;
use Nvoos\Core\Domain\Contract\ToolResolverInterface;

/**
 * @internal Test double.
 */
final class NecessityTestResolver implements ToolResolverInterface
{
    public function __construct(private readonly ToolInterface $tool)
    {
    }

    public function get(string $slug): ?ToolInterface
    {
        return $slug === $this->tool->getSlug() ? $this->tool : null;
    }

    public function has(string $slug): bool
    {
        return $slug === $this->tool->getSlug();
    }
}
