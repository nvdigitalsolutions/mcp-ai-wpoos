<?php

/**
 * Tests for the approval request value object.
 *
 * The fingerprint is what binds an approval to an action, so the two properties
 * that matter are stability (same action, same hash) and sensitivity (different
 * payload, different hash).
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\ValueObject;

use Nvoos\Core\Domain\ValueObject\ApprovalRequest;
use PHPUnit\Framework\TestCase;

final class ApprovalRequestTest extends TestCase
{
    public function test_key_order_does_not_change_the_fingerprint(): void
    {
        $a = new ApprovalRequest('t', array('to' => 'x', 'subject' => 'y'));
        $b = new ApprovalRequest('t', array('subject' => 'y', 'to' => 'x'));

        $this->assertSame($a->argumentsFingerprint(), $b->argumentsFingerprint());
    }

    public function test_nested_key_order_does_not_change_the_fingerprint(): void
    {
        $a = new ApprovalRequest('t', array('meta' => array('b' => 1, 'a' => 2)));
        $b = new ApprovalRequest('t', array('meta' => array('a' => 2, 'b' => 1)));

        $this->assertSame($a->argumentsFingerprint(), $b->argumentsFingerprint());
    }

    public function test_a_different_payload_changes_the_fingerprint(): void
    {
        $a = new ApprovalRequest('t', array('to' => 'x'));
        $b = new ApprovalRequest('t', array('to' => 'y'));

        $this->assertNotSame($a->argumentsFingerprint(), $b->argumentsFingerprint());
    }

    /**
     * `ksort` sorts keys, so a list keeps its order and two orderings of the
     * same values are two different actions. Same behaviour as the machine
     * path's canonicalisation.
     */
    public function test_list_order_changes_the_fingerprint(): void
    {
        $a = new ApprovalRequest('t', array('ids' => array(1, 2)));
        $b = new ApprovalRequest('t', array('ids' => array(2, 1)));

        $this->assertNotSame($a->argumentsFingerprint(), $b->argumentsFingerprint());
    }

    public function test_empty_arguments_still_hash(): void
    {
        $this->assertSame(64, \strlen((new ApprovalRequest('t'))->argumentsFingerprint()));
    }

    public function test_the_tool_is_not_part_of_the_arguments_hash(): void
    {
        // Tool and requester bind through columns, not the hash — the same
        // payload sent to two tools must produce the same payload hash.
        $a = new ApprovalRequest('one', array('id' => 1));
        $b = new ApprovalRequest('two', array('id' => 1));

        $this->assertSame($a->argumentsFingerprint(), $b->argumentsFingerprint());
    }
}
