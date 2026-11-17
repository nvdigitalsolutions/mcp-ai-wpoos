<?php

namespace Tests\Feature;

use App\Filament\Operator\Pages\ConversationImportPage;
use App\Models\AdminAudit;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Import\ConversationImport;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Nvoos\Core\Domain\Contract\TranscriptStoreInterface;
use Tests\TestCase;

/**
 * Proposal 149 §37 — conversation import.
 *
 * The parser is pure, so most of these assert it directly: caps, role mapping,
 * truncation, invalid UTF-8 and both accepted shapes. The store tests then prove
 * the property that matters operationally — re-importing the same export upserts
 * rather than duplicating.
 */
class AdminConversationImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('operator'));

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_the_page_renders_the_limits_and_the_import_controls(): void
    {
        $this->actingAs($this->operator())
            ->get('/ops/conversation-import')
            ->assertOk()
            ->assertSee('Conversation import')
            ->assertSee('Preview')
            ->assertSee('idempotent');
    }

    public function test_a_sharegpt_export_parses(): void
    {
        $parsed = ConversationImport::parse((string) json_encode(array(
            array(
                'id' => 'abc-123',
                'conversations' => array(
                    array('from' => 'human', 'value' => 'Hello'),
                    array('from' => 'gpt', 'value' => 'Hi'),
                ),
            ),
        )));

        $this->assertSame('', $parsed['fatal']);
        $this->assertCount(1, $parsed['conversations']);
        $this->assertSame('import:abc-123', $parsed['conversations'][0]['session_id']);
        $this->assertSame('user', $parsed['conversations'][0]['messages'][0]['role']);
        $this->assertSame('assistant', $parsed['conversations'][0]['messages'][1]['role']);
    }

    public function test_a_generic_export_parses(): void
    {
        $parsed = ConversationImport::parse((string) json_encode(array(
            array(
                'session_id' => 'sess-9',
                'messages' => array(
                    array('role' => 'system', 'content' => 'Be brief.'),
                    array('role' => 'user', 'content' => 'Hi'),
                ),
            ),
        )));

        $this->assertSame('import:sess-9', $parsed['conversations'][0]['session_id']);
        $this->assertSame('system', $parsed['conversations'][0]['messages'][0]['role']);
    }

    /**
     * An unknown speaker label must not become a role the runtime does not know.
     */
    public function test_an_unknown_role_falls_back_to_user(): void
    {
        $parsed = ConversationImport::parse((string) json_encode(array(
            array('conversations' => array(
                array('from' => 'wizard', 'value' => 'x'),
                array('from' => 'system', 'value' => 'y'),
            )),
        )));

        $this->assertSame('user', $parsed['conversations'][0]['messages'][0]['role']);
        $this->assertSame('system', $parsed['conversations'][0]['messages'][1]['role']);
    }

    public function test_invalid_json_is_a_fatal_with_no_conversations(): void
    {
        $parsed = ConversationImport::parse('{not json');

        $this->assertStringContainsString('not valid JSON', $parsed['fatal']);
        $this->assertSame(array(), $parsed['conversations']);
    }

    public function test_an_empty_body_is_a_fatal(): void
    {
        $this->assertStringContainsString('Paste an export body', ConversationImport::parse('   ')['fatal']);
    }

    public function test_a_non_list_top_level_is_a_fatal(): void
    {
        $this->assertStringContainsString(
            'JSON array',
            ConversationImport::parse('{"conversations":[]}')['fatal']
        );
    }

    public function test_too_many_conversations_is_a_fatal(): void
    {
        $entries = array_fill(0, ConversationImport::MAX_CONVERSATIONS + 1, array(
            'conversations' => array(array('from' => 'human', 'value' => 'x')),
        ));

        $this->assertStringContainsString(
            'limit is',
            ConversationImport::parse((string) json_encode($entries))['fatal']
        );
    }

    public function test_a_body_over_the_byte_cap_is_a_fatal(): void
    {
        $body = str_repeat('a', ConversationImport::MAX_BYTES + 1);

        $this->assertStringContainsString('larger than', ConversationImport::parse($body)['fatal']);
    }

    public function test_excess_messages_are_truncated_and_reported(): void
    {
        $messages = array();

        for ($i = 0; $i <= ConversationImport::MAX_MESSAGES; ++$i) {
            $messages[] = array('role' => 'user', 'content' => 'message '.$i);
        }

        $parsed = ConversationImport::parse((string) json_encode(array(
            array('id' => 'long', 'messages' => $messages),
        )));

        $this->assertCount(ConversationImport::MAX_MESSAGES, $parsed['conversations'][0]['messages']);
        $this->assertSame(1, $parsed['conversations'][0]['truncated']);
    }

    /**
     * A JSON export can carry content as a list of parts.
     */
    public function test_multipart_content_is_flattened_to_its_text_parts(): void
    {
        $parsed = ConversationImport::parse((string) json_encode(array(
            array('messages' => array(
                array('role' => 'user', 'content' => array(
                    array('type' => 'text', 'text' => 'first'),
                    array('type' => 'image', 'url' => 'https://example.test/x.png'),
                    array('type' => 'text', 'text' => 'second'),
                )),
            )),
        )));

        $this->assertSame("first\nsecond", $parsed['conversations'][0]['messages'][0]['content']);
    }

    /**
     * A body carrying invalid UTF-8 is rejected at the JSON boundary.
     *
     * JSON must be valid UTF-8, so `json_decode` refuses the whole body — nothing
     * is written and the user is told why. The `text()` scrub remains as
     * defence-in-depth for a byte-level source (a file or stream reader), which
     * this paste-only version does not have; it is not reachable from here.
     */
    public function test_a_body_with_invalid_utf8_is_rejected_before_any_write(): void
    {
        $body = '[{"id":"utf8","messages":[{"role":"user","content":"bad '.chr(0xC3).chr(0x28).' byte"}]}]';

        $this->assertFalse(mb_check_encoding($body, 'UTF-8'));

        $report = ConversationImport::import(
            $body,
            $this->app->make(TranscriptStoreInterface::class)
        );

        $this->assertNotSame('', (string) $report['fatal']);
        $this->assertSame(0, $report['imported']);
        $this->assertSame(0, DB::table('nvoos_transcripts')->count());
    }

    public function test_importing_writes_a_transcript(): void
    {
        $report = $this->import(array(
            array(
                'id' => 'conv-1',
                'conversations' => array(
                    array('from' => 'human', 'value' => 'Hello'),
                    array('from' => 'gpt', 'value' => 'Hi'),
                ),
            ),
        ));

        $this->assertSame(1, $report['imported']);
        $this->assertSame(2, $report['messages']);

        $row = DB::table('nvoos_transcripts')->where('session_id', 'import:conv-1')->first();

        $this->assertNotNull($row);

        $messages = json_decode((string) $row->messages, true);

        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('Hello', $messages[0]['content']);
    }

    /**
     * The property that makes a re-run safe.
     */
    public function test_re_importing_the_same_export_upserts_rather_than_duplicating(): void
    {
        $payload = array(array(
            'id' => 'conv-1',
            'messages' => array(array('role' => 'user', 'content' => 'first')),
        ));

        $this->import($payload);
        $this->assertSame(1, DB::table('nvoos_transcripts')->count());

        $this->import(array(array(
            'id' => 'conv-1',
            'messages' => array(array('role' => 'user', 'content' => 'second')),
        )));

        $this->assertSame(1, DB::table('nvoos_transcripts')->count());

        $row = DB::table('nvoos_transcripts')->where('session_id', 'import:conv-1')->first();
        $messages = json_decode((string) $row->messages, true);

        $this->assertSame('second', $messages[0]['content']);
    }

    public function test_the_page_previews_without_writing(): void
    {
        $this->actingAs($this->operator());

        Livewire::test(ConversationImportPage::class)
            ->set('body', (string) json_encode(array(array(
                'conversations' => array(array('from' => 'human', 'value' => 'Hello')),
            ))))
            ->call('preview')
            ->assertSet('mode', 'preview')
            ->assertSee('ready to import');

        $this->assertSame(0, DB::table('nvoos_transcripts')->count());
    }

    public function test_the_page_imports_and_clears_the_body(): void
    {
        $this->actingAs($this->operator());

        Livewire::test(ConversationImportPage::class)
            ->set('body', (string) json_encode(array(array(
                'id' => 'page-1',
                'conversations' => array(array('from' => 'human', 'value' => 'Hello')),
            ))))
            ->call('import')
            ->assertSet('mode', 'import')
            ->assertSet('body', '')
            ->assertSee('imported');

        $this->assertSame(1, DB::table('nvoos_transcripts')->count());

        $row = AdminAudit::query()
            ->where('resource', 'conversation-import')
            ->where('action', 'import')
            ->first();

        $this->assertNotNull($row, 'A bulk import must leave a record row.');
        $this->assertSame(1, (int) $row->after['conversations']);
        $this->assertArrayNotHasKey('content', $row->after);
    }

    public function test_a_customer_admin_is_refused_the_page(): void
    {
        $customer = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching([$customer->id => ['role' => 'owner']]);

        $this->actingAs($customer)
            ->get('/ops/conversation-import')
            ->assertForbidden();
    }

    /**
     * @param list<array<string, mixed>> $conversations
     * @return array<string, mixed>
     */
    private function import(array $conversations): array
    {
        return ConversationImport::import(
            (string) json_encode($conversations),
            $this->app->make(TranscriptStoreInterface::class)
        );
    }

    private function operator(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail(Tenant::OPERATOR_ID)
            ->users()
            ->syncWithoutDetaching([$user->id => ['role' => 'owner']]);

        return $user;
    }
}
