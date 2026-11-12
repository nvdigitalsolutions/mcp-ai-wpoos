<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;

class ToolController
{
    public function __invoke(Request $request, string $slug): JsonResponse
    {
        $arguments = $request->input('arguments');

        if (! is_array($arguments)) {
            $arguments = $request->except(['arguments']);
        }

        $errors = app(ErrorFactoryInterface::class);

        try {
            $result = app(ToolRegistry::class)->execute(
                $slug,
                $arguments,
                [
                    'user_id' => (int) ($request->user()?->id ?? 0),
                    'auth_provider' => app(AuthProviderInterface::class),
                ],
            );
        } catch (\Throwable $e) {
            return response()->json(
                [
                    'success' => false,
                    'error' => [
                        'code' => 'exception',
                        'message' => $e->getMessage(),
                    ],
                ],
                500,
            );
        }

        if ($errors->isError($result)) {
            return response()->json(
                [
                    'success' => false,
                    'error' => $errors->normalize($result),
                ],
                400,
            );
        }

        return response()->json($result);
    }
}
