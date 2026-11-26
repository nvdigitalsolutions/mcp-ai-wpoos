<?php

/**
 * Resolve the image a vision tool was asked about.
 *
 * Four vision tools need the same six steps — one source only, ownership checked,
 * type checked, size capped, bytes read, encoded — and one of them had already
 * grown a private copy. A second copy would be the fourth time this runtime has
 * had two implementations of one rule, so it lives here instead.
 *
 * ## Two sources, and the one that is refused
 *
 *  - **`file_id`** — a file in the registry (§162). Ownership is the registry's
 *    own `userCanAccess()`, not a second implementation of it: a caller that
 *    cannot download a file must not be able to ask a model what is in it. A file
 *    the caller may not see answers **404**, the same shape §17/§18/§19 use, so
 *    the tools cannot be compared to learn whether a row exists.
 *  - **`image_data`** — a `data:` URI. A bare base64 string is refused: the media
 *    type is the one thing the model is told about the bytes, and guessing it is
 *    how a PNG gets analysed as a JPEG.
 *
 * A **remote URL is refused**, for two independent reasons:
 *
 *  1. Both non-OpenAI converters treat the `image_url` value as base64 payload,
 *     so a `https://…` string reaches the provider as garbage.
 *  2. Fetching it here would be an SSRF surface that cannot be closed:
 *     `HttpClientInterface::send()` exposes no redirect or size controls, so a
 *     redirect into a private range would walk past any guard.
 *
 * The refusal names both alternatives, because "no" without a next step is how a
 * model retries the same call five times.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Media;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;

final class ImageInput
{
    /**
     * The largest image sent to a provider.
     *
     * Providers accept far more, but a tool call is not a bulk import: at 6 MB
     * raw the base64 payload is already ~8 MB, and the registry is the right
     * place for anything larger.
     */
    public const MAX_IMAGE_BYTES = 6000000;

    /**
     * The matching ceiling for a caller-supplied data URI, base64 included.
     */
    public const MAX_DATA_URI_CHARS = 8400000;

    public function __construct(
        private readonly ErrorFactoryInterface $errors,
        private readonly ?FileStoreInterface $files = null,
    ) {
    }

    /**
     * The two schema properties every vision tool exposes.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function parameters(): array
    {
        return array(
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
        );
    }

    /**
     * Resolve the arguments to bytes, a media type and a data URI.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     *
     * @return array{mime: string, bytes: string, size: int, dataUri: string}|mixed
     *                                                                           The image, or the error value to return unchanged.
     */
    public function resolve(array $arguments, array $context = array()): mixed
    {
        $fileId = isset($arguments['file_id']) && \is_numeric($arguments['file_id'])
            ? (int) $arguments['file_id']
            : 0;

        $data = isset($arguments['image_data']) && \is_string($arguments['image_data'])
            ? \trim($arguments['image_data'])
            : '';

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

        $image = $fileId > 0 ? $this->fromRegistry($fileId, $context) : $this->fromDataUri($data);

        if ($this->errors->isError($image)) {
            return $image;
        }

        $image['dataUri'] = VisionRequest::dataUri($image['bytes'], $image['mime']);

        return $image;
    }

    /**
     * Read an image the registry already owns.
     *
     * @param array<string, mixed> $context
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
