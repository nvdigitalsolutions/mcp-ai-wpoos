<?php
/**
 * Tool Queue Profiles for WP oOS.
 *
 * Configuration profiles for common tool queue behaviors.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configuration constants for common tool profiles.
 *
 * Use these with the merge_queue_config helper for common patterns.
 */
class WP_MCP_AI_Tool_Queue_Profiles {

	/**
	 * Profile for quick, read-only tools (< 2 seconds).
	 *
	 * Examples: get_current_time, get_site_summary, get_user_info
	 */
	const QUICK_READ = array(
		'priority'       => 'high',
		'timeout'        => 5,
		'max_retries'    => 0,
		'idempotent'     => true,
		'parallelizable' => true,
	);

	/**
	 * Profile for standard data retrieval tools (2-10 seconds).
	 *
	 * Examples: search_content, get_recent_posts, get_woo_products
	 */
	const STANDARD_READ = array(
		'priority'       => 'normal',
		'timeout'        => 30,
		'max_retries'    => 2,
		'idempotent'     => true,
		'parallelizable' => true,
	);

	/**
	 * Profile for write operations (modifies data).
	 *
	 * Examples: save_post, create_assistant, update_option
	 */
	const WRITE_OPERATION = array(
		'priority'       => 'normal',
		'timeout'        => 30,
		'max_retries'    => 1,
		'idempotent'     => false,
		'parallelizable' => false,
	);

	/**
	 * Profile for external API calls.
	 *
	 * Examples: web_search, get_open_meteo_forecast
	 */
	const EXTERNAL_API = array(
		'priority'       => 'normal',
		'timeout'        => 60,
		'max_retries'    => 3,
		'retry_delay'    => 2000,
		'prefer_queue'   => true,
		'idempotent'     => true,
		'parallelizable' => true,
	);

	/**
	 * Profile for image generation tools.
	 *
	 * Examples: generate_openai_image, generate_gemini_image
	 */
	const IMAGE_GENERATION = array(
		'queue'          => 'tool.execution.async',
		'priority'       => 'low',
		'timeout'        => 120,
		'max_retries'    => 2,
		'retry_delay'    => 5000,
		'requires_queue' => false,
		'prefer_queue'   => true,
	);

	/**
	 * Profile for video generation tools.
	 *
	 * Examples: generate_veo_video
	 */
	const VIDEO_GENERATION = array(
		'queue'          => 'tool.execution.async',
		'priority'       => 'low',
		'timeout'        => 600,
		'max_retries'    => 1,
		'retry_delay'    => 10000,
		'requires_queue' => true,
	);

	/**
	 * Profile for web crawling tools.
	 *
	 * Examples: run_crawl4ai_job, scrape_product
	 */
	const WEB_CRAWL = array(
		'queue'        => 'tool.execution.async',
		'priority'     => 'low',
		'timeout'      => 300,
		'max_retries'  => 2,
		'retry_delay'  => 5000,
		'prefer_queue' => true,
	);

	/**
	 * Profile for speech/audio tools.
	 *
	 * Examples: generate_openai_speech, transcribe_openai_audio
	 */
	const AUDIO_PROCESSING = array(
		'priority'     => 'normal',
		'timeout'      => 120,
		'max_retries'  => 2,
		'retry_delay'  => 3000,
		'prefer_queue' => true,
	);

	/**
	 * Profile for tools requiring immediate response.
	 *
	 * Examples: count_tokens
	 */
	const REALTIME = array(
		'priority'       => 'high',
		'timeout'        => 2,
		'max_retries'    => 0,
		'requires_queue' => false,
		'idempotent'     => true,
		'parallelizable' => true,
	);

	/**
	 * Get profile by name.
	 *
	 * @param string $profile_name Profile name.
	 * @return array|null Profile configuration or null.
	 */
	public static function get( $profile_name ) {
		$profiles = array(
			'quick_read'       => self::QUICK_READ,
			'standard_read'    => self::STANDARD_READ,
			'write_operation'  => self::WRITE_OPERATION,
			'external_api'     => self::EXTERNAL_API,
			'image_generation' => self::IMAGE_GENERATION,
			'video_generation' => self::VIDEO_GENERATION,
			'web_crawl'        => self::WEB_CRAWL,
			'audio_processing' => self::AUDIO_PROCESSING,
			'realtime'         => self::REALTIME,
		);

		return isset( $profiles[ $profile_name ] ) ? $profiles[ $profile_name ] : null;
	}
}
