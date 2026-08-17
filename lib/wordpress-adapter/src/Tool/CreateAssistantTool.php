<?php
/**
 * WordPress-specific tool for creating AI assistants programmatically.
 *
 * Lives in the WordPress adapter because it creates WordPress CPT posts
 * (mcp_ai_assistant), manages post meta, handles attachment sideloading,
 * and supports async execution via WP cron.
 *
 * Content generation methods (profession inference, knowledge documents,
 * domain context extraction) remain in the legacy tool — this adaptation
 * focuses on CPT creation and meta storage.
 *
 * @package Nvoos\WordPress
 * @since   1.0.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Tool\AbstractTool;

class CreateAssistantTool extends AbstractTool {

	protected const MAX_DOCUMENTS    = 20;
	protected const MAX_DOCUMENT_SIZE = 10485760; // 10MB.
	protected const CPT              = 'mcp_ai_assistant';

	protected static bool $asyncHookRegistered = false;

	public function __construct(
		ErrorFactoryInterface $errors,
	) {
		parent::__construct( $errors );
		if ( ! self::$asyncHookRegistered ) {
			\add_action( 'wp_mcp_ai_create_assistant_async', array( $this, 'processAsyncCreation' ) );
			self::$asyncHookRegistered = true;
		}
	}

	public function getSlug(): string {
		return 'create_assistant';
	}

	public function getName(): string {
		return 'Create AI Assistant';
	}

	public function getDescription(): string {
		return 'Creates a new AI assistant. Provide a title, description or system prompt, and optional professions/regions. Supports attachment IDs for knowledge base files. The assistant is saved as a draft.';
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'             => array(
					'type'        => 'string',
					'description' => 'The name/title for the AI assistant.',
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'description'       => array(
					'type'        => 'string',
					'description' => 'Free-form description of what the assistant should do.',
					'maxLength'   => 5000,
				),
				'system_prompt'     => array(
					'type'        => 'string',
					'description' => 'Custom system prompt for the assistant.',
					'maxLength'   => 32000,
				),
				'professions'       => array(
					'type'        => 'array',
					'description' => 'Up to 3 profession keys.',
					'items'       => array( 'type' => 'string' ),
					'minItems'    => 0,
					'maxItems'    => 3,
					'uniqueItems' => true,
				),
				'regions'           => array(
					'type'        => 'array',
					'description' => 'Up to 2 region keys.',
					'items'       => array( 'type' => 'string' ),
					'minItems'    => 0,
					'maxItems'    => 2,
					'uniqueItems' => true,
				),
				'industry_focus'    => array(
					'type'        => 'string',
					'description' => 'Optional specific industry focus.',
					'maxLength'   => 200,
				),
				'attachment_ids'    => array(
					'type'        => 'array',
					'description' => 'Media attachment IDs for knowledge base.',
					'items'       => array( 'type' => 'integer' ),
					'maxItems'    => self::MAX_DOCUMENTS,
				),
				'provider'          => array(
					'type'        => 'string',
					'description' => 'AI provider. Defaults to openai.',
					'default'     => 'openai',
				),
				'model'             => array(
					'type'        => 'string',
					'description' => 'Model name. Defaults to gpt-4.',
					'maxLength'   => 100,
				),
				'temperature'       => array(
					'type'        => 'number',
					'description' => 'Temperature (0-2). Default 0.7.',
					'minimum'     => 0,
					'maximum'     => 2,
					'default'     => 0.7,
				),
				'tools'             => array(
					'type'        => 'array',
					'description' => 'Tool slugs to enable.',
					'items'       => array( 'type' => 'string' ),
					'maxItems'    => 100,
				),
				'async'             => array(
					'type'        => 'boolean',
					'description' => 'Schedule creation via cron.',
					'default'     => false,
				),
				'notify_email'      => array(
					'type'        => 'string',
					'description' => 'Email to notify on completion.',
					'format'      => 'email',
				),
				'featured_image_id' => array(
					'type'        => 'integer',
					'description' => 'Attachment ID for featured image.',
					'minimum'     => 1,
				),
				'categories'        => array(
					'type'        => 'array',
					'description' => 'Category IDs or names.',
					'items'       => array( 'anyOf' => array(
						array( 'type' => 'integer', 'minimum' => 1 ),
						array( 'type' => 'string' ),
					) ),
				),
				'tags'              => array(
					'type'        => 'array',
					'description' => 'Tag IDs or names.',
					'items'       => array( 'anyOf' => array(
						array( 'type' => 'integer', 'minimum' => 1 ),
						array( 'type' => 'string' ),
					) ),
				),
				'meta_input'        => array(
					'type'                 => 'object',
					'description'          => 'Custom field key-value pairs.',
					'additionalProperties' => true,
				),
			),
			'required'             => array( 'title' ),
			'additionalProperties' => false,
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$user_id = isset( $context['user_id'] )
			? \absint( $context['user_id'] )
			: \get_current_user_id();

		if ( ! $user_id || ! \user_can( $user_id, 'edit_posts' ) ) {
			return $this->errors->accessDenied( 'You do not have permission to create assistants.' );
		}

		if ( \is_multisite() && ! \is_user_member_of_blog( $user_id, \get_current_blog_id() ) ) {
			return $this->errors->accessDenied( 'You do not have access to this site.' );
		}

		$async = ! empty( $arguments['async'] );
		if ( ! empty( $context['in_async_executor'] ) ) {
			$async = false; // Prevent double-async.
		}

		if ( $async ) {
			return $this->scheduleAsync( $arguments, $user_id );
		}

		return $this->createAssistant( $arguments, $user_id );
	}

	// ─── Core assistant creation ──────────────────────────────────────

	protected function createAssistant( array $arguments, int $user_id ): mixed {
		$title          = \sanitize_text_field( $arguments['title'] ?? '' );
		$description    = \sanitize_textarea_field( $arguments['description'] ?? '' );
		$system_prompt  = \sanitize_textarea_field( $arguments['system_prompt'] ?? '' );
		$industry_focus = \sanitize_text_field( $arguments['industry_focus'] ?? '' );
		$attachment_ids = isset( $arguments['attachment_ids'] ) && \is_array( $arguments['attachment_ids'] )
			? \array_map( 'absint', $arguments['attachment_ids'] )
			: array();

		if ( '' === $title ) {
			return $this->errors->validationFailed( 'Title is required.', array( 'title' => array( 'This field is required.' ) ) );
		}

		// Generate instructions.
		if ( '' !== $system_prompt ) {
			$instructions = $system_prompt;
		} elseif ( '' !== $description ) {
			$instructions = "You are {$title}, an AI assistant.\n\nPURPOSE:\n{$description}";
		} else {
			$instructions = "You are {$title}, a helpful AI assistant.";
		}

		// Build post content.
		$post_content = $description ?: "AI Assistant: {$title}";

		// Create post.
		$post_data = array(
			'post_type'    => self::CPT,
			'post_title'   => $title,
			'post_content' => $post_content,
			'post_status'  => 'draft',
			'post_author'  => $user_id,
		);

		$assistant_id = \wp_insert_post( \wp_slash( $post_data ), true );
		if ( \is_wp_error( $assistant_id ) ) {
			return $this->errors->create( $assistant_id->get_error_code(), $assistant_id->get_error_message() );
		}

		// Save meta.
		\update_post_meta( $assistant_id, '_wp_mcp_ai_system_prompt', $instructions );

		$provider = \sanitize_key( $arguments['provider'] ?? 'openai' );
		\update_post_meta( $assistant_id, '_wp_mcp_ai_provider', $provider );

		$model = \sanitize_text_field( $arguments['model'] ?? 'gpt-4' );
		\update_post_meta( $assistant_id, '_wp_mcp_ai_model', $model );

		if ( isset( $arguments['temperature'] ) && \is_numeric( $arguments['temperature'] ) ) {
			$t = (float) $arguments['temperature'];
			if ( $t >= 0 && $t <= 2 ) {
				\update_post_meta( $assistant_id, '_wp_mcp_ai_temperature', $t );
			}
		}

		// Tools.
		if ( isset( $arguments['tools'] ) && \is_array( $arguments['tools'] ) ) {
			$tools = \array_map( 'sanitize_key', $arguments['tools'] );
			\update_post_meta( $assistant_id, '_wp_mcp_ai_tools', $tools );
		}

		// Attachment documents for knowledge base.
		$all_doc_ids = array();
		if ( ! empty( $attachment_ids ) ) {
			$validated   = $this->validateAttachmentIds( $attachment_ids, $user_id );
			$all_doc_ids = \array_merge( $all_doc_ids, $validated );
		}

		if ( ! empty( $all_doc_ids ) ) {
			\update_post_meta( $assistant_id, '_wp_mcp_ai_memory_files', \array_unique( $all_doc_ids ) );
		}

		// Enhanced metadata.
		$this->handleMetadata( $assistant_id, $arguments );

		return $this->success(
			\sprintf( 'AI assistant "%s" created successfully as draft.', $title ),
			array(
				'assistant_id' => $assistant_id,
				'title'        => $title,
				'status'       => 'draft',
				'edit_link'    => \get_edit_post_link( $assistant_id, '' ),
				'documents'    => \count( $all_doc_ids ),
			)
		);
	}

	// ─── Async scheduling ─────────────────────────────────────────────

	protected function scheduleAsync( array $arguments, int $user_id ): array {
		$job_id    = 'create_assistant_' . \uniqid();
		$transient = 'wp_mcp_ai_async_assistant_' . $job_id;

		\set_transient( $transient, array( 'arguments' => $arguments, 'user_id' => $user_id ), \DAY_IN_SECONDS );

		$timestamp = \time() + 60;
		$scheduled = \wp_schedule_single_event( $timestamp, 'wp_mcp_ai_create_assistant_async', array( $job_id ) );

		if ( false === $scheduled ) {
			\delete_transient( $transient );
			return $this->errors->create( 'wp_mcp_ai_schedule_failed', 'Failed to schedule assistant creation.' );
		}

		if ( \class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
			\WP_MCP_AI_Cron_Manager::record_job( 'wp_mcp_ai_create_assistant_async', array( $job_id ), 'single', $timestamp, $user_id );
		}

		\spawn_cron();

		return $this->success(
			'Assistant creation scheduled.',
			array(
				'job_id'        => $job_id,
				'status'        => 'scheduled',
				'scheduled_for' => \wp_date( \DATE_ATOM, $timestamp ),
			)
		);
	}

	public function processAsyncCreation( string $job_id ): void {
		$transient = 'wp_mcp_ai_async_assistant_' . $job_id;
		$data      = \get_transient( $transient );

		if ( ! $data || ! isset( $data['arguments'], $data['user_id'] ) ) {
			return;
		}

		$arguments = $data['arguments'];
		$user_id   = \absint( $data['user_id'] );
		\delete_transient( $transient );

		$result = $this->createAssistant( $arguments, $user_id );

		if ( ! isset( $result['success'] ) || ! $result['success'] ) {
			$this->sendErrorNotification( $result, $arguments, $user_id );
		} else {
			$this->sendCompletionNotification( $result['data'] ?? array(), $arguments, $user_id );
		}
	}

	// ─── Metadata helpers ─────────────────────────────────────────────

	protected function handleMetadata( int $assistant_id, array $arguments ): void {
		// Featured image.
		if ( ! empty( $arguments['featured_image_id'] ) ) {
			$thumb_id = \absint( $arguments['featured_image_id'] );
			if ( $thumb_id > 0 && \wp_attachment_is_image( $thumb_id ) ) {
				\set_post_thumbnail( $assistant_id, $thumb_id );
			}
		}

		// Categories.
		if ( isset( $arguments['categories'] ) && \is_array( $arguments['categories'] ) ) {
			$tax = 'mcp_ai_assistant_category';
			if ( \taxonomy_exists( $tax ) ) {
				$ids = $this->resolveTerms( $arguments['categories'], $tax );
				if ( ! empty( $ids ) ) {
					\wp_set_object_terms( $assistant_id, $ids, $tax );
				}
			}
		}

		// Tags.
		if ( isset( $arguments['tags'] ) && \is_array( $arguments['tags'] ) ) {
			$tax = 'mcp_ai_assistant_tag';
			if ( \taxonomy_exists( $tax ) ) {
				$ids = $this->resolveTerms( $arguments['tags'], $tax );
				if ( ! empty( $ids ) ) {
					\wp_set_object_terms( $assistant_id, $ids, $tax );
				}
			}
		}

		// Custom meta.
		if ( isset( $arguments['meta_input'] ) && \is_array( $arguments['meta_input'] ) ) {
			foreach ( $arguments['meta_input'] as $key => $value ) {
				$sk = \sanitize_key( $key );
				if ( \is_protected_meta( $sk, 'post' ) ) {
					continue;
				}
				$sv = \is_array( $value )
					? \array_map( 'sanitize_text_field', $value )
					: \sanitize_text_field( $value );
				\update_post_meta( $assistant_id, $sk, $sv );
			}
		}
	}

	protected function resolveTerms( array $terms, string $taxonomy ): array {
		$ids = array();
		foreach ( $terms as $term ) {
			if ( \is_numeric( $term ) ) {
				$tid = \absint( $term );
				if ( \term_exists( $tid, $taxonomy ) ) {
					$ids[] = $tid;
				}
			} else {
				$existing = \term_exists( $term, $taxonomy );
				if ( ! $existing ) {
					$existing = \wp_insert_term( \sanitize_text_field( $term ), $taxonomy );
				}
				if ( ! \is_wp_error( $existing ) && isset( $existing['term_id'] ) ) {
					$ids[] = $existing['term_id'];
				}
			}
		}
		return \array_unique( $ids );
	}

	protected function validateAttachmentIds( array $ids, int $user_id ): array {
		$valid = array();
		foreach ( $ids as $id ) {
			$id   = \absint( $id );
			$post = \get_post( $id );

			if ( ! $post || 'attachment' !== $post->post_type ) {
				continue;
			}
			if ( (int) $post->post_author !== $user_id && ! \user_can( $user_id, 'edit_others_posts' ) ) {
				continue;
			}

			$path = \get_attached_file( $id );
			if ( $path && \file_exists( $path ) && \filesize( $path ) > self::MAX_DOCUMENT_SIZE ) {
				continue;
			}

			$valid[] = $id;
		}
		return \array_values( \array_unique( $valid ) );
	}

	// ─── Notifications ────────────────────────────────────────────────

	protected function sendCompletionNotification( array $result, array $arguments, int $user_id ): void {
		$email = \sanitize_email( $arguments['notify_email'] ?? '' );
		if ( '' === $email ) {
			$user  = \get_userdata( $user_id );
			$email = $user ? $user->user_email : '';
		}
		if ( '' === $email ) {
			return;
		}

		$title     = $result['title'] ?? 'Your Assistant';
		$edit_link = $result['edit_link'] ?? '';
		$subject   = \sprintf( '[%s] AI Assistant Created', \get_bloginfo( 'name' ) );
		$message   = \sprintf( "Your AI assistant \"%s\" has been created as a draft.%s", $title, $edit_link ? "\n\nEdit: {$edit_link}" : '' );

		\wp_mail( $email, $subject, $message );
	}

	protected function sendErrorNotification( array $error_result, array $arguments, int $user_id ): void {
		$email = \sanitize_email( $arguments['notify_email'] ?? '' );
		if ( '' === $email ) {
			$user  = \get_userdata( $user_id );
			$email = $user ? $user->user_email : '';
		}
		if ( '' === $email ) {
			return;
		}

		$subject = \sprintf( '[%s] AI Assistant Creation Failed', \get_bloginfo( 'name' ) );
		$message = \sprintf( 'Failed to create AI assistant: %s', $error_result['message'] ?? 'Unknown error' );
		\wp_mail( $email, $subject, $message );
	}
}
