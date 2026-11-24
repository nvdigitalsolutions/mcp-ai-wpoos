<?php

/**
 * Tool: analyze_video — describe a stored video for a reader who cannot watch it.
 *
 * `AnalyzeVideoCompletionTool` because `AnalyzeVideoTool` is the quarantined
 * prompt-builder answering to `analyze_video_prompt`. Frames are not extracted; the
 * video goes to the provider whole (see {@see AbstractVideoCompletionTool}), so the
 * model sees motion rather than a sample of stills.
 *
 * `analysis_type` is a shape for the answer, not a different pipeline: the same bytes go
 * out, and a timeline, a scene breakdown and a general description differ only in what
 * the model is asked to produce.
 *
 * `include_timestamps` is offered because the model has the video's own timeline and can
 * report positions in it. Asking for timestamps does not make them exact, which is why
 * they are described as approximate rather than presented as metadata.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Entity\StoredFile;

final class AnalyzeVideoCompletionTool extends AbstractVideoCompletionTool
{
    private const ANALYSIS_TYPES = array( 'general', 'scene_breakdown', 'timeline', 'detailed' );

    public function getSlug(): string
    {
        return 'analyze_video';
    }

    public function getName(): string
    {
        return 'Analyze Video';
    }

    public function getDescription(): string
    {
        return 'Analyses a stored video and returns a description, a scene breakdown, a timeline or a detailed account, optionally with approximate timestamps. The video is sent to the provider whole, so the model sees motion rather than extracted stills. Requires a provider that accepts video input, such as Google Gemini.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'file_id'            => $this->fileIdParameter('Stored video to analyse.'),
                'analysis_type'      => array(
                    'type'        => 'string',
                    'description' => 'general describes what happens; scene_breakdown splits it into scenes; timeline gives a chronological account; detailed analyses subjects, action, setting, on-screen text and audio cues.',
                    'enum'        => self::ANALYSIS_TYPES,
                    'default'     => 'general',
                ),
                'include_timestamps' => array(
                    'type'        => 'boolean',
                    'description' => 'Ask for approximate timestamps at the key moments.',
                    'default'     => false,
                ),
                'context'            => array(
                    'type'        => 'string',
                    'description' => 'Optional context that shapes what the analysis looks for.',
                ),
            ) + $this->providerParameters() + array(
                'max_tokens' => $this->maxTokensParameter('Cap on the answer length.'),
            ),
            'required'             => array( 'file_id' ),
            'additionalProperties' => false,
        );
    }

    protected function instruction(array $arguments): string
    {
        $type = $this->stringParam($arguments, 'analysis_type', 'general');

        if (! \in_array($type, self::ANALYSIS_TYPES, true)) {
            $type = 'general';
        }

        $ask = match ($type) {
            'scene_breakdown' => 'Break it into scenes: what happens in each, in order, and what changes between them.',
            'timeline' => 'Give a chronological account of what happens and when.',
            'detailed' => 'Analyse it in depth: subjects, action, setting, any on-screen text, audio cues, and how the sequence develops.',
            default => 'Describe what happens, what is shown, and anything a reader would need in order to understand it.',
        };

        $instruction = "Watch this video and report on it for a reader who cannot see it.\n\n" . $ask;

        if ($this->boolParam($arguments, 'include_timestamps')) {
            $instruction .= "\n\nInclude approximate timestamps for the key moments, marked as approximate.";
        }

        return $instruction . $this->contextNote($arguments);
    }

    protected function respond(array $answer, StoredFile $file, array $arguments): array
    {
        $type = $this->stringParam($arguments, 'analysis_type', 'general');

        if (! \in_array($type, self::ANALYSIS_TYPES, true)) {
            $type = 'general';
        }

        return $this->success(
            \sprintf('Analysed %s (%s).', $file->filename, $this->human($file->sizeBytes)),
            array(
                'analysis'           => $answer['text'],
                'analysis_type'      => $type,
                'include_timestamps' => $this->boolParam($arguments, 'include_timestamps'),
                'file_id'            => $file->id,
                'filename'           => $file->filename,
                'mime_type'          => $file->mimeType,
                'provider'           => $answer['provider'],
                'model'              => $answer['model'],
                'usage'              => $answer['usage'],
            )
        );
    }
}
