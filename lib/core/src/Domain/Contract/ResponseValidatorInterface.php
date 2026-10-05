<?php
/**
 * Response validator — domain contract.
 *
 * Judges whether a cheap-tier response is acceptable, or whether the request
 * must escalate to the primary (frontier) model. This is the "stop judge"
 * role of the AutoMix / FrugalGPT cascade: a cheap first answer is only
 * returned when a validator signs it off.
 *
 * Implementations may be deterministic (schema checks, refusal-pattern
 * detection) or semantic (a decision model scoring the answer against the
 * request). The contract returns a confidence so the caller can compare it
 * against a configured threshold.
 *
 * @credit  Cascade-routing concept inspired by affaan-m/ECC cost-aware LLM
 *          pipeline skill (MIT) and the FrugalGPT/RouteLLM literature.
 * @package Nvoos\Core
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface ResponseValidatorInterface
{
    /**
     * Validate a provider response against the request that produced it.
     *
     * @param mixed                          $response Provider response
     *                                                 (array envelope or
     *                                                 string content,
     *                                                 implementation-defined).
     * @param array<int, array<string, mixed>> $messages Original chat
     *                                                   messages.
     * @param array<string, mixed>              $options  Request options.
     *
     * @return array{acceptable: bool, confidence: float, reason: string}
     *               `acceptable` true means the response may be returned to
     *               the caller without escalation.
     */
    public function validate(mixed $response, array $messages, array $options = []): array;
}
