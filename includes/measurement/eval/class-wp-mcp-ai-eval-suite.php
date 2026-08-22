<?php
/**
 * Eval Suite
 *
 * An ordered collection of {@see WP_MCP_AI_Eval_Case}s with shared metadata.
 * Suites are registered via `wp_mcp_ai_register_eval_suites` and run via
 * {@see WP_MCP_AI_Eval_Runner}. The suite carries a `generator_context`
 * which the runner uses for verifier-independence enforcement — this means
 * authors can declare "this suite tests the openai:gpt-4o generator" once
 * and every verifier invocation will reject judges that share provenance.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Eval Suite.
 */
class WP_MCP_AI_Eval_Suite {

	/**
	 * Slug.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Label.
	 *
	 * @var string
	 */
	private $label;

	/**
	 * Description.
	 *
	 * @var string
	 */
	private $description;

	/**
	 * Cases indexed by slug (insertion order preserved).
	 *
	 * @var array<string,WP_MCP_AI_Eval_Case>
	 */
	private $cases = array();

	/**
	 * Generator context (provider, model, tools).
	 *
	 * @var array<string,mixed>
	 */
	private $generator_context;

	/**
	 * Tags (categorization for the dashboard).
	 *
	 * @var array<int,string>
	 */
	private $tags;

	/**
	 * Artifact type this suite scores ('' = general-purpose suite).
	 *
	 * One of '', 'prompt', 'role', 'skill', 'memory', 'profile'.
	 *
	 * @var string
	 */
	private $artifact_type;

	/**
	 * Artifact identifier this suite scores ('' = any artifact of the type).
	 *
	 * @var string
	 */
	private $artifact_id;

	/**
	 * Valid artifact types.
	 *
	 * @since 1.9.0
	 * @var   array<int,string>
	 */
	const VALID_ARTIFACT_TYPES = array( '', 'prompt', 'role', 'skill', 'memory', 'profile' );

	/**
	 * Constructor.
	 *
	 * @param array $args Suite args.
	 * @throws InvalidArgumentException When slug is missing.
	 */
	public function __construct( array $args ) {
		$slug = isset( $args['slug'] ) ? sanitize_key( $args['slug'] ) : '';
		if ( '' === $slug ) {
			throw new InvalidArgumentException( 'Eval suite requires a non-empty slug.' );
		}

		$this->slug              = $slug;
		$this->label             = isset( $args['label'] ) ? (string) $args['label'] : $slug;
		$this->description       = isset( $args['description'] ) ? (string) $args['description'] : '';
		$this->generator_context = isset( $args['generator_context'] ) && is_array( $args['generator_context'] )
			? $args['generator_context']
			: array();
		$this->tags              = array();
		if ( isset( $args['tags'] ) && is_array( $args['tags'] ) ) {
			foreach ( $args['tags'] as $tag ) {
				if ( is_scalar( $tag ) ) {
					$this->tags[] = (string) $tag;
				}
			}
		}
		$this->artifact_type = isset( $args['artifact_type'] ) ? sanitize_key( (string) $args['artifact_type'] ) : '';
		if ( ! in_array( $this->artifact_type, self::VALID_ARTIFACT_TYPES, true ) ) {
			$this->artifact_type = '';
		}
		$this->artifact_id = isset( $args['artifact_id'] ) ? sanitize_key( (string) $args['artifact_id'] ) : '';
		if ( ! empty( $args['cases'] ) && is_array( $args['cases'] ) ) {
			foreach ( $args['cases'] as $case ) {
				if ( $case instanceof WP_MCP_AI_Eval_Case ) {
					$this->add_case( $case );
				} elseif ( is_array( $case ) ) {
					$this->add_case( new WP_MCP_AI_Eval_Case( $case ) );
				}
			}
		}
	}

	/**
	 * Add a case to the suite. Later additions with the same slug replace
	 * earlier ones — this mirrors the registry pattern used elsewhere.
	 *
	 * @param WP_MCP_AI_Eval_Case $case Case.
	 * @return void
	 */
	public function add_case( WP_MCP_AI_Eval_Case $case ) {
		$this->cases[ $case->get_slug() ] = $case;
	}

	/**
	 * Get slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return $this->slug;
	}

	/**
	 * Get label.
	 *
	 * @return string
	 */
	public function get_label() {
		return $this->label;
	}

	/**
	 * Get description.
	 *
	 * @return string
	 */
	public function get_description() {
		return $this->description;
	}

	/**
	 * Get all cases (insertion order).
	 *
	 * @return array<int,WP_MCP_AI_Eval_Case>
	 */
	public function get_cases() {
		return array_values( $this->cases );
	}

	/**
	 * Get a specific case by slug.
	 *
	 * @param string $slug Case slug.
	 * @return WP_MCP_AI_Eval_Case|null
	 */
	public function get_case( $slug ) {
		$slug = sanitize_key( (string) $slug );
		return isset( $this->cases[ $slug ] ) ? $this->cases[ $slug ] : null;
	}

	/**
	 * Case count.
	 *
	 * @return int
	 */
	public function count_cases() {
		return count( $this->cases );
	}

	/**
	 * Get generator context.
	 *
	 * @return array
	 */
	public function get_generator_context() {
		return $this->generator_context;
	}

	/**
	 * Get tags.
	 *
	 * @return array
	 */
	public function get_tags() {
		return $this->tags;
	}

	/**
	 * Get the artifact type this suite scores ('' = general).
	 *
	 * @since 1.9.0
	 *
	 * @return string
	 */
	public function get_artifact_type() {
		return $this->artifact_type;
	}

	/**
	 * Get the artifact identifier this suite scores ('' = any of the type).
	 *
	 * @since 1.9.0
	 *
	 * @return string
	 */
	public function get_artifact_id() {
		return $this->artifact_id;
	}

	/**
	 * Whether the suite is scoped to a specific artifact.
	 *
	 * @since 1.9.0
	 *
	 * @return bool True when an artifact type is declared.
	 */
	public function is_artifact_scoped() {
		return '' !== $this->artifact_type;
	}

	/**
	 * Serialize to array for the dashboard.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'slug'              => $this->slug,
			'label'             => $this->label,
			'description'       => $this->description,
			'tags'              => $this->tags,
			'case_count'        => $this->count_cases(),
			'generator_context' => $this->generator_context,
			'artifact_type'     => $this->artifact_type,
			'artifact_id'       => $this->artifact_id,
		);
	}
}
