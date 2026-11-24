<?php

/**
 * Tool: vision_object_localization — where the things in an image are.
 *
 * `VisionObjectLocalizationDetectTool` because `VisionObjectLocalizationTool` is the
 * quarantined prompt-builder answering to `vision_object_localization_prompt`, and
 * because detection is the mechanism: Google Cloud Vision's `images:annotate` with the
 * `OBJECT_LOCALIZATION` feature returns each object with its score and a normalised box.
 *
 * ## Why a separate provider and not the chat model
 *
 * Localisation is a detection task with a fixed output contract. A chat model asked to
 * produce bounding boxes returns coordinates that look plausible and cannot be checked;
 * Vision returns `localizedObjectAnnotations` with normalised vertices that can. The
 * tool therefore spends no tokens and makes no completion call — it makes one HTTP
 * request per image.
 *
 * ## The credential, and the failure that follows from it
 *
 * The WordPress implementation reads its key from `gemini_api_key`, so this port asks
 * the settings store for the `gemini` key. That key is a Google API key, and Cloud
 * Vision only answers it when the Vision API is enabled on the same Google Cloud
 * project. When it is not, Vision returns `403` and the provider's own message reaches
 * the caller unchanged — a rewritten "vision failed" would hide the one sentence that
 * tells an operator what to enable.
 *
 * ## 200 with an error inside
 *
 * Vision's batch shape answers `200 OK` and puts a per-image error in
 * `responses[0].error`. Treating that as success would return an empty object list and
 * let the caller conclude there was nothing in the picture, so it is checked and
 * reported.
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
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;

final class VisionObjectLocalizationDetectTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    private const ENDPOINT = 'https://vision.googleapis.com/v1/images:annotate';

    private const FEATURE = 'OBJECT_LOCALIZATION';

    private const DEFAULT_MAX_RESULTS = 10;

    private const MAX_MAX_RESULTS = 100;

    private const MAX_ERROR_BODY = 300;

    public function __construct(
        ErrorFactoryInterface $errors,
        private readonly ?FileStoreInterface $files = null,
        private readonly ?HttpClientInterface $http = null,
        private readonly ?SettingsStoreInterface $settings = null,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string
    {
        return 'vision_object_localization';
    }

    public function getName(): string
    {
        return 'Vision Object Localization';
    }

    public function getDescription(): string
    {
        return 'Detects objects in an image and returns each one with a confidence score and a normalised bounding box, using Google Cloud Vision. Spends no model tokens. Requires a Google API key with the Vision API enabled.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => ImageInput::parameters() + array(
                'max_results' => array(
                    'type'        => 'integer',
                    'description' => 'Maximum objects to detect.',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_MAX_RESULTS,
                    'default'     => self::DEFAULT_MAX_RESULTS,
                ),
            ),
            'required'             => array(),
            'additionalProperties' => false,
        );
    }

    /**
     * The original gates this on `edit_posts`, and that is kept: a Vision call is
     * billed per image, so the gate is about cost as much as about access.
     */
    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    public function getCapabilityFlags(): array
    {
        return array(
            'read-only',
            'idempotent',
            'external-api',
            'network-dependent',
            'requires-credentials',
            'rate-limited',
            'requires-capability',
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        if (null === $this->http || null === $this->settings) {
            return $this->errors->create(
                'vision_unavailable',
                'This host has no HTTP client or settings store, so it cannot call Cloud Vision.'
            );
        }

        $image = (new ImageInput($this->errors, $this->files))->resolve($arguments, $context);

        if ($this->errors->isError($image)) {
            return $image;
        }

        $key = (string) ( $this->settings->getApiKey('gemini') ?? '' );

        if ('' === $key) {
            return $this->errors->create(
                'missing_api_key',
                'No Google API key is configured. Cloud Vision needs a key with the Vision API enabled on its Google Cloud project.'
            );
        }

        $maxResults = \max(
            1,
            \min(self::MAX_MAX_RESULTS, $this->intParam($arguments, 'max_results', self::DEFAULT_MAX_RESULTS))
        );

        $body = \json_encode(
            array(
                'requests' => array(
                    array(
                        'image'    => array( 'content' => \base64_encode((string) $image['bytes']) ),
                        'features' => array(
                            array(
                                'type'       => self::FEATURE,
                                'maxResults' => $maxResults,
                            ),
                        ),
                    ),
                ),
            ),
            JSON_UNESCAPED_SLASHES
        );

        if (false === $body) {
            return $this->errors->create('encoding_failed', 'The Vision request could not be encoded.');
        }

        // The key goes in the query string because that is how this endpoint documents
        // its API-key credential; the URL must not be logged by the host.
        $response = $this->http->send(
            'POST',
            self::ENDPOINT . '?key=' . \rawurlencode($key),
            array( 'Content-Type: application/json' ),
            $body
        );

        if (200 > $response->statusCode || 300 <= $response->statusCode) {
            return $this->errors->create(
                'provider_error',
                \sprintf(
                    'Cloud Vision answered %d: %s',
                    $response->statusCode,
                    \mb_substr(\trim($response->body), 0, self::MAX_ERROR_BODY)
                )
            );
        }

        $decoded = \json_decode($response->body, true);

        if (! \is_array($decoded) || ! isset($decoded['responses'][0]) || ! \is_array($decoded['responses'][0])) {
            return $this->errors->create(
                'invalid_response',
                'Cloud Vision returned JSON without a responses array.'
            );
        }

        $first = $decoded['responses'][0];

        // 200 with a per-image error: an empty object list here would read as "nothing
        // in the picture", which is a different answer from "the call failed".
        if (isset($first['error']) && \is_array($first['error'])) {
            return $this->errors->create(
                'provider_error',
                'Cloud Vision rejected the image: '
                    . \mb_substr((string) ( $first['error']['message'] ?? 'no message' ), 0, self::MAX_ERROR_BODY)
            );
        }

        $annotations = \is_array($first['localizedObjectAnnotations'] ?? null)
            ? $first['localizedObjectAnnotations']
            : array();

        $objects = array();

        foreach ($annotations as $annotation) {
            if (! \is_array($annotation)) {
                continue;
            }

            $name = \trim((string) ( $annotation['name'] ?? '' ));

            if ('' === $name) {
                continue;
            }

            $objects[] = array(
                'name'  => $name,
                'score' => \round((float) ( $annotation['score'] ?? 0.0 ), 3),
                'mid'   => (string) ( $annotation['mid'] ?? '' ),
                'box'   => $this->box($annotation['boundingPoly'] ?? null),
            );
        }

        return $this->success(
            \sprintf(
                'Found %d object%s in the image.',
                \count($objects),
                1 === \count($objects) ? '' : 's'
            ),
            array(
                'objects'    => $objects,
                'count'      => \count($objects),
                'max_results' => $maxResults,
                'image'      => array(
                    'mime_type' => (string) $image['mime'],
                    'bytes'     => (int) $image['size'],
                ),
            )
        );
    }

    /**
     * Collapse Vision's normalised vertices into one box.
     *
     * Vision returns a polygon of up to four points in 0–1 space. A caller that wants to
     * crop or overlay needs an extent, so the points become min/max. Missing or
     * malformed vertices yield null rather than a fabricated `0,0,0,0` box, which would
     * put every object in the top-left corner.
     *
     * @return array{x_min: float, y_min: float, x_max: float, y_max: float}|null
     */
    private function box(mixed $polygon): ?array
    {
        if (! \is_array($polygon) || ! \is_array($polygon['normalizedVertices'] ?? null)) {
            return null;
        }

        $xs = array();
        $ys = array();

        foreach ($polygon['normalizedVertices'] as $vertex) {
            if (! \is_array($vertex)) {
                continue;
            }

            if (isset($vertex['x']) && \is_numeric($vertex['x'])) {
                $xs[] = (float) $vertex['x'];
            }

            if (isset($vertex['y']) && \is_numeric($vertex['y'])) {
                $ys[] = (float) $vertex['y'];
            }
        }

        if (array() === $xs || array() === $ys) {
            return null;
        }

        return array(
            'x_min' => \round(\max(0.0, \min($xs)), 4),
            'y_min' => \round(\max(0.0, \min($ys)), 4),
            'x_max' => \round(\min(1.0, \max($xs)), 4),
            'y_max' => \round(\min(1.0, \max($ys)), 4),
        );
    }
}
