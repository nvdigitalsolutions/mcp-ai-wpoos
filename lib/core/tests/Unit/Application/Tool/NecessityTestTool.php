<?php

/**
 * Tool double carrying the flags that trigger an approval.
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Tool;

use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;

/**
 * @internal Test double.
 */
final class NecessityTestTool implements ToolInterface, ToolCapabilityFlagsInterface
{
    /**
     * @param list<string> $flags Capability flags.
     */
    public function __construct(
        private readonly string $slug,
        private readonly array $flags,
    ) {
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->slug;
    }

    public function getDescription(): string
    {
        return '';
    }

    public function getParametersSchema(): array
    {
        return array('type' => 'object', 'properties' => array());
    }

    public function getRequiredCapability(): string
    {
        return '';
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        unset($arguments, $context);

        return array('ok' => true);
    }

    public function getCapabilityFlags(): array
    {
        return $this->flags;
    }
}
