<?php

/**
 * Tool: generate_image_alt_text — the sentence a screen reader reads.
 *
 * `GenerateImageAltTextCompletionTool` because `GenerateImageAltTextTool` is the
 * quarantined prompt-builder answering to `generate_image_alt_text_prompt`.
 *
 * ## Why the length is reported and not enforced
 *
 * Alt text is read aloud by a screen reader and shown when an image fails to load.
 * The widely used guidance is to stay near **125 characters** — not a hard limit,
 * but the point past which a reader starts skimming and the description stops
 * being useful. The tool therefore asks for that length, then **reports** the
 * actual count and `within_recommended_length` rather than truncating: cutting an
 * alt text mid-phrase leaves a reader with a broken sentence and no indication
 * that anything was dropped.
 *
 * ## Why `context` is a parameter
 *
 * The same photograph needs different alt text on a product page and in a blog
 * post — "a red leather handbag on a white background" versus "the handbag the
 * author carried for a decade". The caller knows the surrounding page; the model
 * does not. This is the one piece of information that makes alt text good rather
 * than merely accurate.
 *
 * ## Decorative images
 *
 * An image that carries no information should have **empty** alt text, not a
 * description of itself. The prompt asks the model to say so, and the tool maps
 * that to an empty string with `decorative: true` — the same reasoning as
 * `extract_image_text`'s empty answer: a finding, not a failure.
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

final class GenerateImageAltTextCompletionTool extends AbstractCompletionTool
{
    /**
     * The length past which a screen-reader description stops being useful.
     */
    public const RECOMMENDED_MAX_CHARACTERS = 125;

    /**
     * What the model must return for an image that carries no information.
     */
    private const DECORATIVE_SENTINEL = '[DECORATIVE]';

    public function __construct(
        ErrorFactoryInterface $errors,
        ?ProviderRouter $providers = null,
        private readonly ?FileStoreInterface $files = null,
    ) {
        parent::__construct($errors, $providers);
    }

    public function getSlug(): string
    {
        return 'generate_image_alt_text';
    }

    public function getName(): string
    {
        return 'Generate Image Alt Text';
    }

    public function getDescription(): string
    {
        return 'Writes accessible alt text for an image: a description of what it shows and why it is there, near 125 characters, for a screen reader or a failed load. Returns the text with its length, and marks an image that carries no information as decorative rather than describing it.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'context' => array(
                    'type'        => 'string',
                    'description' => 'What the surrounding page is about, or what the image is being used for. This is what separates useful alt text from an accurate inventory.',
                    'maxLength'   => 1000,
                ),
            ) + ImageInput::parameters() + $this->providerParameters() + array(
                'max_tokens' => $this->maxTokensParameter('Cap on the answer length.', 256),
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

        $answer = $this->completeTool(
            $arguments,
            $this->prompt($contextText),
            $image['dataUri'],
            '',
            true
        );

        if ($this->errors->isError($answer)) {
            return $answer;
        }

        $altText    = $this->tidyText($answer['text']);
        $decorative = self::DECORATIVE_SENTINEL === \strtoupper($altText);

        if ($decorative) {
            $altText = '';
        }

        $characters = \mb_strlen($altText);
        $lengths    = $characters <= self::RECOMMENDED_MAX_CHARACTERS;

        return $this->success(
            $decorative
                ? 'The image carries no information; alt text should be empty.'
                : \sprintf(
                    'Alt text is %d characters%s.',
                    $characters,
                    $lengths ? '' : \sprintf(' (over the recommended %d)', self::RECOMMENDED_MAX_CHARACTERS)
                ),
            array(
                'alt_text'                  => $altText,
                'decorative'                => $decorative,
                'characters'                => $characters,
                'within_recommended_length' => $lengths,
                'recommended_max'           => self::RECOMMENDED_MAX_CHARACTERS,
                'mime'                      => $image['mime'],
                'provider'                  => $answer['provider'],
                'model'                     => $answer['model'],
                'usage'                     => $answer['usage'],
            )
        );
    }

    private function prompt(string $contextText): string
    {
        $lines = array(
            'Write alt text for this image.',
            '',
            'Rules:',
            \sprintf('- At most %d characters. Shorter is better.', self::RECOMMENDED_MAX_CHARACTERS),
            '- Describe what the image shows and, where it matters, what it is doing there.',
            '- Do not start with "Image of" or "Photo of" — a screen reader already says it is an image.',
            '- Do not repeat the surrounding page\'s wording; describe the image, not the page.',
            '- Plain text: no quotation marks, no markdown, no trailing period if the text is a phrase.',
            '- Return the alt text and nothing else.',
            '',
            \sprintf('If the image is purely decorative and carries no information, return exactly %s.', self::DECORATIVE_SENTINEL),
        );

        if ('' !== $contextText) {
            $lines[] = '';
            $lines[] = 'The image is used here: ' . $contextText;
        }

        return \implode("\n", $lines);
    }
}
