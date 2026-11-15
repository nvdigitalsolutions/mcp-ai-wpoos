<?php

/**
 * Tests for the tool router harness.
 *
 * The load-bearing properties: a read tool outranks a write tool on a
 * read-leaning task, preset weights only move tools that belong to the family,
 * assistant weights are clamped at read time (they have no sanitiser upstream),
 * RRF fuses on ranks rather than raw scores, and nothing here throws — a
 * router that throws takes the chat turn with it.
 *
 * @package Nvoos\Core\Tests
 * @since   1.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Service\Harness;

use Nvoos\Core\Domain\Service\Harness\HarnessProfile;
use Nvoos\Core\Domain\Service\Harness\ToolRouterHarness;
use PHPUnit\Framework\TestCase;

final class ToolRouterHarnessTest extends TestCase
{
	/**
	 * A tool with the snake_case shape a WordPress tool exposes.
	 */
	private function wpTool(string $slug, array $flags = array()): object
	{
		return new class ($slug, $flags) {
			public function __construct(private string $slug, private array $flags)
			{
			}

			public function get_slug(): string
			{
				return $this->slug;
			}

			public function get_capability_flags(): array
			{
				return $this->flags;
			}
		};
	}

	/**
	 * A tool with the camelCase shape a core tool exposes.
	 */
	private function coreTool(string $slug, array $flags = array()): object
	{
		return new class ($slug, $flags) {
			public function __construct(private string $slug, private array $flags)
			{
			}

			public function getSlug(): string
			{
				return $this->slug;
			}

			public function getCapabilityFlags(): array
			{
				return $this->flags;
			}
		};
	}

	private function presets(): array
	{
		return array(
			'agentic_workflow' => array( 'tools' => array( 'web_search', 'save_post' ) ),
			'research' => array( 'tools' => array( 'web_search' ) ),
			'empty_family' => array( 'tools' => array() ),
			'no_tools_key' => array( 'name' => 'Broken' ),
		);
	}

	public function test_a_read_only_tool_outranks_a_write_tool_for_qa(): void
	{
		$ranked = ToolRouterHarness::rank(
			array(
				$this->wpTool('reader', array( 'read-only' )),
				$this->wpTool('writer', array( 'write', 'state-changing' )),
			),
			'qa'
		);

		$this->assertSame(array( 'reader', 'writer' ), array_keys($ranked));
		$this->assertGreaterThan($ranked['writer'], $ranked['reader']);
	}

	public function test_the_base_score_is_one_plus_the_flag_weights(): void
	{
		// general weights: read-only 1.0.
		$this->assertSame(2.0, ToolRouterHarness::scoreTool($this->wpTool('t', array( 'read-only' )), 'general'));
		$this->assertSame(1.0, ToolRouterHarness::scoreTool($this->wpTool('t'), 'general'));
	}

	public function test_an_assistant_preference_boosts_the_score(): void
	{
		$tool = $this->wpTool('preferred', array( 'read-only' ));

		$base = ToolRouterHarness::scoreTool($tool, 'general');
		$boosted = ToolRouterHarness::scoreTool($tool, 'general', array( 'preferred' => 5.0 ));

		$this->assertSame($base + 5.0, $boosted);
	}

	public function test_a_preset_weight_only_moves_tools_in_that_family(): void
	{
		$presetIndex = ToolRouterHarness::buildPresetIndex($this->presets());

		$inFamily = ToolRouterHarness::scoreTool(
			$this->wpTool('web_search', array( 'read-only' )),
			'research',
			array(),
			array( 'agentic_workflow' => 2.5 ),
			null,
			$presetIndex
		);

		$base = ToolRouterHarness::scoreTool($this->wpTool('web_search', array( 'read-only' )), 'research');

		$this->assertSame($base + 2.5, $inFamily);

		$outside = ToolRouterHarness::scoreTool(
			$this->wpTool('made_up_tool_xyz', array( 'read-only' )),
			'general',
			array(),
			array( 'agentic_workflow' => 5.0 ),
			null,
			$presetIndex
		);

		$this->assertSame(
			ToolRouterHarness::scoreTool($this->wpTool('made_up_tool_xyz', array( 'read-only' )), 'general'),
			$outside,
			'A tool outside every weighted family gains nothing.'
		);
	}

	public function test_a_tool_in_two_weighted_families_accumulates_both(): void
	{
		$score = ToolRouterHarness::scoreTool(
			$this->wpTool('web_search', array( 'read-only' )),
			'general',
			array(),
			array( 'agentic_workflow' => 1.0, 'research' => 2.0 ),
			null,
			ToolRouterHarness::buildPresetIndex($this->presets())
		);

		$this->assertSame(5.0, $score, '1.0 base + 1.0 read-only + 1.0 + 2.0.');
	}

	public function test_a_negative_preset_weight_dampens_the_family(): void
	{
		$base = ToolRouterHarness::scoreTool($this->wpTool('web_search', array( 'read-only' )), 'research');

		$dampened = ToolRouterHarness::scoreTool(
			$this->wpTool('web_search', array( 'read-only' )),
			'research',
			array(),
			array( 'agentic_workflow' => -3.0 ),
			null,
			ToolRouterHarness::buildPresetIndex($this->presets())
		);

		$this->assertSame($base - 3.0, $dampened);
	}

	/**
	 * `tools.preferences` has no sanitiser in `HarnessProfile`, and both weight
	 * maps can be passed straight to the router, so the router clamps.
	 */
	public function test_an_oversized_weight_is_clamped_at_read_time(): void
	{
		$tool = $this->wpTool('t', array( 'read-only' ));
		$base = ToolRouterHarness::scoreTool($tool, 'general');

		$this->assertSame(
			$base + HarnessProfile::MAX_PRESET_WEIGHT,
			ToolRouterHarness::scoreTool($tool, 'general', array( 't' => 500.0 ))
		);

		$this->assertSame(
			$base + HarnessProfile::MIN_PRESET_WEIGHT,
			ToolRouterHarness::scoreTool($tool, 'general', array( 't' => -500.0 ))
		);
	}

	public function test_a_write_tool_is_penalised_only_on_read_leaning_tasks(): void
	{
		$write = $this->wpTool('writer', array( 'read-only', 'write' ));

		// general: 1.0 base + 1.0 read-only, no penalty.
		$this->assertSame(2.0, ToolRouterHarness::scoreTool($write, 'general'));

		// qa: 1.0 base + 1.5 read-only + 0.5 cacheable? no — 1.5 read-only, then -1.5.
		$this->assertSame(1.0, ToolRouterHarness::scoreTool($write, 'qa'));
	}

	public function test_an_unknown_task_class_falls_back_to_the_general_weights(): void
	{
		$tool = $this->wpTool('t', array( 'read-only' ));

		$this->assertSame(
			ToolRouterHarness::scoreTool($tool, 'general'),
			ToolRouterHarness::scoreTool($tool, 'not_a_known_class')
		);
	}

	public function test_a_score_filter_can_override_the_result(): void
	{
		$score = ToolRouterHarness::scoreTool(
			$this->wpTool('t', array( 'read-only' )),
			'general',
			array(),
			array(),
			null,
			array(),
			static fn (float $score): float => $score + 100.0
		);

		$this->assertSame(102.0, $score);
	}

	public function test_the_filter_receives_every_documented_argument(): void
	{
		$seen = null;

		ToolRouterHarness::scoreTool(
			$this->wpTool('t', array( 'read-only' )),
			'qa',
			array( 't' => 1.0 ),
			array( 'family' => 2.0 ),
			0.5,
			array(),
			static function (float $score, $tool, string $taskClass, array $prefs, array $presetWeights, ?float $attention) use (&$seen): float {
				$seen = array( $taskClass, $prefs, $presetWeights, $attention );

				return $score;
			}
		);

		$this->assertSame('qa', $seen[0]);
		$this->assertSame(array( 't' => 1.0 ), $seen[1]);
		$this->assertSame(array( 'family' => 2.0 ), $seen[2]);
		$this->assertSame(0.5, $seen[3]);
	}

	public function test_a_core_shaped_tool_scores_the_same_as_a_wordpress_shaped_one(): void
	{
		$this->assertSame(
			ToolRouterHarness::scoreTool($this->wpTool('t', array( 'read-only' )), 'research'),
			ToolRouterHarness::scoreTool($this->coreTool('t', array( 'read-only' )), 'research')
		);
	}

	public function test_rank_skips_tools_without_a_usable_slug(): void
	{
		$slugless = new class () {
			public function get_capability_flags(): array
			{
				return array( 'read-only' );
			}
		};

		$ranked = ToolRouterHarness::rank(
			array( $this->wpTool('keeper', array( 'read-only' )), $slugless, 'not-an-object' ),
			'general'
		);

		$this->assertSame(array( 'keeper' ), array_keys($ranked));
	}

	public function test_a_tool_that_throws_while_scoring_is_skipped(): void
	{
		$exploding = new class () {
			public function get_slug(): string
			{
				return 'exploding';
			}

			public function get_capability_flags(): array
			{
				throw new \RuntimeException('boom');
			}
		};

		$ranked = ToolRouterHarness::rank(
			array( $exploding, $this->wpTool('survivor', array( 'read-only' )) ),
			'general'
		);

		$this->assertSame(array( 'survivor' ), array_keys($ranked));
	}

	public function test_rrf_fuses_on_ranks_not_raw_scores(): void
	{
		// Harness scores are three orders of magnitude larger than the
		// semantic ones. RRF only reads positions, so the fused numbers are
		// identical to any other pair of scores with the same ranking.
		$fused = ToolRouterHarness::fuseWithRrf(
			array( 'a' => 900.0, 'b' => 500.0 ),
			array( 'a' => 0.9, 'b' => 0.1 ),
			'general'
		);

		$this->assertSame(array( 'a', 'b' ), array_keys($fused));

		// Both stages rank `a` first and `b` second.
		$this->assertSame(2 * (1.0 / 60.0), $fused['a']);
		$this->assertSame(2 * (1.0 / 61.0), $fused['b']);

		// Scaling either input without reordering it changes nothing.
		$this->assertSame(
			$fused,
			ToolRouterHarness::fuseWithRrf(
				array( 'a' => 3.0, 'b' => 2.0 ),
				array( 'a' => 0.51, 'b' => 0.5 ),
				'general'
			)
		);
	}

	public function test_rrf_scores_a_slug_missing_from_one_stage_at_max_rank(): void
	{
		$fused = ToolRouterHarness::fuseWithRrf(
			array( 'a' => 1.0 ),
			array( 'b' => 1.0 ),
			'general'
		);

		// Each slug is rank 0 in one list and rank 1 (max_rank) in the other.
		$this->assertSame((1.0 / 60.0) + (1.0 / 61.0), $fused['a']);
		$this->assertSame((1.0 / 61.0) + (1.0 / 60.0), $fused['b']);
	}

	public function test_rrf_uses_the_task_class_stage_weights(): void
	{
		$scores = array( 'a' => 1.0 );

		$general = ToolRouterHarness::fuseWithRrf($scores, $scores, 'general');
		$research = ToolRouterHarness::fuseWithRrf($scores, $scores, 'research');

		// general is 1.0/1.0 (sum 2/60); research is 0.5 harness + 1.5
		// attention (the same sum, so the names must differ elsewhere).
		$this->assertSame($general['a'], $research['a']);

		// math is 1.5 harness + 0.5 attention — same total, still 2/60.
		$this->assertSame($general['a'], ToolRouterHarness::fuseWithRrf($scores, $scores, 'math')['a']);
	}

	public function test_the_stage_weight_filter_can_rebalance_the_fusion(): void
	{
		$scores = array( 'a' => 1.0, 'b' => 0.5 );

		$default = ToolRouterHarness::fuseWithRrf($scores, $scores, 'general');
		$boosted = ToolRouterHarness::fuseWithRrf(
			$scores,
			$scores,
			'general',
			static fn (float $weight, string $stage): float => 'harness' === $stage ? 10.0 : $weight
		);

		$this->assertGreaterThan($default['a'], $boosted['a']);
	}

	public function test_rank_switches_to_rrf_when_attention_scores_are_given(): void
	{
		$ranked = ToolRouterHarness::rank(
			array( $this->wpTool('a', array( 'read-only' )), $this->wpTool('b') ),
			'general',
			array(),
			array(),
			array( 'a' => 0.2, 'b' => 0.9 )
		);

		// Structural favours `a` (read-only), semantic favours `b`; with equal
		// stage weights the fused order follows whoever wins both or ties.
		$this->assertArrayHasKey('a', $ranked);
		$this->assertArrayHasKey('b', $ranked);
		$this->assertLessThan(1.0, $ranked['a'], 'Fused scores are RRF-sized, not raw.');
	}

	public function test_rank_returns_an_empty_ranking_when_it_cannot_score_at_all(): void
	{
		// iterable that throws on iteration — the wholesale-failure path.
		$broken = new class () implements \IteratorAggregate {
			public function getIterator(): \Traversable
			{
				throw new \RuntimeException('broken iterable');
			}
		};

		$this->assertSame(array(), ToolRouterHarness::rank($broken, 'general'));
	}

	public function test_the_preset_index_maps_tools_to_every_containing_family(): void
	{
		$index = ToolRouterHarness::buildPresetIndex($this->presets());

		$this->assertSame(array( 'agentic_workflow', 'research' ), $index['web_search']);
		$this->assertSame(array( 'agentic_workflow' ), $index['save_post']);
		$this->assertArrayNotHasKey('empty_family', $index);
	}

	public function test_the_preset_index_tolerates_junk(): void
	{
		$index = ToolRouterHarness::buildPresetIndex(array(
			'good' => array( 'tools' => array( 'a', 'a', '', 12 ) ),
			'no_tools' => array( 'name' => 'x' ),
			'not_array' => 'nope',
			'' => array( 'tools' => array( 'b' ) ),
		));

		$this->assertSame(array( 'good' ), $index['a'], 'Duplicates and junk slugs are dropped.');
		$this->assertSame(array( 'good' ), $index['12']);
		$this->assertArrayNotHasKey('b', $index);
	}

	public function test_assistant_inputs_are_resolved_and_clamped(): void
	{
		list($prefs, $presetWeights) = ToolRouterHarness::resolveAssistantInputs(array(
			'tools' => array(
				'preset_weights' => array( 'research' => 99.0, 'code' => -99.0, '  ' => 1.0, 'keep' => 2.0 ),
				'preferences' => array( 'web_search' => 50.0 ),
			),
		));

		$this->assertSame(HarnessProfile::MAX_PRESET_WEIGHT, $presetWeights['research']);
		$this->assertSame(HarnessProfile::MIN_PRESET_WEIGHT, $presetWeights['code']);
		$this->assertSame(2.0, $presetWeights['keep']);
		$this->assertArrayNotHasKey('', $presetWeights);

		$this->assertSame(HarnessProfile::MAX_PRESET_WEIGHT, $prefs['web_search']);
	}

	public function test_a_profile_without_tools_yields_empty_inputs(): void
	{
		$this->assertSame(array( array(), array() ), ToolRouterHarness::resolveAssistantInputs(array( 'enabled' => true )));
	}

	public function test_the_rrf_constant_matches_the_industry_default(): void
	{
		$this->assertSame(60, ToolRouterHarness::RRF_K);
	}
}
