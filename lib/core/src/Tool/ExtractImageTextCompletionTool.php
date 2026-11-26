<?php

/**
 * Tool: extract_image_text — read the text in an image.
 *
 * `ExtractImageTextCompletionTool` because `ExtractImageTextTool` is the
 * quarantined prompt-builder answering to `extract_image_text_prompt`.
 *
 * ## An empty answer is a finding here
 *
 * This is the one tool in the family for which "nothing" is a real result: a
 * photograph of a dog has no text in it, and reporting `completion_empty_response`
 * would tell the caller the call failed when it succeeded and found nothing. So
 * the call is made with `allowEmpty`, and the payload distinguishes the two
 * outcomes with `has_text`.
 *
 * That is the opposite of the rule every other completion tool follows, and the
 * difference is deliberate: elsewhere a blank answer usually means the model
 * declined, and a blank success reads as "nothing to report".
 *
 * ## Layout
 *
 * `preserve_layout` asks for the text as it sits on the page rather than as a
 * single run. Models are not layout engines, so this is a prompt instruction and
 * not a guarantee — which is why the result reports the flag it applied rather
 * than claiming a reading order it cannot verify.
 *
 * WordPress also offers `include_metadata`. It is not offered here: the counts
 * are always returned because they cost nothing, and a parameter that changes
 * nothing in the answer is a lying surface.
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

final class ExtractImageTextCompletionTool extends AbstractCompletionTool
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
        return 'extract_image_text';
    }

    public function getName(): string
    {
        return 'Extract Image Text';
    }

    public function getDescription(): string
    {
        return 'Reads the text visible in an image and returns it as plain text, optionally keeping the line breaks and reading order of the page. Reports whether any text was found, so an image with none is a clear result rather than an error. Supply the image by file_id or image_data; remote URLs are not fetched.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'preserve_layout' => array(
                    'type'        => 'boolean',
                    'description' => 'Keep the line breaks and reading order rather than returning one run of text.',
                    'default'     => false,
                ),
            ) + ImageInput::parameters() + $this->providerParameters() + array(
                'max_tokens' => $this->maxTokensParameter('Cap on the transcription length.', 4096),
            ),
            'additionalProperties' => false,
        );
    }

    /**
     * WordPress requires `upload_files`, which is the right predicate: read the
     * text out of the images you could have uploaded.
     */
    public function getRequiredCapability(): string
    {
        return 'upload_files';
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

        $preserveLayout = $this->boolParam($arguments, 'preserve_layout');

        $answer = $this->completeTool(
            $arguments,
            $this->prompt($preserveLayout),
            $image['dataUri'],
            '',
            true
        );

        if ($this->errors->isError($answer)) {
            return $answer;
        }

        $text = $this->transcription($answer['text'], $preserveLayout);
        $has  = '' !== \trim($text);

        return $this->success(
            $has
                ? \sprintf('Read %d characters from the image with %s.', \mb_strlen($text), $answer['provider'])
                : \sprintf('No text was found in the image (%s).', $answer['provider']),
            array(
                'text'            => $text,
                'has_text'        => $has,
                'character_count' => \mb_strlen($text),
                'word_count'      => \str_word_count($text),
                'preserve_layout' => $preserveLayout,
                'mime'            => $image['mime'],
                'provider'        => $answer['provider'],
                'model'           => $answer['model'],
                'usage'           => $answer['usage'],
            )
        );
    }

    private function prompt(bool $preserveLayout): string
    {
        $lines = array(
            'Transcribe every piece of text visible in this image, exactly as written.',
            'Do not translate, correct spelling, or add commentary.',
            'Return the transcription and nothing else — no preamble, no quotation marks.',
        );

        if ($preserveLayout) {
            $lines[] = 'Keep the line breaks and the reading order of the page.';
        } else {
            $lines[] = 'Return it as continuous running text.';
        }

        $lines[] = 'If the image contains no legible text, return nothing at all.';

        return \implode("\n", $lines);
    }

    /**
     * Trim the framing a model adds, keeping the text's own structure when layout
     * was requested — collapsing its newlines would throw away the thing the
     * caller asked for.
     */
    private function transcription(string $text, bool $preserveLayout): string
    {
        return $preserveLayout ? $this->tidyBlock($text) : $this->tidyText($text);
    }
}
