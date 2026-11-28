<?php

/**
 * The `math` family, fifth slice: graph_function.
 *
 * Proposal 164 section 164.169. What is pinned here is mostly what the
 * WordPress tool got wrong: the expression reaches the renderer unmodified,
 * `'false'` does not mean true, a requested size outside the schema is refused
 * rather than clamped to a million pixels, an unusable colour is reported
 * instead of becoming null, and a window that cannot be plotted is a validation
 * failure rather than a success with no image.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use InvalidArgumentException;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\GraphRendererInterface;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\ValueObject\GraphRequest;
use Nvoos\Core\Tests\Support\FakeFileStore;
use Nvoos\Core\Tool\Math\GraphFunctionTool;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GraphFunctionToolTest extends TestCase
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
    }

    public function test_the_tool_metadata(): void
    {
        $tool = $this->tool();

        $this->assertSame('graph_function', $tool->getSlug());
        $this->assertSame('upload_files', $tool->getRequiredCapability());
        $this->assertSame(array('function'), $tool->getParametersSchema()['required']);
    }

    public function test_it_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->tool()->execute(array('function' => 'x^2'), array());
    }

    public function test_it_requires_a_function(): void
    {
        $result = $this->tool()->execute(array(), $this->context());

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('function', $result['error']['data']);
    }

    public function test_it_refuses_a_malformed_x_range(): void
    {
        $result = $this->tool()->execute(
            array('function' => 'x', 'x_range' => array(1)),
            $this->context()
        );

        $this->assertArrayHasKey('x_range', $result['error']['data']);
    }

    public function test_it_refuses_an_inverted_y_range(): void
    {
        $result = $this->tool()->execute(
            array('function' => 'x', 'y_range' => array(9, 3)),
            $this->context()
        );

        $this->assertArrayHasKey('y_range', $result['error']['data']);
    }

    public function test_it_refuses_a_size_outside_the_schema(): void
    {
        // WordPress absint()'d this and passed a million pixels to the renderer.
        $result = $this->tool()->execute(
            array('function' => 'x', 'width' => 1000000),
            $this->context()
        );

        $this->assertArrayHasKey('width', $result['error']['data']);
    }

    public function test_it_refuses_a_colour_that_is_not_hex(): void
    {
        // WordPress passed sanitize_hex_color()'s null straight through.
        $result = $this->tool()->execute(
            array('function' => 'x', 'line_color' => 'chartreuse'),
            $this->context()
        );

        $this->assertArrayHasKey('line_color', $result['error']['data']);
    }

    public function test_the_defaults_reach_the_renderer(): void
    {
        		$captured = null;
        		$tool = $this->tool($this->renderer(true, $captured));

        		$tool->execute(array('function' => 'x^2'), $this->context());

        $this->assertInstanceOf(GraphRequest::class, $captured);
        $this->assertSame('x^2', $captured->expression);
        $this->assertSame(array(-10.0, 10.0), $captured->xRange);
        $this->assertNull($captured->yRange);
        $this->assertSame(800, $captured->width);
        $this->assertSame(600, $captured->height);
        $this->assertTrue($captured->showGrid);
        $this->assertSame('#0066cc', $captured->lineColor);
        $this->assertSame('', $captured->title);
    }

    public function test_the_string_false_does_not_switch_the_grid_on(): void
    {
        		$captured = null;
        		$tool = $this->tool($this->renderer(true, $captured));

        		// The WordPress defect: (bool) 'false' === true.
        $tool->execute(
            array('function' => 'x', 'show_grid' => 'false'),
            $this->context()
        );

        $this->assertFalse($captured->showGrid);
    }

    public function test_a_shorthand_hex_colour_is_expanded(): void
    {
        		$captured = null;
        		$tool = $this->tool($this->renderer(true, $captured));

        		$tool->execute(
        			array('function' => 'x', 'line_color' => '#abc'),
                    $this->context()
                );

        $this->assertSame('#aabbcc', $captured->lineColor);
    }

    public function test_a_missing_engine_is_named(): void
    {
        $result = $this->tool($this->renderer(false))
            ->execute(array('function' => 'x'), $this->context());

        $this->assertSame(GraphRendererInterface::ERROR_UNAVAILABLE, $result['error']['code']);
    }

    public function test_an_unplottable_window_is_a_validation_failure(): void
    {
        $renderer = $this->renderer(true);
        $renderer->method('graph')->willThrowException(
            new InvalidArgumentException('The expression has fewer than two finite values')
        );

        $result = $this->tool($renderer)->execute(
            array('function' => 'sqrt(x)', 'x_range' => array(-10, -1)),
            $this->context()
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('function', $result['error']['data']);
    }

    public function test_a_renderer_failure_is_reported(): void
    {
        $renderer = $this->renderer(true);
        $renderer->method('graph')->willThrowException(new RuntimeException('worker down'));

        $result = $this->tool($renderer)->execute(
            array('function' => 'x'),
            $this->context()
        );

        $this->assertSame('wp_mcp_ai_graph_failed', $result['error']['code']);
    }

    public function test_the_png_is_stored_and_described(): void
    {
        $files = new FakeFileStore();
        $tool = $this->tool($this->renderer(true), $files);

        $result = $tool->execute(
            array('function' => 'sin(x)', 'x_range' => array(-3, 3), 'title' => 'sine'),
            $this->context()
        );

        $this->assertTrue($result['success']);
        		$this->assertSame(self::PNG_BYTES, $files->payload, 'the decoded bytes must be stored verbatim');
        		$this->assertSame('.png', substr($files->filename, -4));
        $this->assertIsInt($result['data']['attachment_id']);
        $this->assertSame('sin(x)', $result['data']['function']);
        $this->assertSame('Function graphed: sin(x)', $result['data']['text']);
        $this->assertSame('Graph of sin(x) from x=-3 to x=3', $result['data']['prompt']);
        $this->assertSame(400, $result['data']['samples']);
        $this->assertSame(397, $result['data']['finite_samples']);
    }

    public function test_an_empty_image_is_reported_rather_than_stored(): void
    {
        $files = new FakeFileStore();
        $renderer = $this->createMock(GraphRendererInterface::class);
        $renderer->method('isAvailable')->willReturn(true);
        $renderer->method('graph')->willReturn(array(
            'data'           => '',
            'mime_type'      => 'image/png',
            'width'          => 800,
            'height'         => 600,
            'samples'        => 400,
            'finite_samples' => 0,
            'x_range'        => array(-10.0, 10.0),
            'y_range'        => array(-1.0, 1.0),
        ));

        $result = $this->tool($renderer, $files)->execute(
            array('function' => 'x'),
            $this->context()
        );

        $this->assertSame('wp_mcp_ai_graph_failed', $result['error']['code']);
        $this->assertSame('', $files->payload);
    }

    public function test_it_reports_when_no_file_store_is_bound(): void
    {
        $tool = new GraphFunctionTool($this->errors, $this->renderer(true));

        $result = $tool->execute(array('function' => 'x'), $this->context());

        $this->assertSame('wp_mcp_ai_graph_storage_unavailable', $result['error']['code']);
    }

    /** Bytes that survive base64 unchanged and are not valid UTF-8 by accident. */
    private const PNG_BYTES = "\x89PNG\r\n\x1a\nGRAPH";

    /** A signed-in caller. */
    private function context(): array
    {
        return array('user_id' => 7);
    }

    	/**
    	 * A renderer double.
    	 *
    	 * @param bool                  $available Whether the engine is present.
    	 * @param GraphRequest|null     $captured  Receives the request when passed.
    	 */
    	private function renderer(bool $available = true, ?GraphRequest &$captured = null): GraphRendererInterface
        {
        $renderer = $this->createMock(GraphRendererInterface::class);
        $renderer->method('isAvailable')->willReturn($available);
        $renderer->method('graph')->willReturnCallback(
            static function (GraphRequest $request) use (&$captured): array {
                $captured = $request;

                return array(
                    'data'           => base64_encode(self::PNG_BYTES),
                    'mime_type'      => 'image/png',
                    'width'          => $request->width,
                    'height'         => $request->height,
                    'samples'        => 400,
                    'finite_samples' => 397,
                    'x_range'        => $request->xRange,
                    'y_range'        => array(-1.0, 1.0),
                );
            }
        );

        return $renderer;
        }

    /** The tool under test. */
    private function tool(?GraphRendererInterface $renderer = null, ?FakeFileStore $files = null): GraphFunctionTool
    {
        return new GraphFunctionTool(
            $this->errors,
            $renderer ?? $this->renderer(),
            $files ?? new FakeFileStore()
        );
    }
}
