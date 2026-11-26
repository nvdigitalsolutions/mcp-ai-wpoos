<?php

/** Profession Stats. @package Nvoos\Core @since 2.0.0 @license MIT
 *
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ProfessionRepositoryInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;

class ProfessionStatsTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(ErrorFactoryInterface $e, private readonly ProfessionRepositoryInterface $p)
    {
        parent::__construct($e);
    }
    public function getSlug(): string
    {
        return 'profession_stats';
    }
    public function getName(): string
    {
        return 'Profession Stats';
    }
    public function getDescription(): string
    {
        return 'Returns statistics about available professions grouped by category.';
    }
    public function getParametersSchema(): array
    {
        return array('type' => 'object','properties' => new \stdClass(),'additionalProperties' => false);
    }
    public function getRequiredCapability(): string
    {
        return 'read';
    }
    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $cats = $this->p->getCategories();
        $total = 0;
        foreach ($cats as $c) {
            $total += \count($c);
        }
        return $this->success('Profession statistics.', array('total_professions' => $total,'category_count' => \count($cats),'categories' => array_map(fn($c)=>count($c), $cats)));
    }

    /**
     * Capability flags — Proposal 149 §154.
     *
     * Reads a local store. Side-effect free, but not cacheable: the store
     * can change between two identical calls.
     *
     * @return list<string>
     */
    public function getCapabilityFlags(): array
    {
        return CapabilityFlagVocabulary::LOCAL_READ;
    }
}
