<?php

/**
 * Embed stored images in a post body — Proposal 162 §162.9.5.
 *
 * WordPress's `create_post` tool accepts `content_images` and renders each one as
 * an image block (`trait-wp-mcp-ai-tool-content-media.php`): a `wp-image-{id}`
 * class on a `<figure class="wp-block-image">`, with an optional caption, placed
 * at the start, middle or end of the body.
 *
 * This mirrors the argument and the placement, and departs from WordPress in two
 * places where copying would be wrong here:
 *
 *  - **Attributes are emitted, not deferred.** WordPress relies on
 *    `wp_filter_content_tags()` to add `srcset`, `sizes` and `loading` at render
 *    time, keyed off `wp-image-{id}`. This runtime has no render filter, so the
 *    markup carries `width`, `height`, `loading="lazy"` and `decoding="async"`
 *    itself. Intrinsic dimensions are not cosmetic: without them the browser
 *    cannot reserve space and the page shifts as each image lands (web.dev's
 *    image guidance is explicit that width and height belong on every `<img>`).
 *  - **A private file is refused.** WordPress has one media visibility; here a
 *    private file deliberately has no public URL, so embedding it in a body
 *    would produce an `<img>` that 401s for every anonymous reader. The refusal
 *    is a validation error rather than a broken page.
 *
 * The caption is stripped of markup before it is escaped: a caption arrives from
 * a model, and an image block is not a place to accept HTML.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Media;

use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Error\ValidationException;

final class ContentImages
{
    /** WordPress caps the array; so does this. */
    public const MAX_IMAGES = 5;

    /**
     * The `content_images` schema fragment, so the two create-post tools agree.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function parameters(): array
    {
        return array(
            'content_images' => array(
                'type'        => 'array',
                'description' => 'Images to embed in the body. Maximum 5. Each source is a stored file id or an absolute http(s) URL.',
                'maxItems'    => self::MAX_IMAGES,
                'items'       => array(
                    'type'       => 'object',
                    'properties' => array(
                        'source'   => array(
                            'description' => 'Stored file id (preferred) or an absolute http(s) URL.',
                            'anyOf'       => array(
                                array('type' => 'integer', 'minimum' => 1),
                                array('type' => 'string'),
                            ),
                        ),
                        'alt'      => array(
                            'type'        => 'string',
                            'description' => 'Alternative text. Leave empty only when the image is purely decorative.',
                        ),
                        'caption'  => array(
                            'type'        => 'string',
                            'description' => 'Optional caption. Rendered as plain text.',
                        ),
                        'position' => array(
                            'type'        => 'string',
                            'description' => 'Where to insert the block. Default: middle.',
                            'enum'        => array('start', 'middle', 'end'),
                            'default'     => 'middle',
                        ),
                    ),
                    'required'   => array('source'),
                ),
            ),
        );
    }

    /**
     * Insert the requested images into a body.
     *
     * @param string               $content   The body as the caller wrote it.
     * @param array<string, mixed> $arguments Tool arguments (`content_images` is read).
     * @param FileStoreInterface|null $files  Registry, needed for numeric sources.
     *
     * @throws ValidationException When a source cannot be embedded.
     */
    public static function embed(string $content, array $arguments, ?FileStoreInterface $files): string
    {
        $images = $arguments['content_images'] ?? null;

        if (! \is_array($images) || array() === $images) {
            return $content;
        }

        $start  = '';
        $middle = '';
        $end    = '';

        foreach (\array_slice(\array_values($images), 0, self::MAX_IMAGES) as $image) {
            if (! \is_array($image)) {
                continue;
            }

            $html = self::html($image, $files);

            if ('' === $html) {
                continue;
            }

            switch ((string) ($image['position'] ?? 'middle')) {
                case 'start':
                    $start .= $html;
                    break;

                case 'end':
                    $end .= $html;
                    break;

                default:
                    $middle .= $html;
            }
        }

        return self::insert($content, $start, $middle, $end);
    }

    /**
     * Place the blocks the way WordPress does: start and end are literal, and
     * "middle" goes after the paragraph at the midpoint of the body.
     */
    private static function insert(string $content, string $start, string $middle, string $end): string
    {
        $out = $start;

        if ('' !== $middle) {
            $paragraphs = \preg_split('/\n\s*\n/', \trim($content)) ?: array();
            $midpoint   = (int) \floor(\count($paragraphs) / 2);

            if ($midpoint > 0) {
                $out .= \implode("\n\n", \array_slice($paragraphs, 0, $midpoint));
                $out .= "\n\n" . $middle;
                $out .= \implode("\n\n", \array_slice($paragraphs, $midpoint));
            } else {
                $out .= $content . "\n\n" . $middle;
            }
        } else {
            $out .= $content;
        }

        if ('' !== $end) {
            $out .= "\n\n" . $end;
        }

        return \trim($out);
    }

    /**
     * One image block.
     *
     * @param array<string, mixed> $image
     *
     * @throws ValidationException
     */
    private static function html(array $image, ?FileStoreInterface $files): string
    {
        $source = $image['source'] ?? null;

        if (null === $source || '' === $source) {
            return '';
        }

        $fileId     = 0;
        $url        = '';
        $dimensions = null;

        if (\is_int($source) || (\is_string($source) && \ctype_digit($source))) {
            $fileId = (int) $source;

            if (null === $files) {
                throw new ValidationException(
                    'This host has no file registry, so a numeric content_images source cannot be resolved.',
                );
            }

            $file = $files->getMetadata($fileId);

            if (null === $file) {
                throw new ValidationException("No stored file has id {$fileId}.");
            }

            if (! \str_starts_with((string) $file->mimeType, 'image/')) {
                throw new ValidationException("Stored file {$fileId} is not an image.");
            }

            if (FileVisibilityStoreInterface::VISIBILITY_PUBLIC !== $file->visibility) {
                throw new ValidationException(
                    "Stored file {$fileId} is private, so embedding it would produce an image no reader can load.",
                );
            }

            $url = (string) $file->publicUrl;

            if ('' === $url) {
                throw new ValidationException("Stored file {$fileId} has no public URL to embed.");
            }

            $dimensions = self::dimensions($files, $fileId);
        } elseif (\is_string($source)) {
            $url = self::safeUrl($source);

            if ('' === $url) {
                return '';
            }
        }

        if ('' === $url) {
            return '';
        }

        $alt     = self::escape((string) ($image['alt'] ?? ''));
        $caption = self::escape(\trim(\strip_tags((string) ($image['caption'] ?? ''))));

        $attributes = ' src="' . self::escape($url) . '" alt="' . $alt . '"';

        if ($fileId > 0) {
            $attributes .= ' class="wp-image-' . $fileId . '"';
        }

        if (null !== $dimensions) {
            $attributes .= ' width="' . $dimensions[0] . '" height="' . $dimensions[1] . '"';
        }

        $attributes .= ' loading="lazy" decoding="async"';

        $html = "<!-- wp:image -->\n";
        $html .= '<figure class="wp-block-image"><img' . $attributes . '/>';

        if ('' !== $caption) {
            $html .= '<figcaption>' . $caption . '</figcaption>';
        }

        return $html . "</figure>\n<!-- /wp:image -->\n\n";
    }

    /**
     * Intrinsic size from the stored bytes, so the browser can reserve space.
     *
     * Returns null rather than guessing when the file or the reader is missing;
     * an absent attribute is a missed optimisation, a wrong one is a squashed
     * image.
     *
     * @return array{0: int, 1: int}|null
     */
    private static function dimensions(?FileStoreInterface $files, int $fileId): ?array
    {
        $path = $files?->getPath($fileId);

        if (null === $path || ! \is_file($path)) {
            return null;
        }

        $size = @\getimagesize($path);

        if (! \is_array($size) || $size[0] <= 0 || $size[1] <= 0) {
            return null;
        }

        return array((int) $size[0], (int) $size[1]);
    }

    /**
     * Only an absolute http(s) URL or a site-relative path may reach an `src`.
     */
    private static function safeUrl(string $url): string
    {
        $url = \trim($url);

        if (\str_starts_with($url, '/') && ! \str_starts_with($url, '//')) {
            return $url;
        }

        $scheme = \strtolower((string) \parse_url($url, PHP_URL_SCHEME));

        if (! \in_array($scheme, array('http', 'https'), true)) {
            throw new ValidationException(
                'A content_images URL must be absolute http(s) or a site-relative path.',
            );
        }

        return $url;
    }

    private static function escape(string $value): string
    {
        return \htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
