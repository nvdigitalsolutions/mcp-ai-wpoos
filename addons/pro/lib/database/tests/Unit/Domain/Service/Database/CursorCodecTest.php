<?php

/**
 * Cursor tests: opacity, signature, and request binding.
 *
 * A cursor is the one piece of kernel output a caller can hand back. If it can be
 * edited to change the scope, the relation, the ordering or the direction, it
 * becomes a way to page past a grant.
 *
 * @package Nvoos\Database\Tests
 * @since   3.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Tests\Unit\Domain\Service\Database;

use Nvoos\Database\Domain\Service\Database\CursorCodec;
use Nvoos\Database\Domain\Service\Database\RefusalCode;
use PHPUnit\Framework\TestCase;

final class CursorCodecTest extends TestCase
{
    use RefusalAssertions;

    private const SECRET = 'test-signing-key';

    private CursorCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new CursorCodec();
    }

    public function test_it_round_trips_a_key_tuple(): void
    {
        $cursor = $this->codec->encode(
            array( '2026-11-01 00:00:00', 88123 ),
            array( 'placed_at', 'order_id' ),
            'desc',
            'analytics-readonly',
            'reporting.orders',
            self::SECRET
        );

        $decoded = $this->codec->decode(
            $cursor,
            self::SECRET,
            'analytics-readonly',
            'reporting.orders',
            array( 'placed_at', 'order_id' ),
            'desc'
        );

        self::assertSame(array( '2026-11-01 00:00:00', 88123 ), $decoded);
    }

    public function test_it_refuses_a_tampered_payload(): void
    {
        $cursor = $this->codec->encode(
            array( 1 ),
            array( 'order_id' ),
            'asc',
            'analytics-readonly',
            'reporting.orders',
            self::SECRET
        );

        // Flip the key without re-signing.
        $decoded = json_decode($this->codec->base64UrlEncode(''), true);
        self::assertNull($decoded);

        $tampered = $this->codec->base64UrlEncode((string) json_encode(
            array( 'v' => 1, 's' => 'analytics-readonly', 't' => 'reporting.orders',
                   'o' => array( 'order_id' ), 'd' => 'asc', 'k' => array( 999 ),
                   'h' => 'deadbeef' )
        ));

        $this->assertRefusal(
            RefusalCode::STATEMENT_NOT_ADMITTED,
            fn (): array => $this->codec->decode(
                $tampered,
                self::SECRET,
                'analytics-readonly',
                'reporting.orders',
                array( 'order_id' ),
                'asc'
            )
        );
    }

    public function test_it_refuses_a_cursor_from_another_scope(): void
    {
        $cursor = $this->codec->encode(
            array( 1 ),
            array( 'order_id' ),
            'asc',
            'wide-scope',
            'reporting.orders',
            self::SECRET
        );

        $this->assertRefusal(
            RefusalCode::STATEMENT_NOT_ADMITTED,
            fn (): array => $this->codec->decode(
                $cursor,
                self::SECRET,
                'narrow-scope',
                'reporting.orders',
                array( 'order_id' ),
                'asc'
            )
        );
    }

    public function test_it_refuses_a_cursor_from_another_relation(): void
    {
        $cursor = $this->codec->encode(
            array( 1 ),
            array( 'order_id' ),
            'asc',
            'analytics-readonly',
            'reporting.orders',
            self::SECRET
        );

        $this->assertRefusal(
            RefusalCode::STATEMENT_NOT_ADMITTED,
            fn (): array => $this->codec->decode(
                $cursor,
                self::SECRET,
                'analytics-readonly',
                'reporting.users',
                array( 'order_id' ),
                'asc'
            )
        );
    }

    public function test_it_refuses_a_changed_ordering_or_direction(): void
    {
        $cursor = $this->codec->encode(
            array( 1 ),
            array( 'order_id' ),
            'asc',
            'analytics-readonly',
            'reporting.orders',
            self::SECRET
        );

        $this->assertRefusal(
            RefusalCode::STATEMENT_NOT_ADMITTED,
            fn (): array => $this->codec->decode(
                $cursor,
                self::SECRET,
                'analytics-readonly',
                'reporting.orders',
                array( 'placed_at' ),
                'asc'
            )
        );

        $this->assertRefusal(
            RefusalCode::STATEMENT_NOT_ADMITTED,
            fn (): array => $this->codec->decode(
                $cursor,
                self::SECRET,
                'analytics-readonly',
                'reporting.orders',
                array( 'order_id' ),
                'desc'
            )
        );
    }

    public function test_it_refuses_a_different_signing_key(): void
    {
        $cursor = $this->codec->encode(
            array( 1 ),
            array( 'order_id' ),
            'asc',
            'analytics-readonly',
            'reporting.orders',
            self::SECRET
        );

        $this->assertRefusal(
            RefusalCode::STATEMENT_NOT_ADMITTED,
            fn (): array => $this->codec->decode(
                $cursor,
                'another-key',
                'analytics-readonly',
                'reporting.orders',
                array( 'order_id' ),
                'asc'
            )
        );
    }

    public function test_it_refuses_malformed_base64(): void
    {
        $this->assertRefusal(
            RefusalCode::STATEMENT_NOT_ADMITTED,
            fn (): array => $this->codec->decode(
                'not-base64!!',
                self::SECRET,
                'analytics-readonly',
                'reporting.orders',
                array( 'order_id' ),
                'asc'
            )
        );
    }
}
