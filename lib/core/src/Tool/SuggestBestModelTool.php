<?php
/**
 * Suggest Best Model — rules engine for model recommendations based on task type.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Tool;

class SuggestBestModelTool extends AbstractTool
{
    private const RECOMMENDATIONS = [
        'coding'      => ['quality' => 'claude-sonnet-4-6',  'speed' => 'gpt-4o-mini',           'cost' => 'deepseek-chat'],
        'writing'     => ['quality' => 'claude-opus-4-6',    'speed' => 'gpt-4o-mini',           'cost' => 'gemini-2.0-flash'],
        'analysis'    => ['quality' => 'gpt-5',              'speed' => 'gpt-4o-mini',           'cost' => 'gemini-2.0-flash-lite'],
        'vision'      => ['quality' => 'gpt-4o',             'speed' => 'gemini-2.0-flash',      'cost' => 'gemini-2.0-flash-lite'],
        'math'        => ['quality' => 'o4-mini',            'speed' => 'deepseek-chat',         'cost' => 'deepseek-chat'],
        'translation' => ['quality' => 'claude-sonnet-4-6',  'speed' => 'gpt-4o-mini',           'cost' => 'gemini-2.0-flash'],
    ];

    public function getSlug(): string { return 'suggest_best_model'; }

    public function getName(): string { return 'Suggest Best Model'; }

    public function getDescription(): string
    {
        return 'Recommends the best AI model for a given task based on capability requirements.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'task' => [
                    'type'        => 'string',
                    'description' => 'Task description (coding, writing, analysis, vision, etc.).',
                ],
                'priority' => [
                    'type'        => 'string',
                    'description' => 'Optimize for: speed, quality, or cost. Default: quality.',
                    'enum'        => ['speed', 'quality', 'cost'],
                    'default'     => 'quality',
                ],
            ],
            'required'             => ['task'],
            'additionalProperties' => false,
        ];
    }

    public function getRequiredCapability(): string { return 'read'; }

    public function execute(array $arguments = [], array $context = []): mixed
    {
        $task     = $this->stringParam($arguments, 'task');
        $priority = $this->stringParam($arguments, 'priority', 'quality');

        $best = self::RECOMMENDATIONS['analysis'];

        foreach (self::RECOMMENDATIONS as $keyword => $candidates) {
            if (\str_contains(\strtolower($task), $keyword)) {
                $best = $candidates;
                break;
            }
        }

        $model = $best[$priority];
        $alternatives = \array_values(\array_diff($best, [$model]));

        return $this->success(
            "Recommended model for '{$task}': {$model}.",
            [
                'task'         => $task,
                'priority'     => $priority,
                'model'        => $model,
                'alternatives' => $alternatives,
            ],
        );
    }
}
