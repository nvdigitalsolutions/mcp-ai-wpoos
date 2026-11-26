<?php

/**
 * The CRM capture and research pair — the last two slugs in the toolkit.
 *
 * Two things are worth pinning here beyond the obvious argument handling:
 *
 * - **The wing slug is a key, not a label.** The Pro base runs the account id
 *   through `sanitize_key()` before it forms `account/{id}`, so `Acme Corp`
 *   addresses `account/acmecorp`. A port that trimmed instead would write a
 *   record nothing else can find.
 * - **The capture service's normalisation moved into the tool.** Without the
 *   service, the tool is the last line before the store: empty content is
 *   refused, importance is clamped, `ttl` floors at a day, and an unrecognised
 *   tier falls back to `recall`. Each of those was the service's job.
 *
 * The research half pins the query the focus builds, the payload the WordPress
 * tool returned, and the one argument that changed name — WordPress passes
 * `num_results` to a search tool that reads `max_results`, so the request never
 * arrived; the port passes the core's real `count`.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\MemoryStoreInterface;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Tool\Crm\CrmCaptureInteractionTool;
use Nvoos\Core\Tool\Crm\CrmResearchCompanyTool;
use Nvoos\Core\Tool\WebSearchTool;
use PHPUnit\Framework\TestCase;

final class CrmCaptureAndResearchToolsTest extends TestCase
{
    /** The context every authorized call needs. */
    private const CONTEXT = array('user_id' => 1);

    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => $code, 'message' => $message, 'data' => $data),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'validation_failed', 'message' => $message, 'data' => $fields),
            )
        );

        $this->errors->method('isError')->willReturnCallback(
            static fn (mixed $value): bool => is_array($value) && false === ( $value['success'] ?? true )
        );

        $this->errors->method('normalize')->willReturnCallback(
            static fn (mixed $value): array => is_array($value) && isset($value['error'])
                ? (array) $value['error'] + array('data' => array())
                : array('code' => 'error', 'message' => (string) $value, 'data' => array())
        );
    }

    // ─── crm_capture_interaction: identity and schema ─────────────────────

    public function test_the_capture_tool_declares_the_wordpress_surface(): void
    {
        $tool = $this->capture($this->memory());

        $this->assertSame('crm_capture_interaction', $tool->getSlug());
        $this->assertSame('edit_posts', $tool->getRequiredCapability());

        $schema = $tool->getParametersSchema();

        $this->assertSame(array('account_id', 'room', 'content'), $schema['required']);
        $this->assertSame(CrmCaptureInteractionTool::ROOMS, $schema['properties']['room']['enum']);
        $this->assertArrayNotHasKey('ttl', $schema['properties']);
        $this->assertArrayNotHasKey('sensitivity', $schema['properties']);
    }

    // ─── crm_capture_interaction: the record ──────────────────────────────

    public function test_the_record_carries_the_account_wing_and_the_capture_defaults(): void
    {
        $memory = $this->memory();

        $result = $this->capture($memory)->execute(
            array('account_id' => '42', 'room' => 'interactions', 'content' => 'Called about the renewal.'),
            self::CONTEXT,
        );

        $this->assertTrue($result['success']);
        $record = $memory->records[0];

        $this->assertSame('account/42', $record['wing']);
        $this->assertSame('interactions', $record['room']);
        $this->assertSame('recall', $record['tier']);
        $this->assertSame(0.6, $record['importance']);
        $this->assertSame(31536000, $record['ttl']);
        $this->assertSame('Called about the renewal.', $record['content']);
    }

    public function test_the_account_id_is_a_key_not_a_label(): void
    {
        $memory = $this->memory();

        $this->capture($memory)->execute(
            array('account_id' => 'Acme Corp', 'room' => 'interactions', 'content' => 'x'),
            self::CONTEXT,
        );

        // `sanitize_key()` lowercases and drops everything outside a-z0-9_-.
        $this->assertSame('account/acmecorp', $memory->records[0]['wing']);
    }

    public function test_the_success_payload_keeps_the_wordpress_keys(): void
    {
        $result = $this->capture($this->memory())->execute(
            array('account_id' => '42', 'room' => 'objections', 'content' => 'x'),
            self::CONTEXT,
        );

        $this->assertSame('Memory captured.', $result['message']);
        $this->assertSame('account/42', $result['data']['wing']);
        $this->assertSame('objections', $result['data']['room']);
        $this->assertSame('recall', $result['data']['tier']);
        $this->assertSame('mem-1', $result['data']['memory_id']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $result['data']['expires_at'],
        );
    }

    public function test_every_capture_carries_the_policy_in_metadata(): void
    {
        $memory = $this->memory();

        $this->capture($memory)->execute(
            array('account_id' => '42', 'room' => 'next-actions', 'content' => 'x'),
            self::CONTEXT,
        );

        $metadata = $memory->records[0]['metadata'];

        $this->assertSame('crm_capture_interaction', $metadata['context_type']);
        $this->assertSame('crm_capture_interaction', $metadata['source']);
        $this->assertSame('pii', $metadata['sensitivity']);
        $this->assertSame('legitimate_interest', $metadata['consent_basis']);
        $this->assertTrue($metadata['verbatim']);
    }

    public function test_unsupplied_provenance_is_absent_rather_than_empty(): void
    {
        $memory = $this->memory();

        $this->capture($memory)->execute(
            array('account_id' => '42', 'room' => 'interactions', 'content' => 'x'),
            self::CONTEXT,
        );

        $metadata = $memory->records[0]['metadata'];

        $this->assertArrayNotHasKey('subject_refs', $metadata);
        $this->assertArrayNotHasKey('attachments', $metadata);
        $this->assertArrayNotHasKey('recorded_at', $metadata);
        $this->assertArrayNotHasKey('valid_from', $metadata);
        $this->assertArrayNotHasKey('valid_until', $metadata);
    }

    public function test_supplied_provenance_reaches_metadata(): void
    {
        $memory = $this->memory();

        $this->capture($memory)->execute(
            array(
                'account_id'   => '42',
                'room'         => 'interactions',
                'content'      => 'x',
                'subject_refs' => array('https://crm.example/notes/1', '', 7),
                'attachments'  => array(array('sha256' => 'abc'), 'not-a-map'),
                'recorded_at'  => '2026-01-02 03:04:05',
                'valid_from'   => '2026-01-02',
                'valid_until'  => '2026-02-02',
            ),
            self::CONTEXT,
        );

        $metadata = $memory->records[0]['metadata'];

        $this->assertSame(array('https://crm.example/notes/1', '7'), $metadata['subject_refs']);
        $this->assertSame(array(array('sha256' => 'abc')), $metadata['attachments']);
        $this->assertSame('2026-01-02 03:04:05', $metadata['recorded_at']);
        $this->assertSame('2026-01-02', $metadata['valid_from']);
        $this->assertSame('2026-02-02', $metadata['valid_until']);
    }

    public function test_the_caller_may_override_sensitivity_and_consent_as_the_pro_base_allows(): void
    {
        $memory = $this->memory();

        $this->capture($memory)->execute(
            array(
                'account_id'    => '42',
                'room'          => 'interactions',
                'content'       => 'x',
                'sensitivity'   => 'internal',
                'consent_basis' => 'contract',
            ),
            self::CONTEXT,
        );

        $this->assertSame('internal', $memory->records[0]['metadata']['sensitivity']);
        $this->assertSame('contract', $memory->records[0]['metadata']['consent_basis']);
    }

    public function test_tags_merge_the_defaults_and_the_callers(): void
    {
        $memory = $this->memory();

        $this->capture($memory)->execute(
            array('account_id' => '42', 'room' => 'interactions', 'content' => 'x', 'tags' => array('crm', 'renewal', '')),
            self::CONTEXT,
        );

        $this->assertSame(array('crm', 'capture', 'renewal'), $memory->records[0]['tags']);
    }

    public function test_importance_is_clamped_and_ttl_floors_at_one_day(): void
    {
        $memory = $this->memory();
        $tool   = $this->capture($memory);

        $tool->execute(
            array('account_id' => '42', 'room' => 'interactions', 'content' => 'x', 'importance' => 5, 'ttl' => 0),
            self::CONTEXT,
        );

        $tool->execute(
            array('account_id' => '42', 'room' => 'interactions', 'content' => 'y', 'importance' => -3, 'ttl' => -600),
            self::CONTEXT,
        );

        $this->assertSame(1.0, $memory->records[0]['importance']);
        $this->assertSame(86400, $memory->records[0]['ttl']);
        $this->assertSame(0.0, $memory->records[1]['importance']);
        $this->assertSame(600, $memory->records[1]['ttl']);
    }

    public function test_an_unrecognised_tier_falls_back_to_recall(): void
    {
        $memory = $this->memory();
        $tool   = $this->capture($memory);

        $tool->execute(
            array('account_id' => '42', 'room' => 'interactions', 'content' => 'x', 'tier' => 'archival'),
            self::CONTEXT,
        );

        $tool->execute(
            array('account_id' => '42', 'room' => 'interactions', 'content' => 'y', 'tier' => 'permanent'),
            self::CONTEXT,
        );

        $this->assertSame('archival', $memory->records[0]['tier']);
        $this->assertSame('recall', $memory->records[1]['tier']);
    }

    public function test_structured_content_is_encoded_rather_than_refused(): void
    {
        $memory = $this->memory();

        $this->capture($memory)->execute(
            array('account_id' => '42', 'room' => 'interactions', 'content' => array('note' => 'renewal')),
            self::CONTEXT,
        );

        $this->assertSame('{"note":"renewal"}', $memory->records[0]['content']);
    }

    public function test_the_agent_resolves_from_context_then_arguments_then_the_slug(): void
    {
        $memory = $this->memory();
        $tool   = $this->capture($memory);
        $base   = array('account_id' => '42', 'room' => 'interactions', 'content' => 'x');

        $tool->execute($base, array('user_id' => 1, 'assistant_id' => 7, 'agent_id' => 8));
        $tool->execute($base + array('agent_id' => 9), array('user_id' => 1, 'agent_id' => 8));
        $tool->execute($base + array('agent_id' => 9), self::CONTEXT);
        $tool->execute($base, self::CONTEXT);

        $this->assertSame('7', $memory->records[0]['agent_id']);
        $this->assertSame('8', $memory->records[1]['agent_id']);
        $this->assertSame('9', $memory->records[2]['agent_id']);
        $this->assertSame('capture:crm_capture_interaction', $memory->records[3]['agent_id']);
    }

    // ─── crm_capture_interaction: refusals ────────────────────────────────

    public function test_a_missing_account_id_is_a_validation_failure(): void
    {
        $result = $this->capture($this->memory())->execute(
            array('room' => 'interactions', 'content' => 'x'),
            self::CONTEXT,
        );

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_an_account_id_that_sanitises_to_nothing_is_refused(): void
    {
        $result = $this->capture($this->memory())->execute(
            array('account_id' => '***', 'room' => 'interactions', 'content' => 'x'),
            self::CONTEXT,
        );

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_an_unknown_room_is_refused(): void
    {
        $result = $this->capture($this->memory())->execute(
            array('account_id' => '42', 'room' => 'small-talk', 'content' => 'x'),
            self::CONTEXT,
        );

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_empty_content_is_refused_but_whitespace_is_a_value(): void
    {
        $memory = $this->memory();
        $tool   = $this->capture($memory);

        $refused = $tool->execute(
            array('account_id' => '42', 'room' => 'interactions', 'content' => ''),
            self::CONTEXT,
        );

        $accepted = $tool->execute(
            array('account_id' => '42', 'room' => 'interactions', 'content' => '   '),
            self::CONTEXT,
        );

        $this->assertSame('validation_failed', $refused['error']['code']);
        // The capture service tested `'' !== $content`, not `trim()`.
        $this->assertTrue($accepted['success']);
        $this->assertSame('   ', $memory->records[0]['content']);
    }

    public function test_the_store_is_checked_before_the_arguments(): void
    {
        $tool = new CrmCaptureInteractionTool($this->errors);

        // The Pro base checks for the capture service first, so a host without
        // one answers "unavailable" even for a malformed call.
        $result = $tool->execute(array(), self::CONTEXT);

        $this->assertSame('memory_store_unavailable', $result['error']['code']);
    }

    public function test_a_store_that_refuses_the_record_is_a_capture_failure(): void
    {
        $result = $this->capture($this->memory('refuse'))->execute(
            array('account_id' => '42', 'room' => 'interactions', 'content' => 'x'),
            self::CONTEXT,
        );

        $this->assertSame('capture_failed', $result['error']['code']);
    }

    public function test_a_store_that_throws_is_a_capture_failure(): void
    {
        $result = $this->capture($this->memory('throw'))->execute(
            array('account_id' => '42', 'room' => 'interactions', 'content' => 'x'),
            self::CONTEXT,
        );

        $this->assertSame('capture_failed', $result['error']['code']);
    }

    public function test_the_capture_tool_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->capture($this->memory())->execute(
            array('account_id' => '42', 'room' => 'interactions', 'content' => 'x'),
        );
    }

    // ─── research_company ─────────────────────────────────────────────────

    public function test_the_research_tool_declares_the_wordpress_surface(): void
    {
        $tool = $this->research($this->webSearch());

        $this->assertSame('research_company', $tool->getSlug());
        $this->assertSame('edit_posts', $tool->getRequiredCapability());
        $this->assertSame(array('company_name'), $tool->getParametersSchema()['required']);
    }

    public function test_a_company_name_is_required(): void
    {
        $result = $this->research($this->webSearch())->execute(array(), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_a_name_that_sanitises_to_nothing_is_refused(): void
    {
        $result = $this->research($this->webSearch())->execute(
            array('company_name' => '<script></script>'),
            self::CONTEXT,
        );

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_the_general_focus_builds_the_wordpress_query_and_requests_five_hits(): void
    {
        $search = $this->webSearch();

        $this->research($search)->execute(array('company_name' => 'Acme'), self::CONTEXT);

        $this->assertSame('Acme company information overview website contact', $search->calls[0]['query']);
        // WordPress passed `num_results`, which the search tool never read.
        $this->assertSame(5, $search->calls[0]['count']);
        $this->assertArrayNotHasKey('num_results', $search->calls[0]);
    }

    public function test_industry_and_location_lead_the_focus_tail(): void
    {
        $search = $this->webSearch();

        $this->research($search)->execute(
            array('company_name' => 'Acme', 'industry' => 'Widgets', 'location' => 'Lagos', 'research_focus' => 'competition'),
            self::CONTEXT,
        );

        $this->assertSame(
            'Acme Widgets Lagos competitors market position competitive landscape',
            $search->calls[0]['query'],
        );
    }

    public function test_target_fit_adds_the_service_context_as_its_own_phrase(): void
    {
        $search = $this->webSearch();
        $tool   = $this->research($search);

        $tool->execute(array('company_name' => 'Acme', 'research_focus' => 'target_fit'), self::CONTEXT);
        $tool->execute(
            array('company_name' => 'Acme', 'research_focus' => 'target_fit', 'service_context' => 'WordPress development agency'),
            self::CONTEXT,
        );

        $this->assertSame('Acme company size employees revenue business model', $search->calls[0]['query']);
        $this->assertSame(
            'Acme company size employees revenue business model uses WordPress development agency',
            $search->calls[1]['query'],
        );
    }

    public function test_an_unrecognised_focus_falls_to_the_general_tail_and_is_echoed_unchanged(): void
    {
        $search = $this->webSearch();

        $result = $this->research($search)->execute(
            array('company_name' => 'Acme', 'research_focus' => 'vibes'),
            self::CONTEXT,
        );

        // The WordPress switch has `case 'general': default:` and no enum check
        // in execute(); the argument is returned as it arrived.
        $this->assertSame('vibes', $result['data']['research_focus']);
        $this->assertSame('Acme company information overview website contact', $search->calls[0]['query']);
        $this->assertStringContainsString('company overview', $result['data']['research_data']['guidance']);
    }

    public function test_the_payload_keeps_the_wordpress_shape(): void
    {
        $result = $this->research($this->webSearch())->execute(
            array('company_name' => 'Acme', 'research_focus' => 'industry_analysis'),
            self::CONTEXT,
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            'Research completed for "Acme". Review the data and use create_company tool to add to CRM.',
            $result['message'],
        );
        $this->assertSame('Acme', $result['data']['company_name']);
        $this->assertSame('industry_analysis', $result['data']['research_focus']);
        $this->assertSame('Acme industry trends market analysis best practices', $result['data']['search_query']);

        $data = $result['data']['research_data'];

        $this->assertSame(
            array(array('title' => 'Acme', 'url' => 'https://acme.test', 'description' => 'A company.')),
            $data['search_results'],
        );
        // Both are constant empty in WordPress too: its search tool returns
        // only `results`.
        $this->assertSame('', $data['summary']);
        $this->assertSame(array(), $data['sources']);
        $this->assertSame('Found 1 sources of information about Acme.', $data['findings_summary']);
        $this->assertStringContainsString('industry trends', $data['guidance']);
    }

    public function test_an_empty_search_is_a_successful_research_with_no_hits(): void
    {
        $search = $this->webSearch();
        $search->result = array('success' => true, 'message' => 'No search results found.', 'data' => array());

        $result = $this->research($search)->execute(array('company_name' => 'Acme'), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['data']['research_data']['search_results']);
        $this->assertSame('Found 0 sources of information about Acme.', $result['data']['research_data']['findings_summary']);
    }

    public function test_a_search_error_is_a_typed_failure(): void
    {
        $search = $this->webSearch();
        $search->result = array(
            'success' => false,
            'error'   => array('code' => 'search_request_failed', 'message' => 'Search request failed: timed out', 'data' => array()),
        );

        $result = $this->research($search)->execute(array('company_name' => 'Acme'), self::CONTEXT);

        $this->assertSame('search_failed', $result['error']['code']);
        $this->assertSame('Web search failed: Search request failed: timed out', $result['error']['message']);
    }

    public function test_the_research_tool_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->research($this->webSearch())->execute(array('company_name' => 'Acme'));
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function capture(?MemoryStoreInterface $memory = null): CrmCaptureInteractionTool
    {
        return new CrmCaptureInteractionTool($this->errors, $memory ?? $this->memory());
    }

    /**
     * A recording memory store.
     *
     * @param string $mode Empty, `refuse` (a failed result) or `throw`.
     */
    private function memory(string $mode = ''): MemoryStoreInterface
    {
        return new class ($mode) implements MemoryStoreInterface {
            /** @var list<array<string, mixed>> */
            public array $records = array();

            public function __construct(private readonly string $mode)
            {
            }

            public function store(array $record): array
            {
                $this->records[] = $record;

                if ('throw' === $this->mode) {
                    throw new \RuntimeException('The store is down.');
                }

                if ('refuse' === $this->mode) {
                    return array('success' => false, 'error' => 'The store refused the record.');
                }

                return array('success' => true, 'memory_id' => 'mem-' . count($this->records));
            }

            public function get(string $memoryId): array
            {
                return array('found' => false);
            }

            public function update(string $memoryId, array $patch): array
            {
                return array('success' => true);
            }

            public function delete(string $memoryId): array
            {
                return array('success' => true);
            }

            public function search(string $query, array $filters = array(), int $limit = 10): array
            {
                return array();
            }

            public function listByAgent(string $agentId, array $filters = array(), int $limit = 50, int $offset = 0): array
            {
                return array('memories' => array(), 'total' => 0);
            }
        };
    }
    private function research(?WebSearchTool $search = null): CrmResearchCompanyTool
    {
        return new CrmResearchCompanyTool($this->errors, $search ?? $this->webSearch());
    }

    /**
     * A web-search stand-in that records its calls.
     *
     * The parent constructor is skipped deliberately: nothing here reads the
     * provider collaborators, and the point of the fake is the arguments the
     * research tool hands over.
     */
    private function webSearch(): WebSearchTool
    {
        return new class () extends WebSearchTool {
            /** @var list<array<string, mixed>> */
            public array $calls = array();

            /** @var mixed */
            public mixed $result = array(
                'success' => true,
                'message' => 'Found 1 results.',
                'data'    => array(
                    'items' => array(
                        array('title' => 'Acme', 'url' => 'https://acme.test', 'description' => 'A company.'),
                    ),
                    'total' => 1,
                ),
            );

            public function __construct()
            {
            }

            public function execute(array $arguments = array(), array $context = array()): mixed
            {
                $this->calls[] = $arguments;

                return $this->result;
            }
        };
    }
}
