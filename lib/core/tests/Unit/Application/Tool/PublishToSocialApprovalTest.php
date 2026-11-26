<?php

/**
 * `publish_to_social` must cost a human decision, exactly once.
 *
 * The tool declares `irreversible` + `external-communication`, which derives
 * PERMANENT irreversibility, and a helpful call at that rung is
 * APPROVAL_REQUIRED. This test proves the whole loop rather than the flag:
 *
 *   1. a call with no ticket is refused and a ticket is queued;
 *   2. a retry with an approved ticket runs;
 *   3. the ticket is spent, so a third call is refused again.
 *
 * Step 3 is the one that matters. An approval that can be replayed is not an
 * approval, and the guard's `consume()` contract is where that is enforced.
 *
 * The tool under test declares **no** flags of its own in the resolver double, so
 * `CapabilityFlagResolution` falls through to the generated catalogue. That is
 * deliberate: it pins the *declared* flags, so an edit that removes
 * `irreversible` from the corpus fails here.
 *
 * @package Nvoos\Core\Tests
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Tool;

use Nvoos\Core\Application\Tool\NecessityGuard;
use Nvoos\Core\Domain\Contract\ApprovalTicketInterface;
use Nvoos\Core\Tests\Support\TicketOnceStore;
use PHPUnit\Framework\TestCase;

final class PublishToSocialApprovalTest extends TestCase
{
    /**
     * A well-formed publish: a channel and copy. Without arguments the gate
     * classifies the call OPTIONAL and proves nothing about the flags.
     */
    private const ARGUMENTS = array(
        'platform' => 'linkedin',
        'content' => 'A sentence that will be seen.',
        'dry_run' => false,
    );

    public function test_a_publish_is_refused_until_a_human_approves_it(): void
    {
        $store = new TicketOnceStore();

        $reason = $this->guard($store)->evaluate('publish_to_social', self::ARGUMENTS, array('user_id' => 3));

        $this->assertNotNull($reason, 'an unapproved publish must be refused');
        $this->assertStringContainsString('requires human approval', $reason);
        $this->assertStringContainsString('ticket 9 is pending', $reason);
        $this->assertSame(1, $store->requested, 'the refusal must leave a ticket to approve');
        $this->assertSame(0, $store->spent);
    }

    public function test_an_approved_ticket_runs_the_publish_exactly_once(): void
    {
        $store = new TicketOnceStore();
        $guard = $this->guard($store);

        $guard->evaluate('publish_to_social', self::ARGUMENTS, array('user_id' => 3));

        $store->approved = true;

        $this->assertNull(
            $guard->evaluate('publish_to_social', self::ARGUMENTS, array('user_id' => 3)),
            'an approved ticket must let the call through'
        );
        $this->assertSame(1, $store->spent);

        // The replay is the whole point: an approval is not a standing permit.
        $reason = $guard->evaluate('publish_to_social', self::ARGUMENTS, array('user_id' => 3));

        $this->assertNotNull($reason, 'a spent ticket must not authorise a second publish');
        $this->assertStringContainsString('requires human approval', $reason);
        $this->assertSame(1, $store->spent);
        $this->assertSame(2, $store->requested, 'the third call queues a fresh decision');
    }

    public function test_the_ticket_carries_the_payload_that_was_approved(): void
    {
        $store = new TicketOnceStore();

        $this->guard($store)->evaluate('publish_to_social', self::ARGUMENTS, array(
            'user_id' => 3,
            'session_id' => 'sess-9',
        ));

        $this->assertNotNull($store->lastRequest);
        $this->assertSame('publish_to_social', $store->lastRequest->tool);
        $this->assertSame(3, $store->lastRequest->requesterId);
        $this->assertSame('sess-9', $store->lastRequest->sessionId);
        $this->assertSame(self::ARGUMENTS, $store->lastRequest->arguments);
    }

    public function test_without_a_store_the_publish_fails_closed(): void
    {
        $reason = $this->guard(null)->evaluate('publish_to_social', self::ARGUMENTS, array());

        $this->assertNotNull($reason);
        $this->assertStringContainsString('no approval store is bound', $reason);
    }

    private function guard(?ApprovalTicketInterface $store): NecessityGuard
    {
        // No flags on the double, so the resolver falls through to the generated
        // catalogue — the declaration under test.
        $resolver = new NecessityTestResolver(new NecessityTestTool('publish_to_social', array()));

        return new NecessityGuard($resolver, true, $store);
    }
}
