<?php

/**
 * Tests for ToolResultSanitizer — the single reader for the model-facing copy.
 *
 * @package Nvoos\Core\Tests
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Chat;

use Nvoos\Core\Application\Chat\ToolResultSanitizer;
use Nvoos\Core\Domain\Contract\LlmSanitizerInterface;
use PHPUnit\Framework\TestCase;

final class ToolResultSanitizerTest extends TestCase
{
    private function declaringTool(mixed $copy): LlmSanitizerInterface
    {
        return new class ($copy) implements LlmSanitizerInterface {
            public function __construct(private mixed $copy)
            {
            }

            public function sanitizeForLlm(mixed $result): mixed
            {
                return $this->copy;
            }
        };
    }

    public function test_the_tools_declaration_owns_the_copy(): void
    {
        $sanitizer = new ToolResultSanitizer();

        $copy = $sanitizer->sanitize(
            array('message' => 'raw shape', 'verbose' => array('a' => 1)),
            $this->declaringTool(array('only' => 'this'))
        );

        $this->assertSame(array('only' => 'this'), $copy);
    }

    public function test_encoded_payloads_are_dropped_at_any_depth(): void
    {
        $sanitizer = new ToolResultSanitizer();

        $copy = $sanitizer->sanitize(array(
            'images' => array(
                array('b64_json' => str_repeat('A', 64), 'url' => 'https://example.test/a.png'),
            ),
            'audio' => array('audio_base64' => 'QUJD', 'duration' => 3),
        ));

        $this->assertSame(
            array(
                'images' => array(array('url' => 'https://example.test/a.png')),
                'audio' => array('duration' => 3),
            ),
            $copy
        );
    }

    public function test_the_legacy_top_level_keys_are_dropped_but_nested_data_survives(): void
    {
        $sanitizer = new ToolResultSanitizer();

        $copy = $sanitizer->sanitize(array(
            'base64' => 'QUJD',
            'data' => array('would-be-lost' => true),
            'keep' => 'yes',
            'wrap' => array('data' => array('nested' => 'kept')),
        ));

        $this->assertSame(
            array(
                'keep' => 'yes',
                'wrap' => array('data' => array('nested' => 'kept')),
            ),
            $copy
        );
    }

    public function test_oversized_strings_are_truncated_on_the_generic_path(): void
    {
        $sanitizer = new ToolResultSanitizer();

        $payload = str_repeat('x', 20000);
        $copy    = $sanitizer->sanitize(array('body' => $payload));

        $this->assertStringStartsWith(str_repeat('x', 200), $copy['body']);
        $this->assertStringContainsString('[truncated 20000 bytes]', $copy['body']);
    }

    public function test_a_declared_copy_keeps_its_long_strings_but_still_loses_payloads(): void
    {
        $sanitizer = new ToolResultSanitizer();
        $body      = str_repeat('y', 20000);

        $copy = $sanitizer->sanitize(
            array('raw' => true),
            $this->declaringTool(array('body' => $body, 'b64_json' => 'QUJD'))
        );

        $this->assertSame($body, $copy['body']);
        $this->assertArrayNotHasKey('b64_json', $copy);
    }

    public function test_the_raw_result_is_not_mutated(): void
    {
        $sanitizer = new ToolResultSanitizer();

        $raw = array(
            'images' => array(array('b64_json' => 'QUJD', 'url' => 'u')),
            'body' => str_repeat('z', 20000),
        );
        $snapshot = $raw;

        $sanitizer->sanitize($raw);

        // The orchestrator reads the raw result again after sanitizing — vision
        // extraction depends on it — so sanitising must copy, never edit.
        $this->assertSame($snapshot, $raw);
    }

    public function test_a_top_level_string_is_truncated_when_it_is_oversized(): void
    {
        $sanitizer = new ToolResultSanitizer();

        $copy = $sanitizer->sanitize(str_repeat('q', 12000));

        $this->assertIsString($copy);
        $this->assertStringContainsString('[truncated 12000 bytes]', $copy);
    }
}
