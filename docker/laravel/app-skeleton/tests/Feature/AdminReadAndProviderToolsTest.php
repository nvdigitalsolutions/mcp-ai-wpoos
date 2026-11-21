<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Nvoos\Core\Application\Skill\SkillRegistry;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\MemoryStoreInterface;
use Nvoos\Core\Domain\Contract\ProfessionRepositoryInterface;
use Nvoos\Core\Domain\Contract\QueueClientInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Nvoos\Core\Domain\Entity\JobStatus;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Tool\ContentFreshnessCheckerTool;
use Nvoos\Core\Tool\GetCurrentUserTool;
use Nvoos\Core\Tool\GetFileInfoTool;
use Nvoos\Core\Tool\GetJobStatusTool;
use Nvoos\Core\Tool\GetModelInformationTool;
use Nvoos\Core\Tool\GetPostTypeSchemaTool;
use Nvoos\Core\Tool\GetProfessionTool;
use Nvoos\Core\Tool\GetSettingTool;
use Nvoos\Core\Tool\GetSiteSummaryTool;
use Nvoos\Core\Tool\ListAvailableModelsTool;
use Nvoos\Core\Tool\ListBatchesTool;
use Nvoos\Core\Tool\ListCronJobsTool;
use Nvoos\Core\Tool\ListJobsTool;
use Nvoos\Core\Tool\ListProfessionsTool;
use Nvoos\Core\Tool\ListSettingsTool;
use Nvoos\Core\Tool\ListSkillsTool;
use Nvoos\Core\Tool\ProfessionStatsTool;
use Nvoos\Core\Tool\RecallMemoryTool;
use Nvoos\Core\Tool\SearchAttachmentsTool;
use Nvoos\Core\Tool\ValidateToolSchemaTool;
use Nvoos\Core\Tool\VerifyInformationTool;
use Tests\TestCase;

/**
 * Batch 6 evidence: the admin, queue, file, catalogue and near-pure tools.
 *
 * Tools are resolved from the container by class, not from `ToolRegistry`: the
 * registrar builds every tool once at boot, so a fake bound in a test only
 * reaches a tool the container constructs afterwards. `Http::fake()` is the
 * exception — the HTTP client is read at call time, so provider tests can use
 * whichever path they like.
 */
class AdminReadAndProviderToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->admin()->create();
        $this->actingAs($this->user);
    }

    private function tool(string $class): ToolInterface
    {
        $tool = $this->app->make($class);

        $this->assertInstanceOf(ToolInterface::class, $tool);

        return $tool;
    }

    /**
     * @param class-string<ToolInterface> $class
     * @param array<string, mixed>        $arguments
     * @return array<string, mixed>
     */
    private function callClass(string $class, array $arguments = array()): array
    {
        $result = $this->tool($class)->execute($arguments, array('user_id' => $this->user->id));

        $this->assertIsArray($result, $class . ' returned a non-array: ' . print_r($result, true));
        $this->assertTrue($result['success'] ?? false, $class . ': ' . print_r($result, true));

        return $result;
    }

    private function queue(?JobStatus $status = null, array $jobs = array()): void
    {
        $status = $status ?? new JobStatus('job-1', 'completed');

        $this->app->instance(QueueClientInterface::class, new class($status, $jobs) implements QueueClientInterface {
            public function __construct(private JobStatus $status, private array $jobs)
            {
            }

            public function enqueue(string $handler, array $payload, array $options = array()): string
            {
                return 'job-1';
            }

            public function getStatus(string $jobId): JobStatus
            {
                return $this->status;
            }

            public function cancel(string $jobId): bool
            {
                return true;
            }

            public function schedule(string $handler, array $payload, string $cronExpression): string
            {
                return 'sched-1';
            }

            public function unschedule(string $scheduleId): void
            {
            }

            public function listJobs(array $filters = array(), int $limit = 50): array
            {
                return $this->jobs;
            }
        });
    }

    private function files(?StoredFile $file = null, array $found = array()): void
    {
        $this->app->instance(FileStoreInterface::class, new class($file, $found) implements FileStoreInterface {
            public function __construct(private ?StoredFile $file, private array $found)
            {
            }

            public function store(string $localPath, string $filename, string $mimeType, int $userId): StoredFile
            {
                throw new \LogicException('not used');
            }

            public function getPath(int $fileId): ?string
            {
                return null;
            }

            public function getMetadata(int $fileId): ?StoredFile
            {
                return $this->file;
            }

            public function userCanAccess(int $fileId, int $userId): bool
            {
                return true;
            }

            public function delete(int $fileId): void
            {
            }

            public function findByMetadata(array $criteria, int $limit = 50): array
            {
                return $this->found;
            }
        });
    }

    private function memories(array $results): void
    {
        $this->app->instance(MemoryStoreInterface::class, new class($results) implements MemoryStoreInterface {
            public function __construct(private array $results)
            {
            }

            public function store(array $record): array
            {
                return $record;
            }

            public function get(string $memoryId): array
            {
                return array();
            }

            public function update(string $memoryId, array $patch): array
            {
                return $patch;
            }

            public function delete(string $memoryId): array
            {
                return array();
            }

            public function search(string $query, array $filters = array(), int $limit = 10): array
            {
                return $this->results;
            }

            public function listByAgent(string $agentId, array $filters = array(), int $limit = 50, int $offset = 0): array
            {
                return array('memories' => $this->results);
            }
        });
    }

    public function test_content_freshness_checker_scores_stale_content(): void
    {
        $result = $this->callClass(ContentFreshnessCheckerTool::class, array(
            'content' => 'Updated yesterday, this is the latest pricing.',
            'publish_date' => date('Y-m-d', strtotime('-400 days')),
            'age_threshold_days' => 365,
        ));

        $this->assertSame('stale', $result['data']['status']);
        $this->assertGreaterThan(365, $result['data']['age_days']);
        $this->assertTrue($result['data']['needs_review']);
        $this->assertGreaterThan(0, $result['data']['time_sensitive_refs']);
    }

    public function test_validate_tool_schema_accepts_a_schema_and_refuses_a_bare_file(): void
    {
        $ok = $this->callClass(ValidateToolSchemaTool::class, array(
            'schema' => array(
                'type' => 'object',
                'properties' => array('title' => array('type' => 'string')),
                'required' => array('title'),
            ),
        ));

        $this->assertSame('Schema validated.', $ok['message']);

        // File extraction is not implemented in this runtime, and the tool says so
        // rather than pretending to have read the file. `validationFailed()`
        // returns the exception object rather than throwing it.
        $refused = $this->tool(ValidateToolSchemaTool::class)
            ->execute(array('tool_file' => __FILE__), array('user_id' => $this->user->id));

        $this->assertInstanceOf(\RuntimeException::class, $refused);
        $this->assertStringContainsString('schema', $refused->getMessage());
    }

    public function test_verify_information_reports_the_source_count(): void
    {
        $result = $this->callClass(VerifyInformationTool::class, array(
            'claim' => 'The sky is green.',
            'sources' => array(
                array('url' => 'https://a.example', 'content' => 'The sky is blue.', 'credibility' => 'high'),
                array('url' => 'https://b.example', 'content' => 'Sky colour varies.', 'credibility' => 'medium'),
            ),
        ));

        $this->assertSame('The sky is green.', $result['data']['claim']);
        $this->assertSame(2, $result['data']['sources_checked']);
        $this->assertIsString($result['data']['verdict']);
        $this->assertNotSame('', $result['data']['recommendation']);
    }

    public function test_get_current_user_reports_the_signed_in_user_and_the_anonymous_case(): void
    {
        $result = $this->callClass(GetCurrentUserTool::class);

        $this->assertSame($this->user->id, $result['data']['id']);

        Auth::logout();

        $anonymous = $this->callClass(GetCurrentUserTool::class);

        $this->assertFalse($anonymous['data']['authenticated']);
        $this->assertSame(0, $anonymous['data']['user_id']);
    }

    public function test_get_setting_returns_a_configured_value_and_reports_a_missing_one(): void
    {
        config(array('oos.openai_api_key' => 'sk-configured'));

        $found = $this->callClass(GetSettingTool::class, array('key' => 'openai_api_key'));

        $this->assertTrue($found['data']['found']);
        $this->assertSame('sk-configured', $found['data']['value']);

        $missing = $this->callClass(GetSettingTool::class, array('key' => 'definitely_not_a_setting'));

        $this->assertFalse($missing['data']['found']);
        $this->assertNull($missing['data']['value']);
    }

    public function test_list_settings_redacts_api_keys(): void
    {
        config(array(
            'oos.openai_api_key' => 'sk-must-not-leak',
            'oos.default_provider' => 'openai',
        ));

        $result = $this->callClass(ListSettingsTool::class);

        $this->assertGreaterThan(0, $result['data']['count']);
        $this->assertSame('openai', $result['data']['settings']['default_provider']);
        $this->assertNotSame('sk-must-not-leak', $result['data']['settings']['openai_api_key']);
    }

    public function test_get_site_summary_reports_provider_and_features(): void
    {
        config(array('oos.default_provider' => 'openai'));

        $result = $this->callClass(GetSiteSummaryTool::class);

        $this->assertSame('openai', $result['data']['default_provider']);
        $this->assertIsArray($result['data']['features']);
        $this->assertArrayHasKey('rate_limiting', $result['data']['features']);
    }

    public function test_get_job_status_returns_the_status_payload(): void
    {
        $this->queue(new JobStatus('job-9', 'failed', null, 'boom'));

        $result = $this->callClass(GetJobStatusTool::class, array('job_id' => 'job-9'));

        $this->assertSame('job-9', $result['data']['job_id']);
        $this->assertSame('failed', $result['data']['status']);
        $this->assertSame('boom', $result['data']['error']);
    }

    public function test_list_jobs_counts_each_status(): void
    {
        $this->queue(null, array(
            new JobStatus('job-1', 'completed'),
            new JobStatus('job-2', 'failed'),
        ));

        $result = $this->callClass(ListJobsTool::class);

        $this->assertSame(2, $result['data']['total']);
        $this->assertSame(1, $result['data']['counts']['completed']);
        $this->assertSame(1, $result['data']['counts']['failed']);
        $this->assertSame(0, $result['data']['counts']['running']);
    }

    public function test_list_cron_jobs_reports_an_empty_schedule(): void
    {
        $this->queue();

        $result = $this->callClass(ListCronJobsTool::class);

        $this->assertSame('No tasks are currently scheduled.', $result['message']);
        $this->assertSame(0, $result['data']['count']);
    }

    public function test_get_file_info_returns_the_file_metadata(): void
    {
        $this->files(new StoredFile(7, 'report.pdf', 'application/pdf', 1024, '/tmp/report.pdf', 1, new \DateTimeImmutable()));

        $result = $this->callClass(GetFileInfoTool::class, array('file_id' => 7));

        $this->assertSame('report.pdf', $result['data']['filename']);
        $this->assertSame('application/pdf', $result['data']['mime_type']);
    }

    public function test_search_attachments_returns_matching_files(): void
    {
        $this->files(null, array(
            new StoredFile(1, 'a.png', 'image/png', 10, '/tmp/a.png', 1, new \DateTimeImmutable()),
            new StoredFile(2, 'b.png', 'image/png', 20, '/tmp/b.png', 1, new \DateTimeImmutable()),
        ));

        $result = $this->callClass(SearchAttachmentsTool::class, array('query' => 'png'));

        $this->assertSame(2, $result['data']['total']);
        $this->assertSame('a.png', $result['data']['items'][0]['filename']);
    }

    /**
     * @return string A directory holding one profession catalogue file.
     */
    private function professionCatalogue(): string
    {
        $dir = sys_get_temp_dir() . '/oos-professions-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);

        file_put_contents(
            $dir . '/trades.json',
            json_encode(array(
                'professions' => array(
                    array('slug' => 'plumber', 'title' => 'Plumber', 'category' => 'Trades'),
                    array('slug' => 'electrician', 'title' => 'Electrician', 'category' => 'Trades'),
                ),
            )),
        );

        config(array('oos.professions.path' => $dir));
        $this->app->forgetInstance(ProfessionRepositoryInterface::class);

        return $dir;
    }

    private function removeProfessionCatalogue(string $dir): void
    {
        @unlink($dir . '/trades.json');
        @rmdir($dir);
    }

    public function test_get_profession_finds_a_catalogue_entry(): void
    {
        $dir = $this->professionCatalogue();

        $result = $this->callClass(GetProfessionTool::class, array('slug' => 'plumber'));

        $this->assertSame('plumber', $result['data']['slug']);
        $this->assertSame('Plumber', $result['data']['name']);

        $this->removeProfessionCatalogue($dir);
    }

    public function test_list_professions_reads_the_catalogue(): void
    {
        $dir = $this->professionCatalogue();

        $result = $this->callClass(ListProfessionsTool::class);

        $this->assertSame(2, $result['data']['total']);
        $this->assertContains('Plumber', $result['data']['items']);

        $this->removeProfessionCatalogue($dir);
    }

    public function test_profession_stats_counts_the_catalogue(): void
    {
        $dir = $this->professionCatalogue();

        $result = $this->callClass(ProfessionStatsTool::class);

        $this->assertSame(2, $result['data']['total_professions']);
        $this->assertSame(1, $result['data']['category_count']);

        $this->removeProfessionCatalogue($dir);
    }

    public function test_get_post_type_schema_describes_a_registered_type_and_refuses_an_unknown_one(): void
    {
        $result = $this->callClass(GetPostTypeSchemaTool::class, array('post_type' => 'mcp_ai_student'));

        $this->assertIsArray($result['data']['taxonomies']);
        $this->assertStringContainsString('Schema retrieved for post type', $result['message']);

        // An unregistered type is reported, not invented.
        $refused = $this->tool(GetPostTypeSchemaTool::class)
            ->execute(array('post_type' => 'not_a_type'), array('user_id' => $this->user->id));

        $this->assertInstanceOf(\RuntimeException::class, $refused);
    }

    public function test_list_skills_reads_a_seeded_registry(): void
    {
        $root = sys_get_temp_dir() . '/oos-skills-' . bin2hex(random_bytes(4));

        foreach (array('alpha', 'beta') as $name) {
            mkdir($root . '/' . $name, 0777, true);
            file_put_contents(
                $root . '/' . $name . '/SKILL.md',
                "---\nname: {$name}\ndescription: The {$name} skill.\n---\nDo {$name}.\n",
            );
        }

        $registry = $this->app->make(SkillRegistry::class);
        $registry->loadFromDirectory($root);
        $this->app->instance(SkillRegistry::class, $registry);

        $result = $this->callClass(ListSkillsTool::class);

        $this->assertSame(2, $result['data']['total']);

        foreach (array('alpha', 'beta') as $name) {
            @unlink($root . '/' . $name . '/SKILL.md');
            @rmdir($root . '/' . $name);
        }
        @rmdir($root);
    }

    public function test_recall_memory_returns_store_results(): void
    {
        $this->memories(array(
            array('id' => 'm1', 'content' => 'The estate uses PHP 8.3.'),
            array('id' => 'm2', 'content' => 'Deploys go through Velocity.'),
        ));

        $result = $this->callClass(RecallMemoryTool::class, array(
            'agent_id' => 'agent-1',
            'wing' => 'deployments',
            'query' => 'php',
        ));

        $this->assertSame(2, $result['data']['count']);
        $this->assertSame('m1', $result['data']['memories'][0]['id']);
    }

    public function test_get_model_information_returns_the_provider_payload(): void
    {
        config(array('oos.openai_api_key' => 'sk-test'));

        Http::fake(array(
            'api.openai.com/v1/models/gpt-5' => Http::response(array('id' => 'gpt-5', 'object' => 'model')),
        ));

        $result = $this->callClass(GetModelInformationTool::class, array('model' => 'gpt-5'));

        $this->assertSame('gpt-5', $result['data']['id']);
        $this->assertSame('model', $result['data']['object']);
    }

    public function test_list_available_models_maps_the_provider_list(): void
    {
        config(array('oos.openai_api_key' => 'sk-test'));

        Http::fake(array(
            'api.openai.com/v1/models' => Http::response(array(
                'data' => array(array('id' => 'gpt-5'), array('id' => 'gpt-4o')),
            )),
        ));

        $result = $this->callClass(ListAvailableModelsTool::class);

        $this->assertSame(2, $result['data']['total']);
        $this->assertSame(
            array('gpt-4o', 'gpt-5'),
            array_column($result['data']['items'], 'id')
        );
    }

    public function test_list_batches_maps_the_batch_api(): void
    {
        config(array('oos.openai_api_key' => 'sk-test'));

        Http::fake(array(
            'api.openai.com/v1/batches?*' => Http::response(array(
                'data' => array(array(
                    'id' => 'batch_1',
                    'status' => 'completed',
                    'endpoint' => '/v1/responses',
                    'created_at' => 1700000000,
                    'request_counts' => array('total' => 3, 'completed' => 3, 'failed' => 0),
                )),
            )),
        ));

        $result = $this->callClass(ListBatchesTool::class);

        $this->assertSame(1, $result['data']['total']);
        $this->assertSame('batch_1', $result['data']['items'][0]['id']);
        $this->assertSame('completed', $result['data']['items'][0]['status']);
    }
}
