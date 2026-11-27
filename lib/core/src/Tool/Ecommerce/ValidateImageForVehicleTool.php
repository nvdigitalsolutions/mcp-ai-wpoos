<?php

/**
 * Tool: validate_image_for_vehicle — are these photos usable for an estimate?
 *
 * Proposal 164 section 164.124. The WordPress original gates a quote on
 * photographs: a cleaning estimate needs one full-vehicle shot for the size
 * tier, and a repair estimate needs the four exterior sides, because a repairer
 * cannot price a panel they were never shown.
 *
 * ## Two tools in one action, and the weights are not shared
 *
 * `cleaning` and `repair` ask different questions, so they score differently:
 *
 * | Category | Cleaning | Repair |
 * |---|---|---|
 * | vehicle detected | 25 | 15 |
 * | full vehicle visible / coverage completeness | 20 | 25 |
 * | damage visibility | — | 20 |
 * | technical format | 15 | 10 |
 * | image dimensions | 8 | 5 |
 * | lighting | 12 | 10 |
 * | sharpness | 8 | 8 |
 * | condition visibility | 10 | — |
 * | VIN visibility | — | 5 |
 * | single vehicle | 2 | 2 |
 *
 * Both total 100, both grade at 90/75/60/40, and in both a required item is
 * critical. `repair` additionally treats each of the four required views as
 * critical, so three sides score `coverage_completeness` as critical and the set
 * is refused — which is the point of asking for four.
 *
 * ## Multiple images are one question, not several
 *
 * "Are all four sides here?" cannot be answered image by image: it is a question
 * about the set. So the images are sent as **one** user turn carrying several
 * `image_url` parts, which is why `CompletionRequest::messages()` now accepts an
 * array. Before that widening, `completeTool()` could only ever ask about one.
 *
 * ## Four corrections
 *
 * 1. **A critical technical failure refuses before the model is called.** The
 *    WordPress tool merged every technical failure into the checks and then
 *    called the model anyway, so a set containing a 100-pixel thumbnail paid for
 *    an analysis that could not change the verdict. The product validator ported
 *    in §164.123 already refuses early, and this one matches it.
 * 2. **An unresolvable image is reported.** The original skipped every reference
 *    it could not resolve and carried on with the rest, so a caller who passed
 *    four views and got three resolved was told coverage was incomplete without
 *    being told why. A bad reference is now the refusal.
 * 3. **No remote URL input.** The original accepted attachment ids, file ids and
 *    public URLs. Core resolves a registry `file_id` or a `data:` URI and never
 *    fetches a caller-supplied host, so the URL form is not ported.
 * 4. **The capability is the one the tool enforced.** `get_required_capability()`
 *    answered `edit_posts` while `execute()` refused without `upload_files`. The
 *    stricter of the two is the behaviour, so it is the one declared.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Ecommerce;

use Nvoos\Core\Application\Media\ImageInput;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Tool\AbstractCompletionTool;

final class ValidateImageForVehicleTool extends AbstractCompletionTool
{
    /**
     * The shortest side below which no estimate is attempted.
     */
    private const MIN_DIMENSION = 720;

    /**
     * The shortest side at which no resolution warning is raised.
     */
    private const RECOMMENDED_DIMENSION = 1080;

    /**
     * The same per-image ceiling the WordPress tool enforced.
     */
    private const MAX_BYTES = 20971520;

    /**
     * The most images one call may carry, matching the original's `maxItems`.
     */
    private const MAX_IMAGES = 30;

    /**
     * @var list<string>
     */
    private const ALLOWED_MIME = array('image/jpeg', 'image/png', 'image/webp', 'image/gif');

    /**
     * @var array<string, string>
     */
    private const REQUIRED_VIEWS = array(
        'front'      => 'Front view',
        'rear'       => 'Rear view',
        'left_side'  => 'Left (driver) side',
        'right_side' => 'Right (passenger) side',
    );

    /**
     * @var array<string, string>
     */
    private const RECOMMENDED_VIEWS = array(
        'front_left_corner'  => 'Front-left corner (45 degrees)',
        'front_right_corner' => 'Front-right corner (45 degrees)',
        'rear_left_corner'   => 'Rear-left corner (45 degrees)',
        'rear_right_corner'  => 'Rear-right corner (45 degrees)',
        'damage_closeup'     => 'Close-up of the damaged area',
        'vin_plate'          => 'VIN plate (windshield or door jamb)',
    );

    /**
     * Cleaning weights, which total 100.
     *
     * @var array<string, int>
     */
    private const CLEANING_WEIGHTS = array(
        'vehicle_detected'     => 25,
        'full_vehicle_visible' => 20,
        'technical_format'     => 15,
        'image_dimensions'     => 8,
        'lighting'             => 12,
        'sharpness'            => 8,
        'condition_visibility' => 10,
        'single_vehicle'       => 2,
    );

    /**
     * Repair weights, which total 100.
     *
     * @var array<string, int>
     */
    private const REPAIR_WEIGHTS = array(
        'vehicle_detected'      => 15,
        'coverage_completeness' => 25,
        'damage_visibility'     => 20,
        'technical_format'      => 10,
        'image_dimensions'      => 5,
        'lighting'              => 10,
        'sharpness'             => 8,
        'vin_visibility'        => 5,
        'single_vehicle'        => 2,
    );

    /**
     * @var array<string, int>
     */
    private const THRESHOLDS = array('A' => 90, 'B' => 75, 'C' => 60, 'D' => 40, 'F' => 0);

    /**
     * @var array<string, string>
     */
    private const LABELS = array('A' => 'Excellent', 'B' => 'Good', 'C' => 'Fair', 'D' => 'Poor', 'F' => 'Fail');

    public function __construct(
        ErrorFactoryInterface $errors,
        ?ProviderRouter $providers = null,
        private readonly ?FileStoreInterface $files = null,
    ) {
        parent::__construct($errors, $providers);
    }

    public function getSlug(): string
    {
        return 'validate_image_for_vehicle';
    }

    public function getName(): string
    {
        return 'Validate Images for Vehicle';
    }

    public function getDescription(): string
    {
        return 'Checks whether a set of vehicle photos can produce a cleaning or repair estimate, and '
            . 'grades the set. Runs a local technical pass over every image before calling a vision '
            . 'model. For estimate_type cleaning, one full-vehicle shot is required for the size tier. '
            . 'For estimate_type repair, the front, rear, left and right sides are each required, and '
            . 'damage close-ups plus a VIN plate are recommended. Returns named checks, a weighted '
            . '0-100 rating with an A-F grade, and suggestions. Supply images by file_ids or images.';
    }

    /**
     * The predicate the original enforced in its body.
     */
    public function getRequiredCapability(): string
    {
        return 'upload_files';
    }

    /**
     * @return string[]
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
            'non-deterministic',
        );
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'estimate_type' => array(
                    'type'        => 'string',
                    'enum'        => array('cleaning', 'repair'),
                    'default'     => 'cleaning',
                    'description' => 'cleaning needs one full-vehicle shot for the size tier; repair '
                        . 'needs the four exterior sides and rewards damage close-ups and a VIN plate. '
                        . 'An unknown value falls back to cleaning.',
                ),
                'file_ids'      => array(
                    'type'        => 'array',
                    'items'       => array('type' => 'integer', 'minimum' => 1),
                    'maxItems'    => self::MAX_IMAGES,
                    'description' => 'Ids of images in this runtime\'s file registry. Combine with '
                        . 'images if needed; at least one reference of either kind is required.',
                ),
                'images'        => array(
                    'type'        => 'array',
                    'items'       => array('type' => 'string'),
                    'maxItems'    => self::MAX_IMAGES,
                    'description' => 'Images as data: URIs (data:image/jpeg;base64,...). Remote URLs '
                        . 'are not fetched by this runtime.',
                ),
            ) + $this->providerParameters() + array(
                'strict_mode' => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'When true, any warning also fails the set rather than only a '
                        . 'critical check.',
                ),
                'max_tokens'  => $this->maxTokensParameter('Cap on the analysis length.'),
            ),
            'required'             => array(),
            'additionalProperties' => false,
        );
    }

    /**
     * @param array<string, mixed> $arguments Tool arguments.
     * @param array<string, mixed> $context   Execution context.
     */
    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $type   = $this->estimateType($arguments);
        $images = $this->resolveImages($arguments, $context);

        if ($this->errors->isError($images)) {
            return $images;
        }

        $technical = $this->technical($images);

        if (array() !== $technical['critical']) {
            return $this->response(false, $technical['checks'], $type, $technical['critical'], $images, array());
        }

        $uris = array();

        foreach ($images as $image) {
            $uris[] = $image['dataUri'];
        }

        $answer = $this->completeTool(
            $arguments,
            $this->prompt($type, count($images)),
            $uris,
            '',
            false
        );

        if ($this->errors->isError($answer)) {
            return $answer;
        }

        $analysis = $this->decode($answer['text']);

        if (null === $analysis) {
            return $this->errors->create(
                'vehicle_validation_unparsable',
                'The vision model did not answer with the JSON object this tool asks for. '
                . 'Retry, or ask analyze_image for a free-text description instead.'
            );
        }

        $checks = array_merge($technical['checks'], $this->checks($analysis, $type));

        $critical = array();
        $warnings = array();

        foreach ($checks as $check) {
            if ('fail' === $check['status'] && $check['critical']) {
                $critical[] = $check['message'];
            } elseif ('fail' === $check['status'] || 'warning' === $check['status']) {
                $warnings[] = $check['message'];
            }
        }

        $critical = array_values(array_unique($critical));
        $warnings = array_values(array_unique($warnings));

        $valid = array() === $critical;

        if ($this->boolParam($arguments, 'strict_mode', false) && array() !== $warnings) {
            $valid = false;
        }

        return $this->response(
            $valid,
            $checks,
            $type,
            array_merge($critical, $warnings),
            $images,
            $this->suggestions($analysis)
        );
    }

    /**
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function estimateType(array $arguments): string
    {
        $type = $this->stringParam($arguments, 'estimate_type');

        return 'repair' === $type ? 'repair' : 'cleaning';
    }

    /**
     * Every referenced image, resolved, or the first refusal.
     *
     * The WordPress tool skipped a reference it could not resolve and continued,
     * which left the caller with a coverage failure and no reason for it.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     * @param array<string, mixed> $context   Execution context.
     *
     * @return array<int, array{mime: string, bytes: string, size: int, dataUri: string}>|mixed
     */
    private function resolveImages(array $arguments, array $context): mixed
    {
        $ids    = $this->intListParam($arguments, 'file_ids');
        $uris   = $this->stringListParam($arguments, 'images');
        $total  = count($ids) + count($uris);
        $input  = new ImageInput($this->errors, $this->files);
        $images = array();

        if (0 === $total) {
            return $this->errors->validationFailed(
                'At least one vehicle image is required.',
                array('file_ids' => array('Pass file_ids, images, or both.'))
            );
        }

        if ($total > self::MAX_IMAGES) {
            return $this->errors->validationFailed(
                sprintf('At most %d images may be validated at once.', self::MAX_IMAGES),
                array('images' => array('Too many images.'))
            );
        }

        foreach ($ids as $id) {
            $image = $input->resolve(array('file_id' => $id), $context);

            if ($this->errors->isError($image)) {
                return $image;
            }

            $images[] = $image;
        }

        foreach ($uris as $uri) {
            $image = $input->resolve(array('image_data' => $uri), $context);

            if ($this->errors->isError($image)) {
                return $image;
            }

            $images[] = $image;
        }

        return $images;
    }

    /**
     * Pass 1: format, size and dimensions, per image, with no network call.
     *
     * @param array<int, array{mime: string, bytes: string, size: int, dataUri: string}> $images Images.
     *
     * @return array{checks: list<array{name: string, status: string, message: string, critical: bool}>, critical: list<string>}
     */
    private function technical(array $images): array
    {
        $checks   = array();
        $critical = array();

        foreach ($images as $index => $image) {
            $label = 'Image #' . ( $index + 1 );

            $formatOk = in_array($image['mime'], self::ALLOWED_MIME, true);

            $checks[] = array(
                'name'     => 'file_format_' . $index,
                'status'   => $formatOk ? 'pass' : 'fail',
                'message'  => $formatOk
                    ? $label . ': format supported (' . $image['mime'] . ').'
                    : $label . ': unsupported format (' . $image['mime'] . '). Send JPEG, PNG, WebP or GIF.',
                'critical' => true,
            );

            if (! $formatOk) {
                $critical[] = $checks[count($checks) - 1]['message'];
            }

            $sizeOk = $image['size'] <= self::MAX_BYTES;

            $checks[] = array(
                'name'     => 'file_size_' . $index,
                'status'   => $sizeOk ? 'pass' : 'fail',
                'message'  => $sizeOk
                    ? sprintf('%s: file size acceptable (%s).', $label, $this->human($image['size']))
                    : sprintf(
                        '%s: %s exceeds the %s limit.',
                        $label,
                        $this->human($image['size']),
                        $this->human(self::MAX_BYTES)
                    ),
                'critical' => true,
            );

            if (! $sizeOk) {
                $critical[] = $checks[count($checks) - 1]['message'];
            }

            $dimensions = $this->dimensions($image['bytes']);

            if (null === $dimensions) {
                $checks[] = array(
                    'name'     => 'dimensions_' . $index,
                    'status'   => 'info',
                    'message'  => $label . ': dimensions could not be read, so resolution was not checked.',
                    'critical' => false,
                );

                continue;
            }

            $shortest = min($dimensions[0], $dimensions[1]);

            if ($shortest >= self::RECOMMENDED_DIMENSION) {
                $checks[] = array(
                    'name'     => 'dimensions_' . $index,
                    'status'   => 'pass',
                    'message'  => sprintf('%s: %d x %d px is sufficient.', $label, $dimensions[0], $dimensions[1]),
                    'critical' => false,
                );
            } elseif ($shortest >= self::MIN_DIMENSION) {
                $checks[] = array(
                    'name'     => 'dimensions_' . $index,
                    'status'   => 'warning',
                    'message'  => sprintf(
                        '%s: %d x %d px is below the recommended %d px. Results may be suboptimal.',
                        $label,
                        $dimensions[0],
                        $dimensions[1],
                        self::RECOMMENDED_DIMENSION
                    ),
                    'critical' => false,
                );
            } else {
                $message = sprintf(
                    '%s: %d x %d px is too small; the shortest side must be at least %d px.',
                    $label,
                    $dimensions[0],
                    $dimensions[1],
                    self::MIN_DIMENSION
                );

                $checks[] = array(
                    'name'     => 'dimensions_' . $index,
                    'status'   => 'fail',
                    'message'  => $message,
                    'critical' => true,
                );

                $critical[] = $message;
            }
        }

        return array('checks' => $checks, 'critical' => array_values(array_unique($critical)));
    }

    /**
     * The prompt for the estimate type.
     */
    private function prompt(string $type, int $count): string
    {
        if ('repair' === $type) {
            $prompt = 'You are a vehicle image validation assistant for an AI-powered vehicle repair '
                . 'estimate system. Analyze these ' . $count . ' vehicle photo(s) to determine whether '
                . 'they are suitable for producing an accurate repair estimate.';
            $prompt .= "\n\nRequired views: front, rear, left side and right side. Close-ups of the "
                . 'damaged areas and a VIN plate photo are strongly recommended.';
        } else {
            $prompt = 'You are a vehicle image validation assistant for an AI-powered vehicle cleaning '
                . 'and detailing estimate system. Analyze these ' . $count . ' vehicle photo(s) to '
                . 'determine whether they are suitable for classifying the vehicle size and producing a '
                . 'cleaning estimate.';
            $prompt .= "\n\nRequired: at least one photo of the full vehicle exterior for size "
                . 'classification into car, small truck or SUV, or oversize truck or SUV.';
        }

        $prompt .= "\n\nRespond with a JSON object containing exactly these fields:";
        $prompt .= "\n{";
        $prompt .= "\n  \"vehicle_detected\": boolean,";
        $prompt .= "\n  \"vehicle_count\": integer,";
        $prompt .= "\n  \"vehicle_description\": string,";

        if ('repair' === $type) {
            $prompt .= "\n  \"views_identified\": {";

            $views = array();

            foreach (array_keys(self::REQUIRED_VIEWS) as $view) {
                $views[] = '    "' . $view . '": boolean';
            }

            foreach (array_keys(self::RECOMMENDED_VIEWS) as $view) {
                $views[] = '    "' . $view . '": boolean';
            }

            $prompt .= "\n" . implode(",\n", $views);
            $prompt .= "\n  },";
            $prompt .= "\n  \"damage_visible\": boolean,";
            $prompt .= "\n  \"damage_areas\": [string],";
            $prompt .= "\n  \"damage_severity_estimate\": \"minor\" | \"moderate\" | \"severe\" | \"unknown\",";
            $prompt .= "\n  \"vin_readable\": boolean,";
        } else {
            $prompt .= "\n  \"full_vehicle_visible\": boolean,";
            $prompt .= "\n  \"estimated_size_tier\": \"car\" | \"small_truck_suv\" | \"oversize_truck_suv\" | \"unknown\",";
            $prompt .= "\n  \"size_confidence\": float,";
            $prompt .= "\n  \"condition_visible\": boolean,";
            $prompt .= "\n  \"visible_conditions\": [string],";
        }

        $prompt .= "\n  \"lighting_quality\": \"good\" | \"acceptable\" | \"poor\",";
        $prompt .= "\n  \"image_sharpness\": \"sharp\" | \"acceptable\" | \"blurry\",";
        $prompt .= "\n  \"obstructions\": [string],";
        $prompt .= "\n  \"overall_suitable\": boolean,";
        $prompt .= "\n  \"suggestions\": [string]";
        $prompt .= "\n}";
        $prompt .= "\n\nFor obstructions, list anything blocking the view of the vehicle, for example a "
            . 'person standing in front of it or another car partially blocking a panel.';
        $prompt .= "\nFor suggestions, give actionable advice to improve the photos for the estimate.";
        $prompt .= "\nRespond only with the JSON object. No prose and no code fence.";

        return $prompt;
    }

    /**
     * The shared checks plus the estimate-specific ones.
     *
     * @param array<string, mixed> $analysis Decoded analysis.
     *
     * @return list<array{name: string, status: string, message: string, critical: bool}>
     */
    private function checks(array $analysis, string $type): array
    {
        $checks = array();

        $detected = ! empty($analysis['vehicle_detected']);

        $checks[] = array(
            'name'     => 'vehicle_detected',
            'status'   => $detected ? 'pass' : 'fail',
            'message'  => $detected
                ? 'A vehicle was detected in the images.'
                : 'No vehicle was detected. Provide photographs of a vehicle.',
            'critical' => true,
        );

        $count = is_numeric($analysis['vehicle_count'] ?? null) ? (int) $analysis['vehicle_count'] : 0;

        if ($count > 1) {
            $checks[] = array(
                'name'     => 'single_vehicle',
                'status'   => 'warning',
                'message'  => sprintf(
                    '%d vehicles were detected. Focus the photographs on a single vehicle for an accurate estimate.',
                    $count
                ),
                'critical' => false,
            );
        } elseif (1 === $count) {
            $checks[] = array(
                'name'     => 'single_vehicle',
                'status'   => 'pass',
                'message'  => 'A single vehicle was detected, which is ideal for an estimate.',
                'critical' => false,
            );
        }

        $checks = array_merge($checks, 'repair' === $type ? $this->repairChecks($analysis) : $this->cleaningChecks($analysis));

        $lighting = strtolower($this->stringParam($analysis, 'lighting_quality'));

        if ('good' === $lighting) {
            $checks[] = array(
                'name'     => 'lighting',
                'status'   => 'pass',
                'message'  => 'Lighting quality is good.',
                'critical' => false,
            );
        } elseif ('acceptable' === $lighting) {
            $checks[] = array(
                'name'     => 'lighting',
                'status'   => 'pass',
                'message'  => 'Lighting is acceptable. Better lighting would improve estimate accuracy.',
                'critical' => false,
            );
        } else {
            $checks[] = array(
                'name'     => 'lighting',
                'status'   => 'warning',
                'message'  => 'Lighting is poor: dark, overexposed or backlit images reduce accuracy. Retake in even light.',
                'critical' => false,
            );
        }

        $sharpness = strtolower($this->stringParam($analysis, 'image_sharpness'));

        $checks[] = array(
            'name'     => 'sharpness',
            'status'   => 'blurry' === $sharpness ? 'warning' : 'pass',
            'message'  => 'blurry' === $sharpness
                ? 'One or more images appear blurry. Sharper photographs improve size and damage detection.'
                : ( 'sharp' === $sharpness ? 'The images are sharp and clear.' : 'Image sharpness is acceptable.' ),
            'critical' => false,
        );

        $obstructions = $this->strings($analysis['obstructions'] ?? null);

        $checks[] = array() !== $obstructions
            ? array(
                'name'     => 'obstructions',
                'status'   => 'warning',
                'message'  => 'Obstructions detected: ' . implode(', ', $obstructions) . '. These may reduce estimate accuracy.',
                'critical' => false,
            )
            : array(
                'name'     => 'obstructions',
                'status'   => 'pass',
                'message'  => 'No obstructions were detected blocking the vehicle.',
                'critical' => false,
            );

        return $checks;
    }

    /**
     * @param array<string, mixed> $analysis Decoded analysis.
     *
     * @return list<array{name: string, status: string, message: string, critical: bool}>
     */
    private function cleaningChecks(array $analysis): array
    {
        $checks = array();

        $full = ! empty($analysis['full_vehicle_visible']);

        $checks[] = array(
            'name'     => 'full_vehicle_visible',
            'status'   => $full ? 'pass' : 'fail',
            'message'  => $full
                ? 'The full vehicle is visible for size classification.'
                : 'The full vehicle is not visible. A photograph of the entire vehicle is required for size classification and pricing.',
            'critical' => true,
        );

        $tier       = strtolower($this->stringParam($analysis, 'estimated_size_tier'));
        $confidence = is_numeric($analysis['size_confidence'] ?? null) ? (float) $analysis['size_confidence'] : 0.0;

        if ('unknown' !== $tier && '' !== $tier && $confidence > 0.5) {
            $checks[] = array(
                'name'     => 'size_classification',
                'status'   => $confidence >= 0.75 ? 'pass' : 'warning',
                'message'  => sprintf(
                    'The vehicle was classified as "%s" with %d%% confidence.',
                    $tier,
                    (int) round($confidence * 100)
                ),
                'critical' => false,
            );
        } elseif ($full) {
            $checks[] = array(
                'name'     => 'size_classification',
                'status'   => 'warning',
                'message'  => 'The vehicle size could not be classified confidently. A clearer full side-on photograph would help.',
                'critical' => false,
            );
        }

        $condition   = ! empty($analysis['condition_visible']);
        $conditions  = $this->strings($analysis['visible_conditions'] ?? null);

        $checks[] = array(
            'name'     => 'condition_visibility',
            'status'   => $condition ? 'pass' : 'info',
            'message'  => $condition
                ? 'Conditions detected: ' . ( array() !== $conditions ? implode(', ', $conditions) : 'not itemised' )
                    . '. This helps recommend add-on services.'
                : 'The vehicle condition is not clearly visible. Close-ups of soiled areas help recommend add-ons.',
            'critical' => false,
        );

        return $checks;
    }

    /**
     * @param array<string, mixed> $analysis Decoded analysis.
     *
     * @return list<array{name: string, status: string, message: string, critical: bool}>
     */
    private function repairChecks(array $analysis): array
    {
        $checks = array();

        $views = is_array($analysis['views_identified'] ?? null) ? $analysis['views_identified'] : array();

        $present = 0;
        $total   = count(self::REQUIRED_VIEWS);

        foreach (self::REQUIRED_VIEWS as $key => $label) {
            $seen = ! empty($views[$key]);

            $checks[] = array(
                'name'     => 'view_' . $key,
                'status'   => $seen ? 'pass' : 'fail',
                'message'  => $seen
                    ? $label . ' is present.'
                    : $label . ' is missing. This angle is required for a complete repair estimate.',
                'critical' => true,
            );

            if ($seen) {
                ++$present;
            }
        }

        $coverage = $total > 0 ? (int) round(( $present / $total ) * 100) : 0;

        $checks[] = array(
            'name'     => 'coverage_completeness',
            'status'   => 100 <= $coverage ? 'pass' : ( 50 <= $coverage ? 'warning' : 'fail' ),
            'message'  => sprintf(
                'Exterior coverage: %d of %d required views present (%d%%).',
                $present,
                $total,
                $coverage
            ),
            'critical' => 50 > $coverage,
        );

        foreach (self::RECOMMENDED_VIEWS as $key => $label) {
            $seen = ! empty($views[$key]);

            $checks[] = array(
                'name'     => 'recommended_' . $key,
                'status'   => $seen ? 'pass' : 'info',
                'message'  => $seen
                    ? 'Recommended: ' . $label . ' is present.'
                    : 'Recommended: ' . $label . ' is missing (optional, but it improves estimate accuracy).',
                'critical' => false,
            );
        }

        $damage = ! empty($analysis['damage_visible']);
        $areas  = $this->strings($analysis['damage_areas'] ?? null);

        $checks[] = array(
            'name'     => 'damage_visibility',
            'status'   => $damage ? 'pass' : 'warning',
            'message'  => $damage
                ? 'Damage is visible in: ' . ( array() !== $areas ? implode(', ', $areas) : 'unspecified area' ) . '.'
                : 'No clear damage is visible. Include close-ups of the damaged areas for an accurate estimate.',
            'critical' => false,
        );

        $severity = strtolower($this->stringParam($analysis, 'damage_severity_estimate'));

        if ('unknown' !== $severity && '' !== $severity) {
            $checks[] = array(
                'name'     => 'damage_severity',
                'status'   => 'info',
                'message'  => 'Estimated damage severity: ' . ucfirst($severity) . '.',
                'critical' => false,
            );
        }

        $vin = ! empty($analysis['vin_readable']);

        $checks[] = array(
            'name'     => 'vin_visibility',
            'status'   => $vin ? 'pass' : 'info',
            'message'  => $vin
                ? 'The VIN plate is readable, which enables accurate part identification and pricing.'
                : 'No VIN plate was detected. A VIN photograph enables accurate part lookup and pricing.',
            'critical' => false,
        );

        return $checks;
    }

    /**
     * @param array<string, mixed> $analysis Decoded analysis.
     *
     * @return list<string>
     */
    private function suggestions(array $analysis): array
    {
        return $this->strings($analysis['suggestions'] ?? null);
    }

    /**
     * The weighted rating for the estimate type, and a grade.
     *
     * @param list<array{name: string, status: string, message: string, critical: bool}> $checks Checks.
     *
     * @return array{score: int, grade: string, label: string, breakdown: array<string, array{weight: int, category_score: float, weighted_score: float}>}
     */
    private function rating(array $checks, string $type): array
    {
        $weights = 'repair' === $type ? self::REPAIR_WEIGHTS : self::CLEANING_WEIGHTS;
        $scored  = array();

        foreach ($checks as $check) {
            $category = $this->category($check['name']);

            if (null === $category) {
                continue;
            }

            $scored[$category][] = match ($check['status']) {
                'pass', 'info' => 100.0,
                'warning'      => 50.0,
                default        => 0.0,
            };
        }

        $total     = 0.0;
        $breakdown = array();

        foreach ($weights as $category => $weight) {
            $scores = $scored[$category] ?? array();

            $categoryScore = array() === $scores ? 100.0 : array_sum($scores) / count($scores);
            $weighted      = ( $categoryScore / 100.0 ) * $weight;
            $total        += $weighted;

            $breakdown[$category] = array(
                'weight'         => $weight,
                'category_score' => round($categoryScore, 1),
                'weighted_score' => round($weighted, 1),
            );
        }

        $score = max(0, min(100, (int) round($total)));
        $grade = 'F';

        foreach (self::THRESHOLDS as $letter => $threshold) {
            if ($score >= $threshold) {
                $grade = $letter;
                break;
            }
        }

        return array(
            'score'     => $score,
            'grade'     => $grade,
            'label'     => self::LABELS[$grade],
            'breakdown' => $breakdown,
        );
    }

    /**
     * The scoring category for a check name, or null when it does not score.
     */
    private function category(string $name): ?string
    {
        foreach (array('file_format_' => 'technical_format', 'file_size_' => 'technical_format', 'dimensions_' => 'image_dimensions') as $prefix => $category) {
            if (str_starts_with($name, $prefix)) {
                return $category;
            }
        }

        if (str_starts_with($name, 'view_') || 'coverage_completeness' === $name) {
            return 'coverage_completeness';
        }

        // A recommended view is advice, never a deduction.
        if (str_starts_with($name, 'recommended_')) {
            return null;
        }

        $exact = array(
            'vehicle_detected'     => 'vehicle_detected',
            'single_vehicle'       => 'single_vehicle',
            'full_vehicle_visible' => 'full_vehicle_visible',
            'size_classification'  => 'full_vehicle_visible',
            'condition_visibility' => 'condition_visibility',
            'damage_visibility'    => 'damage_visibility',
            'damage_severity'      => 'damage_visibility',
            'vin_visibility'       => 'vin_visibility',
            'lighting'             => 'lighting',
            'sharpness'            => 'sharpness',
            'obstructions'         => 'lighting',
        );

        return $exact[$name] ?? null;
    }

    /**
     * The response, in the canonical envelope.
     *
     * @param list<array{name: string, status: string, message: string, critical: bool}> $checks Checks.
     * @param list<string>                                                               $notes  Failures and warnings.
     * @param array<int, array{mime: string, bytes: string, size: int, dataUri: string}>  $images Images.
     * @param list<string>                                                               $advice Suggestions.
     *
     * @return array<string, mixed>
     */
    private function response(bool $valid, array $checks, string $type, array $notes, array $images, array $advice): array
    {
        $rating = $this->rating($checks, $type);

        $pass = 0;
        $fail = 0;
        $warn = 0;

        foreach ($checks as $check) {
            if ('pass' === $check['status']) {
                ++$pass;
            } elseif ('fail' === $check['status']) {
                ++$fail;
            } elseif ('warning' === $check['status']) {
                ++$warn;
            }
        }

        $label = 'repair' === $type ? 'repair' : 'cleaning';
        $total = count($checks);

        $summary = $valid
            ? sprintf(
                'These images suit a %s estimate. %d of %d checks passed across %d image(s). Rating %d/100 (%s, %s).',
                $label,
                $pass,
                $total,
                count($images),
                $rating['score'],
                $rating['grade'],
                $rating['label']
            )
            : sprintf(
                'These images do not suit a %s estimate. %d issue(s) need resolving across %d image(s). Rating %d/100 (%s, %s).',
                $label,
                $fail,
                count($images),
                $rating['score'],
                $rating['grade'],
                $rating['label']
            );

        if ($warn > 0) {
            $summary .= sprintf(' %d warning(s) noted.', $warn);
        }

        $data = array(
            'valid'         => $valid,
            'estimate_type' => $type,
            'rating'        => $rating,
            'checks'        => $checks,
            'statistics'    => array(
                'total'    => $total,
                'passed'   => $pass,
                'failed'   => $fail,
                'warnings' => $warn,
            ),
            'images'        => array_map(
                static fn (array $image): array => array('mime' => $image['mime'], 'bytes' => $image['size']),
                $images
            ),
        );

        if (array() !== $advice) {
            $data['suggestions'] = $advice;
        }

        if (array() !== $notes) {
            $data['failures'] = $notes;
        }

        return $this->success($summary, $data);
    }

    /**
     * Decode the model's JSON, tolerating a fence or surrounding prose.
     *
     * @return array<string, mixed>|null
     */
    private function decode(string $text): ?array
    {
        $trimmed = trim($text);

        if ('' === $trimmed) {
            return null;
        }

        $trimmed = (string) preg_replace('/^```(?:json)?\s*/i', '', $trimmed);
        $trimmed = (string) preg_replace('/\s*```$/', '', $trimmed);

        $decoded = json_decode($trimmed, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($trimmed, '{');
        $end   = strrpos($trimmed, '}');

        if (false === $start || false === $end || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($trimmed, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return array();
        }

        $out = array();

        foreach ($value as $item) {
            if (is_scalar($item)) {
                $text = trim((string) $item);

                if ('' !== $text) {
                    $out[] = $text;
                }
            }
        }

        return $out;
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function dimensions(string $bytes): ?array
    {
        $size = @getimagesizefromstring($bytes);

        if (! is_array($size)) {
            return null;
        }

        $width  = (int) $size[0];
        $height = (int) $size[1];

        if ($width < 1 || $height < 1) {
            return null;
        }

        return array($width, $height);
    }

    private function human(int $bytes): string
    {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
}
