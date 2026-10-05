<?php
/**
 * Complexity classifier — domain contract.
 *
 * Classifies an incoming message list into a routing tier. Implementations
 * may be deterministic (heuristic) or semantic (a decision model such as
 * TypeSafe Jev); the contract deliberately returns both a tier and a
 * confidence so the caller can apply its own escalation policy.
 *
 * Inspired by the FrugalGPT / RouteLLM cascade pattern and the
 * "classifier-based routing" family described in the 2026 routing surveys:
 * classify before the expensive call, route the easy majority to a cheap
 * model, escalate only when confidence demands it.
 *
 * @link    https://arxiv.org/abs/2305.05176 FrugalGPT (Stanford/TMLR 2024)
 * @credit  Cascade-routing concept inspired by affaan-m/ECC cost-aware LLM
 *          pipeline skill (MIT) and the FrugalGPT/RouteLLM literature.
 * @package Nvoos\Core
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface ComplexityClassifierInterface
{
    /**
     * Classify a message list into a routing tier.
     *
     * @param array<int, array<string, mixed>> $messages Chat messages in the
     *                                                   standard
     *                                                   `{role, content}` shape.
     * @param array<string, mixed>              $options  Request options
     *                                                   (model, provider,
     *                                                   temperature, etc.).
     *
     * @return array{tier: 'simple'|'complex', confidence: float, reason: string}
     *               `confidence` is normalised to 0..1; `reason` is a short
     *               human-readable justification for logging and debugging.
     */
    public function classify(array $messages, array $options = []): array;
}
