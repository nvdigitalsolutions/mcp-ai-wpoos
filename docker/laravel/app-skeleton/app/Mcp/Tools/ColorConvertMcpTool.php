<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;

#[Name('color_convert')]
#[Description('Convert a color between HEX, RGB, and HSL formats.')]
#[IsReadOnly]
#[IsIdempotent]
class ColorConvertMcpTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $arguments = [
            'color' => (string) $request->get('color', ''),
        ];

        $toFormat = $request->get('to_format');
        if (is_string($toFormat) && '' !== $toFormat) {
            $arguments['to_format'] = $toFormat;
        }

        $errors = app(ErrorFactoryInterface::class);

        try {
            $result = app(ToolRegistry::class)->execute(
                'color_convert',
                $arguments,
                [
                    'user_id' => (int) ($request->user()?->id ?? 0),
                    'auth_provider' => app(AuthProviderInterface::class),
                ],
            );
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }

        if ($errors->isError($result)) {
            return Response::error($errors->normalize($result)['message']);
        }

        $data = $result['data'] ?? [];

        return Response::structured(is_array($data) ? $data : ['value' => $data]);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'color' => $schema->string()
                ->description('A HEX (#ff0000, #f00), RGB (rgb(255,0,0)), or HSL (hsl(0,100%,50%)) value.')
                ->required(),
            'to_format' => $schema->string()
                ->enum(['hex', 'rgb', 'hsl'])
                ->default('hex')
                ->description('Output format.'),
        ];
    }
}
