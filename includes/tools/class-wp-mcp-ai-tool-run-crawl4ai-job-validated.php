<?php
/**
 * Tool for running Crawl4AI jobs (Validated version).
 *
 * This is the Symfony Validator version of the run_crawl4ai_job tool.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../validators/class-wp-mcp-ai-validated-tool.php';
require_once __DIR__ . '/../validators/arguments/class-run-crawl4ai-job-arguments.php';
require_once __DIR__ . '/class-wp-mcp-ai-tool-run-crawl4ai-job.php';

/**
 * Runs Crawl4AI jobs with Symfony Validator.
 *
 * This class extends the original run_crawl4ai_job tool to use
 * Symfony Validator for argument validation.
 */
class WP_MCP_AI_Tool_Run_Crawl4AI_Job_Validated extends WP_MCP_AI_Validated_Tool implements WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface {

	/**
	 * The original run_crawl4ai_job tool instance for delegation.
	 *
	 * @var WP_MCP_AI_Tool_Run_Crawl4AI_Job
	 */
	protected $original_tool;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->original_tool = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'run_crawl4ai_job_validated';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Run Crawl4AI Job (Validated)', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Submits a Crawl4AI crawl request and optionally waits for the results with Symfony Validator for argument validation.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		// Use the same schema as the original tool.
		return $this->original_tool->get_parameters_schema();
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_validation_class() {
		return \WP_MCP_AI\Tools\Arguments\RunCrawl4AIJobArguments::class;
	}

	/**
	 * Execute the tool with validated arguments.
	 *
	 * @param \WP_MCP_AI\Tools\Arguments\RunCrawl4AIJobArguments $validated_args Validated arguments object.
	 * @param array                                              $context        Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	protected function execute_validated( $validated_args, $context ) {
		// Convert validated arguments object back to array format.
		$arguments = array();

		// Add optional arguments if provided.
		if ( null !== $validated_args->urls ) {
			$arguments['urls'] = $validated_args->urls;
		}

		if ( null !== $validated_args->url ) {
			$arguments['url'] = $validated_args->url;
		}

		if ( null !== $validated_args->priority ) {
			$arguments['priority'] = $validated_args->priority;
		}

		if ( null !== $validated_args->options ) {
			$arguments['options'] = $validated_args->options;
		}

		if ( null !== $validated_args->wait_for_completion ) {
			$arguments['wait_for_completion'] = $validated_args->wait_for_completion;
		}

		if ( null !== $validated_args->poll_interval ) {
			$arguments['poll_interval'] = $validated_args->poll_interval;
		}

		if ( null !== $validated_args->timeout ) {
			$arguments['timeout'] = $validated_args->timeout;
		}

		// Delegate to the original tool's execute method.
		return $this->original_tool->execute( $arguments, $context );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		// Delegate to the original tool.
		return $this->original_tool->get_capability_flags();
	}

	/**
	 * {@inheritdoc}
	 */
	public function sanitize_for_llm( $result ) {
		// Delegate to the original tool.
		return $this->original_tool->sanitize_for_llm( $result );
	}
}
