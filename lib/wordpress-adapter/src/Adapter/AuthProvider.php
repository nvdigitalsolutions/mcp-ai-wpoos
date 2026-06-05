<?php
/**
 * WordPress adapter: AuthProviderInterface implementation.
 *
 * Wraps WordPress user functions (get_current_user_id, current_user_can,
 * wp_verify_nonce, get_userdata) behind the framework-agnostic
 * AuthProviderInterface.
 *
 * @package Nvoos\WordPress
 * @since   1.0.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Entity\AuthContext;
use Nvoos\Core\Domain\Entity\Credential;
use Nvoos\Core\Domain\Entity\UserInfo;
use Nvoos\Core\Domain\Error\AuthenticationException;

class AuthProvider implements AuthProviderInterface {

	public function currentUserId(): int {
		return \get_current_user_id();
	}

	public function userCan( int $userId, string $capability, ?int $objectId = null ): bool {
		if ( '' === $capability || 'public' === $capability ) {
			return true;
		}

		if ( null !== $objectId ) {
			return \user_can( $userId, $capability, $objectId );
		}

		return \user_can( $userId, $capability );
	}

	public function authenticate( string $token, string $tokenType = 'bearer' ): AuthContext {
		switch ( $tokenType ) {
			case 'bearer':
				return $this->authenticateBearer( $token );
			case 'nonce':
				return $this->authenticateNonce( $token );
			case 'mesh':
				return $this->authenticateMesh( $token );
			case 'guest':
				return $this->authenticateGuest( $token );
			default:
				throw new AuthenticationException(
					"Unknown token type: {$tokenType}",
					'invalid',
				);
		}
	}

	public function issueCredential( int $assistantId, array $options = array() ): Credential {
		if ( ! \current_user_can( 'manage_options' ) ) {
			throw new AuthenticationException(
				'Only administrators can issue credentials.',
				'forbidden',
			);
		}

		// Generate a WordPress-style token: prefix + random bytes.
		$tokenBytes = \random_bytes( 48 );
		$token      = 'cred_' . \bin2hex( $tokenBytes );
		$secret     = \wp_hash_password( $token );
		$credId     = \wp_generate_uuid4();

		$expiresAt = null;
		if ( ! empty( $options['expires_in_seconds'] ) ) {
			$expiresAt = ( new \DateTimeImmutable() )->add(
				new \DateInterval( 'PT' . (int) $options['expires_in_seconds'] . 'S' ),
			);
		}

		$grantedCapabilities = $options['capabilities'] ?? array( 'edit_posts' );

		// Store the credential hash in post meta on the assistant.
		$existing = \get_post_meta( $assistantId, '_wp_mcp_ai_credentials', true );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$existing[ $credId ] = array(
			'secret'       => $secret,
			'capabilities' => $grantedCapabilities,
			'created_at'   => \gmdate( 'c' ),
			'expires_at'   => $expiresAt?->format( 'c' ),
		);

		\update_post_meta( $assistantId, '_wp_mcp_ai_credentials', $existing );

		return new Credential(
			id: $credId,
			token: $token,
			secret: $secret,
			assistantId: $assistantId,
			createdAt: new \DateTimeImmutable(),
			expiresAt: $expiresAt,
			capabilities: $grantedCapabilities,
		);
	}

	public function revokeCredential( string $credentialId ): void {
		// Walk all assistants to find and remove the credential.
		$assistants = \get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $assistants as $assistantId ) {
			$credentials = \get_post_meta( $assistantId, '_wp_mcp_ai_credentials', true );
			if ( is_array( $credentials ) && isset( $credentials[ $credentialId ] ) ) {
				unset( $credentials[ $credentialId ] );
				\update_post_meta( $assistantId, '_wp_mcp_ai_credentials', $credentials );
				break;
			}
		}
	}

	public function getUserInfo( int $userId ): ?UserInfo {
		$user = \get_userdata( $userId );
		if ( ! $user instanceof \WP_User ) {
			return null;
		}

		return new UserInfo(
			id: $user->ID,
			login: $user->user_login,
			displayName: $user->display_name,
			email: $user->user_email,
			roles: array_values( $user->roles ),
			capabilities: array_keys( $user->allcaps, true, true ),
		);
	}

	public function isUserMemberOfSite( int $userId ): bool {
		if ( ! \is_multisite() ) {
			return $this->getUserInfo( $userId ) !== null;
		}

		return \is_user_member_of_blog( $userId, \get_current_blog_id() );
	}

	// ─── Private authentication helpers ────────────────────────────────

	private function authenticateBearer( string $token ): AuthContext {
		// Walk assistants to find a matching credential.
		$assistants = \get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $assistants as $assistantId ) {
			$credentials = \get_post_meta( $assistantId, '_wp_mcp_ai_credentials', true );
			if ( ! is_array( $credentials ) ) {
				continue;
			}

			foreach ( $credentials as $credId => $cred ) {
				if ( ! is_array( $cred ) || empty( $cred['secret'] ) ) {
					continue;
				}

				if ( \wp_check_password( $token, $cred['secret'] ) ) {
					// Check expiration.
					if ( ! empty( $cred['expires_at'] ) ) {
						$expires = \DateTimeImmutable::createFromFormat( 'c', $cred['expires_at'] );
						if ( $expires && $expires <= new \DateTimeImmutable() ) {
							continue; // Expired — skip.
						}
					}

					// Map the credential to a WordPress user. If the assistant
					// has an author, use that user for capability resolution.
					$post     = \get_post( $assistantId );
					$mappedId = $post instanceof \WP_Post ? (int) $post->post_author : 0;
					$userInfo = $mappedId > 0 ? $this->getUserInfo( $mappedId ) : null;

					return new AuthContext(
						userId: $mappedId,
						authenticated: true,
						tokenType: 'bearer',
						scopedAssistantId: $assistantId,
						capabilities: $cred['capabilities'] ?? array(),
						metadata: array(
							'token_context' => array(
								'credential_id' => $credId,
								'assistant_id'  => $assistantId,
							),
						),
					);
				}
			}
		}

		throw new AuthenticationException( 'Invalid or expired bearer token.', 'invalid' );
	}

	private function authenticateNonce( string $token ): AuthContext {
		if ( ! \wp_verify_nonce( $token, 'wp_rest' ) ) {
			throw new AuthenticationException( 'Invalid REST nonce.', 'invalid' );
		}

		$userId = \get_current_user_id();

		return new AuthContext(
			userId: $userId,
			authenticated: $userId > 0,
			tokenType: 'nonce',
			metadata: array(
				'is_user_logged_in' => \is_user_logged_in(),
			),
		);
	}

	private function authenticateMesh( string $token ): AuthContext {
		$settings = \get_option( 'wp_mcp_ai_settings', array() );
		$meshKey  = $settings['mesh_api_key'] ?? '';

		if ( '' === $meshKey || ! \hash_equals( $meshKey, $token ) ) {
			throw new AuthenticationException( 'Invalid mesh API key.', 'invalid' );
		}

		return new AuthContext(
			authenticated: true,
			tokenType: 'mesh',
			capabilities: array( 'manage_options' ), // Mesh has full access.
			metadata: array( 'mesh_authenticated' => true ),
		);
	}

	private function authenticateGuest( string $token ): AuthContext {
		if ( ! \class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			throw new AuthenticationException( 'Guest tokens are not available.', 'invalid' );
		}

		// Guest tokens are validated by the WP_MCP_AI_Shortcode helper.
		$assistantId = \WP_MCP_AI_Shortcode::validate_guest_token( $token, 0, null );

		if ( ! $assistantId ) {
			throw new AuthenticationException( 'Invalid guest token.', 'invalid' );
		}

		return new AuthContext(
			userId: 0,
			authenticated: true,
			tokenType: 'guest',
			scopedAssistantId: (int) $assistantId,
			capabilities: array( 'public' ),
			metadata: array( 'is_guest' => true ),
		);
	}
}
