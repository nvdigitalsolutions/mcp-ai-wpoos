<?php
/**
 * Analytics Account DTO — Normalized account/source entity across platforms.
 *
 * Immutable data carrier representing a single social media account, ecommerce
 * storefront, or analytics property. Construct via from_array() static factory.
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
 * Normalized analytics account DTO.
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Analytics_Account_DTO {

	/**
	 * Platform identifier (instagram, facebook, twitter, linkedin, tiktok, woocommerce, etc.).
	 *
	 * @since 1.7.0
	 * @var string
	 */
	private $platform;

	/**
	 * Platform-native account ID.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	private $account_id;

	/**
	 * Human-readable account name / handle.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	private $account_name;

	/**
	 * Account type classification.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	private $account_type;

	/**
	 * Avatar / profile image URL.
	 *
	 * @since 1.7.0
	 * @var string|null
	 */
	private $avatar_url;

	/**
	 * Current follower / subscriber count.
	 *
	 * @since 1.7.0
	 * @var int
	 */
	private $followers_count;

	/**
	 * Current following / friends count.
	 *
	 * @since 1.7.0
	 * @var int
	 */
	private $following_count;

	/**
	 * Total posts / content items.
	 *
	 * @since 1.7.0
	 * @var int
	 */
	private $posts_count;

	/**
	 * Whether the account is platform-verified.
	 *
	 * @since 1.7.0
	 * @var bool
	 */
	private $verified;

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
		$this->platform        = (string) $data['platform'];
		$this->account_id      = (string) $data['account_id'];
		$this->account_name    = (string) ( $data['account_name'] ?? '' );
		$this->account_type    = (string) ( $data['account_type'] ?? 'business' );
		$this->avatar_url      = isset( $data['avatar_url'] ) ? (string) $data['avatar_url'] : null;
		$this->followers_count = isset( $data['followers_count'] ) ? (int) $data['followers_count'] : 0;
		$this->following_count = isset( $data['following_count'] ) ? (int) $data['following_count'] : 0;
		$this->posts_count     = isset( $data['posts_count'] ) ? (int) $data['posts_count'] : 0;
		$this->verified        = ! empty( $data['verified'] );
		$this->extra           = isset( $data['extra'] ) && is_array( $data['extra'] ) ? $data['extra'] : array();
	}

	/**
	 * Create from an associative array.
	 *
	 * @since 1.7.0
	 *
	 * @param array<string,mixed> $data Raw data from adapter or cache.
	 * @return WP_MCP_AI_Analytics_Account_DTO
	 */
	public static function from_array( array $data ) {
		return new self( $data );
	}

	/**
	 * Get the platform identifier.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	public function get_platform() {
		return $this->platform;
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
	 * Get the account name.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	public function get_account_name() {
		return $this->account_name;
	}

	/**
	 * Get the account type.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	public function get_account_type() {
		return $this->account_type;
	}

	/**
	 * Get the avatar URL.
	 *
	 * @since 1.7.0
	 * @return string|null
	 */
	public function get_avatar_url() {
		return $this->avatar_url;
	}

	/**
	 * Get the followers count.
	 *
	 * @since 1.7.0
	 * @return int
	 */
	public function get_followers_count() {
		return $this->followers_count;
	}

	/**
	 * Get the following count.
	 *
	 * @since 1.7.0
	 * @return int
	 */
	public function get_following_count() {
		return $this->following_count;
	}

	/**
	 * Get the posts count.
	 *
	 * @since 1.7.0
	 * @return int
	 */
	public function get_posts_count() {
		return $this->posts_count;
	}

	/**
	 * Whether the account is verified.
	 *
	 * @since 1.7.0
	 * @return bool
	 */
	public function is_verified() {
		return $this->verified;
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
			'platform'        => $this->platform,
			'account_id'      => $this->account_id,
			'account_name'    => $this->account_name,
			'account_type'    => $this->account_type,
			'avatar_url'      => $this->avatar_url,
			'followers_count' => $this->followers_count,
			'following_count' => $this->following_count,
			'posts_count'     => $this->posts_count,
			'verified'        => $this->verified,
			'extra'           => $this->extra,
		);
	}
}
