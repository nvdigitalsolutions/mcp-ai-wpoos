<?php

/**
 * The `math` family, third slice: the LaTeX renderer.
 *
 * Proposal 164 section 164.167. What is pinned: the expression reaches the
 * renderer unmodified, because `wp_kses_post` used to escape angle brackets and
 * corrupt comparisons; `display_mode` and `output_format` reach the renderer,
 * because WordPress sent the route options it does not read; an unavailable
 * renderer is named rather than discovered by failing; a macro map is refused
 * rather than passed to a route that ignores it; and the quiz association
 * appends across calls instead of replacing, after checking the item exists.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use DateTimeImmutable;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\MathRendererInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Tool\Math\RenderMathEquationTool;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MathRenderEquationTest extends TestCase
{
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
            static fn (string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'not_found', 'message' => $message, 'data' => $data),
            )
        );
    }

    public function test_metadata(): void
    {
        $tool = $this->tool();

        $this->assertSame('render_math_equation', $tool->getSlug());
        $this->assertSame('edit_posts', $tool->getRequiredCapability());
        $this->assertSame(array('latex'), $tool->getParametersSchema()['required']);
    }

    public function test_it_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->tool()->execute(array('latex' => 'x^2'));
    }

    public function test_it_renders_html(): void
    {
        $result = $this->call(array('latex' => 'x^2'), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame('<span class="katex">x^2</span>', $result['data']['markup']);
        $this->assertSame('x^2', $result['data']['latex']);
        $this->assertSame('html', $result['data']['output_format']);
        $this->assertFalse($result['data']['display_mode']);
        $this->assertFalse($result['data']['cached']);
        $this->assertIsFloat($result['data']['render_time_ms']);
        $this->assertArrayNotHasKey('quiz_id', $result['data']);
    }

    public function test_display_mode_reaches_the_renderer(): void
    {
        $renderer = $this->createMock(MathRendererInterface::class);
        $renderer->method('isAvailable')->willReturn(true);
        $renderer->expects($this->once())
            ->method('render')
            ->with('x^2', true, 'html')
            ->willReturn(array('markup' => '<b>x</b>', 'latex' => 'x^2'));

        $result = $this->call(
            array('latex' => 'x^2', 'display_mode' => true),
            array('user_id' => 7),
            $renderer
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['display_mode']);
    }

    public function test_the_output_format_reaches_the_renderer(): void
    {
        $renderer = $this->createMock(MathRendererInterface::class);
        $renderer->method('isAvailable')->willReturn(true);
        $renderer->expects($this->once())
            ->method('render')
            ->with('x^2', false, 'html_and_mathml')
            ->willReturn(array('markup' => '<b>x</b>', 'latex' => 'x^2'));

        $result = $this->call(
            array('latex' => 'x^2', 'output_format' => 'html_and_mathml'),
            array('user_id' => 7),
            $renderer
        );

        $this->assertSame('html_and_mathml', $result['data']['output_format']);
    }

    public function test_the_output_format_is_case_insensitive(): void
    {
        $result = $this->call(
            array('latex' => 'x^2', 'output_format' => 'MathML'),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame('mathml', $result['data']['output_format']);
    }

    public function test_an_unknown_output_format_is_refused(): void
    {
        $renderer = $this->createMock(MathRendererInterface::class);
        $renderer->expects($this->never())->method('render');

        $result = $this->call(
            array('latex' => 'x^2', 'output_format' => 'latex'),
            array('user_id' => 7),
            $renderer
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('output_format', $result['error']['data']);
    }

    public function test_a_missing_expression_is_refused(): void
    {
        $result = $this->call(array(), array('user_id' => 7));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('latex', $result['error']['data']);
    }

    public function test_a_non_string_expression_is_refused(): void
    {
        $result = $this->call(array('latex' => array('x^2')), array('user_id' => 7));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('latex', $result['error']['data']);
    }

    public function test_an_oversized_expression_is_refused(): void
    {
        $result = $this->call(
            array('latex' => str_repeat('x', RenderMathEquationTool::MAX_LATEX_LENGTH + 1)),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('20000', $result['error']['data']['latex'][0]);
    }

    public function test_a_macro_map_is_refused_rather_than_ignored(): void
    {
        $renderer = $this->createMock(MathRendererInterface::class);
        $renderer->expects($this->never())->method('render');

        $result = $this->call(
            array('latex' => 'x^2', 'macros' => array('\\RR' => '\\mathbb{R}')),
            array('user_id' => 7),
            $renderer
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ignored', $result['error']['data']['macros'][0]);
    }

    public function test_an_empty_macro_map_is_accepted(): void
    {
        $result = $this->call(array('latex' => 'x^2', 'macros' => array()), array('user_id' => 7));

        $this->assertTrue($result['success']);
    }

    public function test_a_non_array_macro_map_is_refused(): void
    {
        $result = $this->call(array('latex' => 'x^2', 'macros' => 'x'), array('user_id' => 7));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('macros', $result['error']['data']);
    }

    public function test_an_unavailable_renderer_is_named(): void
    {
        $result = $this->call(
            array('latex' => 'x^2'),
            array('user_id' => 7),
            $this->renderer(false)
        );

        $this->assertFalse($result['success']);
        $this->assertSame('wp_mcp_ai_engine_missing', $result['error']['code']);
        $this->assertSame('katex', $result['error']['data']['engine']);
    }

    public function test_a_renderer_failure_is_reported(): void
    {
        $renderer = $this->createMock(MathRendererInterface::class);
        $renderer->method('isAvailable')->willReturn(true);
        $renderer->method('render')->willThrowException(new RuntimeException('the worker is unreachable'));

        $result = $this->call(array('latex' => 'x^2'), array('user_id' => 7), $renderer);

        $this->assertFalse($result['success']);
        $this->assertSame('wp_mcp_ai_render_failed', $result['error']['code']);
        $this->assertStringContainsString('unreachable', $result['error']['message']);
        $this->assertSame('x^2', $result['error']['data']['latex']);
    }

    public function test_the_equation_is_appended_to_the_quiz(): void
    {
        $store = new InMemoryContentStore();
        $store->add($this->quiz(40));

        $result = $this->call(
            array('latex' => 'x^2', 'quiz_id' => 40),
            array('user_id' => 7),
            null,
            $store
        );

        $this->assertTrue($result['success']);
        $this->assertSame(40, $result['data']['quiz_id']);
        $this->assertSame(1, $result['data']['quiz_equations']);

        $entries = $store->find(40)->meta[RenderMathEquationTool::QUIZ_META_KEY];
        $this->assertCount(1, $entries);
        $this->assertSame('x^2', $entries[0]['latex']);
        $this->assertSame('<span class="katex">x^2</span>', $entries[0]['markup']);
        $this->assertArrayHasKey('created_at', $entries[0]);
    }

    public function test_a_second_render_appends_to_the_same_quiz(): void
    {
        $store = new InMemoryContentStore();
        $store->add($this->quiz(40));

        $this->call(array('latex' => 'x^2', 'quiz_id' => 40), array('user_id' => 7), null, $store);
        $result = $this->call(array('latex' => 'y^2', 'quiz_id' => 40), array('user_id' => 7), null, $store);

        $this->assertSame(2, $result['data']['quiz_equations']);

        $entries = $store->find(40)->meta[RenderMathEquationTool::QUIZ_META_KEY];
        $this->assertSame('x^2', $entries[0]['latex'], 'the first equation survives the second write');
        $this->assertSame('y^2', $entries[1]['latex']);
    }

    public function test_an_unknown_quiz_is_not_found(): void
    {
        $store = new InMemoryContentStore();

        $result = $this->call(
            array('latex' => 'x^2', 'quiz_id' => 99),
            array('user_id' => 7),
            null,
            $store
        );

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['error']['code']);
        $this->assertSame(99, $result['error']['data']['quiz_id']);
    }

    public function test_the_quiz_is_checked_before_the_render(): void
    {
        $renderer = $this->createMock(MathRendererInterface::class);
        $renderer->method('isAvailable')->willReturn(true);
        $renderer->expects($this->never())->method('render');

        $result = $this->call(
            array('latex' => 'x^2', 'quiz_id' => 99),
            array('user_id' => 7),
            $renderer,
            new InMemoryContentStore()
        );

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['error']['code']);
    }

    public function test_a_quiz_without_a_store_is_named(): void
    {
        $result = $this->call(array('latex' => 'x^2', 'quiz_id' => 40), array('user_id' => 7));

        $this->assertFalse($result['success']);
        $this->assertSame('wp_mcp_ai_quiz_store_unavailable', $result['error']['code']);
    }

    public function test_a_malformed_quiz_id_is_refused(): void
    {
        $result = $this->call(array('latex' => 'x^2', 'quiz_id' => 'abc'), array('user_id' => 7));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('quiz_id', $result['error']['data']);
    }

    private function tool(
        ?MathRendererInterface $renderer = null,
        ?ContentStoreInterface $content = null
    ): RenderMathEquationTool {
        return new RenderMathEquationTool(
            $this->errors,
            $renderer ?? $this->renderer(),
            $content
        );
    }

    private function renderer(bool $available = true): MathRendererInterface
    {
        $renderer = $this->createMock(MathRendererInterface::class);
        $renderer->method('isAvailable')->willReturn($available);
        $renderer->method('render')->willReturn(
            array('markup' => '<span class="katex">x^2</span>', 'latex' => 'x^2')
        );

        return $renderer;
    }

    private function quiz(int $id): ContentItem
    {
        return new ContentItem(
            id: $id,
            title: 'Quiz',
            content: '',
            status: 'publish',
            type: 'mcp_ai_quiz',
            authorId: 7,
            createdAt: new DateTimeImmutable('2026-01-01T09:00:00+00:00'),
            updatedAt: new DateTimeImmutable('2026-01-01T09:00:00+00:00'),
        );
    }

    /**
     * @param array<mixed, mixed> $arguments Tool arguments.
     * @param array<mixed, mixed> $context   Execution context.
     *
     * @return array<string, mixed>
     */
    private function call(
        array $arguments,
        array $context,
        ?MathRendererInterface $renderer = null,
        ?ContentStoreInterface $content = null
    ): array {
        /** @var array<string, mixed> $result */
        $result = $this->tool($renderer, $content)->execute($arguments, $context);

        return $result;
    }
}
