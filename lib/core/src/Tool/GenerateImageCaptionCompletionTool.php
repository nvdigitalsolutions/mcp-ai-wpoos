<?php

/**
 * Tool: generate_image_caption — the sentence that sits under the image.
 *
 * `GenerateImageCaptionCompletionTool` because `GenerateImageCaptionTool` is the
 * quarantined prompt-builder answering to `generate_image_caption_prompt`.
 *
 * ## A caption is not alt text
 *
 * Alt text replaces the image for a reader who cannot see it and is kept near 125
 * characters. A caption is *visible* — it is read alongside the image, so it can
 * carry the thing the image cannot show: what happened, who is involved, why it
 * matters. The two tools therefore give different instructions to the model and
 * return different fields, rather than one calling the other with a longer limit.
 *
 * ## Length
 *
 * No ceiling is imposed. A caption is one or two sentences by convention, and the
 * prompt says so; `characters` is reported for a caller that wants to trim. A hard
 * limit here would be a product decision the tool has no basis for.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Application\Media\ImageInput;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;

final class GenerateImageCaptionCompletionTool extends AbstractCompletionTool
{
    public function __construct(
        ErrorFactoryInterface $errors,
        ?ProviderRouter $providers = null,
        private readonly ?FileStoreInterface $files = null,
    ) {
        parent::__construct($errors, $providers);
    }

    public function getSlug(): string
    {
        return 'generate_image_caption';
    }

    public function getName(): string
    {
        return 'Generate Image Caption';
    }

    public function getDescription(): string
    {
        return 'Writes a visible caption for an image: one or two sentences that add what the picture cannot show — what is happening, who is involved, why it matters. Distinct from alt text, which replaces the image for a reader who cannot see it.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'context' => array(
                    'type'        => 'string',
                    'description' => 'What the surrounding page is about, or what the image illustrates.',
                    'maxLength'   => 1000,
                ),
            ) + ImageInput::parameters() + $this->providerParameters() + array(
                'max_tokens' => $this->maxTokensParameter('Cap on the answer length.', 512),
            ),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    public function getCapabilityFlags(): array
    {
        return array(
            'read-only',
            'external-api',
            'network-dependent',
            'consumes-tokens',
            'requires-credentials',
            'requires-vision-model',
            'model-dependent',
            'rate-limited',
            'requires-capability',
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $image = (new ImageInput($this->errors, $this->files))->resolve($arguments, $context);

        if ($this->errors->isError($image)) {
            return $image;
        }

        $contextText = \mb_substr($this->stringParam($arguments, 'context'), 0, 1000);

        $answer = $this->completeTool($arguments, $this->prompt($contextText), $image['dataUri']);

        if ($this->errors->isError($answer)) {
            return $answer;
        }

        $caption = $this->tidyText($answer['text']);

        if ('' === $caption) {
            return $this->errors->create(
                'caption_empty',
                'The model returned a caption that was empty after cleanup.'
            );
        }

        return $this->success(
            \sprintf('Wrote a %d-character caption.', \mb_strlen($caption)),
            array(
                'caption'    => $caption,
                'characters' => \mb_strlen($caption),
                'mime'       => $image['mime'],
                'provider'   => $answer['provider'],
                'model'      => $answer['model'],
                'usage'      => $answer['usage'],
            )
        );
    }

    private function prompt(string $contextText): string
    {
        $lines = array(
            'Write a caption for this image.',
            '',
            'Rules:',
            '- One or two sentences. The image is already visible, so do not describe everything in it.',
            '- Add what the picture cannot show on its own: what is happening, who is involved, why it matters.',
            '- Write in the present tense and in plain prose.',
            '- No quotation marks around the caption, no markdown, no "Caption:" prefix.',
            '- Return the caption and nothing else.',
        );

        if ('' !== $contextText) {
            $lines[] = '';
            $lines[] = 'The image illustrates: ' . $contextText;
        }

        return \implode("\n", $lines);
    }
}
