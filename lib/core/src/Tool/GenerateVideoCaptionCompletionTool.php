<?php

/**
 * Tool: generate_video_caption — one sentence for a stored video.
 *
 * `GenerateVideoCaptionCompletionTool` because `GenerateVideoCaptionTool` is the
 * quarantined prompt-builder answering to `generate_video_caption_prompt`.
 *
 * A caption is bounded, so `max_length` is enforced here rather than trusted to the
 * model: a prompt that says "at most 200 characters" is a request, and a truncation is a
 * guarantee. When the cut happens the payload says so with `truncated: true`, because a
 * silently shortened sentence reads like the model wrote it that way.
 *
 * Surrounding quotes are stripped — models wrap captions in them even when told not to,
 * and the quotes are not part of the caption.
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

final class GenerateVideoCaptionCompletionTool extends AbstractVideoCompletionTool
{
    private const DEFAULT_MAX_LENGTH = 200;

    private const MIN_MAX_LENGTH = 50;

    private const MAX_MAX_LENGTH = 500;

    public function getSlug(): string
    {
        return 'generate_video_caption';
    }

    public function getName(): string
    {
        return 'Generate Video Caption';
    }

    public function getDescription(): string
    {
        return 'Writes one caption for a stored video, for a reader deciding whether to watch it, bounded to a character count. The video is sent to the provider whole. Requires a provider that accepts video input, such as Google Gemini.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'file_id'    => $this->fileIdParameter('Stored video to caption.'),
                'max_length' => array(
                    'type'        => 'integer',
                    'description' => 'Maximum caption length in characters.',
                    'minimum'     => self::MIN_MAX_LENGTH,
                    'maximum'     => self::MAX_MAX_LENGTH,
                    'default'     => self::DEFAULT_MAX_LENGTH,
                ),
                'context'    => array(
                    'type'        => 'string',
                    'description' => 'Optional context that shapes the caption.',
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
        $max = $this->maxLength($arguments);

        $instruction = \sprintf(
            "Write one caption for this video, for a reader deciding whether to watch it. At most %d characters. Reply with the caption only: no quotes, no preamble, no trailing commentary.",
            $max
        );

        return $instruction . $this->contextNote($arguments);
    }

    protected function respond(array $answer, StoredFile $file, array $arguments): array
    {
        $max = $this->maxLength($arguments);

        $caption = \trim((string) $answer['text']);
        $caption = \trim($caption, "\"'“”‘’ \n\r\t");

        $truncated = \mb_strlen($caption) > $max;

        if ($truncated) {
            $caption = \mb_substr($caption, 0, $max);
        }

        return $this->success(
            $truncated
                ? \sprintf('Caption for %s, shortened to the %d-character limit.', $file->filename, $max)
                : \sprintf('Caption for %s.', $file->filename),
            array(
                'caption'    => $caption,
                'truncated'  => $truncated,
                'max_length' => $max,
                'file_id'    => $file->id,
                'filename'   => $file->filename,
                'mime_type'  => $file->mimeType,
                'provider'   => $answer['provider'],
                'model'      => $answer['model'],
                'usage'      => $answer['usage'],
            )
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function maxLength(array $arguments): int
    {
        return \max(
            self::MIN_MAX_LENGTH,
            \min(
                self::MAX_MAX_LENGTH,
                $this->intParam($arguments, 'max_length', self::DEFAULT_MAX_LENGTH)
            )
        );
    }
}
