<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Tests\TestCase;

/**
 * `get_system_logs` against this app's real stores — Proposal 090-M §13.
 *
 * The unit test pins the filters and the `since` parser with a mocked host. This
 * pins the wiring the unit test cannot: that the manifest row is servable here,
 * that the app binding (not the package's absent one) answers, and that a real
 * audit row is normalised into an entry with the level its HTTP status implies.
 *
 * The rows are inserted directly rather than through `AdminAuditLogger` because
 * the interface under test reads the trail; the writer has its own coverage.
 */
final class GetSystemLogsToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_container_builds_the_log_tool(): void
    {
        $this->assertTrue(
            $this->app->make(ToolRegistry::class)->has('get_system_logs'),
            'The manifest row is servable but the app did not register it.'
        );
    }

    public function test_it_reads_the_audit_trail_through_the_app_binding(): void
    {
        $this->insertAuditRows();

        $tool = $this->app->make(ToolRegistry::class)->get('get_system_logs');

        $this->assertInstanceOf(ToolInterface::class, $tool);

        $result = $tool->execute();

        $this->assertTrue($result['success']);
        $this->assertSame(array('audit', 'traces'), $result['data']['sources']);

        $byType = array();

        foreach ($result['data']['entries'] as $entry) {
            $byType[ $entry['type'] ] = $entry;
        }

        $this->assertArrayHasKey('audit.request', $byType, 'The refused request row is missing.');
        $this->assertArrayHasKey('audit.record', $byType, 'The change row is missing.');

        // A 403 is a warning, not an error: the request was refused, not failed.
        $this->assertSame('warning', $byType['audit.request']['level']);
        $this->assertSame('(system)', $byType['audit.request']['actor']);
        $this->assertSame(403, $byType['audit.request']['detail']['status']);

        // A change row is a notice, and the diff flag survives normalisation.
        $this->assertSame('notice', $byType['audit.record']['level']);
        $this->assertTrue($byType['audit.record']['detail']['has_diff']);
        $this->assertSame('settings', $byType['audit.record']['detail']['resource']);
    }

    public function test_type_and_level_filters_apply_to_real_rows(): void
    {
        $this->insertAuditRows();

        $tool = $this->app->make(ToolRegistry::class)->get('get_system_logs');
        $this->assertInstanceOf(ToolInterface::class, $tool);

        $onlyRequests = $tool->execute(array('activity_types' => array('audit.request')));
        $this->assertSame(
            array('audit.request'),
            array_values(array_unique(array_column($onlyRequests['data']['entries'], 'type')))
        );

        $onlyWarnings = $tool->execute(array('levels' => array('warning')));
        $this->assertSame(
            array('audit.request'),
            array_values(array_unique(array_column($onlyWarnings['data']['entries'], 'type')))
        );
    }

    /**
     * One refused request row and one applied change, which is the pair the
     * trail exists to show together.
     */
    private function insertAuditRows(): void
    {
        $at = gmdate('Y-m-d H:i:s');

        DB::table('nvoos_admin_audit')->insert(
            array(
                array(
                    'tenant_id'  => null,
                    'user_id'    => null,
                    'panel'      => 'operator',
                    'method'     => 'DELETE',
                    'path'       => '/ops/estates/acme',
                    'route'      => 'operator.estates.destroy',
                    'status'     => 403,
                    'payload'    => null,
                    'ip'         => null,
                    'created_at' => $at,
                    'resource'   => null,
                    'record_id'  => null,
                    'action'     => null,
                    'before'     => null,
                    'after'      => null,
                ),
                array(
                    'tenant_id'  => 'default',
                    'user_id'    => null,
                    'panel'      => 'admin',
                    'method'     => 'PUT',
                    'path'       => '/admin/default/settings',
                    'route'      => 'admin.settings.update',
                    'status'     => 200,
                    'payload'    => null,
                    'ip'         => null,
                    'created_at' => $at,
                    'resource'   => 'settings',
                    'record_id'  => 'default',
                    'action'     => 'update',
                    'before'     => json_encode(array('default_model' => 'a')),
                    'after'      => json_encode(array('default_model' => 'b')),
                ),
            )
        );
    }
}
