<?php
/**
 * Tests for the workflow template and condition resolver.
 *
 * @package Nvoos\Core\Tests
 * @since   2.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Workflow;

use Nvoos\Core\Application\Workflow\TemplateResolver;
use PHPUnit\Framework\TestCase;

class TemplateResolverTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function context(): array
    {
        return [
            'input' => [
                'topic' => 'Spring menu',
                'count' => 7,
                'urls' => ['https://example.test/a'],
            ],
            'nodes' => [
                'node_1' => ['post_id' => 42, 'is_valid' => true, 'broken_count' => 0],
                'node_2' => [
                    'pageviews' => 99,
                    'held' => [['lead_id' => 'lead-7', 'body' => 'Hello']],
                ],
            ],
        ];
    }

    public function test_a_single_template_preserves_the_value_type(): void
    {
        $this->assertSame(42, TemplateResolver::resolve('{{ node_1.post_id }}', $this->context()));
        $this->assertTrue(TemplateResolver::resolve('{{node_1.is_valid}}', $this->context()));
        $this->assertSame(
            ['https://example.test/a'],
            TemplateResolver::resolve('{{ input.urls }}', $this->context())
        );
    }

    public function test_embedded_templates_are_interpolated_as_text(): void
    {
        $this->assertSame(
            'Post 42 about Spring menu',
            TemplateResolver::resolve('Post {{ node_1.post_id }} about {{input.topic}}', $this->context())
        );
    }

    public function test_dotted_paths_walk_lists(): void
    {
        $this->assertSame('lead-7', TemplateResolver::resolve('{{ node_2.held.0.lead_id }}', $this->context()));
    }

    public function test_missing_paths_resolve_to_null(): void
    {
        $this->assertNull(TemplateResolver::resolve('{{ node_9.nope }}', $this->context()));
        $this->assertNull(TemplateResolver::resolve('{{ node_2.held.5.lead_id }}', $this->context()));
    }

    public function test_arrays_are_resolved_recursively(): void
    {
        $this->assertSame(
            ['post_id' => 42, 'nested' => ['topic' => 'Spring menu']],
            TemplateResolver::resolve(
                ['post_id' => '{{ node_1.post_id }}', 'nested' => ['topic' => '{{ input.topic }}']],
                $this->context()
            )
        );
    }

    public function test_expression_strict_equality(): void
    {
        $this->assertTrue(TemplateResolver::evaluate('node_1.is_valid === true', $this->context()));
        $this->assertFalse(TemplateResolver::evaluate('node_1.post_id === true', $this->context()));
        $this->assertTrue(TemplateResolver::evaluate('node_1.post_id !== true', $this->context()));
    }

    public function test_expression_ordering_is_numeric_when_both_sides_are(): void
    {
        $this->assertFalse(TemplateResolver::evaluate('node_1.broken_count > 0', $this->context()));
        $this->assertTrue(TemplateResolver::evaluate('node_2.pageviews < 100', $this->context()));
        $this->assertTrue(TemplateResolver::evaluate('node_2.pageviews >= 99', $this->context()));
    }

    public function test_expression_string_literals(): void
    {
        $this->assertTrue(TemplateResolver::evaluate("input.topic === 'Spring menu'", $this->context()));
        $this->assertFalse(TemplateResolver::evaluate('input.topic === "Other"', $this->context()));
    }

    public function test_a_bare_reference_is_a_truthiness_test(): void
    {
        $this->assertTrue(TemplateResolver::evaluate('node_1.is_valid', $this->context()));
        $this->assertFalse(TemplateResolver::evaluate('node_1.broken_count', $this->context()));
    }

    public function test_unparseable_expressions_return_null(): void
    {
        $this->assertNull(TemplateResolver::evaluate('', $this->context()));
        $this->assertNull(TemplateResolver::evaluate('phpinfo()', $this->context()));
        $this->assertNull(TemplateResolver::evaluate('1 + 2', $this->context()));
    }
}
