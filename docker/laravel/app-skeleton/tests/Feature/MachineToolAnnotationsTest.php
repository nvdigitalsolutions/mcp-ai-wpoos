<?php

namespace Tests\Feature;

use App\Models\Assistant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AssistantWriter;
use App\Support\TenantContext;
use App\Support\Tools\MachineToolSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Application\Tool\ToolReadiness;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Tests\TestCase;

/**
 * Annotations as a veto — Proposal 159 slice 4, 149 plan §126.
 *
 * The manifest already carries MCP annotations for most tools, and every
 * state-changing tool declares `readOnlyHint: false`. The missing piece was a
 * reader: the machine policy ignored them. These tests pin the rule that the
 * reader implements.
 *
 * The rule is a **veto, never a permit**. `oos.machine_tools` is the operator
 * saying a machine may run a tool; a tool's own declaration can only add
 * "not unattended". Applying the specification's defaults (readOnlyHint false,
 * destructiveHint true) to a tool that declares *nothing* would route every
 * unannotated tool to a human and make the allowlist meaningless, so absence is
 * treated as no claim.
 */
final class MachineToolAnnotationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_only_hint_does_not_veto(): void
    {
        $this->assertNull($this->safety(array('readOnlyHint' => true))->veto('probe'));
    }

    public function test_read_only_false_vetoes(): void
    {
        $this->assertSame(
            'the tool declares readOnlyHint: false',
            $this->safety(array('readOnlyHint' => false))->veto('probe')
        );
    }

    public function test_destructive_hint_vetoes(): void
    {
        $this->assertSame(
            'the tool declares destructiveHint',
            $this->safety(array('destructiveHint' => true))->veto('probe')
        );
    }

    public function test_an_undeclared_tool_makes_no_claim(): void
    {
        // Not "read-only by default": no claim at all. The allowlist decides.
        $this->assertNull($this->safety(array())->veto('probe'));
    }

    public function test_open_world_alone_does_not_veto(): void
    {
        // openWorldHint describes where data comes from, not whether the call
        // mutates. A read-only open-world tool is still a read.
        $this->assertNull($this->safety(array('openWorldHint' => true))->veto('probe'));
    }

    public function test_a_declared_gated_capability_flag_vetoes(): void
    {
        $registry = app(ToolRegistry::class);
        $registry->register($this->stubWriter());

        $safety = new MachineToolSafety($this->readiness(array()), $registry);

        $this->assertSame(
            'the tool declares the capability flag state_changing',
            $safety->veto('stub_writer')
        );
    }

    public function test_an_allowlisted_state_changing_tool_is_routed_to_approval(): void
    {
        config(array('oos.machine_tools' => array('create_post')));

        $this->usesManifest(array(
            'create_post' => array('read_only' => false, 'destructive' => false),
        ));

        $this->postTool()->assertStatus(202)->assertJsonPath('status', 'pending_approval');

        $this->assertDatabaseHas('nvoos_approvals', array(
            'tool' => 'create_post',
            'status' => 'pending',
        ));
    }

    public function test_an_allowlisted_read_only_tool_still_executes(): void
    {
        config(array('oos.machine_tools' => array('base64')));

        $this->usesManifest(array(
            'base64' => array('read_only' => true, 'destructive' => false),
        ));

        $this->postTool('base64', array('text' => 'hi'))
            ->assertOk()
            ->assertJsonPath('data.output', 'aGk=');
    }

    /**
     * @param array<string, bool> $annotations
     */
    private function safety(array $annotations): MachineToolSafety
    {
        // Hint-shaped input: the caller writes the annotations exactly as a
        // manifest row would, so the test exercises the real reader.
        $row = array('class' => 'Probe', 'readiness' => 'ready');

        if (array() !== $annotations) {
            $row['annotations'] = $annotations;
        }

        return new MachineToolSafety(
            new ToolReadiness($this->writeRaw(array('probe' => $row))),
            app(ToolRegistry::class)
        );
    }

    /**
     * @param array<string, array<string, mixed>> $tools
     */
    private function writeRaw(array $tools): string
    {
        $path = storage_path('framework/testing/annotations-' . uniqid('', true) . '.php');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, '<?php return ' . var_export(array('version' => 1, 'tools' => $tools), true) . ';');

        return $path;
    }

    /**
     * A readiness instance over a temporary manifest.
     *
     * @param array<string, array{read_only?: bool, destructive?: bool}> $rows
     */
    private function readiness(array $rows): ToolReadiness
    {
        return new ToolReadiness($this->writeManifest($rows));
    }

    /**
     * @param array<string, array{read_only?: bool, destructive?: bool}> $rows
     */
    private function writeManifest(array $rows): string
    {
        $tools = array();
        foreach ($rows as $slug => $flags) {
            $annotations = array();
            if (array_key_exists('read_only', $flags)) {
                $annotations['readOnlyHint'] = (bool) $flags['read_only'];
            }
            if (array_key_exists('destructive', $flags)) {
                $annotations['destructiveHint'] = (bool) $flags['destructive'];
            }
            $tools[$slug] = array(
                'class' => 'Probe',
                'readiness' => 'ready',
                'annotations' => $annotations,
            );
        }
        if (array() === $tools) {
            $tools['_placeholder'] = array('class' => 'Probe', 'readiness' => 'ready');
        }

        return $this->writeRaw($tools);
    }

    /**
     * @param array<string, array{read_only?: bool, destructive?: bool}> $rows
     */
    private function usesManifest(array $rows): void
    {
        $this->app->instance(ToolReadiness::class, $this->readiness($rows));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function postTool(
        string $tool = 'create_post',
        array $arguments = array('title' => 'Hello')
    ): \Illuminate\Testing\TestResponse {
        $user = User::factory()->admin()->withoutEstate()->create();
        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching([$user->id => array('role' => 'owner')]);

        TenantContext::set(Tenant::DEFAULT_ID);
        $assistant = app(AssistantWriter::class)->create(array(
            'title' => 'Annotations fixture',
            'tools' => array($tool),
        ));
        TenantContext::forget();

        $token = $user->createToken('machine', array('assistant:' . $assistant->getKey()))->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/tools/' . $tool, array('arguments' => $arguments));
    }

    private function stubWriter(): ToolInterface
    {
        return new class implements ToolInterface, ToolCapabilityFlagsInterface {
            public function getSlug(): string
            {
                return 'stub_writer';
            }

            public function getName(): string
            {
                return 'Stub writer';
            }

            public function getDescription(): string
            {
                return 'Test stub.';
            }

            /**
             * @return array<string, mixed>
             */
            public function getParametersSchema(): array
            {
                return array('type' => 'object', 'properties' => array());
            }

            public function getRequiredCapability(): string
            {
                return '';
            }

            public function execute(array $arguments = array(), array $context = array()): mixed
            {
                return array();
            }

            /**
             * @return list<string>
             */
            public function getCapabilityFlags(): array
            {
                return array('state_changing');
            }
        };
    }
}
