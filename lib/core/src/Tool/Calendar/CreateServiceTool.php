<?php

/**
 * Create or update one bookable service.
 *
 * Port of `WP_MCP_AI_Tool_Create_Service` (Proposal 164 section 164.86). A call
 * without `service_id` creates; a call with one updates the supplied fields and
 * leaves the rest untouched.
 *
 * ## Corrections
 *
 * - **The bounds are enforced.** WordPress passed `absint` straight into the
 *   record, so a 10,000-minute service or a negative price was storable and
 *   unbookable. Duration, buffer, participants and price are validated now.
 * - **The update path honours the record rule.** WordPress checked the author
 *   or `edit_others_posts`; the port applies the store `edit` rule, which is the
 *   portable equivalent.
 * - **`source_url` is in the schema.** WordPress's single-service writer read
 *   the argument and saved it, but its schema omitted it, so a validated call
 *   could never set it. It is exposed and validated here.
 * - **`tags` is stored.** WordPress declared the argument and never wrote it.
 *   It now saves `_service_tags`.
 * - **Remote images are refused.** `image_urls` needed
 *   `media_sideload_image()`, which fetches an arbitrary URL server-side; this
 *   runtime has no SSRF-guarded fetch contract. The argument is kept so the
 *   refusal is explicit, and `media_ids` replaces it.
 *
 * The places toolkit is not ported, so `place_id` is stored as an opaque
 * reference and is not verified to exist.
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
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Domain\Error\ValidationException;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;
use Nvoos\Core\Tool\AbstractTool;

class CreateServiceTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /** The fields a caller may set. */
    private const FIELDS = array(
        'name',
        'description',
        'duration_minutes',
        'price',
        'buffer_time_minutes',
        'category',
        'place_id',
        'max_participants',
        'tags',
        'media_ids',
        'source_url',
        'external_id',
    );

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'create_service';
    }

    public function getName(): string
    {
        return 'Create Service';
    }

    public function getDescription(): string
    {
        return 'Creates a bookable service, or updates one when service_id is given. Includes duration, price, buffer time, capacity, category, tags, a place reference and gallery media IDs.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'service_id'          => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Optional service ID. When given, the supplied fields update that service.',
                ),
                'name'                => array(
                    'type'        => 'string',
                    'description' => 'Service name (required).',
                    'minLength'   => 1,
                    'maxLength'   => 200,
                ),
                'description'         => array(
                    'type'        => 'string',
                    'description' => 'Service description (optional, stored as plain text).',
                    'maxLength'   => 5000,
                ),
                'duration_minutes'    => array(
                    'type'        => 'integer',
                    'description' => 'Service duration in minutes.',
                    'minimum'     => ServiceDefinition::MIN_DURATION,
                    'maximum'     => ServiceDefinition::MAX_DURATION,
                ),
                'price'               => array(
                    'type'        => 'number',
                    'description' => 'Service price, rounded to two decimals. The runtime keeps no currency.',
                    'minimum'     => 0,
                ),
                'buffer_time_minutes' => array(
                    'type'        => 'integer',
                    'description' => 'Buffer between appointments in minutes.',
                    'default'     => 0,
                    'minimum'     => 0,
                    'maximum'     => ServiceDefinition::MAX_BUFFER,
                ),
                'category'            => array(
                    'type'        => 'string',
                    'description' => 'Service category term. Created when it does not exist; an empty string clears it.',
                ),
                'place_id'            => array(
                    'type'        => 'integer',
                    'minimum'     => 0,
                    'description' => 'Opaque place reference (the places toolkit is not bound in this runtime). 0 clears it.',
                ),
                'max_participants'    => array(
                    'type'        => 'integer',
                    'description' => 'Maximum participants per session.',
                    'minimum'     => ServiceDefinition::MIN_PARTICIPANTS,
                    'maximum'     => ServiceDefinition::MAX_PARTICIPANTS,
                ),
                'media_ids'           => array(
                    'type'        => 'array',
                    'description' => 'Gallery media IDs from the file registry.',
                    'items'       => array('type' => 'integer', 'minimum' => 1),
                ),
                'tags'                => array(
                    'type'        => 'array',
                    'description' => 'Custom tags.',
                    'items'       => array('type' => 'string'),
                ),
                'source_url'          => array(
                    'type'        => 'string',
                    'description' => 'Optional http(s) URL the service was imported from.',
                ),
                'external_id'         => array(
                    'type'        => 'string',
                    'description' => 'Stable identity in the source system, used by import_services for deduplication.',
                ),
                'image_urls'          => array(
                    'type'        => 'array',
                    'description' => 'Accepted for compatibility but refused: remote images cannot be fetched here. Use media_ids.',
                    'items'       => array('type' => 'string', 'format' => 'uri'),
                ),
            ),
            'required'             => array('name'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return CapabilityFlagVocabulary::LOCAL_WRITE;
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $userId    = (int) ( $context['user_id'] ?? 0 );
        $serviceId = max(0, $this->intParam($arguments, 'service_id', 0));
        $existing  = null;

        if ($serviceId > 0) {
            $existing = $this->content->find($serviceId);

            if (! $existing instanceof ContentItem || ServiceDefinition::TYPE !== $existing->type) {
                return $this->errors->create('service_not_found', 'Service not found.');
            }

            if (! $this->content->userCanAccess($serviceId, $userId, 'edit')) {
                return $this->errors->forbidden('You do not have permission to update this service.');
            }
        }

        if ($this->hasRemoteImages($arguments)) {
            return $this->errors->validationFailed(
                'Remote image import is not available in this runtime.',
                array('image_urls' => array('Pass media_ids of files already in the registry instead.'))
            );
        }

        $fields = array();

        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $arguments)) {
                $fields[ $field ] = $arguments[ $field ];
            }
        }

        try {
            $built = ServiceWriter::build($fields, $existing);
        } catch (ValidationException $error) {
            return $this->errors->validationFailed(
                $error->getMessage(),
                $error->hasFieldErrors() ? $error->errors : array()
            );
        }

        try {
            $item = ServiceWriter::persist($this->content, $userId, $built, $existing);
        } catch (AccessDeniedException $error) {
            return $this->errors->forbidden($error->getMessage());
        } catch (NotFoundException $error) {
            return $this->errors->create('service_not_found', $error->getMessage());
        } catch (ValidationException $error) {
            return $this->errors->validationFailed(
                $error->getMessage(),
                $error->hasFieldErrors() ? $error->errors : array()
            );
        } catch (\Throwable $error) {
            return $this->errors->create('service_write_failed', $error->getMessage());
        }

        $updated = null !== $existing;

        return $this->success(
            $updated ? sprintf('Service updated: %s', $item->title) : 'Service created successfully.',
            array(
                'service_id' => $item->id,
                'service'    => ServiceDefinition::row($item),
                'updated'    => $updated,
            )
        );
    }

    /**
     * Whether the caller asked for remote images.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function hasRemoteImages(array $arguments): bool
    {
        if (! array_key_exists('image_urls', $arguments)) {
            return false;
        }

        $urls = $arguments['image_urls'];

        if (! is_array($urls)) {
            return is_scalar($urls) && '' !== trim((string) $urls);
        }

        foreach ($urls as $url) {
            if (is_scalar($url) && '' !== trim((string) $url)) {
                return true;
            }
        }

        return false;
    }
}
