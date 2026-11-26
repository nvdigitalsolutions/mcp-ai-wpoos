<?php

/**
 * The featured image, as content meta — Proposal 162 §162.9.2.
 *
 * Storing generated and uploaded media is only half the job: until a content
 * item can point at a file, the library is populated but unusable. WordPress
 * solves this with `_thumbnail_id` post meta plus `set_post_thumbnail()`, which
 * refuses an attachment that cannot produce an image.
 *
 * This mirrors both halves:
 *
 *  - the value lives in the content item's own meta, the closest equivalent of
 *    post meta, and is therefore readable by `get_post` and queryable through
 *    the store's WP-shaped `metaQuery` (`meta->featured_image_id`);
 *  - the id is validated *at write time* against the registry, so a text file
 *    cannot become a thumbnail and an unknown id is refused rather than stored.
 *
 * It exists as a class rather than a private method because this runtime has two
 * save-post tools (`save_post` and `save_post_validated`) that are separate
 * implementations; the rule is written once so they cannot disagree.
 *
 * Deleting a stored file later leaves the id in place, exactly as WordPress
 * leaves a `_thumbnail_id` behind. Readers must tolerate an id whose file is
 * gone; nothing here invents a cascade that WordPress does not have.
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
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Domain\Error\ValidationException;

final class FeaturedImage
{
    /**
     * Meta key. Matches the tool argument and the WordPress meaning.
     */
    public const META_KEY = 'featured_image_id';

    /**
     * The meta fragment for this call, or `null` when the argument is absent.
     *
     * Returning `null` rather than an empty array is deliberate: an absent
     * argument means "leave the current featured image alone", while
     * `featured_image_id: 0` means "clear it". Conflating the two would make an
     * unrelated post update silently drop the image.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>|null
     *
     * @throws ValidationException When the value is negative, the host has no
     *                             registry, or the file is not an image.
     * @throws NotFoundException   When no stored file has that id.
     */
    public static function metaFor(array $arguments, ?FileStoreInterface $files): ?array
    {
        if (! \array_key_exists(self::META_KEY, $arguments)) {
            return null;
        }

        $id = (int) $arguments[self::META_KEY];

        if ($id < 0) {
            throw new ValidationException('featured_image_id must be 0 or a positive stored-file id.');
        }

        if (0 === $id) {
            return array(self::META_KEY => null);
        }

        if (null === $files) {
            throw new ValidationException('This host has no file registry, so featured_image_id cannot be resolved.');
        }

        $file = $files->getMetadata($id);

        if (null === $file) {
            throw new NotFoundException("No stored file has id {$id}.");
        }

        if (! \str_starts_with((string) $file->mimeType, 'image/')) {
            throw new ValidationException('Only an image can be a featured image.');
        }

        return array(self::META_KEY => $id);
    }
}
