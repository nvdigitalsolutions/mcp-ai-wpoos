<?php
/**
 * Tests for the OOS composition service (Proposal 029, Phase 5.2/5.3).
 *
 * Covers compose() restriction intersection, composeFrom() parent binding
 * and provenance, generation determinism / drift detection, the effective
 * dump shape, and the legacy-tool resolver smoke surface.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.1.57
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The composition module is flag-gated (default off) — load the classes
// directly for the suite.
require_once WP_MCP_AI_PRO_PATH . 'includes/composition/class-wp-mcp-ai-pro-composition.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/composition/class-wp-mcp-ai-pro-legacy-tool-resolver.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/composition/class-wp-mcp-ai-pro-composition-service.php';

use Nvoos\Core\Domain\Contract\ToolInterface;
use Nvoos\Core\Domain\Contract\ToolResolverInterface;

/**
 * Test class for WP_MCP_AI_Pro_Composition_Service.
 */
class Test_WP_MCP_AI_Pro_Composition_Service extends WP_UnitTestCase {

	/**
	 * Create an assistant post with the given tool meta.
	 *
	 * @param array $meta Meta key/value pairs.
	 * @return int Assistant post ID.
	 */
	private function create_assistant( array $meta = array() ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Composition Test Assistant',
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		foreach ( $meta as $key => $value ) {
			// Production stores these metas as plain arrays. Real tool
			// slugs survive the registered sanitize callback for
			// _wp_mcp_ai_tools; normalize_slugs() also accepts the
			// serialized/JSON shapes some environments read back.
			update_post_meta( $post_id, $key, $value );
		}

		return $post_id;
	}

	/**
	 * A duck-typed Nvoos\Core tool for resolver fixtures.
	 *
	 * @param string $slug Tool slug.
	 * @return ToolInterface
	 */
	private function fake_tool( string $slug ): ToolInterface {
		return new class( $slug ) implements ToolInterface {

			/**
			 * Constructor.
			 *
			 * @param string $slug Tool slug.
			 */
			public function __construct( private string $slug ) {}

			/**
			 * Tool slug.
			 *
			 * @return string
			 */
			public function getSlug(): string {
				return $this->slug;
			}

			/**
			 * Tool name.
			 *
			 * @return string
			 */
			public function getName(): string {
				return $this->slug;
			}

			/**
			 * Tool description.
			 *
			 * @return string
			 */
			public function getDescription(): string {
				return $this->slug . ' description.';
			}

			/**
			 * Parameter schema.
			 *
			 * @return array
			 */
			public function getParametersSchema(): array {
				return array(
					'type'       => 'object',
					'properties' => array(),
				);
			}

			/**
			 * Required capability.
			 *
			 * @return string
			 */
			public function getRequiredCapability(): string {
				return '';
			}

			/**
			 * Execute.
			 *
			 * @param array $arguments Tool arguments.
			 * @param array $context   Execution context.
			 * @return mixed
			 */
			public function execute( array $arguments = array(), array $context = array() ): mixed {
				return array(
					'success' => true,
					'message' => $this->slug,
					'data'    => array(),
				);
			}
		};
	}

	/**
	 * An in-memory resolver over a slug → tool map.
	 *
	 * @param array $tools Slug → ToolInterface map.
	 * @return ToolResolverInterface
	 */
	private function fake_resolver( array $tools ): ToolResolverInterface {
		return new class( $tools ) implements ToolResolverInterface {

			/**
			 * Constructor.
			 *
			 * @param array $tools Slug → ToolInterface map.
			 */
			public function __construct( private array $tools ) {}

			/**
			 * Resolve a tool.
			 *
			 * @param string $slug Tool slug.
			 * @return ToolInterface|null
			 */
			public function get( string $slug ): ?ToolInterface {
				return isset( $this->tools[ $slug ] ) ? $this->tools[ $slug ] : null;
			}

			/**
			 * Whether a slug is visible.
			 *
			 * @param string $slug Tool slug.
			 * @return bool
			 */
			public function has( string $slug ): bool {
				return isset( $this->tools[ $slug ] );
			}
		};
	}

