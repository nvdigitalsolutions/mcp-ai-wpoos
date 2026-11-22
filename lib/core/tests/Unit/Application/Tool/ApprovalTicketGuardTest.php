<?php

/**
 * Tests for the guard's approval-ticket path.
 *
 * The guard is the only thing standing between "a human said yes" and "the call
 * runs", so these pin the three outcomes that matter: no store fails closed, a
 * spent ticket allows the call through, and an unspent one asks for a human and
 * names the ticket.
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Tool;

use Nvoos\Core\Application\Tool\NecessityGuard;
use Nvoos\Core\Domain\Contract\ApprovalTicketInterface;
use PHPUnit\Framework\TestCase;

final class ApprovalTicketGuardTest extends TestCase
{
    private const ARGUMENTS = array('to' => 'a@b.c', 'subject' => 'hi');

    public function test_without_a_store_the_call_fails_closed(): void
    {
        $reason = $this->guard(null)->evaluate('send_email', self::ARGUMENTS, array());

        $this->assertNotNull($reason);
        $this->assertStringContainsString('no approval store is bound', $reason);
    }

    public function test_a_spent_ticket_lets_the_call_run(): void
    {
        $store = new RecordingApprovalStore(true);

        $this->assertNull($this->guard($store)->evaluate('send_email', self::ARGUMENTS, array()));
        $this->assertSame(1, $store->consumed);
        $this->assertSame(0, $store->requested, 'A spent ticket must not queue another request.');
    }

    public function test_an_unspent_ticket_names_the_request_it_queued(): void
    {
        $store = new RecordingApprovalStore(false, '42');

        $reason = $this->guard($store)->evaluate('send_email', self::ARGUMENTS, array());

        $this->assertNotNull($reason);
        $this->assertStringContainsString('ticket 42 is pending', $reason);
        $this->assertSame(1, $store->requested);
    }

    public function test_the_ticket_carries_the_requester_and_the_payload(): void
    {
        $store = new RecordingApprovalStore(false, '7');

        $this->guard($store)->evaluate(
            'send_email',
            self::ARGUMENTS,
            array('user_id' => 19, 'session_id' => 'sess-1')
        );

        $this->assertNotNull($store->lastRequest);
        $this->assertSame('send_email', $store->lastRequest->tool);
        $this->assertSame(19, $store->lastRequest->requesterId);
        $this->assertSame('sess-1', $store->lastRequest->sessionId);
        $this->assertSame(self::ARGUMENTS, $store->lastRequest->arguments);
    }

    private function guard(?ApprovalTicketInterface $store): NecessityGuard
    {
        $resolver = new NecessityTestResolver(
            new NecessityTestTool('send_email', array('external-communication'))
        );

        return new NecessityGuard($resolver, true, $store);
    }
}
