<?php

/**
 * The one write path for services.
 *
 * Proposal 164 section 164.86. `create_service` and `import_services` both
 * validate through {@see ServiceDefinition} and persist through here, so a
 * single create and a bulk row cannot drift apart — the WordPress tools
 * duplicated this logic and had already diverged (the importer wrote
 * `source_url`, the single writer's schema did not expose it).
 *
 * Partial updates write only the supplied fields. Two keys are always written:
 * `_service_key`, because it is derived from the name and place, and any key
 * the caller supplied, including an empty value used to clear it.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Calendar;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\ValidationException;
use Nvoos\Core\Support\Text;

final class ServiceWriter
{
    /**
     * Validate and normalise a field set.
     *
     * @param array<string, mixed> $fields   Raw caller fields.
     * @param ContentItem|null     $existing Stored service on the update path.
     *
     * @return array{
     *     title: string,
     *     content: string|null,
     *     meta: array<string, mixed>,
     *     taxonomy: array<string, list<string>>
     * }
     *
     * @throws ValidationException When a supplied field is out of range.
     */
    public static function build(array $fields, ?ContentItem $existing = null): array
    {
        $name = Text::singleLine(is_scalar($fields['name'] ?? null) ? (string) $fields['name'] : '');

        if ('' === $name) {
            throw new ValidationException(
                'The service name is required.',
                array('name' => array('A service name is required.'))
            );
        }

        $meta   = array();
        $errors = array();

        if (array_key_exists('duration_minutes', $fields)) {
            $duration = ServiceDefinition::duration($fields['duration_minutes']);

            if (null === $duration) {
                $errors['duration_minutes'][] = sprintf(
                    'Use a whole number of minutes between %d and %d.',
                    ServiceDefinition::MIN_DURATION,
                    ServiceDefinition::MAX_DURATION
                );
            } else {
                $meta[ ServiceDefinition::META_DURATION ] = $duration;
            }
        }

        if (array_key_exists('price', $fields)) {
            $price = ServiceDefinition::price($fields['price']);

            if (null === $price) {
                $errors['price'][] = 'Use a number of 0 or more.';
            } else {
                $meta[ ServiceDefinition::META_PRICE ] = $price;
            }
        }

        if (array_key_exists('buffer_time_minutes', $fields)) {
            $buffer = ServiceDefinition::buffer($fields['buffer_time_minutes']);

            if (null === $buffer) {
                $errors['buffer_time_minutes'][] = sprintf(
                    'Use a whole number of minutes between 0 and %d.',
                    ServiceDefinition::MAX_BUFFER
                );
            } else {
                $meta[ ServiceDefinition::META_BUFFER ] = $buffer;
            }
        }

        if (array_key_exists('max_participants', $fields)) {
            $participants = ServiceDefinition::participants($fields['max_participants']);

            if (null === $participants) {
                $errors['max_participants'][] = sprintf(
                    'Use a whole number between %d and %d.',
                    ServiceDefinition::MIN_PARTICIPANTS,
                    ServiceDefinition::MAX_PARTICIPANTS
                );
            } else {
                $meta[ ServiceDefinition::META_PARTICIPANTS ] = $participants;
            }
        }

        if (array_key_exists('source_url', $fields)) {
            $url = ServiceDefinition::url($fields['source_url']);

            if (null === $url) {
                $errors['source_url'][] = 'Use an http or https URL, or an empty string to clear it.';
            } else {
                $meta[ ServiceDefinition::META_SOURCE_URL ] = $url;
            }
        }

        if (array_key_exists('external_id', $fields)) {
            $meta[ ServiceDefinition::META_EXTERNAL_ID ] = ServiceDefinition::externalId($fields['external_id']);
        }

        if (array_key_exists('tags', $fields)) {
            $meta[ ServiceDefinition::META_TAGS ] = ServiceDefinition::tags($fields['tags']);
        }

        if (array_key_exists('media_ids', $fields)) {
            $meta[ ServiceDefinition::META_GALLERY ] = ServiceDefinition::mediaIds($fields['media_ids']);
        }

        $placeId = null !== $existing
            ? (int) ( $existing->meta[ ServiceDefinition::META_PLACE ] ?? 0 )
            : 0;

        if (array_key_exists('place_id', $fields)) {
            $place = ServiceDefinition::place($fields['place_id']);

            if (null === $place) {
                $errors['place_id'][] = 'Use a whole number of 0 or more.';
            } else {
                $placeId                                    = $place;
                $meta[ ServiceDefinition::META_PLACE ] = $place;
            }
        }

        // The fallback identity is derived, so it is always rewritten.
        $meta[ ServiceDefinition::META_KEY ] = ServiceDefinition::key($name, $placeId);

        $taxonomy = array();

        if (array_key_exists('category', $fields)) {
            $category = Text::singleLine(
                is_scalar($fields['category']) ? (string) $fields['category'] : ''
            );

            // An empty category clears the taxonomy; an absent one leaves it.
            $taxonomy[ ServiceDefinition::TAXONOMY ] = '' !== $category ? array($category) : array();
        }

        if (array() !== $errors) {
            throw new ValidationException('The service data is not valid.', $errors);
        }

        $content = null;

        if (array_key_exists('description', $fields)) {
            $content = Text::multiline(
                is_scalar($fields['description']) ? (string) $fields['description'] : ''
            );
        }

        return array(
            'title'    => $name,
            'content'  => $content,
            'meta'     => $meta,
            'taxonomy' => $taxonomy,
        );
    }

    /**
     * Create or update the record.
     *
     * @param array{
     *     title: string,
     *     content: string|null,
     *     meta: array<string, mixed>,
     *     taxonomy: array<string, list<string>>
     * } $built Result of {@see build()}.
     *
     * @throws \Nvoos\Core\Domain\Error\AccessDeniedException  When the store refuses the write.
     * @throws \Nvoos\Core\Domain\Error\NotFoundException      When the update target is gone.
     * @throws \Nvoos\Core\Domain\Error\ValidationException    When the store rejects the payload.
     */
    public static function persist(
        ContentStoreInterface $content,
        int $userId,
        array $built,
        ?ContentItem $existing = null,
    ): ContentItem {
        if (null !== $existing) {
            return $content->update(
                $existing->id,
                new UpdateContentCommand(
                    userId: $userId,
                    title: $built['title'],
                    content: $built['content'],
                    meta: $built['meta'],
                    taxonomyInput: $built['taxonomy'],
                )
            );
        }

        return $content->create(
            new CreateContentCommand(
                title: $built['title'],
                type: ServiceDefinition::TYPE,
                authorId: $userId,
                status: 'publish',
                content: $built['content'] ?? '',
                meta: $built['meta'],
                taxonomyInput: $built['taxonomy'],
            )
        );
    }
}
