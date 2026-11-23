<?php

/**
 * Build the multimodal message a vision model understands.
 *
 * The provider clients already speak one dialect to their callers: OpenAI's
 * `image_url` content segment. `GeminiClient` converts it to `inlineData` and
 * `AnthropicClient` to an image block, so a caller that emits the OpenAI shape
 * works against every registered provider without knowing which one answered.
 *
 * That is why this class exists instead of the tool assembling arrays inline.
 * The same shape is what every chat turn already sends when an image reaches a
 * vision model, so there is one description of "an image in a message" in the
 * runtime rather than one per tool.
 *
 * ## Data URIs, always
 *
 * A **remote** `https://` URL cannot be used here. Both non-OpenAI converters
 * treat the URL string as base64 payload — `GeminiClient` strips a `data:`
 * prefix if it finds one and passes the rest as `inlineData.data`, and
 * `AnthropicClient` does the same under `source.type = base64`. A remote URL
 * would therefore be base64-decoded as garbage and fail at the provider, or
 * worse, silently analyse nothing. So an image is always reduced to bytes here
 * and carried as a data URI.
 *
 * Fetching a remote URL ourselves is deliberately not offered at this layer
 * either: `HttpClientInterface::send()` exposes no redirect or size controls, so
 * a guard could be written but not enforced, and a redirect into a private range
 * would defeat it. The honest options are a registry file id or bytes the caller
 * already holds.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Media;

final class VisionRequest
{
    /**
     * What the WordPress tool asks for when the caller does not say.
     */
    public const DEFAULT_PROMPT = 'Describe this image in detail, including objects, people, text, colors, and composition.';

    /**
     * Encode bytes as the data URI every client understands.
     */
    public static function dataUri(string $bytes, string $mimeType): string
    {
        return 'data:' . $mimeType . ';base64,' . \base64_encode($bytes);
    }

    /**
     * Decode a caller-supplied data URI.
     *
     * A bare base64 blob is refused rather than assumed to be JPEG: the media
     * type is the one thing the model is told about the bytes, and guessing it
     * is how a PNG gets analysed as a JPEG and produces a confident wrong
     * answer.
     *
     * @return array{mime: string, bytes: string}|null Null when the value is not
     *                                               an image data URI.
     */
    public static function parseDataUri(string $value): ?array
    {
        $pattern = '#^data:(image/[a-z0-9.+-]+);base64,(.+)$#s';

        if (1 !== \preg_match($pattern, $value, $matches)) {
            return null;
        }

        $bytes = \base64_decode($matches[2], true);

        if (false === $bytes || '' === $bytes) {
            return null;
        }

        return array(
            'mime'  => \strtolower($matches[1]),
            'bytes' => $bytes,
        );
    }
}
