<?php

/**
 * Tool-contract parity across hosts (Proposal 167 gate 9, contract half).
 *
 * The eleven shared database tools exist twice: as WordPress Pro classes and as
 * `lib/core` classes. The port is only a port if a caller cannot tell them apart,
 * so this compares the observable contract of each pair — slug, name,
 * description, required capability and parameter schema — and fails on any
 * difference.
 *
 * Schema drift is the failure this exists to catch. A new filter operator, a
 * renamed argument or a changed cap would otherwise diverge silently: the
 * WordPress tool keeps working, the Laravel tool keeps working, and a model
 * trained on one produces calls the other refuses.
 *
 * The five host-bound tools are deliberately absent. `db_export` needs a file
 * store, `db_test_connection` a credential probe, `db_audit_tail` an audit
 * store, and `db_propose_write` / `db_apply_write` a proposal store plus an
 * approver — none of which the Laravel runtime has. They stay WordPress-only,
 * and the implementation plan records that as a scope decision rather than an
 * omission.
 *
 * @package Nvoos\Tests
 * @since   3.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Tests\Database\Parity;

use Nvoos\Core\Tool\Database\AggregateTool;
use Nvoos\Core\Tool\Database\DescribeTool;
use Nvoos\Core\Tool\Database\ExportTool;
use Nvoos\Core\Tool\Database\ExplainTool;
use Nvoos\Core\Tool\Database\ListConnectionsTool;
use Nvoos\Core\Tool\Database\ProfileTableTool;
use Nvoos\Core\Tool\Database\QueryTool;
use Nvoos\Core\Tool\Database\SearchTool;
use Nvoos\Core\Tool\Database\SelectTool;
use Nvoos\Core\Tool\Database\TailAuditTool;
use Nvoos\Core\Tool\Database\TestConnectionTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToolContractParityTest extends TestCase
{
    /**
     * Shared slug → [WordPress class, core class, slug].
     *
     * The slug is repeated as the third element because a data provider passes
     * values, not keys.
     *
     * @return array<string, array{0: class-string, 1: class-string, 2: string}>
     */
    public static function sharedTools(): array
    {
        return array(
            'db_list_connections' => array('WP_MCP_AI_Tool_Db_List_Connections', ListConnectionsTool::class, 'db_list_connections'),
            'db_describe'         => array('WP_MCP_AI_Tool_Db_Describe', DescribeTool::class, 'db_describe'),
            'db_select'           => array('WP_MCP_AI_Tool_Db_Select', SelectTool::class, 'db_select'),
            'db_aggregate'        => array('WP_MCP_AI_Tool_Db_Aggregate', AggregateTool::class, 'db_aggregate'),
            'db_search'           => array('WP_MCP_AI_Tool_Db_Search', SearchTool::class, 'db_search'),
            'db_explain'          => array('WP_MCP_AI_Tool_Db_Explain', ExplainTool::class, 'db_explain'),
            'db_query'            => array('WP_MCP_AI_Tool_Db_Query', QueryTool::class, 'db_query'),
            'db_profile_table'    => array('WP_MCP_AI_Tool_Db_Profile_Table', ProfileTableTool::class, 'db_profile_table'),
            'db_test_connection'  => array('WP_MCP_AI_Tool_Db_Test_Connection', TestConnectionTool::class, 'db_test_connection'),
            'db_export'           => array('WP_MCP_AI_Tool_Db_Export', ExportTool::class, 'db_export'),
            'db_audit_tail'       => array('WP_MCP_AI_Tool_Db_Audit_Tail', TailAuditTool::class, 'db_audit_tail'),
        );
    }

    #[DataProvider('sharedTools')]
    public function test_the_slug_matches(string $wordpress, string $core, string $slug): void
    {
        $this->assertSame($slug, (new $wordpress())->get_slug());
        $this->assertSame($slug, (new \ReflectionClass($core))->newInstanceWithoutConstructor()->getSlug());
    }

    #[DataProvider('sharedTools')]
    public function test_the_name_matches(string $wordpress, string $core, string $slug): void
    {
        unset($slug);

        $this->assertSame((new $wordpress())->get_name(), (new \ReflectionClass($core))->newInstanceWithoutConstructor()->getName());
    }

    #[DataProvider('sharedTools')]
    public function test_the_description_matches(string $wordpress, string $core, string $slug): void
    {
        unset($slug);

        $this->assertSame(
            (new $wordpress())->get_description(),
            (new \ReflectionClass($core))->newInstanceWithoutConstructor()->getDescription(),
        );
    }

    #[DataProvider('sharedTools')]
    public function test_the_capability_matches(string $wordpress, string $core, string $slug): void
    {
        unset($slug);

        $this->assertSame(
            (new $wordpress())->get_required_capability(),
            (new \ReflectionClass($core))->newInstanceWithoutConstructor()->getRequiredCapability(),
        );
    }

    /**
     * The schema is the contract a model actually reads.
     */
    #[DataProvider('sharedTools')]
    public function test_the_parameter_schema_matches(string $wordpress, string $core, string $slug): void
    {
        $expected = (new $wordpress())->get_parameters_schema();
        $actual   = (new \ReflectionClass($core))->newInstanceWithoutConstructor()->getParametersSchema();

        $this->assertSame(
            json_encode($expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'Schema drift on ' . $slug,
        );
    }

    /**
     * Schema drift is not the only way the two hosts diverge: a tool can build a
     * query the grammar cannot compile. Both bases used to hand raw filter and
     * order arrays to `StatementGrammar`, which reads `$filter->column` — a
     * TypeError for every filtered read. This pins the fix on the WordPress side
     * (the core side is covered by `DatabaseToolsTest`).
     */
    public function test_the_wordpress_tool_base_compiles_filters_into_clauses(): void
    {
        $tool   = new \WP_MCP_AI_Tool_Db_Select();
        $method = new \ReflectionMethod($tool, 'select_query');
        $method->setAccessible(true);

        $query = $method->invoke(
            $tool,
            array(
                'relation' => 'reporting.orders',
                'filters'  => array(
                    array('column' => 'status', 'op' => 'eq', 'value' => 'paid'),
                ),
                'order'    => array(
                    array('column' => 'id', 'direction' => 'desc'),
                ),
            ),
        );

        $this->assertInstanceOf(\Nvoos\Database\Domain\ValueObject\Database\FilterClause::class, $query->filters[0]);
        $this->assertInstanceOf(\Nvoos\Database\Domain\ValueObject\Database\OrderClause::class, $query->order[0]);
        $this->assertSame('status', $query->filters[0]->column);
        $this->assertSame('desc', $query->order[0]->direction);
    }
}
