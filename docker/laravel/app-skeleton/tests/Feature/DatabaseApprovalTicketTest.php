<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Tenant;
use App\Support\Approvals\DatabaseApprovalTicket;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nvoos\Core\Domain\ValueObject\ApprovalRequest;
use Tests\TestCase;

/**
 * Approval tickets for the necessity gate — Proposal 149 §157.
 *
 * The necessity gate refuses an overeager write until a human approves *that
 * call*. Six properties carry the safety of that exchange, and each has a test:
 *
 *  - the ticket is **queued** as pending and shows up where a human looks,
 *  - a retry **reuses** the pending ticket rather than asking twice,
 *  - it is **bound to the payload hash**, so approving one call cannot
 *    authorise another,
 *  - it is **bound to the requester**, so one person's approval is not another
 *    person's permission,
 *  - it is **single-use** — the second consume fails,
 *  - it **expires**, so a stale decision cannot be acted on later.
 *
 * A seventh covers the estate scope: an approval granted in one estate must not
 * be spendable from another, because the ticket names the tool and its
 * arguments.
 */
final class DatabaseApprovalTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_request_queues_a_pending_ticket(): void
    {
        $ticket = $this->ticket();
        $request = $this->request(array('color' => '#fff'));

        $id = $ticket->request($request);

        $row = DB::table('nvoos_approvals')->where('id', (int) $id)->first();

        $this->assertNotNull($row, 'The request must queue a row.');
        $this->assertSame('pending', $row->status);
        $this->assertSame('color_convert', $row->tool);
        $this->assertSame(Tenant::DEFAULT_ID, $row->tenant_id);
        $this->assertSame(7, (int) $row->requester_id);
        $this->assertSame($request->argumentsFingerprint(), $row->arguments_hash);
        $this->assertNotNull($row->expires_at);
        $this->assertNull($row->consumed_at);
    }

    public function test_a_retry_reuses_the_pending_ticket(): void
    {
        $ticket = $this->ticket();

        $first = $ticket->request($this->request(array('color' => '#fff', 'space' => 'hex')));
        $second = $ticket->request($this->request(array('space' => 'hex', 'color' => '#fff')));

        // Key order is canonicalised, so the same payload is the same decision.
        $this->assertSame($first, $second);
        $this->assertSame(1, DB::table('nvoos_approvals')->count());
    }

    public function test_an_approved_ticket_is_consumed_once(): void
    {
        $ticket = $this->ticket();
        $request = $this->request(array('color' => '#fff'));

        $id = (int) $ticket->request($request);

        $this->assertFalse($ticket->consume($request), 'A pending ticket is not a permission.');

        $this->approve($id);

        $this->assertTrue($ticket->consume($request));
        $this->assertFalse($ticket->consume($request), 'A ticket must not be spendable twice.');

        $row = DB::table('nvoos_approvals')->where('id', $id)->first();

        $this->assertSame('consumed', $row->status);
        $this->assertNotNull($row->consumed_at);
    }

    public function test_a_different_payload_does_not_consume(): void
    {
        $ticket = $this->ticket();

        $id = (int) $ticket->request($this->request(array('color' => '#fff')));

        $this->approve($id);

        $this->assertFalse(
            $ticket->consume($this->request(array('color' => '#000'))),
            'Approving one payload must not authorise another.'
        );

        $this->assertSame('approved', DB::table('nvoos_approvals')->where('id', $id)->value('status'));
    }

    public function test_a_different_requester_does_not_consume(): void
    {
        $ticket = $this->ticket();

        $id = (int) $ticket->request($this->request(array('color' => '#fff')));

        $this->approve($id);

        $this->assertFalse(
            $ticket->consume($this->request(array('color' => '#fff'), 8)),
            'An approval granted to one person is not another person\'s permission.'
        );
    }

    public function test_an_expired_ticket_does_not_consume(): void
    {
        $ticket = $this->ticket();
        $request = $this->request(array('color' => '#fff'));

        $id = (int) $ticket->request($request);

        $this->approve($id);

        DB::table('nvoos_approvals')->where('id', $id)->update(array(
            'expires_at' => now()->subMinute(),
        ));

        $this->assertFalse($ticket->consume($request), 'A stale decision must not be acted on.');
    }

    public function test_a_ticket_in_another_estate_is_invisible(): void
    {
        Tenant::query()->create(array(
            'id'   => 'acme',
            'name' => 'Acme',
            'slug' => 'acme',
        ));

        $ticket = $this->ticket();
        $request = $this->request(array('color' => '#fff'));

        $id = (int) $ticket->request($request);
        $this->approve($id);

        TenantContext::set('acme');

        try {
            $other = $ticket->request($request);

            $this->assertNotSame($id, $other, 'Another estate must not inherit the ticket.');
            $this->assertSame('acme', DB::table('nvoos_approvals')->where('id', (int) $other)->value('tenant_id'));
            $this->assertFalse($ticket->consume($request), 'Another estate must not spend it.');
        } finally {
            TenantContext::forget();
        }

        $this->assertSame('approved', DB::table('nvoos_approvals')->where('id', $id)->value('status'));
    }

    private function ticket(): DatabaseApprovalTicket
    {
        return new DatabaseApprovalTicket();
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function request(array $arguments, int $requesterId = 7): ApprovalRequest
    {
        return new ApprovalRequest(
            'color_convert',
            $arguments,
            $requesterId,
            'session-a',
            'write declared'
        );
    }

    private function approve(int $id): void
    {
        Approval::query()->whereKey($id)->update(array(
            'status'      => 'approved',
            'resolved_at' => now(),
            'resolved_by' => 1,
        ));
    }
}
