<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ToolInterface;

class ToolsController
{
    public function __invoke(ToolRegistry $registry): JsonResponse
    {
        $tools = array_map(
            static fn (ToolInterface $tool): array => [
                'slug' => $tool->getSlug(),
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'required_capability' => $tool->getRequiredCapability(),
            ],
            array_values($registry->all()),
        );

        return response()->json([
            'success' => true,
            'data' => [
                'tools' => $tools,
                'total' => count($tools),
            ],
        ]);
    }
}
