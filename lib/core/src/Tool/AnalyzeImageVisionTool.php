<?php

/**
 * Tool: analyze_image — ask a vision model what is in an image.
 *
 * WordPress has had this tool since 1.1; the Laravel runtime had only
 * `analyze_image_prompt`, a stand-in that returns a prompt and analyses nothing.
 * That stand-in is quarantined in the manifest with `replaced_by: analyze_image`,
 * and this is the tool that pointer promises.
 *
 * ## The transport already existed
 *
 * The provider clients already accept OpenAI's `image_url` content segment and
 * convert it to their own dialect — `GeminiClient` to Gemini's own inline image
 * part, `AnthropicClient` to an image block — so no new provider surface was
 * needed. `CompletionRequest` builds the message, `ProviderRouter` decides which
 * client answers, and `AbstractCompletionTool` owns the call and its failures.
 *
 * ## Where the bytes come from
 *
 * `ImageInput` owns that question for every vision tool: `file_id` (the registry,
 * with its own ownership check) or `image_data` (a data URI), never a remote URL.
 * See its docblock for why the URL is refused and why an inaccessible file answers
 * 404.
 *
 * ## What it costs, and what that means
 *
 * This is the first tool in the assistant family that spends provider credit per
 * call by design. The flags say so (`consumes-tokens`, `rate-limited`,
 * `external-api`), which is what the necessity gate and the read-only profile
 * read.
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
use Nvoos\Core\Application\Media\VisionRequest;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;

final class AnalyzeImageVisionTool extends AbstractCompletionTool
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
        return 'analyze_image';
    }

    public function getName(): string
    {
        return 'Analyze Image';
    }

    public function getDescription(): string
    {
        return 'Answers a question about an image using a vision-capable model: describe it, read its text, identify objects, or compare it against an instruction. Supply the image by file_id (a file in this runtime\'s registry) or image_data (a data: URI). Remote URLs are not fetched by this runtime. Returns the model\'s text answer.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'prompt' => array(
                    'type'        => 'string',
                    'description' => 'What to ask about the image.',
                    'default'     => VisionRequest::DEFAULT_PROMPT,
                    'maxLength'   => 4000,
                ),
            ) + ImageInput::parameters() + $this->providerParameters() + array(
                'max_tokens' => $this->maxTokensParameter('Cap on the answer length.'),
            ),
            'additionalProperties' => false,
        );
    }

    /**
     * WordPress requires `upload_files` here, and it is the right predicate: a
     * caller may analyse the images it could have uploaded, and no others.
     */
    public function getRequiredCapability(): string
    {
        return 'upload_files';
    }

    /**
     * WordPress declares nine flags for this tool. Eight are declared here;
     * `async` is not, because this call is synchronous — declaring it would tell
     * the necessity gate to treat a completed request as a queued one.
     */
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

        $prompt = $this->stringParam($arguments, 'prompt');

        if ('' === $prompt) {
            $prompt = VisionRequest::DEFAULT_PROMPT;
        }

        $answer = $this->completeTool($arguments, $prompt, $image['dataUri']);

        if ($this->errors->isError($answer)) {
            return $answer;
        }

        return $this->success(
            \sprintf('Analysed the image with %s.', $answer['provider']),
            array(
                'text'     => $answer['text'],
                'provider' => $answer['provider'],
                'model'    => $answer['model'],
                'usage'    => $answer['usage'],
                'mime'     => $image['mime'],
                'bytes'    => $image['size'],
            )
        );
    }

    /**
     * These refusals shipped before the shared base existed; renaming a live
     * error code breaks callers for no gain.
     */
    protected function unavailableCode(): string
    {
        return 'vision_unavailable';
    }

    protected function noDefaultProviderCode(): string
    {
        return 'vision_provider_unavailable';
    }

    protected function providerErrorCode(): string
    {
        return 'vision_provider_error';
    }

    protected function emptyResponseCode(): string
    {
        return 'vision_empty_response';
    }
}
