<?php

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\MathEngineInterface;

/**
 * Symbolic computation over the media worker — Proposal 164 section 164.168.
 *
 * Three routes, one per operation family: `/api/math/derivative`,
 * `/api/math/simplify` and `/api/math/matrix`. The worker already depends on
 * mathjs, so this adapter adds no dependency; `rank` is the one operation mathjs
 * does not have and the route computes it by elimination.
 *
 * ## A bad expression is a bad request
 *
 * The engine answers `400` for an expression it cannot parse, a singular matrix,
 * matrices whose dimensions do not meet, and an order outside 1–5. Those are the
 * caller's input, so they are re-thrown as `\InvalidArgumentException` and the
 * tools report a validation failure rather than "the engine failed". That only
 * works because `MediaWorkerClient::postJson()` now raises
 * `MediaWorkerRefusedException` for `400`, `413` and `422`, the way the
 * multipart methods already did — before that change a malformed expression and
 * an unreachable worker were the same `RuntimeException`.
 *
 * ## Two spellings, one tree
 *
 * Every route answers `text` and `tex` computed from the same node. The adapter
 * passes both through and refuses an answer that carries neither, because a
 * missing spelling is a worker version skew that should be visible rather than
 * silently substituted.
 *
 * @package Nvoos\Laravel
 * @since   1.2.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

final class MediaWorkerMathEngine implements MathEngineInterface
{
    public function __construct(private readonly MediaWorkerClient $worker)
    {
    }

    public function isAvailable(): bool
    {
        return $this->worker->isConfigured();
    }

    /**
     * {@inheritDoc}
     */
    public function derivative(string $expression, string $variable, int $order, bool $simplify): array
    {
        return $this->spelling(
            $this->post(
                '/api/math/derivative',
                array(
                    'expression' => $expression,
                    'variable' => $variable,
                    'order' => $order,
                    'simplify' => $simplify,
                )
            ),
            'derivative'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function simplify(string $expression): array
    {
        return $this->spelling(
            $this->post('/api/math/simplify', array('expression' => $expression)),
            'simplified'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function matrix(string $operation, array $a, ?array $b = null): array
    {
        $body = $this->post(
            '/api/math/matrix',
            array(
                'operation' => $operation,
                'a' => $a,
                'b' => $b,
            )
        );

        if (! array_key_exists('result', $body)) {
            throw new \RuntimeException(
                'The media worker answered without a result for /api/math/matrix.'
            );
        }

        return array(
            'result' => $body['result'],
            'text' => (string) ( $body['text'] ?? '' ),
            'tex' => (string) ( $body['tex'] ?? '' ),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function solve(string $left, string $right, string $variable): array
    {
        $body = $this->post(
            '/api/math/solve',
            array(
                'left' => $left,
                'right' => $right,
                'variable' => $variable,
            )
        );

        $solutions = $body['solutions'] ?? null;

        if (! \is_array($solutions)) {
            throw new \RuntimeException(
                'The media worker answered without solutions for /api/math/solve.'
            );
        }

        return array(
            'solutions' => array_values($solutions),
            'degree' => (int) ( $body['degree'] ?? 0 ),
            'infinite' => (bool) ( $body['infinite'] ?? false ),
            'text' => (string) ( $body['text'] ?? '' ),
            'tex' => (string) ( $body['tex'] ?? '' ),
        );
    }
    /**
     * {@inheritDoc}
     */
    public function integral(
        string $expression,
        string $variable,
        string $type,
        ?float $lower = null,
        ?float $upper = null
    ): array {
        $payload = array(
            'expression' => $expression,
            'variable' => $variable,
            'type' => $type,
        );

        if (null !== $lower && null !== $upper) {
            $payload['lower'] = $lower;
            $payload['upper'] = $upper;
        }

        $body = $this->post('/api/math/integral', $payload);

        if ('definite' === $type) {
            $value = $body['value'] ?? null;

            if (! \is_int($value) && ! \is_float($value)) {
                throw new \RuntimeException(
                    'The media worker answered without a value for /api/math/integral.'
                );
            }

            return array(
                'type' => 'definite',
                'antiderivative' => null,
                'value' => (float) $value,
                'tex' => (string) ( $body['tex'] ?? '' ),
                'verified' => false,
                'samples' => 0,
            );
        }

        $antiderivative = $body['antiderivative'] ?? null;

        if (! \is_string($antiderivative) || '' === $antiderivative) {
            throw new \RuntimeException(
                'The media worker answered without an antiderivative for /api/math/integral.'
            );
        }

        return array(
            'type' => 'indefinite',
            'antiderivative' => $antiderivative,
            'value' => null,
            'tex' => (string) ( $body['tex'] ?? '' ),
            'verified' => (bool) ( $body['verified'] ?? false ),
            'samples' => (int) ( $body['samples'] ?? 0 ),
        );
    }
    /**
     * POST a compute request, mapping a refusal to the caller's mistake.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function post(string $endpoint, array $payload): array
    {
        try {
            return $this->worker->postJson($endpoint, $payload);
        } catch (MediaWorkerRefusedException $e) {
            // 400/413/422: the route is telling the caller its input is unusable.
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }

    /**
     * The two spellings from one answer.
     *
     * @param array<string, mixed> $body
     *
     * @return array{text: string, tex: string}
     */
    private function spelling(array $body, string $label): array
    {
        $text = $body['text'] ?? null;

        if (! \is_string($text) || '' === $text) {
            throw new \RuntimeException(
                'The media worker answered without a ' . $label . ' spelling.'
            );
        }

        return array(
            'text' => $text,
            'tex' => \is_string($body['tex'] ?? null) && '' !== $body['tex'] ? $body['tex'] : $text,
        );
    }
}
