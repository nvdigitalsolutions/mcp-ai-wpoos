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
 * What is left here is argument handling, the two image sources, and the answer
 * shape.
 *
 * ## Where the bytes come from
 *
 * Two sources, both of which put real bytes in the request:
 *
 *  - **`file_id`** — a file in this runtime's registry (§162). Ownership is
 *    checked with the registry's own `userCanAccess()`, so a caller cannot
 *    analyse a file it could not download either.
 *  - **`image_data`** — a `data:` URI the caller already holds.
 *
 * A **remote URL is refused.** Two independent reasons, and either alone would
 * be enough:
 *
 *  1. Both non-OpenAI converters treat the `image_url` string as base64
 *     payload. A `https://…` value would be decoded as garbage and the model
 *     would be asked about nothing.
 *  2. Fetching it here would be an SSRF surface that cannot be closed:
 *     `HttpClientInterface::send()` exposes no redirect or size controls, so a
 *     redirect into a private range would walk straight past any guard.
 *
 * The refusal names both alternatives, because "no" without a next step is how a
 * model retries the same call five times.
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

use Nvoos\Core\Application\Media\VisionRequest;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;

final class AnalyzeImageVisionTool extends AbstractCompletionTool
{
    /**
     * The largest image sent to a provider.
     *
     * Providers accept far more, but a tool call is not a bulk import: at 6 MB
     * raw the base64 payload is already ~8 MB, and the registry is the right
     * place for anything larger.
     */
    private const MAX_IMAGE_BYTES = 6000000;

    /**
     * The matching ceiling for a caller-supplied data URI, base64 included.
     */
    private const MAX_DATA_URI_CHARS = 8400000;

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
                'file_id'    => array(
                    'type'        => 'integer',
                    'description' => 'Id of an image in this runtime\'s file registry. Preferred for anything large, and the only way to analyse a private file.',
                    'minimum'     => 1,
                ),
                'image_data' => array(
                    'type'        => 'string',
                    'description' => 'The image as a data: URI (data:image/png;base64,…). A bare base64 string is refused, because the media type is what the model is told about the bytes.',
                    'maxLength'   => self::MAX_DATA_URI_CHARS,
                ),
                'prompt'     => array(
                    'type'        => 'string',
                    'description' => 'What to ask about the image.',
                    'default'     => VisionRequest::DEFAULT_PROMPT,
                    'maxLength'   => 4000,
                ),
            ) + $this->providerParameters() + array(
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
        $image = $this->image($arguments, $context);

        if ($this->errors->isError($image)) {
            return $image;
        }

        $prompt = $this->stringParam($arguments, 'prompt');

        if ('' === $prompt) {
            $prompt = VisionRequest::DEFAULT_PROMPT;
        }

        $answer = $this->completeTool(
            $arguments,
            $prompt,
            VisionRequest::dataUri($image['bytes'], $image['mime'])
        );

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

    /**
     * Resolve the image to bytes and a media type.
     *
     * @return array{mime: string, bytes: string, size: int}|mixed Bytes, or the
     *                                                      error to return.
     */
    private function image(array $arguments, array $context): mixed
    {
        $fileId = $this->intParam($arguments, 'file_id');
        $data   = $this->stringParam($arguments, 'image_data');

        if ($fileId > 0 && '' !== $data) {
            return $this->errors->validationFailed(
                'Supply exactly one image: file_id or image_data, not both.',
                array( 'file_id' => 'Conflicts with image_data.' )
            );
        }

        if ($fileId <= 0 && '' === $data) {
            return $this->errors->validationFailed(
                'An image is required: file_id, or image_data as a data: URI. This runtime does not fetch remote URLs.',
                array( 'file_id' => 'Supply file_id or image_data.' )
            );
        }

        if ($fileId > 0) {
            return $this->fromRegistry($fileId, $context);
        }

        return $this->fromDataUri($data);
    }

    /**
     * Read an image the registry already owns.
     *
     * The ownership check is the registry's, not a second implementation of it:
     * a caller that cannot download a file must not be able to ask a model what
     * is in it.
     *
     * @return array{mime: string, bytes: string, size: int}|mixed
     */
    private function fromRegistry(int $fileId, array $context): mixed
    {
        if (null === $this->files) {
            return $this->errors->create(
                'file_store_unavailable',
                'This host has no file registry, so file_id cannot be read. Send the image as image_data instead.'
            );
        }

        $stored = $this->files->getMetadata($fileId);

        if (null === $stored) {
            return $this->errors->notFound('No file with that id exists.');
        }

        $userId = (int) ($context['user_id'] ?? $context['owner_id'] ?? 0);

        if ($userId > 0 && ! $this->files->userCanAccess($fileId, $userId)) {
            // Same answer as a missing file: whether the row exists elsewhere is
            // not something a caller should learn from the status code.
            return $this->errors->notFound('No file with that id exists.');
        }

        if (! $stored->isImage()) {
            return $this->errors->validationFailed(
                'That file is not an image.',
                array( 'file_id' => 'Not an image.' )
            );
        }

        if ($stored->sizeBytes > self::MAX_IMAGE_BYTES) {
            return $this->errors->validationFailed(
                \sprintf('That image is %d bytes, over the %d-byte limit.', $stored->sizeBytes, self::MAX_IMAGE_BYTES),
                array( 'file_id' => 'Image too large.' )
            );
        }

        $bytes = @\file_get_contents($stored->localPath);

        if (! is_string($bytes) || '' === $bytes) {
            return $this->errors->create(
                'file_unreadable',
                'That file could not be read from storage.'
            );
        }

        return array(
            'mime'  => $stored->mimeType,
            'bytes' => $bytes,
            'size'  => \strlen($bytes),
        );
    }

    /**
     * @return array{mime: string, bytes: string, size: int}|mixed
     */
    private function fromDataUri(string $data): mixed
    {
        $parsed = VisionRequest::parseDataUri($data);

        if (null === $parsed) {
            return $this->errors->validationFailed(
                'image_data must be a data: URI of an image, e.g. data:image/png;base64,…. A remote URL is not fetched by this runtime; store it and use file_id instead.',
                array( 'image_data' => 'Expected an image data URI.' )
            );
        }

        if (\strlen($parsed['bytes']) > self::MAX_IMAGE_BYTES) {
            return $this->errors->validationFailed(
                \sprintf(
                    'That image is %d bytes, over the %d-byte limit. Store it and use file_id.',
                    \strlen($parsed['bytes']),
                    self::MAX_IMAGE_BYTES
                ),
                array( 'image_data' => 'Image too large.' )
            );
        }

        return array(
            'mime'  => $parsed['mime'],
            'bytes' => $parsed['bytes'],
            'size'  => \strlen($parsed['bytes']),
        );
    }
}
