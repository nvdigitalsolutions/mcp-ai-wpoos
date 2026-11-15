<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Nvoos\Core\Application\Tool\ToolReadiness;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ToolInterface;

class ToolsController
{
    public function __invoke(ToolRegistry $registry, ToolReadiness $readiness): JsonResponse
    {
        $tools = array_map(
            static function (ToolInterface $tool) use ($readiness): array {
                $slug = $tool->getSlug();

                return array(
                    'slug' => $slug,
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'required_capability' => $tool->getRequiredCapability(),
                    // 090-M: readiness is what the client needs to tell a tool
                    // that works from one that is merely present in the package.
                    'readiness' => $readiness->classify($slug),
                    'annotations' => $readiness->annotations($slug),
                );
            },
            array_values($registry->all()),
        );

        $servable = 0;
        foreach ($tools as $tool) {
            if (ToolReadiness::isServableClass($tool['readiness'])) {
                $servable++;
            }
        }

        return response()->json(
            array(
                'success' => true,
                'data' => array(
                    'tools' => $tools,
                    'total' => count($tools),
                    'servable' => $servable,
                    'endpoint_contract_version' => ToolReadiness::CONTRACT_VERSION,
                ),
            )
        );
    }
}
