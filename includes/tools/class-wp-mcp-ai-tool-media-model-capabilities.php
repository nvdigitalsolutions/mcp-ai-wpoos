<?php
/**
 * Tool that exposes the generative-media capability registry.
 *
 * Read-only and offline: answers "which media model should I use?", "what are
 * this model's limits?", and "would this argument set be accepted?" without
 * calling any provider or spending credits.
 *
 * The validate action is the operationally important one — it lets an agent
 * pre-flight a payload before a paid generation, and makes the registry's
 * drop/clamp behaviour inspectable instead of surprising.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-model-catalog.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-prompt-craft.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

/**
 * Queries the generative-media model capability registry.
 */
class WP_MCP_AI_Tool_Media_Model_Capabilities implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'media_model_capabilities';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Media Model Capabilities', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Queries the generative-media model registry: list image/video models with their limits, describe one model in full, recommend the best model for a brief, or pre-flight an argument set against a model\'s declared constraints. Read-only, offline, and free — it never calls a provider. Use it before generate_higgsfield_image, generate_higgsfield_video, generate_veo_video, generate_omni_video, or generate_sora_video to avoid a paid generation with unsupported parameters.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Choosing between media models, checking per-model limits (aspect ratios, resolutions, durations, reference-image caps), or validating arguments before a paid generation.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Actually generating media; use generate_higgsfield_image, generate_higgsfield_video, generate_veo_video, generate_omni_video, or generate_sora_video. Shaping the prompt; use enhance_media_prompt.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'enhance_media_prompt', 'generate_higgsfield_image', 'generate_higgsfield_video', 'generate_veo_video', 'generate_omni_video', 'generate_sora_video' ),
			'notes'           => __( 'action: list, describe, recommend, or validate. Recommendations are advisory and include per-candidate reasons. Validation reports params, dropped, errors, and warnings — dropped arguments are omitted rather than rejected.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		$styles = array_merge( array( 'any' ), WP_MCP_AI_Prompt_Craft::get_styles() );

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'           => array(
					'type'        => 'string',
					'description' => __( 'Operation: "list" registered models, "describe" one model, "recommend" a model for a brief, or "validate" an argument set against a model.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'list', 'describe', 'recommend', 'validate' ),
					'default'     => 'list',
				),
				'model'            => array(
					'type'        => 'string',
					'description' => __( 'Model id, e.g. "seedance-2.0". Required for describe and validate.', 'mcp-ai-wpoos' ),
				),
				'kind'             => array(
					'type'        => 'string',
					'description' => __( 'Media kind filter.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'any', 'image', 'video', 'audio' ),
					'default'     => 'any',
				),
				'provider'         => array(
					'type'        => 'string',
					'description' => __( 'Provider filter, e.g. "higgsfield", "google", "openai", or "any".', 'mcp-ai-wpoos' ),
					'default'     => 'any',
				),
				'style'            => array(
					'type'        => 'string',
					'description' => __( 'Requested visual style. For recommend, budget-tier models are excluded when a specific style is given.', 'mcp-ai-wpoos' ),
					'enum'        => $styles,
					'default'     => 'any',
				),
				'tier'             => array(
					'type'        => 'string',
					'description' => __( 'Price/quality band filter.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'any', 'premium', 'standard', 'budget' ),
					'default'     => 'any',
				),
				'reference_images' => array(
					'type'        => 'integer',
					'description' => __( 'Number of reference images the brief needs. Models with a lower cap are excluded from recommendations.', 'mcp-ai-wpoos' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'duration'         => array(
					'type'        => 'integer',
					'description' => __( 'Required clip length in seconds. Models whose range cannot cover it are excluded from recommendations.', 'mcp-ai-wpoos' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'audio'            => array(
					'type'        => 'string',
					'description' => __( 'Audio requirement: "any", "required" (the tool must expose an audio control), or "none".', 'mcp-ai-wpoos' ),
					'enum'        => array( 'any', 'required', 'none' ),
					'default'     => 'any',
				),
				'arguments'        => array(
					'type'        => 'object',
					'description' => __( 'Argument set to check when action is "validate", e.g. {"aspect_ratio":"21:9","resolution":"4k","duration":12,"reference_images":[1,2,3]}. Non-capability keys are forwarded untouched.', 'mcp-ai-wpoos' ),
				),
				'limit'            => array(
					'type'        => 'integer',
					'description' => __( 'Maximum models returned by "list" or "recommend" (1-50).', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 20,
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Gate 1 — sanitize at entry.
		$action = isset( $arguments['action'] ) ? sanitize_key( (string) $arguments['action'] ) : 'list';
		if ( ! in_array( $action, array( 'list', 'describe', 'recommend', 'validate' ), true ) ) {
			$action = 'list';
		}

		$model_id = isset( $arguments['model'] ) ? sanitize_text_field( (string) $arguments['model'] ) : '';
		$model_id = WP_MCP_AI_Media_Model_Catalog::normalize_id( $model_id );

		$limit = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 20;
		$limit = max( 1, min( 50, $limit ) );

		if ( ! WP_MCP_AI_Media_Model_Catalog::is_available() ) {
			return new WP_Error(
				'wp_mcp_ai_media_catalog_unavailable',
				__( 'The generative-media model registry could not be loaded.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		switch ( $action ) {
			case 'describe':
				return $this->describe_model( $model_id );

			case 'recommend':
				return $this->recommend_models( $arguments, $limit );

			case 'validate':
				return $this->validate_arguments( $model_id, $arguments );

			case 'list':
			default:
				return $this->list_models( $arguments, $limit );
		}
	}

	/**
	 * List registered models, compact form.
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $limit     Maximum rows.
	 * @return array
	 */
	protected function list_models( $arguments, $limit ) {
		$query = array(
			'kind'     => isset( $arguments['kind'] ) ? sanitize_key( (string) $arguments['kind'] ) : 'any',
			'provider' => isset( $arguments['provider'] ) ? sanitize_key( (string) $arguments['provider'] ) : 'any',
			'tier'     => isset( $arguments['tier'] ) ? sanitize_key( (string) $arguments['tier'] ) : 'any',
			'style'    => isset( $arguments['style'] ) ? sanitize_key( (string) $arguments['style'] ) : 'any',
		);

		if ( 'any' === $query['kind'] ) {
			unset( $query['kind'] );
		}

		$models = WP_MCP_AI_Media_Model_Catalog::get_models( $query );
		$rows   = array();

		foreach ( array_slice( $models, 0, $limit, true ) as $id => $model ) {
			$duration = WP_MCP_AI_Media_Model_Catalog::get_duration_range( $id );
			$defaults = WP_MCP_AI_Media_Model_Catalog::get_defaults( $id );

			$row = array(
				'id'                   => (string) $id,
				'label'                => isset( $model['label'] ) ? (string) $model['label'] : (string) $id,
				'provider'             => isset( $model['provider'] ) ? (string) $model['provider'] : '',
				'kind'                 => isset( $model['kind'] ) ? (string) $model['kind'] : '',
				'tool'                 => isset( $model['tool'] ) ? (string) $model['tool'] : '',
				'tier'                 => isset( $model['tier'] ) ? (string) $model['tier'] : '',
				'styles'               => isset( $model['styles'] ) && is_array( $model['styles'] ) ? array_values( $model['styles'] ) : array(),
				'aspect_ratios'        => WP_MCP_AI_Media_Model_Catalog::get_enum( $id, 'aspect_ratios' ),
				'resolutions'          => WP_MCP_AI_Media_Model_Catalog::get_enum( $id, 'resolutions' ),
				'max_reference_images' => WP_MCP_AI_Media_Model_Catalog::max_reference_images( $id ),
				'defaults'             => $defaults,
			);

			if ( null !== $duration ) {
				$row['duration_seconds'] = array(
					'min' => $duration['min'],
					'max' => $duration['max'],
				);
			}

			$rows[] = $row;
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of models returned */
				_n( 'Returned %d media model.', 'Returned %d media models.', count( $rows ), 'mcp-ai-wpoos' ),
				count( $rows )
			),
			'data'    => array(
				'action'      => 'list',
				'count'       => count( $rows ),
				'catalog'     => $this->catalog_meta(),
				'models'      => $rows,
				'truncated'   => count( $models ) > count( $rows ),
				'total_found' => count( $models ),
			),
		);
	}

	/**
	 * Describe one model in full.
	 *
	 * @param string $model_id Model id.
	 * @return array|WP_Error
	 */
	protected function describe_model( $model_id ) {
		if ( '' === $model_id ) {
			return new WP_Error(
				'wp_mcp_ai_media_model_missing',
				__( 'A model id is required for the describe action.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$model = WP_MCP_AI_Media_Model_Catalog::get_model( $model_id );
		if ( null === $model ) {
			return new WP_Error(
				'wp_mcp_ai_media_model_unknown',
				sprintf(
					/* translators: %s: model id */
					__( 'Unknown media model "%s".', 'mcp-ai-wpoos' ),
					$model_id
				),
				array( 'status' => 404 )
			);
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: model label, 2: provider */
				__( '%1$s (%2$s) capability record.', 'mcp-ai-wpoos' ),
				isset( $model['label'] ) ? $model['label'] : $model_id,
				isset( $model['provider'] ) ? $model['provider'] : ''
			),
			'data'    => array(
				'action'  => 'describe',
				'catalog' => $this->catalog_meta(),
				'model'   => $model,
			),
		);
	}

	/**
	 * Rank models for an intent.
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $limit     Maximum candidates.
	 * @return array
	 */
	protected function recommend_models( $arguments, $limit ) {
		$intent = array(
			'kind'             => isset( $arguments['kind'] ) ? sanitize_key( (string) $arguments['kind'] ) : 'any',
			'style'            => isset( $arguments['style'] ) ? sanitize_key( (string) $arguments['style'] ) : 'any',
			'provider'         => isset( $arguments['provider'] ) ? sanitize_key( (string) $arguments['provider'] ) : 'any',
			'tier'             => isset( $arguments['tier'] ) ? sanitize_key( (string) $arguments['tier'] ) : 'any',
			'audio'            => isset( $arguments['audio'] ) ? sanitize_key( (string) $arguments['audio'] ) : 'any',
			'reference_images' => isset( $arguments['reference_images'] ) ? absint( $arguments['reference_images'] ) : 0,
			'duration'         => isset( $arguments['duration'] ) ? absint( $arguments['duration'] ) : 0,
		);

		if ( 'any' === $intent['kind'] ) {
			$intent['kind'] = 'video';
		}

		$results = array_slice( WP_MCP_AI_Media_Model_Catalog::recommend( $intent ), 0, $limit );

		$candidates = array();
		foreach ( $results as $result ) {
			$candidates[] = array(
				'id'       => $result['id'],
				'score'    => $result['score'],
				'label'    => isset( $result['model']['label'] ) ? (string) $result['model']['label'] : $result['id'],
				'provider' => isset( $result['model']['provider'] ) ? (string) $result['model']['provider'] : '',
				'tool'     => isset( $result['model']['tool'] ) ? (string) $result['model']['tool'] : '',
				'tier'     => isset( $result['model']['tier'] ) ? (string) $result['model']['tier'] : '',
				'reasons'  => $result['reasons'],
				'warnings' => $result['warnings'],
			);
		}

		return array(
			'success' => true,
			'message' => empty( $candidates )
				? __( 'No registered model satisfies those constraints. Relax the style, duration, reference count, or provider filter.', 'mcp-ai-wpoos' )
				: sprintf(
					/* translators: %d: number of candidates */
					_n( 'Recommended %d media model.', 'Recommended %d media models.', count( $candidates ), 'mcp-ai-wpoos' ),
					count( $candidates )
				),
			'data'    => array(
				'action'     => 'recommend',
				'intent'     => $intent,
				'count'      => count( $candidates ),
				'candidates' => $candidates,
				'best'       => empty( $candidates ) ? null : $candidates[0]['id'],
				'advisory'   => true,
			),
		);
	}

	/**
	 * Validate an argument set against a model.
	 *
	 * @param string $model_id Model id.
	 * @param array  $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function validate_arguments( $model_id, $arguments ) {
		if ( '' === $model_id ) {
			return new WP_Error(
				'wp_mcp_ai_media_model_missing',
				__( 'A model id is required for the validate action.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$candidate = array();
		if ( isset( $arguments['arguments'] ) && is_array( $arguments['arguments'] ) ) {
			$candidate = $this->sanitize_mixed( $arguments['arguments'] );
		}

		$result = WP_MCP_AI_Media_Model_Catalog::validate( $model_id, $candidate );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$clean = empty( $result['errors'] ) && empty( $result['dropped'] );

		return array(
			'success' => true,
			'message' => $clean
				? __( 'All capability arguments are within this model\'s declared limits.', 'mcp-ai-wpoos' )
				: sprintf(
					/* translators: 1: dropped count, 2: error count */
					__( 'Validation adjusted the request: %1$d argument(s) dropped, %2$d error(s).', 'mcp-ai-wpoos' ),
					count( $result['dropped'] ),
					count( $result['errors'] )
				),
			'data'    => array(
				'action'   => 'validate',
				'model'    => $result['model'],
				'kind'     => $result['kind'],
				'provider' => $result['provider'],
				'tool'     => $result['tool'],
				'clean'    => $clean,
				'params'   => $result['params'],
				'dropped'  => $result['dropped'],
				'errors'   => $result['errors'],
				'warnings' => $result['warnings'],
			),
		);
	}

	/**
	 * Recursively sanitize an arbitrary argument map.
	 *
	 * Arrays of integers (reference media ids) survive as integers; other
	 * scalars are treated as text.
	 *
	 * @param array $value Raw map.
	 * @return array
	 */
	protected function sanitize_mixed( $value ) {
		$clean = array();

		if ( ! is_array( $value ) ) {
			return $clean;
		}

		foreach ( $value as $key => $item ) {
			$key = is_string( $key ) ? sanitize_key( $key ) : $key;

			if ( is_array( $item ) ) {
				$clean[ $key ] = $this->sanitize_mixed( $item );
				continue;
			}

			if ( is_bool( $item ) ) {
				$clean[ $key ] = $item;
				continue;
			}

			if ( is_int( $item ) || is_float( $item ) ) {
				$clean[ $key ] = $item;
				continue;
			}

			$clean[ $key ] = sanitize_text_field( (string) $item );
		}

		return $clean;
	}

	/**
	 * Registry version metadata for response envelopes.
	 *
	 * @return array{version:string,updated_at:string,model_count:int}
	 */
	protected function catalog_meta() {
		$catalog = WP_MCP_AI_Media_Model_Catalog::get_catalog();

		return array(
			'version'     => $catalog['version'],
			'updated_at'  => $catalog['updated_at'],
			'model_count' => count( $catalog['models'] ),
		);
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'content_publishing',
			'pattern_compatibility' => array( 'sequential' ),
			'profession_tags'       => array( 'video_producer', 'content_creator', 'graphic_designer' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only', // Reads bundled registry data only.
		);
	}
}
