<?php

/**
 * Shared plumbing for tools that send a video to a model.
 *
 * ## No frame extraction
 *
 * The obvious way to analyse a video is to extract stills and send those. This runtime
 * has no frame extractor, and the media worker's video endpoint has no frame operation
 * either — but it does not need one: models that accept video inline reason about the
 * motion between frames, which extracted stills throw away. So the bytes go to the
 * provider as they are, and `video/` is the only thing this base validates.
 *
 * ## Which providers accept that
 *
 * Google Gemini converts the request's media segment into `inlineData` with the declared
 * mime type, so `video/mp4` is understood natively. OpenAI's chat endpoint accepts
 * images in that slot only and refuses a video. That is the provider's refusal to give,
 * not something to pre-empt here: the tool states the requirement, and the provider's
 * own message reaches the caller unchanged.
 *
 * ## The size ceiling, and why it is 12 MB
 *
 * Gemini's inline-data ceiling is about 20 MB for the whole request, and base64 adds a
 * third to the payload. 12 MB of raw bytes becomes ~16 MB encoded, which leaves room for
 * the prompt without ever approaching the limit. A larger video needs the provider's
 * file-upload path, which is a different tool rather than a bigger number here.
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
use Nvoos\Core\Domain\Entity\StoredFile;

abstract class AbstractVideoCompletionTool extends AbstractCompletionTool
{
    /**
     * Raw bytes. See the class docblock for the arithmetic.
     */
    protected const MAX_VIDEO_BYTES = 12000000;

    private const VIDEO_MIME_PREFIX = 'video/';

    public function __construct(
        ErrorFactoryInterface $errors,
        ?ProviderRouter $providers = null,
        protected readonly ?FileStoreInterface $files = null,
    ) {
        parent::__construct($errors, $providers);
    }

    public function getRequiredCapability(): string
    {
        return 'read';
    }

    public function getCapabilityFlags(): array
    {
        return array(
            'read-only',
            'external-api',
            'network-dependent',
            'consumes-tokens',
            'requires-credentials',
            'model-dependent',
            'rate-limited',
            'requires-capability',
        );
    }

    /**
     * The instruction sent with the video.
     *
     * @param array<string, mixed> $arguments
     */
    abstract protected function instruction(array $arguments): string;

    /**
     * Build the success envelope from the model's answer.
     *
     * @param array<string, mixed> $answer
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    abstract protected function respond(array $answer, StoredFile $file, array $arguments): array;

    /**
     * The `file_id` property, shared by both video tools.
     *
     * @return array<string, mixed>
     */
    protected function fileIdParameter(string $description): array
    {
        return array(
            'type'        => 'integer',
            'description' => $description,
            'minimum'     => 1,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $fileId = $this->intParam($arguments, 'file_id');

        if ($fileId < 1) {
            return $this->errors->validationFailed(
                'A file_id is required. This runtime does not fetch remote URLs; store the video first.',
                array( 'file_id' => 'Required.' )
            );
        }

        if (null === $this->files) {
            return $this->errors->create(
                'file_store_unavailable',
                'This host has no file store, so a stored video cannot be read.'
            );
        }

        $userId = (int) ( $context['user_id'] ?? $context['owner_id'] ?? 0 );

        if ($userId > 0 && ! $this->files->userCanAccess($fileId, $userId)) {
            return $this->notFound($fileId);
        }

        $file = $this->files->getMetadata($fileId);

        if (null === $file) {
            return $this->notFound($fileId);
        }

        $mime = \strtolower(\trim($file->mimeType));

        if (! \str_starts_with($mime, self::VIDEO_MIME_PREFIX)) {
            // Sending a PDF down the video path would spend a completion to be refused,
            // and would describe the bytes to the model as something they are not.
            return $this->errors->validationFailed(
                \sprintf('File %d is %s, not a video.', $fileId, '' === $mime ? 'untyped' : $mime),
                array( 'file_id' => 'Expected a video.' )
            );
        }

        $bytes = $this->bytes($file);

        if (null === $bytes) {
            return $this->errors->create(
                'video_unreadable',
                \sprintf('File %d is recorded as a video but its bytes could not be read.', $fileId)
            );
        }

        if (\strlen($bytes) > self::MAX_VIDEO_BYTES) {
            return $this->errors->validationFailed(
                \sprintf(
                    'That video is %s, over the %d-byte ceiling for an inline request.',
                    $this->human(\strlen($bytes)),
                    self::MAX_VIDEO_BYTES
                ),
                array( 'file_id' => 'Video too large for an inline request.' )
            );
        }

        $answer = $this->completeTool(
            $arguments,
            $this->instruction($arguments),
            VisionRequest::dataUri($bytes, $mime)
        );

        if ($this->errors->isError($answer)) {
            return $answer;
        }

        return $this->respond($answer, $file, $arguments);
    }

    /**
     * The stored bytes, preferring the path the registry recorded.
     */
    private function bytes(StoredFile $file): ?string
    {
        $path = $this->files?->getPath($file->id) ?? $file->localPath;

        if ('' === $path || ! \is_file($path) || ! \is_readable($path)) {
            return null;
        }

        $bytes = @\file_get_contents($path);

        return \is_string($bytes) ? $bytes : null;
    }

    private function notFound(int $fileId): mixed
    {
        return $this->errors->create(
            'file_not_found',
            \sprintf('No file with id %d is readable by this caller.', $fileId)
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function contextNote(array $arguments): string
    {
        $context = \mb_substr(\trim($this->stringParam($arguments, 'context')), 0, 500);

        return '' === $context ? '' : "\n\nContext from the caller: " . $context;
    }

    protected function human(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $value = $bytes / 1024;

        foreach (array( 'KB', 'MB', 'GB' ) as $unit) {
            if ($value < 1024 || 'GB' === $unit) {
                return \round($value, 1) . ' ' . $unit;
            }

            $value /= 1024;
        }

        return $bytes . ' B';
    }
}
