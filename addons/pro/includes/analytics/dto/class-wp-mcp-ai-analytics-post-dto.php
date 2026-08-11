<?php
/**
 * Analytics Post DTO — Normalized post/content entity across platforms.
 *
 * Immutable data carrier representing a single post, tweet, reel, video, or
 * other content item with its associated metrics. Construct via from_array().
 *
 * @package WP_MCP_AI_Pro
 * @since 1.7.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license  Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalized analytics post DTO.
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Analytics_Post_DTO {

	/**
	 * Platform identifier.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	private $platform;

	/**
	 * Platform-native post ID.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	private $post_id;

	/**
	 * Owning account ID.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	private $account_id;

	/**
	 * Content type (image, video, carousel, text, link, story, reel, short).
	 *
	 * @since 1.7.0
	 * @var string
	 */
	private $content_type;

	/**
	 * Permalink to the post.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	private $permalink;

	/**
	 * ISO 8601 timestamp of when the post was published.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	private $posted_at;

	/**
	 * Caption / post body text.
	 *
	 * @since 1.7.0
	 * @var string|null
	 */
	private $caption;

	/**
	 * Hashtags used in the post.
	 *
	 * @since 1.7.0
	 * @var array<int,string>
	 */
	private $hashtags;

	/**
	 * Account mentions in the post.
	 *
	 * @since 1.7.0
	 * @var array<int,string>
	 */
	private $mentions;

	/**
	 * Media attachment URLs.
	 *
	 * @since 1.7.0
	 * @var array<int,string>
	 */
	private $media_urls;

	/**
	 * Post-level metrics.
	 *
	 * @since 1.7.0
	 * @var array<string,int|float>
	 */
	private $metrics;

	/**
	 * Arbitrary metadata from the platform adapter.
	 *
	 * @since 1.7.0
	 * @var array<string,mixed>
	 */
	private $extra;

	/**
	 * Private constructor — use from_array().
	 *
	 * @since 1.7.0
	 *
	 * @param array<string,mixed> $data Hydrated data.
	 */
	private function __construct( array $data ) {
		$this->platform     = (string) $data['platform'];
		$this->post_id      = (string) $data['post_id'];
		$this->account_id   = (string) ( $data['account_id'] ?? '' );
		$this->content_type = (string) ( $data['content_type'] ?? 'text' );
		$this->permalink    = (string) ( $data['permalink'] ?? '' );
		$this->posted_at    = (string) ( $data['posted_at'] ?? '' );
		$this->caption      = isset( $data['caption'] ) ? (string) $data['caption'] : null;
		$this->hashtags     = isset( $data['hashtags'] ) && is_array( $data['hashtags'] )
			? array_map( 'strval', $data['hashtags'] )
			: array();
		$this->mentions     = isset( $data['mentions'] ) && is_array( $data['mentions'] )
			? array_map( 'strval', $data['mentions'] )
			: array();
		$this->media_urls   = isset( $data['media_urls'] ) && is_array( $data['media_urls'] )
			? array_map( 'esc_url_raw', $data['media_urls'] )
			: array();
		$this->metrics      = isset( $data['metrics'] ) && is_array( $data['metrics'] ) ? $data['metrics'] : array();
		$this->extra        = isset( $data['extra'] ) && is_array( $data['extra'] ) ? $data['extra'] : array();
	}

	/**
	 * Create from an associative array.
	 *
	 * @since 1.7.0
	 *
	 * @param array<string,mixed> $data Raw data from adapter or cache.
	 * @return WP_MCP_AI_Analytics_Post_DTO
	 */
	public static function from_array( array $data ) {
		return new self( $data );
	}

	/**
	 * Get the platform.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	public function get_platform() {
		return $this->platform;
	}

	/**
	 * Get the post ID.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	public function get_post_id() {
		return $this->post_id;
	}

	/**
	 * Get the account ID.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	public function get_account_id() {
		return $this->account_id;
	}

	/**
	 * Get the content type.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	public function get_content_type() {
		return $this->content_type;
	}

	/**
	 * Get the permalink.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	public function get_permalink() {
		return $this->permalink;
	}

	/**
	 * Get the posted-at timestamp.
	 *
	 * @since 1.7.0
	 * @return string ISO 8601.
	 */
	public function get_posted_at() {
		return $this->posted_at;
	}

	/**
	 * Get the caption.
	 *
	 * @since 1.7.0
	 * @return string|null
	 */
	public function get_caption() {
		return $this->caption;
	}

	/**
	 * Get the hashtags.
	 *
	 * @since 1.7.0
	 * @return array<int,string>
	 */
	public function get_hashtags() {
		return $this->hashtags;
	}

	/**
	 * Get the mentions.
	 *
	 * @since 1.7.0
	 * @return array<int,string>
	 */
	public function get_mentions() {
		return $this->mentions;
	}

	/**
	 * Get the media URLs.
	 *
	 * @since 1.7.0
	 * @return array<int,string>
	 */
	public function get_media_urls() {
		return $this->media_urls;
	}

	/**
	 * Get a specific metric value.
	 *
	 * @since 1.7.0
	 *
	 * @param string $key     Metric key (impressions, reach, engagement, likes, etc.).
	 * @param mixed  $default Default value if key is missing.
	 * @return mixed
	 */
	public function get_metric( $key, $default = 0 ) {
		return isset( $this->metrics[ $key ] ) ? $this->metrics[ $key ] : $default;
	}

	/**
	 * Get all metrics.
	 *
	 * @since 1.7.0
	 * @return array<string,int|float>
	 */
	public function get_metrics() {
		return $this->metrics;
	}

	/**
	 * Get extra metadata.
	 *
	 * @since 1.7.0
	 * @return array<string,mixed>
	 */
	public function get_extra() {
		return $this->extra;
	}

	/**
	 * Convert to an associative array.
	 *
	 * @since 1.7.0
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return array(
			'platform'     => $this->platform,
			'post_id'      => $this->post_id,
			'account_id'   => $this->account_id,
			'content_type' => $this->content_type,
			'permalink'    => $this->permalink,
			'posted_at'    => $this->posted_at,
			'caption'      => $this->caption,
			'hashtags'     => $this->hashtags,
			'mentions'     => $this->mentions,
			'media_urls'   => $this->media_urls,
			'metrics'      => $this->metrics,
			'extra'        => $this->extra,
		);
	}
}
