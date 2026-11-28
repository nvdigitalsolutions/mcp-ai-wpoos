<?php
/**
 * Function graphs through the real adapter (Proposal 164 §164.169).
 *
 * Two distinctions matter to the tool layer and are what these tests pin:
 *
 *  - a `400`/`422` from `/api/math/graph` is the caller's input (an expression
 *    that will not parse, a window that is not a window, a window with nothing
 *    to draw) and must arrive as an `InvalidArgumentException` so the tool
 *    answers with a validation failure;
 *  - a `503` is the native canvas binding being absent, which is a deployment
 *    fact, and must stay a runtime failure so it is not reported back to the
 *    caller as their mistake.
 *
 * The window is also checked: when `y_range` is omitted the worker derives and
 * pads it, and the adapter must return what was drawn rather than what was
 * asked for.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.3.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Nvoos\Core\Domain\ValueObject\GraphRequest;
use Nvoos\Laravel\Adapter\MediaWorkerClient;
use Nvoos\Laravel\Adapter\MediaWorkerGraphRenderer;
use Nvoos\Laravel\Adapter\MediaWorkerRefusedException;
use RuntimeException;

final class MediaWorkerGraphRendererTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('oos.media_worker.url', 'http://worker.test');
        $this->app['config']->set('oos.media_worker.token', 'test-token');
    }

    public function test_it_sends_the_graph_arguments_and_returns_the_drawn_window(): void
    {
        Http::fake(
            array(
                'worker.test/api/math/graph' => Http::response(
                    array(
                        'success'        => true,
                        'mime_type'      => 'image/png',
                        'data_base64'    => base64_encode('PNGDATA'),
                        'width'          => 800,
                        'height'         => 600,
                        'samples'        => 400,
                        'finite_samples' => 397,
                        'x_range'        => array(-10, 10),
                        'y_range'        => array(-22.0, 22.0),
                    )
                ),
            )
        );

        $result = $this->renderer()->graph(
            new GraphRequest(expression: 'x^2', xRange: array(-10.0, 10.0))
        );

        $this->assertSame(base64_encode('PNGDATA'), $result['data']);
        $this->assertSame('image/png', $result['mime_type']);
        $this->assertSame(400, $result['samples']);
        $this->assertSame(397, $result['finite_samples']);
        // The derived window, not the null the caller passed.
        $this->assertSame(array(-22.0, 22.0), $result['y_range']);

        		Http::assertSent(
        			static function ($request): bool {
        				$window = array_map('floatval', (array) ( $request['x_range'] ?? array() ));

        				return \str_ends_with($request->url(), '/api/math/graph')
        					&& 'x^2' === ( $request['expression'] ?? null )
        					&& array(-10.0, 10.0) === $window
        					&& 800 === ( $request['width'] ?? null )
        					&& 600 === ( $request['height'] ?? null )
        					&& true === ( $request['show_grid'] ?? null )
        					&& '#0066cc' === ( $request['line_color'] ?? null )
        					&& ! \array_key_exists('y_range', $request->data())
        					&& ! \array_key_exists('title', $request->data());
        			}
        		);
    }

    public function test_it_sends_an_explicit_window_and_title(): void
    {
        Http::fake(
            array(
                'worker.test/api/math/graph' => Http::response(
                    array(
                        'success'        => true,
                        'mime_type'      => 'image/png',
                        'data_base64'    => base64_encode('PNGDATA'),
                        'width'          => 500,
                        'height'         => 400,
                        'samples'        => 400,
                        'finite_samples' => 400,
                        'x_range'        => array(0, 5),
                        'y_range'        => array(-1, 9),
                    )
                ),
            )
        );

        $this->renderer()->graph(
            new GraphRequest(
                expression: 'x',
                xRange: array(0.0, 5.0),
                yRange: array(-1.0, 9.0),
                width: 500,
                height: 400,
                showGrid: false,
                lineColor: '#aabbcc',
                title: 'Linear'
            )
        );

        		Http::assertSent(
        			static function ($request): bool {
        				$xWindow = array_map('floatval', (array) ( $request['x_range'] ?? array() ));
        				$yWindow = array_map('floatval', (array) ( $request['y_range'] ?? array() ));

        				return array(0.0, 5.0) === $xWindow
        					&& array(-1.0, 9.0) === $yWindow
        					&& false === ( $request['show_grid'] ?? null )
        					&& 'Linear' === ( $request['title'] ?? null );
        			}
        		);
    }

    public function test_a_refused_expression_is_the_callers_mistake(): void
    {
        Http::fake(
            array(
                'worker.test/api/math/graph' => Http::response(
                    array('success' => false, 'error' => 'Unexpected end of expression'),
                    400
                ),
            )
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unexpected end of expression');

        $this->renderer()->graph(
            new GraphRequest(expression: 'x^', xRange: array(-1.0, 1.0))
        );
    }

    public function test_an_unplottable_window_is_the_callers_mistake(): void
    {
        Http::fake(
            array(
                'worker.test/api/math/graph' => Http::response(
                    array(
                        'success'        => false,
                        'error'          => 'The expression has fewer than two finite values',
                        'finite_samples' => 0,
                    ),
                    422
                ),
            )
        );

        $this->expectException(InvalidArgumentException::class);

        $this->renderer()->graph(
            new GraphRequest(expression: 'sqrt(x)', xRange: array(-10.0, -1.0))
        );
    }

    public function test_a_missing_canvas_binding_is_not_the_callers_mistake(): void
    {
        Http::fake(
            array(
                'worker.test/api/math/graph' => Http::response(
                    array(
                        'error'      => 'capability_unavailable',
                        'capability' => 'chart-rendering',
                    ),
                    503
                ),
            )
        );

        try {
            $this->renderer()->graph(
                new GraphRequest(expression: 'x', xRange: array(-1.0, 1.0))
            );

            $this->fail('A 503 should not be reported as a validation failure.');
        } catch (RuntimeException $e) {
            // A deployment without the binding must not look like bad input.
            $this->assertNotInstanceOf(MediaWorkerRefusedException::class, $e);
        }
    }

    public function test_an_answer_without_image_data_is_a_runtime_failure(): void
    {
        Http::fake(
            array(
                'worker.test/api/math/graph' => Http::response(
                    array('success' => true, 'width' => 800, 'height' => 600)
                ),
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('without image data');

        $this->renderer()->graph(
            new GraphRequest(expression: 'x', xRange: array(-1.0, 1.0))
        );
    }

    private function renderer(): MediaWorkerGraphRenderer
    {
        return new MediaWorkerGraphRenderer(new MediaWorkerClient());
    }
}
