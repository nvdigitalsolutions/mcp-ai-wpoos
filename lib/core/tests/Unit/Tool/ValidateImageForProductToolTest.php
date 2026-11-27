<?php

/**
 * The product try-on validator.
 *
 * Five properties are worth pinning, and each is a way the WordPress original
 * could have been ported badly:
 *
 * 1. **A technical failure costs nothing.** A 300 px image must be refused before
 *    the provider is called, not after paying for a token spend that cannot
 *    change the answer. The assertion is `chat` never invoked.
 * 2. **The per-type table is the domain knowledge.** `watch` requires a wrist and
 *    a hand; a photo with neither must fail, and the failure must name the part.
 * 3. **A warning is not a failure** — unless `strict_mode` says it is.
 * 4. **The rating is weighted, not counted.** Ten passing checks out of eleven do
 *    not score 91/100, because a missing wrist outweighs a missing forearm. A
 *    perfect analysis scores 100 and grades A.
 * 5. **A prose answer is a refusal, not a crash.** Core's completion path carries
 *    no `response_format`, so the model may answer with a fence or with chatter
 *    around the JSON; the fence is tolerated and pure prose is reported.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Infrastructure\Provider\AbstractProviderClient;
use Nvoos\Core\Tool\Ecommerce\ValidateImageForProductTool;
use PHPUnit\Framework\TestCase;

final class ValidateImageForProductToolTest extends TestCase
{
    private const CONTEXT = array('user_id' => 7);

    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

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

    public function test_it_identifies_itself_as_a_vision_validator(): void
    {
        $tool = $this->tool($this->client());

        $this->assertSame('validate_image_for_product', $tool->getSlug());
        $this->assertSame('upload_files', $tool->getRequiredCapability());
        $this->assertContains('consumes-tokens', $tool->getCapabilityFlags());
        $this->assertContains('read-only', $tool->getCapabilityFlags());
    }

    public function test_the_schema_offers_no_remote_url_input(): void
    {
        $properties = $this->tool($this->client())->getParametersSchema()['properties'];

        $this->assertArrayHasKey('file_id', $properties);
        $this->assertArrayHasKey('image_data', $properties);
        $this->assertArrayNotHasKey('image_url', $properties);
        $this->assertArrayNotHasKey('url', $properties);
    }

    public function test_a_too_small_image_is_refused_without_calling_the_provider(): void
    {
        $client = $this->createMock(AbstractProviderClient::class);
        $client->expects($this->never())->method('chat');

        $result = $this->tool($client)->execute(array(
            'product_type' => 'watch',
            'image_data'   => $this->dataUri(300, 300),
        ), self::CONTEXT);

        $this->assertFalse($result['data']['valid']);
        $this->assertSame('file_format', $result['data']['checks'][0]['name']);
        $this->assertStringContainsString('at least 512', (string) $result['data']['failures'][0]);
    }

    public function test_the_dimension_floor_is_the_shortest_side(): void
    {
        $result = $this->tool($this->client($this->answer($this->analysis())))->execute(array(
            'product_type' => 'watch',
            'image_data'   => $this->dataUri(2000, 300),
        ), self::CONTEXT);

        $this->assertFalse($result['data']['valid']);
        $this->assertStringContainsString('shortest side', (string) $result['data']['failures'][0]);
    }

    public function test_between_the_floor_and_the_type_minimum_is_a_warning(): void
    {
        $result = $this->tool($this->client($this->answer($this->analysis())))->execute(array(
            'product_type' => 'watch',
            'image_data'   => $this->dataUri(600, 600),
        ), self::CONTEXT);

        $dimensions = $this->check($result['data']['checks'], 'dimensions');

        $this->assertSame('warning', $dimensions['status']);
        $this->assertTrue($result['data']['valid']);
    }

    public function test_strict_mode_turns_a_warning_into_a_failure(): void
    {
        $result = $this->tool($this->client($this->answer($this->analysis())))->execute(array(
            'product_type' => 'watch',
            'image_data'   => $this->dataUri(600, 600),
            'strict_mode'  => true,
        ), self::CONTEXT);

        $this->assertFalse($result['data']['valid']);
    }

    public function test_a_complete_analysis_is_valid_and_scores_a_hundred(): void
    {
        $result = $this->tool($this->client($this->answer($this->analysis())))->execute(array(
            'product_type' => 'watch',
            'image_data'   => $this->dataUri(1024, 1024),
        ), self::CONTEXT);

        $this->assertTrue($result['data']['valid']);
        $this->assertSame(100, $result['data']['rating']['score']);
        $this->assertSame('A', $result['data']['rating']['grade']);
        $this->assertSame('pass', $this->check($result['data']['checks'], 'body_part_wrist')['status']);
    }

    public function test_a_missing_required_part_fails_and_names_the_part(): void
    {
        $analysis = $this->analysis(array('required_parts_visible' => array('wrist' => false, 'hand' => true)));

        $result = $this->tool($this->client($this->answer($analysis)))->execute(array(
            'product_type' => 'watch',
            'image_data'   => $this->dataUri(1024, 1024),
        ), self::CONTEXT);

        $this->assertFalse($result['data']['valid']);
        $this->assertSame('fail', $this->check($result['data']['checks'], 'body_part_wrist')['status']);
        $this->assertStringContainsString('wrist', (string) $result['data']['failures'][0]);
    }

    public function test_no_person_detected_fails(): void
    {
        $analysis = $this->analysis(array('person_detected' => false, 'person_count' => 0));

        $result = $this->tool($this->client($this->answer($analysis)))->execute(array(
            'product_type' => 'ring',
            'image_data'   => $this->dataUri(1024, 1024),
        ), self::CONTEXT);

        $this->assertFalse($result['data']['valid']);
        $this->assertSame('fail', $this->check($result['data']['checks'], 'person_detected')['status']);
    }

    public function test_a_missing_required_part_costs_its_weight_share(): void
    {
        // `person_detected` scores in the same category as the body parts — that
        // is the WordPress mapping — so the category averages three checks and a
        // single missing part leaves it at two thirds, not one half.
        $analysis = $this->analysis(array('required_parts_visible' => array('wrist' => false, 'hand' => true)));

        $result = $this->tool($this->client($this->answer($analysis)))->execute(array(
            'product_type' => 'watch',
            'image_data'   => $this->dataUri(1024, 1024),
        ), self::CONTEXT);

        $required = $result['data']['rating']['breakdown']['required_body_parts'];

        $this->assertSame(35, $required['weight']);
        $this->assertSame(66.7, $required['category_score']);
        $this->assertSame(23.3, $required['weighted_score']);
        $this->assertLessThan(100, $result['data']['rating']['score']);
    }

    public function test_a_poor_lighting_and_blur_lower_the_grade(): void
    {
        $analysis = $this->analysis(array(
            'lighting_quality'  => 'poor',
            'image_sharpness'   => 'blurry',
            'pose_suitable'     => false,
            'obstructions'      => array('hair covering ear'),
            'existing_accessories' => array('watch on left wrist'),
        ), self::CONTEXT);

        $result = $this->tool($this->client($this->answer($analysis)))->execute(array(
            'product_type' => 'watch',
            'image_data'   => $this->dataUri(1024, 1024),
        ), self::CONTEXT);

        $this->assertTrue($result['data']['valid']);
        $this->assertLessThan(100, $result['data']['rating']['score']);
        $this->assertSame('warning', $this->check($result['data']['checks'], 'lighting')['status']);
        $this->assertSame('warning', $this->check($result['data']['checks'], 'sharpness')['status']);
    }

    public function test_the_model_suggestions_are_returned(): void
    {
        $analysis = $this->analysis(array('suggestions' => array('Tuck your hair behind your ear.')));

        $result = $this->tool($this->client($this->answer($analysis)))->execute(array(
            'product_type' => 'earring',
            'image_data'   => $this->dataUri(1024, 1024),
        ), self::CONTEXT);

        $this->assertSame(array('Tuck your hair behind your ear.'), $result['data']['suggestions']);
    }

    public function test_a_fenced_answer_is_decoded(): void
    {
        $fenced = "```json\n" . $this->analysis() . "\n```";

        $result = $this->tool($this->client($this->answer($fenced)))->execute(array(
            'product_type' => 'watch',
            'image_data'   => $this->dataUri(1024, 1024),
        ), self::CONTEXT);

        $this->assertTrue($result['data']['valid']);
    }

    public function test_an_answer_wrapped_in_prose_is_decoded(): void
    {
        $chatty = 'Sure, here is the result: ' . $this->analysis() . ' Hope that helps.';

        $result = $this->tool($this->client($this->answer($chatty)))->execute(array(
            'product_type' => 'watch',
            'image_data'   => $this->dataUri(1024, 1024),
        ), self::CONTEXT);

        $this->assertTrue($result['data']['valid']);
    }

    public function test_pure_prose_is_a_named_refusal(): void
    {
        $result = $this->tool($this->client($this->answer('I think it looks fine.')))->execute(array(
            'product_type' => 'watch',
            'image_data'   => $this->dataUri(1024, 1024),
        ), self::CONTEXT);

        $this->assertSame('product_validation_unparsable', $result['error']['code']);
    }

    public function test_an_unknown_product_type_falls_back_to_general(): void
    {
        $result = $this->tool($this->client($this->answer($this->analysis())))->execute(array(
            'product_type' => 'spacesuit',
            'image_data'   => $this->dataUri(1024, 1024),
        ), self::CONTEXT);

        $this->assertSame('general', $result['data']['product_type']);
        $this->assertSame('General Accessory', $result['data']['product_label']);
    }

    public function test_a_general_image_does_not_lose_points_for_absent_optional_parts(): void
    {
        $result = $this->tool($this->client($this->answer($this->analysis())))->execute(array(
            'product_type' => 'general',
            'image_data'   => $this->dataUri(1024, 1024),
        ), self::CONTEXT);

        $this->assertSame(100, $result['data']['rating']['score']);
        $this->assertSame(100.0, $result['data']['rating']['breakdown']['optional_body_parts']['category_score']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $overrides
     */
    private function analysis(array $overrides = array()): string
    {
        $parts = array(
            'wrist'       => true,
            'hand'        => true,
            'fingers'     => true,
            'ear'         => true,
            'face'        => true,
            'neck'        => true,
            'upper_chest' => true,
            'eyes'        => true,
            'ears'        => true,
            'head'        => true,
            'shoulder'    => true,
            'arm'         => true,
            'person'      => true,
        );

        $encoded = json_encode(
            array_merge(
                array(
                    'person_detected'        => true,
                    'person_count'           => 1,
                    'required_parts_visible' => $parts,
                    'optional_parts_visible' => array('forearm' => true, 'jawline' => true, 'neck_optional' => true),
                    'obstructions'           => array(),
                    'lighting_quality'       => 'good',
                    'image_sharpness'        => 'sharp',
                    'background_complexity'  => 'simple',
                    'existing_accessories'   => array(),
                    'pose_suitable'          => true,
                    'overall_suitable'       => true,
                    'suggestions'            => array(),
                ),
                $overrides
            )
        );

        return (string) $encoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(string $text): array
    {
        return array('choices' => array(array('message' => array('content' => $text))));
    }

    private function tool(mixed $client): ValidateImageForProductTool
    {
        $router = $this->createMock(ProviderRouter::class);
        $router->method('resolveForChat')->willReturn($client);
        $router->method('getRegisteredSlugs')->willReturn(array('openai'));

        return new ValidateImageForProductTool($this->errors, $router);
    }

    private function client(mixed $response = null): AbstractProviderClient
    {
        $client = $this->createMock(AbstractProviderClient::class);
        $client->method('getProviderSlug')->willReturn('openai');
        $client->method('chat')->willReturn($response);

        return $client;
    }

    /**
     * A PNG data URI with the given dimensions, built from its IHDR header.
     *
     * The runtime image reader needs only the signature and the header, and GD is
     * not guaranteed, so this carries no binary fixture.
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
