<?php

/**
 * Signed keyset cursors.
 *
 * Proposal 167 section 167.6.8. A cursor is opaque to the model and signed by the
 * host, so it cannot be edited to widen a query. It binds four things:
 *
 *  - the scope id, so a cursor from a broader scope is unusable on a narrower one;
 *  - the relation, so it cannot be replayed against another table;
 *  - the ordering, so it cannot be re-purposed under a different sort;
 *  - the direction, so a reversed page is not silently accepted.
 *
 * The signature is HMAC-SHA256 over the canonical JSON. Comparison uses
 * `hash_equals`, because a length- or timing-based comparison on a MAC is a
 * signature-forgery oracle.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\Service\Database;

final class CursorCodec
{
    private const VERSION = 1;

    /**
     * Encode a key tuple.
     *
     * @param array<int, mixed> $key       Values of the ordered columns, last row seen.
     * @param list<string>      $order     Ordered column names.
     * @param string            $direction `asc` or `desc`.
     * @param string            $scopeId   Scope the cursor belongs to.
     * @param string            $relation  `schema.table`.
     * @param string            $secret    Host signing key.
     */
    public function encode(
        array $key,
        array $order,
        string $direction,
        string $scopeId,
        string $relation,
        string $secret,
    ): string {
        $payload = array(
            'v' => self::VERSION,
            's' => $scopeId,
            't' => $relation,
            'o' => array_values($order),
            'd' => $direction,
            'k' => array_values($key),
        );

        $canonical = (string) json_encode($payload);
        $payload['h'] = hash_hmac('sha256', $canonical, $secret);

        return $this->base64UrlEncode((string) json_encode($payload));
    }

    /**
     * Decode and verify a cursor.
     *
     * @param list<string> $order     The live request's ordering.
     * @param string       $direction The live request's direction.
     *
     * @return array<int, mixed> The key tuple.
     *
     * @throws DatabaseRefusal When the cursor is malformed, tampered with, or belongs to a different request.
     */
    public function decode(
        string $cursor,
        string $secret,
        string $scopeId,
        string $relation,
        array $order,
        string $direction,
    ): array {
        $decoded = $this->base64UrlDecode($cursor);
        $payload = json_decode($decoded, true);

        if (! is_array($payload) || ! isset($payload['h'], $payload['k'])) {
            throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'Malformed cursor.');
        }

        $provided = (string) $payload['h'];
        unset($payload['h']);

        $canonical = (string) json_encode($payload);

        if (! hash_equals(hash_hmac('sha256', $canonical, $secret), $provided)) {
            throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'Cursor signature mismatch.');
        }

        if ((int) ($payload['v'] ?? 0) !== self::VERSION) {
            throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'Cursor version mismatch.');
        }

        if ((string) ($payload['s'] ?? '') !== $scopeId || (string) ($payload['t'] ?? '') !== $relation) {
            throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'Cursor belongs to another scope or relation.');
        }

        if (array_values($order) !== array_values((array) ($payload['o'] ?? array()))) {
            throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'Cursor ordering mismatch.');
        }

        if ((string) ($payload['d'] ?? '') !== $direction) {
            throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'Cursor direction mismatch.');
        }

        $key = $payload['k'];

        if (! is_array($key)) {
            throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'Cursor key is not a tuple.');
        }

        return array_values($key);
    }

    /**
     * Base64url without padding, so the cursor is safe in a JSON string and a URL.
     */
    public function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if (false === $decoded) {
            throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'Cursor is not valid base64url.');
        }

        return $decoded;
    }
}
