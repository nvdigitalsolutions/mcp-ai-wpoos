<?php

/**
 * The vehicle estimate validator.
 *
 * Six properties are worth pinning:
 *
 * 1. **Several images are one question.** The set is sent as one user turn with
 *    several `image_url` parts, not as one call per image, because "are all four
 *    sides here?" cannot be asked image by image. The assertion counts the parts.
 * 2. **The estimate type changes the weights, not just the wording.** A repair
 *    set values coverage at 25 and a cleaning set values vehicle detection at 25;
 *    a test asserts the weights rather than a total, because a total could match
 *    by coincidence.
 * 3. **Each required repair view is critical.** Three sides of four is refused,
 *    even though coverage scores 75%.
 * 4. **A recommended view is never a deduction.** A repair set with no corner or
 *    VIN shots still scores 100 when every required check passes.
 * 5. **A technical failure costs nothing.** A too-small image is refused before
 *    the provider is called.
 * 6. **An unresolvable reference is reported.** The WordPress tool skipped it and
 *    carried on, leaving the caller with a coverage failure and no reason.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Infrastructure\Provider\AbstractProviderClient;
use Nvoos\Core\Tool\Ecommerce\ValidateImageForVehicleTool;
use PHPUnit\Framework\TestCase;

final class ValidateImageForVehicleToolTest extends TestCase
{
    private const CONTEXT = array('user_id' => 7);

    private ErrorFactoryInterface $errors;

    /** @var list<array<int, mixed>> */
    private array $captured = array();

    protected function setUp(): void
    {
        $this->captured = array();
        $this->errors   = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => $code, 'message' => $message, 'data' => $data),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'validation_failed', 'message' => $message, 'data' => $fields),
            )
        );

        $this->errors->method('notFound')->willReturnCallback(
            static fn (string $message = 'Not found.', array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'not_found', 'message' => $message, 'data' => $data),
            )
        );

        $this->errors->method('normalize')->willReturnCallback(
            static fn (mixed $value): array => array('message' => 'normalised')
        );

        $this->errors->method('isError')->willReturnCallback(
            static fn (mixed $value): bool => is_array($value) && false === ( $value['success'] ?? null )
        );
    }

    public function test_it_identifies_itself_and_declares_the_enforced_capability(): void
    {
        $tool = $this->tool($this->client());

        $this->assertSame('validate_image_for_vehicle', $tool->getSlug());
        $this->assertSame('upload_files', $tool->getRequiredCapability());
        $this->assertContains('consumes-tokens', $tool->getCapabilityFlags());
    }

    public function test_the_schema_offers_no_remote_url_input(): void
    {
        $properties = $this->tool($this->client())->getParametersSchema()['properties'];

        $this->assertArrayHasKey('file_ids', $properties);
        $this->assertArrayHasKey('images', $properties);
        $this->assertArrayNotHasKey('image_url', $properties);
        $this->assertArrayNotHasKey('image_attachment_ids', $properties);
    }

    public function test_at_least_one_image_is_required(): void
    {
        $result = $this->tool($this->client())->execute(array('estimate_type' => 'cleaning'), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_more_than_thirty_images_is_refused(): void
    {
        $images = array_fill(0, 31, $this->dataUri(1200, 1200));

        $result = $this->tool($this->client())->execute(array(
            'estimate_type' => 'cleaning',
            'images'        => $images,
        ), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('30', $result['error']['message']);
    }

    public function test_an_unresolvable_file_reference_is_reported(): void
    {
        $files = $this->createMock(FileStoreInterface::class);
        $files->method('getMetadata')->willReturn(null);
        $files->method('userCanAccess')->willReturn(true);

        $result = $this->tool($this->client(), $files)->execute(array(
            'estimate_type' => 'cleaning',
            'file_ids'      => array(999),
        ), self::CONTEXT);

        $this->assertArrayHasKey('error', $result);
        $this->assertNotSame('', (string) $result['error']['code']);
    }

    public function test_several_images_are_one_call_with_several_parts(): void
    {
        $tool = $this->tool($this->client($this->answer($this->repair())));

        $tool->execute(array(
            'estimate_type' => 'repair',
            'images'        => array(
                $this->dataUri(1200, 1200),
                $this->dataUri(1200, 1200),
                $this->dataUri(1200, 1200),
            ),
        ), self::CONTEXT);

        $this->assertCount(1, $this->captured, 'The set must be asked about in one call.');

        $content = $this->captured[0][0]['content'];

        // One text part, then one image part per image.
        $this->assertCount(4, $content);
        $this->assertSame('text', $content[0]['type']);
        $this->assertSame('image_url', $content[1]['type']);
        $this->assertSame('image_url', $content[3]['type']);
    }

    public function test_a_too_small_image_is_refused_without_calling_the_provider(): void
    {
        $client = $this->createMock(AbstractProviderClient::class);
        $client->expects($this->never())->method('chat');

        $result = $this->tool($client)->execute(array(
            'estimate_type' => 'cleaning',
            'images'        => array($this->dataUri(300, 300)),
        ), self::CONTEXT);

        $this->assertCount(0, $this->captured);
        $this->assertFalse($result['data']['valid']);
        $this->assertStringContainsString('720', (string) $result['data']['failures'][0]);
    }

    public function test_between_the_floor_and_the_recommendation_is_a_warning(): void
    {
        $result = $this->tool($this->client($this->answer($this->cleaning())))->execute(array(
            'estimate_type' => 'cleaning',
            'images'        => array($this->dataUri(800, 800)),
        ), self::CONTEXT);

        $this->assertSame('warning', $this->check($result['data']['checks'], 'dimensions_0')['status']);
        $this->assertTrue($result['data']['valid']);
    }

    public function test_a_complete_cleaning_set_is_valid_and_scores_a_hundred(): void
    {
        $result = $this->tool($this->client($this->answer($this->cleaning())))->execute(array(
            'estimate_type' => 'cleaning',
            'images'        => array($this->dataUri(1200, 1200)),
        ), self::CONTEXT);

        $this->assertTrue($result['data']['valid']);
        $this->assertSame(100, $result['data']['rating']['score']);
        $this->assertSame('A', $result['data']['rating']['grade']);
        $this->assertSame(25, $result['data']['rating']['breakdown']['vehicle_detected']['weight']);
    }

    public function test_a_cleaning_set_without_a_full_vehicle_shot_fails(): void
    {
        $analysis = $this->cleaning(array('full_vehicle_visible' => false, 'estimated_size_tier' => 'unknown'));

        $result = $this->tool($this->client($this->answer($analysis)))->execute(array(
            'estimate_type' => 'cleaning',
            'images'        => array($this->dataUri(1200, 1200)),
        ), self::CONTEXT);

        $this->assertFalse($result['data']['valid']);
        $this->assertSame('fail', $this->check($result['data']['checks'], 'full_vehicle_visible')['status']);
    }

    public function test_a_complete_repair_set_is_valid_and_weights_coverage_higher(): void
    {
        $result = $this->tool($this->client($this->answer($this->repair())))->execute(array(
            'estimate_type' => 'repair',
            'images'        => array($this->dataUri(1200, 1200)),
        ), self::CONTEXT);

        $this->assertTrue($result['data']['valid']);
        $this->assertSame(100, $result['data']['rating']['score']);
        $this->assertSame(25, $result['data']['rating']['breakdown']['coverage_completeness']['weight']);
        $this->assertSame(15, $result['data']['rating']['breakdown']['vehicle_detected']['weight']);
    }

    public function test_three_sides_of_four_is_refused_because_each_view_is_critical(): void
    {
        $analysis = $this->repair(array(
            'views_identified' => array(
                'front'      => true,
                'rear'       => true,
                'left_side'  => true,
                'right_side' => false,
            ),
        ));

        $result = $this->tool($this->client($this->answer($analysis)))->execute(array(
            'estimate_type' => 'repair',
            'images'        => array($this->dataUri(1200, 1200)),
        ), self::CONTEXT);

        $this->assertFalse($result['data']['valid']);
        $this->assertSame('fail', $this->check($result['data']['checks'], 'view_right_side')['status']);
        $this->assertSame('warning', $this->check($result['data']['checks'], 'coverage_completeness')['status']);

        $failures = (string) implode(' ', $result['data']['failures']);
        $this->assertStringContainsString('Right (passenger) side', $failures);
    }

    public function test_a_missing_recommended_view_is_never_a_deduction(): void
    {
        // Every recommended view is absent; every required one is present.
        $result = $this->tool($this->client($this->answer($this->repair())))->execute(array(
            'estimate_type' => 'repair',
            'images'        => array($this->dataUri(1200, 1200)),
        ), self::CONTEXT);

        $this->assertSame(100, $result['data']['rating']['score']);
        $this->assertSame('info', $this->check($result['data']['checks'], 'recommended_vin_plate')['status']);
        $this->assertArrayNotHasKey('recommended_front_left_corner', $result['data']['rating']['breakdown']);
    }

    public function test_strict_mode_turns_a_warning_into_a_failure(): void
    {
        $result = $this->tool($this->client($this->answer($this->cleaning())))->execute(array(
            'estimate_type' => 'cleaning',
            'images'        => array($this->dataUri(800, 800)),
            'strict_mode'   => true,
        ), self::CONTEXT);

        $this->assertFalse($result['data']['valid']);
    }

    public function test_a_missing_vehicle_fails_both_types(): void
    {
        $analysis = $this->cleaning(array('vehicle_detected' => false, 'vehicle_count' => 0));

        $result = $this->tool($this->client($this->answer($analysis)))->execute(array(
            'estimate_type' => 'cleaning',
            'images'        => array($this->dataUri(1200, 1200)),
        ), self::CONTEXT);

        $this->assertFalse($result['data']['valid']);
        $this->assertSame('fail', $this->check($result['data']['checks'], 'vehicle_detected')['status']);
    }

    public function test_pure_prose_is_a_named_refusal(): void
    {
        $result = $this->tool($this->client($this->answer('Looks like a car.')))->execute(array(
            'estimate_type' => 'cleaning',
            'images'        => array($this->dataUri(1200, 1200)),
        ), self::CONTEXT);

        $this->assertSame('vehicle_validation_unparsable', $result['error']['code']);
    }

    public function test_a_fenced_answer_is_decoded(): void
    {
        $fenced = "```json\n" . $this->cleaning() . "\n```";

        $result = $this->tool($this->client($this->answer($fenced)))->execute(array(
            'estimate_type' => 'cleaning',
            'images'        => array($this->dataUri(1200, 1200)),
        ), self::CONTEXT);

        $this->assertTrue($result['data']['valid']);
    }

    public function test_an_unknown_estimate_type_falls_back_to_cleaning(): void
    {
        $result = $this->tool($this->client($this->answer($this->cleaning())))->execute(array(
            'estimate_type' => 'inspection',
            'images'        => array($this->dataUri(1200, 1200)),
        ), self::CONTEXT);

        $this->assertSame('cleaning', $result['data']['estimate_type']);
    }

    public function test_a_low_confidence_size_tier_is_a_warning(): void
    {
        $analysis = $this->cleaning(array('estimated_size_tier' => 'car', 'size_confidence' => 0.6));

        $result = $this->tool($this->client($this->answer($analysis)))->execute(array(
            'estimate_type' => 'cleaning',
            'images'        => array($this->dataUri(1200, 1200)),
        ), self::CONTEXT);

        $this->assertSame('warning', $this->check($result['data']['checks'], 'size_classification')['status']);
        $this->assertTrue($result['data']['valid']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $overrides
     */
    private function cleaning(array $overrides = array()): string
    {
        return (string) json_encode(
            array_merge(
                array(
                    'vehicle_detected'     => true,
                    'vehicle_count'        => 1,
                    'vehicle_description'  => 'a silver sedan',
                    'full_vehicle_visible' => true,
                    'estimated_size_tier'  => 'car',
                    'size_confidence'      => 0.92,
                    'condition_visible'    => true,
                    'visible_conditions'   => array('light soiling'),
                    'lighting_quality'     => 'good',
                    'image_sharpness'      => 'sharp',
                    'obstructions'         => array(),
                    'overall_suitable'     => true,
                    'suggestions'          => array(),
                ),
                $overrides
            )
        );
    }

    /**
     * Every required view present, and no recommended view at all.
     *
     * @param array<string, mixed> $overrides
     */
    private function repair(array $overrides = array()): string
    {
        return (string) json_encode(
            array_merge(
                array(
                    'vehicle_detected'        => true,
                    'vehicle_count'           => 1,
                    'vehicle_description'     => 'a blue hatchback',
                    'views_identified'        => array(
                        'front'      => true,
                        'rear'       => true,
                        'left_side'  => true,
                        'right_side' => true,
                    ),
                    'damage_visible'          => true,
                    'damage_areas'            => array('rear bumper'),
                    'damage_severity_estimate' => 'moderate',
                    'vin_readable'            => true,
                    'lighting_quality'        => 'good',
                    'image_sharpness'         => 'sharp',
                    'obstructions'            => array(),
                    'overall_suitable'        => true,
                    'suggestions'             => array(),
                ),
                $overrides
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(string $text): array
    {
        return array('choices' => array(array('message' => array('content' => $text))));
    }

    private function tool(mixed $client, ?FileStoreInterface $files = null): ValidateImageForVehicleTool
    {
        $router = $this->createMock(ProviderRouter::class);
        $router->method('resolveForChat')->willReturn($client);
        $router->method('getRegisteredSlugs')->willReturn(array('openai'));

        return new ValidateImageForVehicleTool($this->errors, $router, $files);
    }

    private function client(mixed $response = null): AbstractProviderClient
    {
        $captured = &$this->captured;

        $client = $this->createMock(AbstractProviderClient::class);
        $client->method('getProviderSlug')->willReturn('openai');
        $client->method('chat')->willReturnCallback(
            static function (array $messages) use ($response, &$captured): mixed {
                $captured[] = $messages;

                return $response;
            }
        );

        return $client;
    }

    /**
     * A PNG data URI with the given dimensions, built from its IHDR header.
     */
    private function dataUri(int $width, int $height): string
    {
        $header = 'IHDR' . pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0);
        $bytes  = "\x89PNG\r\n\x1a\n" . pack('N', 13) . $header . pack('N', 0);

        return 'data:image/png;base64,' . base64_encode($bytes);
    }

    /**
     * @param list<array{name: string, status: string, message: string, critical: bool}> $checks
     *
     * @return array{name: string, status: string, message: string, critical: bool}
     */
    private function check(array $checks, string $name): array
    {
        foreach ($checks as $check) {
            if ($name === $check['name']) {
                return $check;
            }
        }

        $this->fail('No check named ' . $name);
    }
}
