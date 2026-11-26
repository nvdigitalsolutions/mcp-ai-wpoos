<?php

namespace Tests\Feature;

use App\Filament\Operator\Pages\ConversationImportPage;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proposal 149 §191 — an uploaded export is a source, not a second importer.
 *
 * §37 said the upload path needed "a temporary file store, a MIME gate and a size
 * check before the bytes are even readable". The byte is that the file only ever
 * fills `$body`, so there is one parser and one write. These tests pin the three
 * gates and the provenance record:
 *
 *  - size — refused before the bytes are read into the body;
 *  - type — decided by the **sniffed** bytes, so a `.json` holding a PNG fails;
 *  - identity — a successful import records the file's name and sha256, and
 *    never its contents.
 */
final class ConversationImportUploadTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('operator'));

        config(array(
            'cache.default' => 'array',
            'sanctum.stateful' => array('localhost', '127.0.0.1'),
        ));

        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_a_json_file_fills_the_body_and_records_its_identity(): void
    {
        $json = $this->shareGpt('uploaded-1', 'Hello from a file');

        $this->actingAs($this->operator());

        Livewire::test(ConversationImportPage::class)
            ->set('upload', UploadedFile::fake()->createWithContent('export.json', $json))
            ->call('loadUpload')
            ->assertSet('uploadError', '')
            ->assertSet('body', $json)
            ->assertSet('sourceName', 'export.json')
            ->assertSet('sourceChecksum', hash('sha256', $json));
    }

    public function test_a_file_over_the_byte_cap_is_refused(): void
    {
        $oversize = str_repeat('A', 2_097_153);

        $this->actingAs($this->operator());

        Livewire::test(ConversationImportPage::class)
            ->set('upload', UploadedFile::fake()->createWithContent('big.json', $oversize))
            ->call('loadUpload')
            ->assertSee('larger than')
            ->assertSet('body', '');
    }

    public function test_a_file_whose_bytes_are_not_an_export_is_refused(): void
    {
        // The name says JSON; the bytes say PNG. The sniff decides.
        $png = (string) base64_decode(self::PNG, true);

        $this->actingAs($this->operator());

        Livewire::test(ConversationImportPage::class)
            ->set('upload', UploadedFile::fake()->createWithContent('export.json', $png))
            ->call('loadUpload')
            ->assertSee('That is a')
            ->assertSet('body', '');
    }

    public function test_an_uploaded_export_imports_and_records_the_checksum(): void
    {
        $json = $this->shareGpt('uploaded-2', 'Imported from a file');

        $this->actingAs($this->operator());

        Livewire::test(ConversationImportPage::class)
            ->set('upload', UploadedFile::fake()->createWithContent('export.json', $json))
            ->call('loadUpload')
            ->assertSet('uploadError', '')
            ->call('import')
            ->assertSee('imported');

        $this->assertSame(1, DB::table('nvoos_transcripts')->count());

        $audit = DB::table('nvoos_admin_audit')
            ->where('resource', 'conversation-import')
            ->first();

        $this->assertNotNull($audit, 'An import writes an audit row.');
        $this->assertStringContainsString(
            hash('sha256', $json),
            (string) $audit->after,
            'The row must identify which file was imported.'
        );
        $this->assertStringNotContainsString(
            'Imported from a file',
            (string) $audit->after,
            'The row must not carry the conversations themselves.'
        );
    }

    /**
     * @return string A ShareGPT export with one conversation.
     */
    private function shareGpt(string $id, string $message): string
    {
        return (string) json_encode(array(
            array(
                'id' => $id,
                'conversations' => array(
                    array('from' => 'human', 'value' => $message),
                    array('from' => 'gpt', 'value' => 'Acknowledged.'),
                ),
            ),
        ));
    }

    private function operator(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail(Tenant::OPERATOR_ID)
            ->users()
            ->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        return $user;
    }
}