	/**
	 * Resolver fixture with three tools.
	 *
	 * @return ToolResolverInterface
	 */
	private function resolver_fixture(): ToolResolverInterface {
		return $this->fake_resolver(
			array(
				'create_post'    => $this->fake_tool( 'create_post' ),
				'search_content' => $this->fake_tool( 'search_content' ),
				'save_post'      => $this->fake_tool( 'save_post' ),
			)
		);
	}

	/**
	 * Compose() intersects allow and deny lists.
	 */
	public function test_compose_applies_allow_and_deny(): void {
		$assistant_id = $this->create_assistant(
			array(
				'_wp_mcp_ai_tools'        => array( 'create_post', 'save_post' ),
				'_wp_mcp_ai_denied_tools' => array( 'save_post' ),
			)
		);

		$service     = new WP_MCP_AI_Pro_Composition_Service( $this->resolver_fixture() );
		$composition = $service->compose( $assistant_id );

		$this->assertSame( array( 'create_post' ), $composition->visible_slugs() );
		$this->assertSame( array( 'create_post', 'save_post' ), $composition->allow_slugs() );
		$this->assertSame( array( 'save_post' ), $composition->deny_slugs() );
		$this->assertSame( WP_MCP_AI_Pro_Composition::SOURCE_COMPOSED, $composition->source() );
		$this->assertNull( $composition->parent_generation_id() );
	}

	/**
	 * A restriction-free composition exposes the seeded universe.
	 */
	public function test_compose_without_restrictions_uses_seed_universe(): void {
		$assistant_id = $this->create_assistant();

		$service = new WP_MCP_AI_Pro_Composition_Service( $this->resolver_fixture() );

		$composition = $service->compose(
			$assistant_id,
			array( 'seed_slugs' => array( 'create_post', 'save_post' ) )
		);

		$this->assertSame( array( 'create_post', 'save_post' ), $composition->visible_slugs() );
	}

	/**
	 * The composeFrom() binding narrows the parent's exact toolset with the child inputs.
	 */
	public function test_compose_from_intersects_parent_and_child(): void {
		$parent_id = $this->create_assistant(
			array( '_wp_mcp_ai_tools' => array( 'create_post', 'save_post' ) )
		);

		$child_id = $this->create_assistant(
			array(
				'_wp_mcp_ai_tools'        => array( 'create_post', 'search_content' ),
				'_wp_mcp_ai_denied_tools' => array( 'save_post' ),
			)
		);

		$service = new WP_MCP_AI_Pro_Composition_Service( $this->resolver_fixture() );

		$parent = $service->compose( $parent_id );
		$child  = $service->compose_from( $parent, $child_id );

		// search_content is not in the parent.s universe — the child binds to the
		// parent's exact toolset and can only narrow it (or shadow).
		$this->assertSame( array( 'create_post' ), $child->visible_slugs() );
		$this->assertSame( WP_MCP_AI_Pro_Composition::SOURCE_COMPOSED_FROM, $child->source() );
		$this->assertSame( $parent->generation_id(), $child->parent_generation_id() );
	}

	/**
	 * Scope-local child tools shadow the parent's set restriction-free.
	 */
	public function test_compose_from_scope_local_shadowing(): void {
		$parent_id = $this->create_assistant(
			array( '_wp_mcp_ai_tools' => array( 'create_post' ) )
		);

		$child_id = $this->create_assistant(
			array( '_wp_mcp_ai_denied_tools' => array( 'create_post' ) )
		);

		$service = new WP_MCP_AI_Pro_Composition_Service( $this->resolver_fixture() );

		$parent = $service->compose( $parent_id );

		$child = $service->compose_from(
			$parent,
			$child_id,
			array( 'local_tools' => array( $this->fake_tool( 'create_post' ) ) )
		);

		// The scope-local registration shadows the denied parent tool.
		$this->assertSame( array( 'create_post' ), $child->visible_slugs() );
		$this->assertSame( array( 'create_post' ), $child->scope_local_slugs() );
	}

