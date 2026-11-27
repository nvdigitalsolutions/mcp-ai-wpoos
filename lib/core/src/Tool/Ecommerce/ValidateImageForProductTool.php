<?php

/**
 * Tool: validate_image_for_product — is this photo fit for a try-on placement?
 *
 * Proposal 164 section 164.123. The WordPress original answers a question a
 * catalogue assistant actually asks before spending a generation credit: does
 * this photo show the body area a watch, ring or necklace would sit on, at a
 * usable resolution, without the hair or a sleeve in the way?
 *
 * ## Built on the completion path, not on `VisionInferenceInterface`
 *
 * This is worth stating because §164.122 built a `VisionInferenceInterface`
 * binding on the assumption it was the prerequisite for this family. Reading
 * `analyze_image` while porting showed a second, older and fully wired vision
 * path already existed in core: `AbstractCompletionTool` owns the call and its
 * failures, `VisionRequest` builds the multimodal message, `ImageInput` resolves
 * the bytes, and `ProviderRouter` picks the client. That is the path this tool
 * uses, because it is the one a host already configures.
 *
 * The consequence is recorded rather than quietly worked around: two vision
 * seams now exist in core, and `VisionInferenceInterface` still has no consumer.
 * §164.123 argues what should happen to it.
 *
 * ## Faithful to the two-pass verdict
 *
 * Pass 1 is technical and local: format, size and dimensions. A critical
 * technical failure returns before any model is called, which is the point —
 * a 200-pixel thumbnail is refused for free rather than for a token spend.
 * Pass 2 asks the model, maps its answer onto named checks, and grades them.
 *
 * | Element | Preserved from the WordPress tool |
 * |---|---|
 * | Per-type body-part requirements | yes, all nine types |
 * | Required parts are critical | yes — a missing wrist fails the image |
 * | Optional parts are informational | yes — `info`, never a failure |
 * | Strict mode fails on any warning | yes |
 * | Weighted 0–100 rating and A–F grade | yes, same weights and thresholds |
 * | A missing scoring category scores 100 | yes |
 *
 * ## Five corrections
 *
 * 1. **`image_url` is refused, because `ImageInput` refuses it.** The WordPress
 *    tool accepted an attachment id, a file id, a URL and a raw URL, and ran its
 *    own `wp_remote_head` against the URL before fetching it. Core accepts a
 *    registry `file_id` (with an ownership check) or a `data:` URI, and never
 *    fetches a caller-supplied host. The URL input is not ported rather than
 *    ported unsafely.
 * 2. **`response_format` is not available on this path**, so the tool asks for
 *    JSON in the prompt and parses tolerantly — fenced blocks and surrounding
 *    prose are stripped before decoding. A caller gets a refusal, not a crash,
 *    when the model answers prose anyway.
 * 3. **Dimensions that cannot be read are `info`, not a pass.** The WordPress
 *    tool skipped the check when width or height was zero and said nothing.
 * 4. **The emoji are gone from the summary.** `✅`/`❌` were prefixed to a string
 *    that also feeds a machine-readable payload; the verdict is already `valid`.
 * 5. **`image_url` is not echoed back.** The response reports the mime type and
 *    byte count the runtime actually saw, which is what a caller can act on.
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
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Tool\AbstractCompletionTool;

final class ValidateImageForProductTool extends AbstractCompletionTool
{
    /**
     * The floor below which no placement is attempted, whatever the type wants.
     */
    private const MIN_DIMENSION = 512;

    /**
     * The same ceiling the WordPress tool enforced, in bytes.
     */
    private const MAX_BYTES = 20971520;

    /**
     * What the vision model is told it may receive.
     *
     * @var list<string>
     */
    private const ALLOWED_MIME = array('image/jpeg', 'image/png', 'image/webp', 'image/gif');

    /**
     * Per-type body-part requirements — the domain knowledge in this tool.
     *
     * @var array<string, array{label: string, required: list<string>, optional: list<string>, pose: string, min: int}>
     */
    private const PRODUCT_TYPES = array(
        'watch'    => array(
            'label'    => 'Watch',
            'required' => array('wrist', 'hand'),
            'optional' => array('forearm'),
            'pose'     => 'Wrist facing camera, hand relaxed or slightly raised.',
            'min'      => 1024,
        ),
        'bracelet' => array(
            'label'    => 'Bracelet',
            'required' => array('wrist', 'hand'),
            'optional' => array('forearm'),
            'pose'     => 'Wrist facing camera with clear view of wrist area.',
            'min'      => 1024,
        ),
        'ring'     => array(
            'label'    => 'Ring',
            'required' => array('hand', 'fingers'),
            'optional' => array('wrist'),
            'pose'     => 'Hand open or naturally relaxed, fingers clearly visible.',
            'min'      => 1024,
        ),
        'earring'  => array(
            'label'    => 'Earring',
            'required' => array('ear', 'face'),
            'optional' => array('jawline', 'neck'),
            'pose'     => 'Ear fully exposed, hair tucked behind ear. Side or three-quarter profile.',
            'min'      => 512,
        ),
        'necklace' => array(
            'label'    => 'Necklace / Chain',
            'required' => array('neck', 'upper_chest'),
            'optional' => array('face', 'shoulders'),
            'pose'     => 'Front-facing with neck and upper chest clearly visible. No high-collar clothing.',
            'min'      => 1024,
        ),
        'glasses'  => array(
            'label'    => 'Glasses / Eyewear',
            'required' => array('face', 'eyes', 'ears'),
            'optional' => array('forehead'),
            'pose'     => 'Front-facing with both eyes and ears visible. No existing glasses or sunglasses.',
            'min'      => 512,
        ),
        'hat'      => array(
            'label'    => 'Hat / Headwear',
            'required' => array('head', 'face'),
            'optional' => array('forehead', 'hair'),
            'pose'     => 'Front-facing or slight angle, head and face visible. No existing hat or headwear.',
            'min'      => 512,
        ),
        'bag'      => array(
            'label'    => 'Bag / Purse',
            'required' => array('shoulder', 'arm'),
            'optional' => array('upper_body', 'hand'),
            'pose'     => 'Upper body visible with at least one arm and shoulder clear. Half or full body shot.',
            'min'      => 1024,
        ),
        'general'  => array(
            'label'    => 'General Accessory',
            'required' => array('person'),
            'optional' => array(),
            'pose'     => 'Clear image of a person with the relevant body area visible.',
            'min'      => 512,
        ),
    );

    /**
     * Category weights, which must total 100.
     *
     * @var array<string, int>
     */
    private const WEIGHTS = array(
        'required_body_parts'  => 35,
        'technical_format'     => 15,
        'image_dimensions'     => 10,
        'lighting'             => 10,
        'sharpness'            => 8,
        'pose'                 => 10,
        'obstructions'         => 5,
        'single_person'        => 3,
        'existing_accessories' => 2,
        'optional_body_parts'  => 2,
    );

    /**
     * Ordered so the first threshold met wins.
     *
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
        return 'validate_image_for_product';
    }

    public function getName(): string
    {
        return 'Validate Image for Product';
    }

    public function getDescription(): string
    {
        return 'Checks whether a photo can be used to place a product on a person, and grades it. '
            . 'Runs a local technical pass (format, size, dimensions) before calling a vision model, '
            . 'so an unusable image is refused without spending tokens. Then asks the model which '
            . 'required body parts are visible, what obstructs them, and whether the pose, lighting '
            . 'and sharpness are usable. Returns named checks, a weighted 0-100 rating with an A-F '
            . 'grade, and actionable suggestions. Supply the image by file_id or image_data.';
    }

    /**
     * A caller may validate the images it could have uploaded, and no others.
     *
     * `analyze_image` sets the same predicate for the same reason; the WordPress
     * original asked for `edit_posts`, which is not about images at all.
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
        );
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'product_type' => array(
                    'type'        => 'string',
                    'enum'        => array_keys(self::PRODUCT_TYPES),
                    'default'     => 'general',
                    'description' => 'The product being placed. Each type names the body parts that '
                        . 'must be visible and a preferred pose. An unknown value falls back to general.',
                ),
            ) + ImageInput::parameters() + $this->providerParameters() + array(
                'strict_mode' => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'When true, any warning also fails the image rather than only a '
                        . 'critical check.',
                ),
                'max_tokens'  => $this->maxTokensParameter('Cap on the analysis length.'),
            ),
            'required'             => array('product_type'),
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

        $type  = $this->productType($arguments);
        $image = ( new ImageInput($this->errors, $this->files) )->resolve($arguments, $context);

        if ($this->errors->isError($image)) {
            return $image;
        }

        $technical = $this->technical($image, $type);

        if (array() !== $technical['critical']) {
            return $this->response(false, $technical['checks'], $type, $technical['critical'], $image, array());
        }

        $answer = $this->completeTool(
            $arguments,
            $this->prompt($type),
            $image['dataUri'],
            '',
            false
        );

        if ($this->errors->isError($answer)) {
            return $answer;
        }

        $analysis = $this->decode($answer['text']);

        if (null === $analysis) {
            return $this->errors->create(
                'product_validation_unparsable',
                'The vision model did not answer with the JSON object this tool asks for. '
                . 'Retry, or ask analyze_image for a free-text description instead.'
            );
        }

        $vision = $this->checks($analysis, $type);
        $checks = array_merge($technical['checks'], $vision);

        $critical = array();
        $warnings = array();

        foreach ($checks as $check) {
            if ('fail' === $check['status'] && $check['critical']) {
                $critical[] = $check['message'];
            } elseif ('fail' === $check['status'] || 'warning' === $check['status']) {
                $warnings[] = $check['message'];
            }
        }

        $valid  = array() === $critical;
        $strict = $this->boolParam($arguments, 'strict_mode', false);

        if ($strict && array() !== $warnings) {
            $valid = false;
        }

        return $this->response($valid, $checks, $type, array_merge($critical, $warnings), $image, $this->suggestions($analysis));
    }

    /**
     * The product type, or `general` when the caller named one that is not known.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function productType(array $arguments): string
    {
        $type = $this->stringParam($arguments, 'product_type');

        return isset(self::PRODUCT_TYPES[$type]) ? $type : 'general';
    }

    /**
     * Pass 1: format, size and dimensions, with no network call.
     *
     * @param array{mime: string, bytes: string, size: int, dataUri: string} $image Resolved image.
     *
     * @return array{checks: list<array{name: string, status: string, message: string, critical: bool}>, critical: list<string>}
     */
    private function technical(array $image, string $type): array
    {
        $config   = self::PRODUCT_TYPES[$type];
        $checks   = array();
        $critical = array();

        $formatOk = in_array($image['mime'], self::ALLOWED_MIME, true);

        $checks[] = array(
            'name'     => 'file_format',
            'status'   => $formatOk ? 'pass' : 'fail',
            'message'  => $formatOk
                ? 'The image format is supported (' . $image['mime'] . ').'
                : 'The image format is not supported (' . $image['mime'] . '). Send a JPEG, PNG, WebP or GIF.',
            'critical' => true,
        );

        if (! $formatOk) {
            $critical[] = $checks[0]['message'];
        }

        $sizeOk = $image['size'] <= self::MAX_BYTES;

        $checks[] = array(
            'name'     => 'file_size',
            'status'   => $sizeOk ? 'pass' : 'fail',
            'message'  => $sizeOk
                ? 'The image size is within the limit.'
                : sprintf(
                    'The image is %s; the limit is %s.',
                    $this->human($image['size']),
                    $this->human(self::MAX_BYTES)
                ),
            'critical' => true,
        );

        if (! $sizeOk) {
            $critical[] = $checks[1]['message'];
        }

        $dimensions = $this->dimensions($image['bytes']);

        if (null === $dimensions) {
            $checks[] = array(
                'name'     => 'dimensions',
                'status'   => 'info',
                'message'  => 'The image dimensions could not be read, so resolution was not checked.',
                'critical' => false,
            );
        } else {
            $shortest = min($dimensions[0], $dimensions[1]);
            $needed   = $config['min'];

            if ($shortest >= $needed) {
                $checks[] = array(
                    'name'     => 'dimensions',
                    'status'   => 'pass',
                    'message'  => sprintf(
                        'The image is %d x %d px, which meets the %d px minimum for %s.',
                        $dimensions[0],
                        $dimensions[1],
                        $needed,
                        $config['label']
                    ),
                    'critical' => false,
                );
            } elseif ($shortest >= self::MIN_DIMENSION) {
                $checks[] = array(
                    'name'     => 'dimensions',
                    'status'   => 'warning',
                    'message'  => sprintf(
                        'The image is %d x %d px, below the %d px recommended for %s. Results may be suboptimal.',
                        $dimensions[0],
                        $dimensions[1],
                        $needed,
                        $config['label']
                    ),
                    'critical' => false,
                );
            } else {
                $message = sprintf(
                    'The image is %d x %d px; the shortest side must be at least %d px.',
                    $dimensions[0],
                    $dimensions[1],
                    self::MIN_DIMENSION
                );

                $checks[] = array(
                    'name'     => 'dimensions',
                    'status'   => 'fail',
                    'message'  => $message,
                    'critical' => true,
                );

                $critical[] = $message;
            }
        }

        return array('checks' => $checks, 'critical' => $critical);
    }

    /**
     * The prompt: the per-type requirements plus the JSON shape to answer in.
     *
     * `response_format` is not available through `completeTool()`, so the shape
     * is spelled out here and {@see decode()} tolerates a fenced or chatty
     * answer rather than trusting that the instruction was honoured.
     */
    private function prompt(string $type): string
    {
        $config = self::PRODUCT_TYPES[$type];

        $required = implode(', ', $config['required']);
        $optional = array() !== $config['optional'] ? implode(', ', $config['optional']) : 'none';

        $prompt = 'You are an image validation assistant for a virtual product try-on system. ';
        $prompt .= 'Analyze this photo to determine whether it is suitable for placing a '
            . $config['label'] . ' product on the person.';
        $prompt .= "\n\nRequired visible body parts: " . $required;
        $prompt .= "\nOptional (helpful) body parts: " . $optional;
        $prompt .= "\nPreferred pose: " . $config['pose'];

        $requiredKeys = array();

        foreach ($config['required'] as $part) {
            $requiredKeys[] = '    "' . $part . '": boolean';
        }

        $optionalKeys = array();

        foreach ($config['optional'] as $part) {
            $optionalKeys[] = '    "' . $part . '": boolean';
        }

        $prompt .= "\n\nRespond with a JSON object with exactly these fields:";
        $prompt .= "\n{";
        $prompt .= "\n  \"person_detected\": boolean,";
        $prompt .= "\n  \"person_count\": integer,";
        $prompt .= "\n  \"required_parts_visible\": {";
        $prompt .= "\n" . implode(",\n", $requiredKeys);
        $prompt .= "\n  },";
        $prompt .= "\n  \"optional_parts_visible\": {";
        $prompt .= "\n" . implode(",\n", $optionalKeys);
        $prompt .= "\n  },";
        $prompt .= "\n  \"obstructions\": [string],";
        $prompt .= "\n  \"lighting_quality\": \"good\" | \"acceptable\" | \"poor\",";
        $prompt .= "\n  \"image_sharpness\": \"sharp\" | \"acceptable\" | \"blurry\",";
        $prompt .= "\n  \"background_complexity\": \"simple\" | \"moderate\" | \"complex\",";
        $prompt .= "\n  \"existing_accessories\": [string],";
        $prompt .= "\n  \"pose_suitable\": boolean,";
        $prompt .= "\n  \"overall_suitable\": boolean,";
        $prompt .= "\n  \"suggestions\": [string]";
        $prompt .= "\n}";
        $prompt .= "\nFor obstructions, list anything blocking the required body parts, "
            . 'for example hair covering an ear or a sleeve covering a wrist.';
        $prompt .= "\nFor existing_accessories, list anything already worn on the target body parts.";
        $prompt .= "\nFor suggestions, give actionable advice to improve the photo.";
        $prompt .= "\nRespond only with the JSON object. No prose and no code fence.";

        return $prompt;
    }

    /**
     * Map the model's answer onto named checks.
     *
     * @param array<string, mixed> $analysis Decoded analysis.
     *
     * @return list<array{name: string, status: string, message: string, critical: bool}>
     */
    private function checks(array $analysis, string $type): array
    {
        $config = self::PRODUCT_TYPES[$type];
        $checks = array();

        $detected = ! empty($analysis['person_detected']);

        $checks[] = array(
            'name'     => 'person_detected',
            'status'   => $detected ? 'pass' : 'fail',
            'message'  => $detected
                ? 'A person was detected in the image.'
                : 'No person was detected. Provide a photo that includes a person.',
            'critical' => true,
        );

        $count = is_numeric($analysis['person_count'] ?? null) ? (int) $analysis['person_count'] : 0;

        if ($count > 1) {
            $checks[] = array(
                'name'     => 'single_person',
                'status'   => 'warning',
                'message'  => sprintf(
                    '%d people were detected. A single person avoids placement ambiguity.',
                    $count
                ),
                'critical' => false,
            );
        } elseif (1 === $count) {
            $checks[] = array(
                'name'     => 'single_person',
                'status'   => 'pass',
                'message'  => 'A single person was detected, which is ideal for placement.',
                'critical' => false,
            );
        }

        $visible = is_array($analysis['required_parts_visible'] ?? null) ? $analysis['required_parts_visible'] : array();

        foreach ($config['required'] as $part) {
            $seen = ! empty($visible[$part]);

            $checks[] = array(
                'name'     => 'body_part_' . $part,
                'status'   => $seen ? 'pass' : 'fail',
                'message'  => $seen
                    ? sprintf('The required body part "%s" is visible.', $part)
                    : sprintf('The required body part "%s" is not visible or not identifiable.', $part),
                'critical' => true,
            );
        }

        $optionalSeen = is_array($analysis['optional_parts_visible'] ?? null) ? $analysis['optional_parts_visible'] : array();

        foreach ($config['optional'] as $part) {
            $seen = ! empty($optionalSeen[$part]);

            $checks[] = array(
                'name'     => 'optional_part_' . $part,
                'status'   => $seen ? 'pass' : 'info',
                'message'  => $seen
                    ? sprintf('The optional body part "%s" is visible, which improves placement.', $part)
                    : sprintf('The optional body part "%s" is not visible. Placement can still proceed.', $part),
                'critical' => false,
            );
        }

        $obstructions = $this->strings($analysis['obstructions'] ?? null);

        $checks[] = array() !== $obstructions
            ? array(
                'name'     => 'obstructions',
                'status'   => 'warning',
                'message'  => 'Obstructions detected: ' . implode(', ', $obstructions) . '. These may affect placement accuracy.',
                'critical' => false,
            )
            : array(
                'name'     => 'obstructions',
                'status'   => 'pass',
                'message'  => 'No obstructions were detected on the target area.',
                'critical' => false,
            );

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
                'message'  => 'Lighting quality is acceptable. Better lighting would improve results.',
                'critical' => false,
            );
        } else {
            $checks[] = array(
                'name'     => 'lighting',
                'status'   => 'warning',
                'message'  => 'Lighting is poor: the image is too dark, overexposed or backlit. Placement may not look natural.',
                'critical' => false,
            );
        }

        $sharpness = strtolower($this->stringParam($analysis, 'image_sharpness'));

        $checks[] = array(
            'name'     => 'sharpness',
            'status'   => 'blurry' === $sharpness ? 'warning' : 'pass',
            'message'  => 'blurry' === $sharpness
                ? 'The image appears blurry. A sharper image produces better placement.'
                : ( 'sharp' === $sharpness ? 'The image is sharp and clear.' : 'Image sharpness is acceptable.' ),
            'critical' => false,
        );

        $existing = $this->strings($analysis['existing_accessories'] ?? null);

        $checks[] = array() !== $existing
            ? array(
                'name'     => 'existing_accessories',
                'status'   => 'warning',
                'message'  => 'Existing accessories on the target area: ' . implode(', ', $existing)
                    . '. Consider removing them or using another photo.',
                'critical' => false,
            )
            : array(
                'name'     => 'existing_accessories',
                'status'   => 'pass',
                'message'  => 'No conflicting accessories were detected on the target area.',
                'critical' => false,
            );

        $suitable = ! empty($analysis['pose_suitable']);

        $checks[] = array(
            'name'     => 'pose',
            'status'   => $suitable ? 'pass' : 'warning',
            'message'  => $suitable
                ? 'The pose is suitable for placement.'
                : 'The pose could be improved. Preferred: ' . $config['pose'],
            'critical' => false,
        );

        return $checks;
    }

    /**
     * The model's own suggestions, stripped of anything that is not a string.
     *
     * @param array<string, mixed> $analysis Decoded analysis.
     *
     * @return list<string>
     */
    private function suggestions(array $analysis): array
    {
        return $this->strings($analysis['suggestions'] ?? null);
    }

    /**
     * The weighted rating, a grade, and the per-category breakdown.
     *
     * A category with no checks scores 100: `general` has no optional parts, and
     * penalising it for a check it cannot have would rank it below the types it
     * is the fallback for.
     *
     * @param list<array{name: string, status: string, message: string, critical: bool}> $checks Checks.
     *
     * @return array{score: int, grade: string, label: string, breakdown: array<string, array{weight: int, category_score: float, weighted_score: float}>}
     */
    private function rating(array $checks): array
    {
        $scored = array();

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

        foreach (self::WEIGHTS as $category => $weight) {
            $scores = $scored[$category] ?? array();

            $categoryScore = array() === $scores
                ? 100.0
                : array_sum($scores) / count($scores);

            $weighted = ( $categoryScore / 100.0 ) * $weight;
            $total   += $weighted;

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
        if (str_starts_with($name, 'body_part_')) {
            return 'required_body_parts';
        }

        if (str_starts_with($name, 'optional_part_')) {
            return 'optional_body_parts';
        }

        $exact = array(
            'person_detected'      => 'required_body_parts',
            'file_format'          => 'technical_format',
            'file_size'            => 'technical_format',
            'dimensions'           => 'image_dimensions',
            'lighting'             => 'lighting',
            'sharpness'            => 'sharpness',
            'pose'                 => 'pose',
            'obstructions'         => 'obstructions',
            'single_person'        => 'single_person',
            'existing_accessories' => 'existing_accessories',
        );

        return $exact[$name] ?? null;
    }

    /**
     * The response, in the canonical envelope.
     *
     * @param list<array{name: string, status: string, message: string, critical: bool}> $checks Checks.
     * @param list<string>                                                               $notes  Failures and warnings.
     * @param array{mime: string, bytes: string, size: int, dataUri: string}              $image  Resolved image.
     * @param list<string>                                                               $advice Model suggestions.
     *
     * @return array<string, mixed>
     */
    private function response(bool $valid, array $checks, string $type, array $notes, array $image, array $advice): array
    {
        $config = self::PRODUCT_TYPES[$type];
        $rating = $this->rating($checks);

        $pass   = 0;
        $fail   = 0;
        $warn   = 0;

        foreach ($checks as $check) {
            if ('pass' === $check['status']) {
                ++$pass;
            } elseif ('fail' === $check['status']) {
                ++$fail;
            } elseif ('warning' === $check['status']) {
                ++$warn;
            }
        }

        $total = count($checks);

        $summary = $valid
            ? sprintf(
                'This image suits %s placement. %d of %d checks passed. Rating %d/100 (%s, %s).',
                $config['label'],
                $pass,
                $total,
                $rating['score'],
                $rating['grade'],
                $rating['label']
            )
            : sprintf(
                'This image does not suit %s placement. %d issue(s) need resolving. Rating %d/100 (%s, %s).',
                $config['label'],
                $fail,
                $rating['score'],
                $rating['grade'],
                $rating['label']
            );

        if ($warn > 0) {
            $summary .= sprintf(' %d warning(s) noted.', $warn);
        }

        $data = array(
            'valid'          => $valid,
            'product_type'   => $type,
            'product_label'  => $config['label'],
            'rating'         => $rating,
            'checks'         => $checks,
            'statistics'     => array(
                'total'    => $total,
                'passed'   => $pass,
                'failed'   => $fail,
                'warnings' => $warn,
            ),
            'preferred_pose' => $config['pose'],
            'mime'           => $image['mime'],
            'bytes'          => $image['size'],
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
     * A list of non-empty strings from a provider value.
     *
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
     * The pixel dimensions of an image from its bytes, or null.
     *
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
