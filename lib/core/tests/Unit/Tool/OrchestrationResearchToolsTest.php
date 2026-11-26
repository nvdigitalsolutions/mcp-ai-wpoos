<?php

/**
 * The five research and content tools.
 *
 * The quartet is the last of the declared `orchestration` toolkit: one pure
 * aggregator, one post bridge, one broadcast wrapper, one blueprint exporter
 * and the multi-step report generator. The generator is the only one that
 * reaches a model, so its tests use the same recording provider-client fake as
 * the router tests rather than a network.
 *
 * Pinned behaviours, collected here rather than buried in assertions:
 *
 * - `aggregate_research_data` deduplicates by URL, by exact content and by
 *   `similar_text()` at the requested threshold, and reports the **effective**
 *   dedup mode and output format when the caller supplied an unknown one.
 * - An excerpt gains its ellipsis only when it was truncated.
 * - `create_post_from_research` resolves `category_id` through the content
 *   store's term vocabulary, and marking a Paper Store record published
 *   preserves its body and tags (WordPress erased both).
 * - `schedule_channel_broadcast` delegates to `create_pro_schedule`, so the id
 *   rule and the re-arm rule have one implementation.
 * - `export_assistant_blueprint` maps the canonical bundle entry, falls back to
 *   the first 40 words for a description, and refuses `save_to_media` without a
 *   file store.
 * - `generate_research_report` runs both modes; the formatting mode is
 *   deterministic, and a research run without a provider router returns
 *   `completion_unavailable` rather than pretending.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use DateTimeImmutable;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\AssistantExportInterface;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\PaperStoreInterface;
use Nvoos\Core\Domain\Contract\ScheduleStoreInterface as Schedule;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Infrastructure\Provider\AbstractProviderClient;
use Nvoos\Core\Infrastructure\Schedule\JsonScheduleStore;
use Nvoos\Core\Tool\Orchestration\AggregateResearchDataTool;
use Nvoos\Core\Tool\Orchestration\CreatePostFromResearchTool;
use Nvoos\Core\Tool\Orchestration\CreateProScheduleTool;
use Nvoos\Core\Tool\Orchestration\ExportAssistantBlueprintTool;
use Nvoos\Core\Tool\Orchestration\GenerateResearchReportTool;
use Nvoos\Core\Tool\Orchestration\ScheduleChannelBroadcastTool;
use Nvoos\Core\Tool\WebSearchTool;
use PHPUnit\Framework\TestCase;

final class OrchestrationResearchToolsTest extends TestCase
{
    private const CONTEXT = array('user_id' => 1);

    private ErrorFactoryInterface $errors;

    /** @var list<string> */
    private array $tempDirs = array();

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

        $this->errors->method('forbidden')->willReturnCallback(
            static fn (string $message = 'Access denied.', array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'forbidden', 'message' => $message, 'data' => $data),
            )
        );

        $this->errors->method('isError')->willReturnCallback(
            static fn (mixed $value): bool => is_array($value) && false === ( $value['success'] ?? true )
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }

        $this->tempDirs = array();
    }

    // ─── aggregate_research_data ─────────────────────────────────────────

    public function test_aggregate_requires_sources_and_a_topic(): void
    {
        $tool = new AggregateResearchDataTool($this->errors);

        $this->assertSame('sources_required', $tool->execute(array(), self::CONTEXT)['error']['code']);
        $this->assertSame(
            'topic_required',
            $tool->execute(array('sources' => array(array('url' => 'u', 'content' => 'c'))), self::CONTEXT)['error']['code']
        );
    }

    public function test_aggregate_drops_a_repeated_url_and_exact_content(): void
    {
        $result = ( new AggregateResearchDataTool($this->errors) )->execute(
            array(
                'topic'   => 'Solar',
                'sources' => array(
                    array('url' => 'https://a.example', 'content' => 'Same body.'),
                    array('url' => 'https://a.example', 'content' => 'Different body.'),
                    array('url' => 'https://b.example', 'content' => 'Same body.'),
                    array('url' => 'https://c.example', 'content' => 'Unrelated content here.'),
                ),
            ),
            self::CONTEXT
        );

        $this->assertSame(4, $result['data']['total_sources']);
        $this->assertSame(2, $result['data']['unique_sources']);
        $this->assertSame(2, $result['data']['duplicates_found']);
    }

    public function test_aggregate_threshold_decides_a_partial_match(): void
    {
        $sourceA = array('url' => 'https://a.example', 'content' => str_repeat('x', 60));
        $sourceB = array('url' => 'https://b.example', 'content' => str_repeat('x', 60) . str_repeat('y', 40));

        $lenient = ( new AggregateResearchDataTool($this->errors) )->execute(
            array('topic' => 'T', 'sources' => array($sourceA, $sourceB), 'deduplication_mode' => 'lenient'),
            self::CONTEXT
        );
        $moderate = ( new AggregateResearchDataTool($this->errors) )->execute(
            array('topic' => 'T', 'sources' => array($sourceA, $sourceB)),
            self::CONTEXT
        );

        $this->assertSame(1, $lenient['data']['unique_sources']);
        $this->assertSame(2, $moderate['data']['unique_sources']);
    }

    public function test_aggregate_extracts_and_caps_key_points(): void
    {
        $content = 'This is important. It shows that sales grew. Unrelated sentence.';

        $result = ( new AggregateResearchDataTool($this->errors) )->execute(
            array('topic' => 'T', 'sources' => array(array('url' => 'https://a.example', 'content' => $content))),
            self::CONTEXT
        );

        $this->assertCount(2, $result['data']['aggregated_data']['key_points']);
        $this->assertSame('This is important.', $result['data']['aggregated_data']['key_points'][0]);

        $many = '';

        for ($index = 0; $index < 15; ++$index) {
            $many .= 'Point ' . $index . ' is important. ';
        }

        $capped = ( new AggregateResearchDataTool($this->errors) )->execute(
            array('topic' => 'T', 'sources' => array(array('url' => 'https://a.example', 'content' => $many))),
            self::CONTEXT
        );

        $this->assertCount(10, $capped['data']['aggregated_data']['key_points']);
    }

    public function test_aggregate_formats_markdown_and_json(): void
    {
        $tool    = new AggregateResearchDataTool($this->errors);
        $args    = array(
            'topic'   => 'Solar',
            'sources' => array(
                array(
                    'url'     => 'https://a.example',
                    'title'   => 'A study',
                    'content' => 'It is critical that panels are angled. A short note.',
                    'date'    => '2026-01-02',
                    'author'  => 'Ada',
                ),
            ),
        );

        $markdown = $tool->execute($args, self::CONTEXT);
        $json     = $tool->execute(array_merge($args, array('output_format' => 'json')), self::CONTEXT);

        $this->assertStringContainsString('# Research Compilation: Solar', $markdown['data']['formatted_output']);
        $this->assertStringContainsString('## Key Findings', $markdown['data']['formatted_output']);
        $this->assertStringContainsString('### [A study](https://a.example)', $markdown['data']['formatted_output']);
        $this->assertStringContainsString('- Ada', $markdown['data']['formatted_output']);

        $decoded = json_decode($json['data']['formatted_output'], true);

        $this->assertSame('Solar', $json['data']['topic']);
        $this->assertStringContainsString('Aggregated research on "Solar"', $decoded['summary']);
        $this->assertArrayHasKey('sources', $decoded);
    }

    public function test_aggregate_html_escapes_content_and_refuses_a_script_url(): void
    {
        $result = ( new AggregateResearchDataTool($this->errors) )->execute(
            array(
                'topic'         => 'Solar & wind',
                'output_format' => 'html',
                'sources'       => array(
                    array(
                        'url'     => 'javascript:alert(1)',
                        'title'   => '<script>alert(2)</script> A & B "quoted"',
                        'content' => '5 < 6 & "quoted"',
                    ),
                ),
            ),
            self::CONTEXT
        );

        $html = $result['data']['formatted_output'];

        // Tags never reach the writer — Text::singleLine strips them at entry.
        // The escaping under test is for the characters that survive.
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('Research Compilation: Solar &amp; wind', $html);
        $this->assertStringContainsString('A &amp; B &quot;quoted&quot;', $html);
        $this->assertStringContainsString('href="#"', $html);
    }

    public function test_aggregate_omits_the_ellipsis_when_nothing_was_truncated(): void
    {
        $result = ( new AggregateResearchDataTool($this->errors) )->execute(
            array(
                'topic'   => 'T',
                'sources' => array(
                    array('url' => 'https://a.example', 'content' => 'Short.'),
                    array('url' => 'https://b.example', 'content' => str_repeat('z', 300)),
                ),
            ),
            self::CONTEXT
        );

        $sources = $result['data']['aggregated_data']['sources'];

        $this->assertSame('Short.', $sources[0]['excerpt']);
        $this->assertStringEndsWith('…', $sources[1]['excerpt']);
    }

    public function test_aggregate_reports_the_effective_defaults(): void
    {
        $result = ( new AggregateResearchDataTool($this->errors) )->execute(
            array(
                'topic'              => 'T',
                'sources'            => array(array('url' => 'https://a.example', 'content' => 'Body.')),
                'deduplication_mode' => 'wild',
                'output_format'      => 'yaml',
            ),
            self::CONTEXT
        );

        $this->assertSame('moderate', $result['data']['dedup_mode']);
        $this->assertSame('markdown', $result['data']['output_format']);
    }

    // ─── create_post_from_research ───────────────────────────────────────

    public function test_create_post_from_raw_data(): void
    {
        $content = $this->contentStore();
        $command = null;

        $content->method('create')->willReturnCallback(
            static function (CreateContentCommand $sent) use (&$command): ContentItem {
                $command = $sent;

                return new ContentItem(
                    id: 42,
                    title: $sent->title,
                    content: $sent->content,
                    status: $sent->status,
                    type: $sent->type,
                    authorId: $sent->authorId,
                    createdAt: new DateTimeImmutable(),
                    updatedAt: new DateTimeImmutable(),
                );
            }
        );

        $result = ( new CreatePostFromResearchTool($this->errors, null, $content) )->execute(
            array(
                'data'        => array(
                    'title'       => 'A study',
                    'report'      => '# Findings',
                    'description' => 'Short summary',
                ),
                'tags'        => array('research'),
                'post_status' => 'pending',
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(42, $result['data']['post_id']);
        $this->assertSame('raw_data', $result['data']['source']);
        $this->assertNull($result['data']['edit_url']);
        $this->assertSame('pending', $command->status);
        $this->assertSame('# Findings', $command->content);
        $this->assertSame('Short summary', $command->excerpt);
        $this->assertSame(array('post_tag' => array('research')), $command->taxonomyInput);
    }

    public function test_create_post_requires_a_title_and_a_body(): void
    {
        $tool = new CreatePostFromResearchTool($this->errors, null, $this->contentStore());

        $missingTitle = $tool->execute(array('data' => array('report' => 'x')), self::CONTEXT);
        $missingBody  = $tool->execute(array('data' => array('title' => 'x')), self::CONTEXT);

        $this->assertSame('validation_failed', $missingTitle['error']['code']);
        $this->assertArrayHasKey('title', $missingTitle['error']['data']);
        $this->assertArrayHasKey('content', $missingBody['error']['data']);
    }

    public function test_create_post_resolves_a_category_id_to_its_name(): void
    {
        $content = $this->contentStore(
            array(
                array('id' => 7, 'name' => 'Research', 'slug' => 'research', 'count' => 3),
            )
        );
        $command = null;
        $content->method('create')->willReturnCallback(
            static function (CreateContentCommand $sent) use (&$command): ContentItem {
                $command = $sent;

                return new ContentItem(
                    id: 1,
                    title: $sent->title,
                    content: $sent->content,
                    status: $sent->status,
                    type: $sent->type,
                    authorId: $sent->authorId,
                    createdAt: new DateTimeImmutable(),
                    updatedAt: new DateTimeImmutable(),
                );
            }
        );

        ( new CreatePostFromResearchTool($this->errors, null, $content) )->execute(
            array('data' => array('title' => 'T', 'report' => 'B'), 'category_id' => 7),
            self::CONTEXT
        );

        $this->assertSame(array('category' => array('Research')), $command->taxonomyInput);
    }

    public function test_create_post_refuses_an_unknown_category_id(): void
    {
        $content = $this->contentStore();
        $content->method('listTaxonomyTerms')->willReturn(array());

        $result = ( new CreatePostFromResearchTool($this->errors, null, $content) )->execute(
            array('data' => array('title' => 'T', 'report' => 'B'), 'category_id' => 999),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('category_id', $result['error']['data']);
    }

    public function test_create_post_reads_a_record_and_marks_it_published_without_losing_the_body(): void
    {
        $record = array(
            'id'          => 'rec-1',
            'collection'  => 'research',
            'title'       => 'A study',
            'description' => 'Short',
            'status'      => 'draft',
            'tags'        => array('research'),
            'body'        => array('markdown' => '# Findings'),
            'meta'        => array('k' => 'v'),
        );

        $paper = $this->createMock(PaperStoreInterface::class);
        $paper->method('find')->willReturn($record);

        $saved = null;
        $paper->method('save')->willReturnCallback(
            static function (string $collection, array $payload) use (&$saved): array {
                $saved = array('collection' => $collection, 'payload' => $payload);

                return $payload;
            }
        );

        $content = $this->contentStore();
        $content->method('create')->willReturn(
            new ContentItem(
                id: 11,
                title: 'A study',
                content: '# Findings',
                status: 'draft',
                type: 'post',
                authorId: 1,
                createdAt: new DateTimeImmutable(),
                updatedAt: new DateTimeImmutable(),
            )
        );

        $result = ( new CreatePostFromResearchTool($this->errors, $paper, $content) )->execute(
            array(
                'paper_store_record_id'  => 'rec-1',
                'paper_store_collection' => 'research',
                'update_paper_status'    => true,
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('paper_store', $result['data']['source']);
        $this->assertSame('published', $result['data']['paper_store_status']);

        $this->assertSame('research', $saved['collection']);
        $this->assertSame('published', $saved['payload']['status']);
        $this->assertSame('# Findings', $saved['payload']['body']['markdown']);
        $this->assertSame(array('research'), $saved['payload']['tags']);
        $this->assertSame(11, $saved['payload']['meta']['post_id']);
    }

    public function test_create_post_refuses_an_unknown_record(): void
    {
        $paper = $this->createMock(PaperStoreInterface::class);
        $paper->method('find')->willReturn(null);

        $result = ( new CreatePostFromResearchTool($this->errors, $paper, $this->contentStore()) )->execute(
            array('paper_store_record_id' => 'nope', 'paper_store_collection' => 'research'),
            self::CONTEXT
        );

        $this->assertSame('record_not_found', $result['error']['code']);
    }

    public function test_create_post_without_a_content_store_is_an_error(): void
    {
        $result = ( new CreatePostFromResearchTool($this->errors) )->execute(
            array('data' => array('title' => 'T', 'report' => 'B')),
            self::CONTEXT
        );

        $this->assertSame('content_store_unavailable', $result['error']['code']);
    }

    // ─── schedule_channel_broadcast ──────────────────────────────────────

    public function test_broadcast_creates_a_channel_schedule(): void
    {
        $store = $this->scheduleStore();

        $result = ( new ScheduleChannelBroadcastTool(
            $this->errors,
            new CreateProScheduleTool($this->errors, $store),
            $store
        ) )->execute(
            array(
                'message'     => 'Standup in five.',
                'channels'    => array('slack', 'telegram'),
                'credentials' => array('slack' => array('webhook' => 'https://example.com')),
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(array('slack', 'telegram'), $result['data']['channels']);
        $this->assertSame('Channel Broadcast to slack, telegram', $result['data']['name']);
        $this->assertSame('Channel broadcast scheduled successfully.', $result['message']);

        $stored = $store->find($result['data']['schedule_id']);

        $this->assertSame(Schedule::TYPE_CHANNEL_BROADCAST, $stored['schedule_type']);
        $this->assertSame('single', $stored['schedule']);
        $this->assertTrue($stored['enabled']);
        $this->assertSame('Standup in five.', $stored['broadcast_config']['message']);
    }

    public function test_broadcast_requires_message_channels_and_credentials(): void
    {
        $store = $this->scheduleStore();
        $tool  = new ScheduleChannelBroadcastTool(
            $this->errors,
            new CreateProScheduleTool($this->errors, $store),
            $store
        );

        $this->assertSame(
            'missing_message',
            $tool->execute(array('channels' => array('slack'), 'credentials' => array('slack' => array())), self::CONTEXT)['error']['code']
        );
        $this->assertSame(
            'missing_channels',
            $tool->execute(array('message' => 'M', 'channels' => array('myspace'), 'credentials' => array('x' => array())), self::CONTEXT)['error']['code']
        );
        $this->assertSame(
            'missing_credentials',
            $tool->execute(array('message' => 'M', 'channels' => array('slack')), self::CONTEXT)['error']['code']
        );
    }

    public function test_broadcast_keeps_a_given_name_and_tags(): void
    {
        $store = $this->scheduleStore();

        $result = ( new ScheduleChannelBroadcastTool(
            $this->errors,
            new CreateProScheduleTool($this->errors, $store),
            $store
        ) )->execute(
            array(
                'name'        => 'Morning standup',
                'message'     => 'Go.',
                'channels'    => array('discord'),
                'credentials' => array('discord' => array('webhook' => 'https://example.com')),
                'tags'        => array('ops'),
            ),
            self::CONTEXT
        );

        $stored = $store->find($result['data']['schedule_id']);

        $this->assertSame('Morning standup', $stored['name']);
        $this->assertSame(array('ops'), $stored['tags']);
    }

    // ─── export_assistant_blueprint ──────────────────────────────────────

    public function test_blueprint_maps_the_bundle_entry(): void
    {
        $exporters = $this->createMock(AssistantExportInterface::class);
        $exporters->method('export')->willReturn(
            array(
                'success' => true,
                'bundle'  => array(
                    'format'         => 'nvoos-assistant',
                    'format_version' => 1,
                    'plugin_version' => '1.2.0',
                    'exported_at'    => '2026-01-01T00:00:00Z',
                    'assistants'     => array(
                        array(
                            'title'   => 'Ops assistant',
                            'excerpt' => 'Runs the estate.',
                            'content' => 'You are an operator.',
                            'meta'    => array(
                                'provider'    => 'openai',
                                'model'       => 'gpt-x',
                                'temperature' => 0.2,
                                'tools'       => array('list_projects', 'get_task_plan'),
                            ),
                        ),
                    ),
                ),
            )
        );

        $result = ( new ExportAssistantBlueprintTool($this->errors, $exporters) )->execute(
            array('assistant_id' => 3),
            self::CONTEXT
        );

        $blueprint = $result['data']['blueprint'];

        $this->assertSame('Ops assistant', $blueprint['name']);
        $this->assertSame('Runs the estate.', $blueprint['description']);
        $this->assertSame('You are an operator.', $blueprint['meta']['instructions']);
        $this->assertSame(array('list_projects', 'get_task_plan'), $blueprint['meta']['available_tools']);
        $this->assertSame('openai', $blueprint['meta']['provider']);
        $this->assertSame('gpt-x', $blueprint['meta']['model']);
        $this->assertSame(0.2, $blueprint['meta']['temperature']);
        $this->assertIsString($result['data']['json']);
    }

    public function test_blueprint_falls_back_to_the_first_forty_words(): void
    {
        $words     = implode(' ', array_fill(0, 50, 'word'));
        $exporters = $this->createMock(AssistantExportInterface::class);
        $exporters->method('export')->willReturn(
            array(
                'success' => true,
                'bundle'  => array('assistants' => array(array('title' => 'T', 'content' => $words, 'meta' => array()))),
            )
        );

        $result = ( new ExportAssistantBlueprintTool($this->errors, $exporters) )->execute(
            array('assistant_id' => 1),
            self::CONTEXT
        );

        $this->assertSame(40, str_word_count($result['data']['blueprint']['description']));
    }

    public function test_blueprint_refuses_an_unknown_assistant(): void
    {
        $exporters = $this->createMock(AssistantExportInterface::class);
        $exporters->method('export')->willReturn(
            array('success' => false, 'code' => 'assistant_not_found', 'error' => 'No assistant 9.')
        );

        $result = ( new ExportAssistantBlueprintTool($this->errors, $exporters) )->execute(
            array('assistant_id' => 9),
            self::CONTEXT
        );

        $this->assertSame('assistant_not_found', $result['error']['code']);
    }

    public function test_blueprint_without_an_export_engine_is_an_error(): void
    {
        $result = ( new ExportAssistantBlueprintTool($this->errors) )->execute(
            array('assistant_id' => 1),
            self::CONTEXT
        );

        $this->assertSame('assistant_export_unavailable', $result['error']['code']);
    }

    public function test_blueprint_save_to_media_without_a_file_store_is_an_error(): void
    {
        $exporters = $this->createMock(AssistantExportInterface::class);
        $exporters->method('export')->willReturn(
            array(
                'success' => true,
                'bundle'  => array('assistants' => array(array('title' => 'T', 'content' => 'P', 'meta' => array()))),
            )
        );

        $result = ( new ExportAssistantBlueprintTool($this->errors, $exporters) )->execute(
            array('assistant_id' => 1, 'save_to_media' => true),
            self::CONTEXT
        );

        $this->assertSame('media_store_unavailable', $result['error']['code']);
    }

    public function test_blueprint_saves_json_through_the_file_store(): void
    {
        $exporters = $this->createMock(AssistantExportInterface::class);
        $exporters->method('export')->willReturn(
            array(
                'success' => true,
                'bundle'  => array('assistants' => array(array('title' => 'Ops assistant', 'content' => 'P', 'meta' => array()))),
            )
        );

        $written = null;
        $files   = $this->createMock(FileStoreInterface::class);
        $files->method('store')->willReturnCallback(
            static function (string $path, string $filename, string $mime, int $owner) use (&$written): StoredFile {
                $written = array(
                    'json'     => (string) file_get_contents($path),
                    'filename' => $filename,
                    'mime'     => $mime,
                );

                return new StoredFile(
                    id: 9,
                    filename: $filename,
                    mimeType: $mime,
                    sizeBytes: 10,
                    localPath: $path,
                    ownerId: $owner,
                    createdAt: new DateTimeImmutable(),
                );
            }
        );

        $result = ( new ExportAssistantBlueprintTool($this->errors, $exporters, $files) )->execute(
            array('assistant_id' => 1, 'save_to_media' => true),
            self::CONTEXT
        );

        $this->assertSame(9, $result['data']['attachment_id']);
        $this->assertStringStartsWith('blueprint-ops-assistant-', $written['filename']);
        $this->assertSame('application/json', $written['mime']);
        $this->assertStringContainsString('"name": "Ops assistant"', $written['json']);
    }

    // ─── generate_research_report — formatting mode ──────────────────────

    public function test_formatting_mode_assembles_the_document(): void
    {
        $result = ( new GenerateResearchReportTool($this->errors) )->execute(
            array(
                'title'     => 'Quarterly review',
                'sections'  => array(
                    array('heading' => 'Findings', 'content' => 'Revenue rose.'),
                    array('heading' => 'Next steps', 'content' => 'Hire two people.'),
                ),
                'citations' => array('Annual report 2026'),
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['section_count']);
        $this->assertStringContainsString('# Quarterly review', $result['data']['report']);
        $this->assertStringContainsString('## Table of Contents', $result['data']['report']);
        $this->assertStringContainsString('1. Findings', $result['data']['report']);
        $this->assertStringContainsString('## Next steps', $result['data']['report']);
        $this->assertStringContainsString('1. Annual report 2026', $result['data']['report']);
        $this->assertGreaterThan(0, $result['data']['word_count']);
    }

    public function test_formatting_mode_honours_the_switches(): void
    {
        $result = ( new GenerateResearchReportTool($this->errors) )->execute(
            array(
                'title'             => 'T',
                'sections'          => array(
                    array('heading' => 'A', 'content' => 'One.'),
                    array('heading' => 'B', 'content' => 'Two.'),
                ),
                'citations'         => array('C'),
                'include_toc'       => false,
                'include_citations' => false,
            ),
            self::CONTEXT
        );

        $this->assertStringNotContainsString('Table of Contents', $result['data']['report']);
        $this->assertStringNotContainsString('## References', $result['data']['report']);
    }

    public function test_neither_mode_is_a_validation_error(): void
    {
        $result = ( new GenerateResearchReportTool($this->errors) )->execute(array(), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    // ─── generate_research_report — research mode ────────────────────────

    public function test_research_without_a_provider_router_reports_unavailable(): void
    {
        $result = ( new GenerateResearchReportTool($this->errors) )->execute(
            array('topic' => 'Solar panels'),
            self::CONTEXT
        );

        $this->assertSame('completion_unavailable', $result['error']['code']);
    }

    public function test_research_synthesises_and_assembles_a_report(): void
    {
        $answer = "```json\n" . json_encode(
            array(
                'title'     => 'Solar Report',
                'sections'  => array(
                    array('heading' => 'Findings', 'content' => 'The sun is bright.'),
                ),
                'citations' => array('Source A'),
            )
        ) . "\n```";

        $router = $this->router($answer);

        $result = ( new GenerateResearchReportTool($this->errors, null, $router) )->execute(
            array('topic' => 'Solar panels', 'depth' => 'basic'),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('Solar Report', $result['data']['title']);
        $this->assertSame(1, $result['data']['section_count']);
        $this->assertStringContainsString('## Findings', $result['data']['report']);
        $this->assertStringContainsString('1. Source A', $result['data']['report']);
        $this->assertSame('fake-model', $result['data']['model']);
        $this->assertSame(array(), $result['data']['sources']);
    }

    public function test_research_maps_search_hits_into_sources_and_citations(): void
    {
        $search = $this->searchTool(
            array(
                array('title' => 'Study A', 'url' => 'https://a.example', 'description' => 'About A'),
                array('title' => 'Study B', 'url' => 'https://b.example', 'description' => 'About B'),
            )
        );

        $answer = json_encode(
            array(
                'title'    => 'Solar Report',
                'sections' => array(array('heading' => 'Findings', 'content' => 'Bright.')),
            )
        );

        $result = ( new GenerateResearchReportTool($this->errors, $search, $this->router($answer)) )->execute(
            array('topic' => 'Solar', 'depth' => 'basic'),
            self::CONTEXT
        );

        $this->assertCount(2, $result['data']['sources']);
        $this->assertSame('About A', $result['data']['sources'][0]['snippet']);
        $this->assertStringContainsString('Study A - https://a.example', $result['data']['report']);
        $this->assertCount(1, $search->queries);
        $this->assertSame('Solar', $search->queries[0]);
    }

    public function test_research_names_a_failed_paper_store_save_without_losing_the_report(): void
    {
        $answer = json_encode(
            array(
                'title'    => 'T',
                'sections' => array(array('heading' => 'H', 'content' => 'C')),
            )
        );

        $result = ( new GenerateResearchReportTool($this->errors, null, $this->router($answer)) )->execute(
            array('topic' => 'Solar', 'save_to_paper_store' => true),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('This host has no Paper Store.', $result['data']['paper_store_error']);
        $this->assertSame('T', $result['data']['title']);
    }

    public function test_research_saves_the_report_to_the_paper_store(): void
    {
        $answer = json_encode(
            array(
                'title'    => 'T',
                'sections' => array(array('heading' => 'H', 'content' => 'C')),
            )
        );

        $saved = null;
        $paper = $this->createMock(PaperStoreInterface::class);
        $paper->method('save')->willReturnCallback(
            static function (string $collection, array $record) use (&$saved): array {
                $saved = array('collection' => $collection, 'record' => $record);

                return $record;
            }
        );

        $result = ( new GenerateResearchReportTool($this->errors, null, $this->router($answer), $paper) )->execute(
            array('topic' => 'Solar', 'save_to_paper_store' => true),
            self::CONTEXT
        );

        $this->assertSame('research-reports', $saved['collection']);
        $this->assertSame('research-report', $saved['record']['type']);
        $this->assertSame('draft', $saved['record']['status']);
        $this->assertStringContainsString('## H', $saved['record']['body']['markdown']);
        $this->assertContains('research-report', $saved['record']['tags']);
        $this->assertNotNull($result['data']['paper_store_id']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /**
     * @param array<int, array{id:int, name:string, slug:string, count:int}> $terms Category vocabulary.
     */
    private function contentStore(array $terms = array()): ContentStoreInterface
    {
        $store = $this->createMock(ContentStoreInterface::class);
        $store->method('listTaxonomyTerms')->willReturn($terms);

        return $store;
    }

    private function scheduleStore(): JsonScheduleStore
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nvoos-research-' . bin2hex(random_bytes(6));

        mkdir($root, 0775, true);
        $this->tempDirs[] = $root;

        return new JsonScheduleStore($root . DIRECTORY_SEPARATOR . 'schedules');
    }

    /**
     * A router whose single registered client answers with $answer.
     */
    private function router(string $answer): ProviderRouter
    {
        $settings = $this->createMock(SettingsStoreInterface::class);
        $settings->method('getDefaultProvider')->willReturn('openai');
        $settings->method('getDefaultModel')->willReturn('fake-model');
        $settings->method('getApiKey')->willReturn('test-key');
        $settings->method('getApiBaseUrl')->willReturn(null);
        $settings->method('getRequestTimeout')->willReturn(30);
        $settings->method('isEnabled')->willReturn(true);

        $errors = $this->errors;

        $client = new class ($settings, $this->createMock(HttpClientInterface::class), $errors) extends AbstractProviderClient {
            /** The answer every call returns. */
            public string $answer = '';

            public function __construct(
                SettingsStoreInterface $settings,
                HttpClientInterface $http,
                ErrorFactoryInterface $errors,
            ) {
                parent::__construct($settings, $http, $errors);
                $this->providerSlug = 'openai';
            }

            public function chat(array $messages, array $options = array()): mixed
            {
                return array(
                    'model'   => 'fake-model',
                    'choices' => array(
                        array('message' => array('content' => $this->answer)),
                    ),
                );
            }

            public function stream(array $messages, array $options = array(), ?callable $onChunk = null): mixed
            {
                return $this->chat($messages, $options);
            }

            public function listModels(): array
            {
                return array();
            }

            protected function getDefaultBaseUrl(): string
            {
                return 'https://example.invalid';
            }
        };

        $client->answer = $answer;

        $router = new ProviderRouter($settings, $errors);
        $router->register($client);

        return $router;
    }

    /**
     * A recording WebSearchTool fake.
     */
    private function searchTool(array $items): WebSearchTool
    {
        $settings = $this->createMock(SettingsStoreInterface::class);
        $settings->method('getApiKey')->willReturn(null);

        $errors = $this->errors;

        return new class ($errors, $settings, $this->createMock(HttpClientInterface::class), $items) extends WebSearchTool {
            /** @var list<string> */
            public array $queries = array();

            public function __construct(
                ErrorFactoryInterface $errors,
                SettingsStoreInterface $settings,
                HttpClientInterface $http,
                private readonly array $items = array(),
            ) {
                parent::__construct($errors, $settings, $http);
            }

            public function execute(array $arguments = array(), array $context = array()): mixed
            {
                $this->queries[] = (string) ( $arguments['query'] ?? '' );

                return $this->collection('Found ' . count($this->items) . ' results.', $this->items, count($this->items));
            }
        };
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach ((array) scandir($dir) as $entry) {
            if (! is_string($entry) || '.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($dir);
    }
}