	/**
	 * The composeFrom() binding records a provenance chain ending at the parent.
	 */
	public function test_compose_from_provenance_chain(): void {
		$parent_id = $this->create_assistant(
			array( '_wp_mcp_ai_tools' => array( 'create_post' ) )
		);

		$child_id = $this->create_assistant(
			array( '_wp_mcp_ai_tools' => array( 'create_post' ) )
		);

		$service = new WP_MCP_AI_Pro_Composition_Service( $this->resolver_fixture() );

		$parent = $service->compose( $parent_id );
		$child  = $service->compose_from( $parent, $child_id );

		$this->assertSame( array( $parent->generation_id() ), $child->generation_chain() );

		$fresh = $service->compose( $child_id );

		$this->assertNotSame( $fresh->generation_id(), $child->generation_id() );
	}

	/**
	 * Generation ids are deterministic and drift-sensitive.
	 */
	public function test_generation_deterministic_and_drift_sensitive(): void {
		$assistant_id = $this->create_assistant(
			array( '_wp_mcp_ai_tools' => array( 'create_post', 'save_post' ) )
		);

		$service = new WP_MCP_AI_Pro_Composition_Service( $this->resolver_fixture() );

		$first  = $service->compose( $assistant_id );
		$second = $service->compose( $assistant_id );

		$this->assertSame( $first->generation_id(), $second->generation_id() );
		$this->assertTrue( $service->assert_same_generation( $first, $second->generation_id() ) );

		update_post_meta( $assistant_id, '_wp_mcp_ai_denied_tools', array( 'save_post' ) );

		$drifted = $service->compose( $assistant_id );

		$this->assertNotSame( $first->generation_id(), $drifted->generation_id() );
		$this->assertFalse( $service->assert_same_generation( $drifted, $first->generation_id() ) );
	}

	/**
	 * The effective dump carries the full resolved picture.
	 */
	public function test_effective_dump_shape(): void {
		$assistant_id = $this->create_assistant(
			array(
				'_wp_mcp_ai_tools'         => array( 'create_post' ),
				'_wp_mcp_ai_denied_tools'  => array( 'save_post' ),
				'_wp_mcp_ai_guard_slugs'   => array( 'guard_a' ),
				'_wp_mcp_ai_provider'      => 'deepseek',
				'_wp_mcp_ai_model'         => 'deepseek-chat',
				'_wp_mcp_ai_system_prompt' => 'You are a test assistant.',
			)
		);

		$service = new WP_MCP_AI_Pro_Composition_Service( $this->resolver_fixture() );

		$effective = $service->effective( $service->compose( $assistant_id ) );

		$this->assertSame( $assistant_id, $effective['assistant_id'] );
		$this->assertSame( 'deepseek', $effective['provider'] );
		$this->assertSame( 'deepseek-chat', $effective['model'] );
		$this->assertSame( array( 'guard_a' ), $effective['guard_slugs'] );
		$this->assertSame( array( 'create_post' ), $effective['visible_slugs'] );
		$this->assertSame( 1, $effective['visible_tool_count'] );
		$this->assertCount( 1, $effective['prompt_sections'] );
		$this->assertSame( 'system_prompt', $effective['prompt_sections'][0]['title'] );
		$this->assertGreaterThan( 0, $effective['prompt_sections'][0]['content_chars'] );
		$this->assertMatchesRegularExpression( '/^gen_[0-9a-f]{20}$/', $effective['generation_id'] );
	}

	/**
	 * The legacy-tool resolver smoke surface stays well-behaved.
	 */
	public function test_legacy_tool_resolver_smoke(): void {
		$resolver = new WP_MCP_AI_Pro_Legacy_Tool_Resolver();

		$this->assertIsBool( $resolver->has( 'create_post' ) );
		$this->assertNull( $resolver->get( 'this_slug_never_exists_xyz' ) );
		$this->assertIsArray( $resolver->all_slugs() );
	}
}
