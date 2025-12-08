<?php
/**
 * Tool for creating AI assistants programmatically (Validated version).
 *
 * This is the Symfony Validator version of the create_assistant tool.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../validators/class-wp-mcp-ai-validated-tool.php';
require_once __DIR__ . '/../validators/arguments/class-create-assistant-arguments.php';
require_once __DIR__ . '/class-wp-mcp-ai-tool-create-assistant.php';

/**
 * Allows users to create AI assistants with Symfony Validator.
 *
 * This class extends the original create_assistant tool to use
 * Symfony Validator for argument validation, delegating the actual
 * creation logic to the parent class.
 */
class WP_MCP_AI_Tool_Create_Assistant_Validated extends WP_MCP_AI_Validated_Tool implements WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * The original create_assistant tool instance for delegation.
	 *
	 * @var WP_MCP_AI_Tool_Create_Assistant
	 */
	protected $original_tool;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->original_tool = new WP_MCP_AI_Tool_Create_Assistant();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_assistant_validated';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create AI Assistant (Validated)', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new AI assistant using Symfony Validator for argument validation. Can be used in two modes: (1) Manual mode - select from predefined professions and regions, or (2) Prompt mode - provide a free-form description and optional custom system prompt. Supports attachment IDs for knowledge base files. The assistant will be saved as a draft.', 'wp-mcp-ai' );
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
		return \WP_MCP_AI\Tools\Arguments\CreateAssistantArguments::class;
	}

	/**
	 * Execute the tool with validated arguments.
	 *
	 * @param \WP_MCP_AI\Tools\Arguments\CreateAssistantArguments $validated_args Validated arguments object.
	 * @param array                                                $context        Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	protected function execute_validated( $validated_args, $context ) {
		// Convert validated arguments object back to array format
		// for the original tool's execute method.
		$arguments = array(
			'title' => $validated_args->title,
		);

		// Add optional fields only if they have values.
		if ( null !== $validated_args->description && '' !== $validated_args->description ) {
			$arguments['description'] = $validated_args->description;
		}

		if ( null !== $validated_args->system_prompt && '' !== $validated_args->system_prompt ) {
			$arguments['system_prompt'] = $validated_args->system_prompt;
		}

		if ( ! empty( $validated_args->professions ) ) {
			$arguments['professions'] = $validated_args->professions;
		}

		if ( ! empty( $validated_args->regions ) ) {
			$arguments['regions'] = $validated_args->regions;
		}

		if ( null !== $validated_args->industry_focus && '' !== $validated_args->industry_focus ) {
			$arguments['industry_focus'] = $validated_args->industry_focus;
		}

		if ( ! empty( $validated_args->attachment_ids ) ) {
			$arguments['attachment_ids'] = $validated_args->attachment_ids;
		}

		if ( $validated_args->async ) {
			$arguments['async'] = $validated_args->async;
		}

		if ( null !== $validated_args->notification_email && '' !== $validated_args->notification_email ) {
			$arguments['notify_email'] = $validated_args->notification_email;
		}

		// Delegate to the original tool's execute method.
		// The original tool handles all the complex logic.
		return $this->original_tool->execute( $arguments, $context );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		// Delegate to the original tool.
		return $this->original_tool->get_capability_flags();
	}
}
