<?php
/**
 * Symbolic computation through the real adapter (Proposal 164 §164.168).
 *
 * The distinction these tests protect is the one the tool layer reports to a
 * caller: a `400` from a math route means the expression or the matrices are
 * unusable, and must arrive as an `InvalidArgumentException` so the tool answers
 * with a validation failure. Before `postJson()` shared the response decoder with
 * the multipart methods, every non-2xx was the same `RuntimeException`, so a bad
 * expression and a dead worker were indistinguishable.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.2.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use Nvoos\Laravel\Adapter\MediaWorkerClient;
use Nvoos\Laravel\Adapter\MediaWorkerMathEngine;

final class MediaWorkerMathEngineTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->app['config']->set( 'oos.media_worker.url', 'http://worker.test' );
		$this->app['config']->set( 'oos.media_worker.token', 'test-token' );
	}

	public function test_it_sends_the_derivative_arguments_and_returns_both_spellings(): void {
		Http::fake(
			array(
				'worker.test/api/math/derivative' => Http::response(
					array(
						'success'    => true,
						'derivative' => array( 'text' => '2 * x', 'tex' => '2 x' ),
						'text'       => '2 * x',
						'tex'        => '2 x',
					)
				),
			)
		);

		$result = $this->engine()->derivative( 'x^2', 'x', 2, false );

		$this->assertSame( '2 * x', $result['text'] );
		$this->assertSame( '2 x', $result['tex'] );

		Http::assertSent(
			static function ( $request ): bool {
				return \str_ends_with( $request->url(), '/api/math/derivative' )
					&& 'x^2' === ( $request['expression'] ?? null )
					&& 'x' === ( $request['variable'] ?? null )
					&& 2 === ( $request['order'] ?? null )
					&& false === ( $request['simplify'] ?? null );
			}
		);
	}

	public function test_a_refused_expression_is_the_callers_mistake(): void {
		Http::fake(
			array(
				'worker.test/api/math/simplify' => Http::response(
					array( 'success' => false, 'error' => 'Unexpected end of expression' ),
					400
				),
			)
		);

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unexpected end' );

		$this->engine()->simplify( 'x +' );
	}

	public function test_a_server_failure_is_not_the_callers_mistake(): void {
		Http::fake(
			array(
				'worker.test/api/math/simplify' => Http::response( array( 'error' => 'boom' ), 500 ),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Media worker error: boom' );

		try {
			$this->engine()->simplify( 'x + x' );
		} catch ( \InvalidArgumentException $e ) {
			$this->fail( 'A 500 must not be reported as a bad request.' );
		}
	}

	public function test_it_returns_a_matrix_result_of_any_shape(): void {
		Http::fake(
			array(
				'worker.test/api/math/matrix' => Http::response(
					array(
						'success'   => true,
						'operation' => 'eigenvalues',
						'result'    => array( 2, 2 ),
						'text'      => '[2,2]',
						'tex'       => '\\begin{bmatrix} 2 \\\\ 2 \\end{bmatrix}',
					)
				),
			)
		);

		$result = $this->engine()->matrix( 'eigenvalues', array( array( 2, 0 ), array( 0, 2 ) ) );

		$this->assertSame( array( 2, 2 ), $result['result'] );
		$this->assertStringContainsString( 'bmatrix', $result['tex'] );
	}

	public function test_a_matrix_answer_without_a_result_is_refused(): void {
		Http::fake(
			array(
				'worker.test/api/math/matrix' => Http::response( array( 'success' => true ) ),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'without a result' );

		$this->engine()->matrix( 'transpose', array( array( 1, 2 ) ) );
	}

	public function test_an_unconfigured_worker_is_unavailable(): void {
		$this->app['config']->set( 'oos.media_worker.url', '' );

		Http::fake();

		$this->assertFalse( $this->engine()->isAvailable() );
	}

	public function test_it_solves_a_polynomial_and_returns_every_root(): void {
		Http::fake(
			array(
				'worker.test/api/math/solve' => Http::response(
					array(
						'success'   => true,
						'variable'  => 'x',
						'solutions' => array( -2, 2 ),
						'degree'    => 2,
						'infinite'  => false,
						'text'      => 'x1 = -2, x2 = 2',
						'tex'       => 'x_{1} = -2, \\quad x_{2} = 2',
					)
				),
			)
		);

		$result = $this->engine()->solve( 'x^2 - 4', '0', 'x' );

		$this->assertSame( array( -2, 2 ), $result['solutions'] );
		$this->assertSame( 2, $result['degree'] );
		$this->assertFalse( $result['infinite'] );
		$this->assertSame( 'x1 = -2, x2 = 2', $result['text'] );

		Http::assertSent(
			static function ( $request ): bool {
				return \str_ends_with( $request->url(), '/api/math/solve' )
					&& 'x^2 - 4' === ( $request['left'] ?? null )
					&& '0' === ( $request['right'] ?? null )
					&& 'x' === ( $request['variable'] ?? null );
			}
		);
	}

	public function test_a_non_polynomial_is_the_callers_mistake(): void {
		Http::fake(
			array(
				'worker.test/api/math/solve' => Http::response(
					array( 'success' => false, 'error' => 'The equation is not a polynomial in x.' ),
					422
				),
			)
		);

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'not a polynomial' );

		$this->engine()->solve( 'sin(x)', '0.5', 'x' );
	}

	public function test_an_answer_without_solutions_is_a_runtime_failure(): void {
		Http::fake(
			array(
				'worker.test/api/math/solve' => Http::response( array( 'success' => true ) ),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'without solutions' );

		$this->engine()->solve( 'x', '1', 'x' );
	}

	public function test_it_sends_an_indefinite_integral_and_returns_the_antiderivative(): void {
		Http::fake(
			array(
				'worker.test/api/math/integral' => Http::response(
					array(
						'success'        => true,
						'type'           => 'indefinite',
						'antiderivative' => '(1/3)*x^3',
						'tex'            => '\frac{x^{3}}{3}',
						'verified'       => true,
						'samples'        => 7,
					)
				),
			)
		);

		$result = $this->engine()->integral( 'x^2', 'x', 'indefinite' );

		$this->assertSame( '(1/3)*x^3', $result['antiderivative'] );
		$this->assertTrue( $result['verified'] );
		$this->assertSame( 7, $result['samples'] );

		Http::assertSent(
			static function ( $request ): bool {
				return \str_ends_with( $request->url(), '/api/math/integral' )
					&& 'x^2' === ( $request['expression'] ?? null )
					&& 'indefinite' === ( $request['type'] ?? null )
					&& ! \array_key_exists( 'lower', $request->data() );
			}
		);
	}

	public function test_it_sends_a_definite_integral_with_limits(): void {
		Http::fake(
			array(
				'worker.test/api/math/integral' => Http::response(
					array(
						'success' => true,
						'type'    => 'definite',
						'value'   => 2,
						'exact'   => '2',
						'tex'     => '2',
					)
				),
			)
		);

		$result = $this->engine()->integral( 'sin(x)', 'x', 'definite', 0.0, 3.14159265358979 );

		$this->assertSame( 2.0, $result['value'] );

		Http::assertSent(
			static function ( $request ): bool {
				return 'definite' === ( $request['type'] ?? null )
					&& \is_numeric( $request['lower'] ?? null )
					&& \is_numeric( $request['upper'] ?? null );
			}
		);
	}

	public function test_an_untrusted_antiderivative_is_the_callers_mistake(): void {
		Http::fake(
			array(
				'worker.test/api/math/integral' => Http::response(
					array(
						'success'  => false,
						'error'    => 'An antiderivative was produced but not trusted.',
						'verified' => false,
					),
					422
				),
			)
		);

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'not trusted' );

		$this->engine()->integral( 'e^(-x^2)', 'x', 'indefinite' );
	}

	private function engine(): MediaWorkerMathEngine {
		return new MediaWorkerMathEngine( new MediaWorkerClient() );
	}
}
