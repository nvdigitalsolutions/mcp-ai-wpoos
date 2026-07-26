<?php
declare(strict_types=1);
namespace Nvoos\Core\Tests\Integration\Tool;

use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\AgentOrchestrationInterface;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\CronStatusInterface;
use Nvoos\Core\Domain\Contract\EmailServiceInterface;
use Nvoos\Core\Domain\Contract\ErlangCInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\EventDispatcherInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\ImageProcessingInterface;
use Nvoos\Core\Domain\Contract\MemoryStoreInterface;
use Nvoos\Core\Domain\Contract\ProfessionRepositoryInterface;
use Nvoos\Core\Domain\Contract\QueueClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use PHPUnit\Framework\TestCase;

final class ToolRegistryIntegrationTest extends TestCase
{
    private ToolRegistry $registry;
    private ErrorFactoryInterface $errors;
    private EventDispatcherInterface $events;
    private SettingsStoreInterface $settings;
    private HttpClientInterface $http;
    private ContentStoreInterface $content;
    private QueueClientInterface $queue;
    private ErlangCInterface $erlang;
    private MemoryStoreInterface $memory;
    private AgentOrchestrationInterface $orch;
    private ImageProcessingInterface $img;
    private ProfessionRepositoryInterface $profs;
    private EmailServiceInterface $email;
    private CronStatusInterface $cron;

    protected function setUp(): void
    {
        $this->errors   = $this->createMock(ErrorFactoryInterface::class);
        $this->events   = $this->createMock(EventDispatcherInterface::class);
        $this->settings  = $this->createMock(SettingsStoreInterface::class);
        $this->http      = $this->createMock(HttpClientInterface::class);
        $this->content   = $this->createMock(ContentStoreInterface::class);
        $this->queue     = $this->createMock(QueueClientInterface::class);
        $this->erlang    = $this->createMock(ErlangCInterface::class);
        $this->memory    = $this->createMock(MemoryStoreInterface::class);
        $this->orch      = $this->createMock(AgentOrchestrationInterface::class);
        $this->img       = $this->createMock(ImageProcessingInterface::class);
        $this->profs     = $this->createMock(ProfessionRepositoryInterface::class);
        $this->email     = $this->createMock(EmailServiceInterface::class);
        $this->cron      = $this->createMock(CronStatusInterface::class);

        $this->errors->method('isError')->willReturnCallback(
            fn($v) => is_array($v) && isset($v['success']) && false === $v['success']
        );

        $this->registry = new ToolRegistry($this->events, $this->errors);
    }

    // ─── Data provider: discover all *Tool.php files ──────────────────

    public static function allToolClasses(): array
    {
        $toolDir = dirname(__DIR__, 3) . '/src/Tool/';
        $files   = glob($toolDir . '*Tool.php');
        $cases   = [];

        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (in_array($name, ['AbstractTool','AbstractClientSideTool','AbstractHuggingFaceTool'], true)) continue;
            $fqcn = "Nvoos\\Core\\Tool\\{$name}";
            $cases[$name] = [$fqcn];
        }

        return $cases;
    }

    /** @dataProvider allToolClasses */
    public function testInstantiation(string $fqcn): void
    {
        $instance = $this->make($fqcn);
        $this->assertInstanceOf($fqcn, $instance);
    }

    /** @dataProvider allToolClasses */
    public function testRegistration(string $fqcn): void
    {
        $tool = $this->make($fqcn);
        $this->registry->register($tool);
        $this->assertTrue($this->registry->has($tool->getSlug()));
    }

    public function testNoDuplicateSlugs(): void
    {
        $seen = [];
        foreach (self::allToolClasses() as $data) {
            $tool = $this->make($data[0]);
            $slug = $tool->getSlug();
            $this->assertArrayNotHasKey($slug, $seen, "Duplicate slug: {$slug}");
            $seen[$slug] = $data[0];
            $this->registry->register($tool);
        }
        $this->assertGreaterThan(150, count($seen));
    }

    public function testToolLookup(): void
    {
        $tool = $this->make('Nvoos\\Core\\Tool\\GetPostTool');
        $this->registry->register($tool);
        $this->assertSame($tool, $this->registry->get('get_post'));
    }

    public function testCanonicalEnvelope(): void
    {
        $tools = [
            ['Nvoos\\Core\\Tool\\CountTokensTool', ['text' => 'hello']],
            ['Nvoos\\Core\\Tool\\GenerateUuidTool', []],
            ['Nvoos\\Core\\Tool\\MathEvalTool', ['expression' => '2+2']],
            ['Nvoos\\Core\\Tool\\GenerateSlugTool', ['text' => 'Hello World']],
            ['Nvoos\\Core\\Tool\\FormatBytesTool', ['bytes' => 1024]],
        ];

        foreach ($tools as [$fqcn, $args]) {
            $tool   = $this->make($fqcn);
            $result = $tool->execute($args, ['user_id' => 0]);
            $this->assertIsArray($result, "{$fqcn}: must return array");
            $this->assertArrayHasKey('success', $result, "{$fqcn}: must have success key");
            if ($result['success']) {
                $this->assertArrayHasKey('message', $result, "{$fqcn}: success must have message");
            }
        }
    }

    public function testAllSchemasValid(): void
    {
        foreach (self::allToolClasses() as $data) {
            $tool   = $this->make($data[0]);
            $schema = $tool->getParametersSchema();
            $this->assertIsArray($schema);
            $this->assertSame('object', $schema['type'] ?? '', "{$data[0]}: schema type must be 'object'");
            // Properties is optional for parameterless tools.
            $this->registry->register($tool);
        }

        $defs = $this->registry->buildToolDefinitions();
        $this->assertGreaterThan(150, count($defs));
        foreach ($defs as $d) {
            $this->assertSame('function', $d['type']);
            $this->assertNotEmpty($d['function']['name']);
        }
    }

    public function testAllDescriptionsNonEmpty(): void
    {
        foreach (self::allToolClasses() as $data) {
            $desc = $this->make($data[0])->getDescription();
            $this->assertIsString($desc);
            $this->assertNotEmpty($desc);
            $this->assertGreaterThan(10, strlen($desc), "{$data[0]}: desc too short");
        }
    }

    // ─── Auto-wiring helper ──────────────────────────────────────────

    private function make(string $fqcn): object
    {
        $rc  = new \ReflectionClass($fqcn);
        $ctor = $rc->getConstructor();
        if (!$ctor) return $rc->newInstance();

        $args = [];
        foreach ($ctor->getParameters() as $p) {
            $t = $p->getType();
            if (!$t instanceof \ReflectionNamedType) { $args[] = null; continue; }
            $args[] = match ($t->getName()) {
                ErrorFactoryInterface::class        => $this->errors,
                EventDispatcherInterface::class     => $this->events,
                SettingsStoreInterface::class       => $this->settings,
                HttpClientInterface::class          => $this->http,
                ContentStoreInterface::class        => $this->content,
                QueueClientInterface::class         => $this->queue,
                ErlangCInterface::class             => $this->erlang,
                MemoryStoreInterface::class         => $this->memory,
                AgentOrchestrationInterface::class   => $this->orch,
                ImageProcessingInterface::class     => $this->img,
                ProfessionRepositoryInterface::class => $this->profs,
                EmailServiceInterface::class        => $this->email,
                CronStatusInterface::class          => $this->cron,
                default                             => $this->createMock($t->getName()),
            };
        }

        return $rc->newInstanceArgs($args);
    }
}
