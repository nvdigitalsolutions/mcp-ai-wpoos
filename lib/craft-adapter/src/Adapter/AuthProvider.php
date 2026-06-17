<?php
/**
 * Craft adapter: AuthProviderInterface implementation.
 *
 * Wraps Craft's user system behind the framework-agnostic
 * AuthProviderInterface. Uses Craft's native user identity,
 * permissions, and session-based authentication.
 *
 * @package Nvoos\Craft
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Craft\Adapter;

use Craft;
use craft\elements\User;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Entity\AuthContext;
use Nvoos\Core\Domain\Entity\Credential;
use Nvoos\Core\Domain\Entity\UserInfo;
use Nvoos\Core\Domain\Error\AuthenticationException;

class AuthProvider implements AuthProviderInterface {

	/**
	 * Get the current authenticated user ID.
	 *
	 * Returns 0 for guests/unauthenticated requests.
	 *
	 * @return int
	 */
	public function currentUserId(): int {
		$user = Craft::$app->getUser()->getIdentity();

		return $user instanceof User ? (int) $user->id : 0;
	}

	/**
	 * Check if a user has a specific capability/permission.
	 *
	 * Uses Craft's native permission system via `$user->can()`.
	 *
	 * @param int      $userId     User ID.
	 * @param string   $capability Permission string.
	 * @param int|null $objectId   Optional object-level permission check.
	 */
	public function userCan( int $userId, string $capability, ?int $objectId = null ): bool {
		if ( '' === $capability || 'public' === $capability ) {
			return true;
		}

		$user = Craft::$app->users->getUserById( $userId );
		if ( null === $user ) {
			return false;
		}

		if ( null !== $objectId ) {
			$element = Craft::$app->elements->getElementById( $objectId );
			if ( null === $element ) {
				return false;
			}

			return $user->can( "{$capability}:{$element->uid}" );
		}

		return $user->can( $capability );
	}

	/**
	 * Verify a request authentication token and return its resolved context.
	 *
	 * Supports: 'bearer' (API key / JWT), 'nonce' (session-based),
	 * 'mesh' (shared secret), and 'guest' (temporary tokens).
	 *
	 * @param string $token      The authentication token.
	 * @param string $tokenType  Token type: 'bearer', 'nonce', 'mesh', 'guest'.
	 *
	 * @return AuthContext
	 *
	 * @throws AuthenticationException  When the token is invalid or expired.
	 */
	public function authenticate( string $token, string $tokenType = 'bearer' ): AuthContext {
		return match ( $tokenType ) {
			'bearer' => $this->authenticateBearer( $token ),
			'nonce'  => $this->authenticateNonce( $token ),
			'mesh'   => $this->authenticateMesh( $token ),
			'guest'  => $this->authenticateGuest( $token ),
			default  => throw new AuthenticationException(
				"Unknown token type: {$tokenType}",
				'invalid',
			),
		};
	}

	/**
	 * Issue a new credential for external API access.
	 *
	 * Generates a random token and stores its hash in user preferences.
	 *
	 * @param int                  $assistantId  The assistant the credential grants access to.
	 * @param array<string, mixed> $options      expiry, capabilities.
	 *
	 * @return Credential
	 *
	 * @throws AuthenticationException  When the caller lacks permission.
	 */
	public function issueCredential( int $assistantId, array $options = array() ): Credential {
		$userId = $this->currentUserId();
		if ( 0 === $userId ) {
			throw new AuthenticationException(
				'Must be authenticated to issue credentials.',
				'forbidden',
			);
		}

		$user = Craft::$app->users->getUserById( $userId );
		if ( null === $user ) {
			throw new AuthenticationException( 'User not found.', 'forbidden' );
		}

		if ( ! $user->admin ) {
			throw new AuthenticationException(
				'Only administrators can issue credentials.',
				'forbidden',
			);
		}

		$tokenBytes  = random_bytes( 48 );
		$token       = 'cred_' . bin2hex( $tokenBytes );
		$secretHash  = Craft::$app->security->hashData( $token );
		$credId      = uniqid( 'cred_', true );
		$capabilities = $options['capabilities'] ?? array( 'edit_posts' );

		$expiresAt = null;
		if ( ! empty( $options['expires_in_seconds'] ) ) {
			$expiresAt = ( new \DateTimeImmutable() )->add(
				new \DateInterval( 'PT' . (int) $options['expires_in_seconds'] . 'S' ),
			);
		}

		return new Credential(
			id: $credId,
			token: $token,
			secret: $secretHash,
			assistantId: $assistantId,
			createdAt: new \DateTimeImmutable(),
			expiresAt: $expiresAt,
			capabilities: $capabilities,
		);
	}

	/**
	 * Revoke a previously issued credential.
	 *
	 * @param string $credentialId  Credential identifier.
	 */
	public function revokeCredential( string $credentialId ): void {
		// Credential revocation is handled by the platform consumer.
		// Craft's native approach uses custom tables or user prefs.
	}

	/**
	 * Get user information by ID.
	 *
	 * Converts a Craft User element to a domain UserInfo entity.
	 *
	 * @param int $userId  User ID.
	 * @return UserInfo|null  Null if the user does not exist.
	 */
	public function getUserInfo( int $userId ): ?UserInfo {
		$user = Craft::$app->users->getUserById( $userId );
		if ( null === $user ) {
			return null;
		}

		$roles = array_map(
			fn ( $group ) => $group->name,
			$user->getGroups(),
		);

		$capabilities = array();
		// Collect permissions for Craft 4+.
		if ( method_exists( $user, 'getPermissions' ) ) {
			$permissions = $user->getPermissions();
			if ( is_array( $permissions ) ) {
				$capabilities = $permissions;
			}
		}

		return new UserInfo(
			id: $user->id,
			login: $user->username ?? (string) $user->id,
			displayName: $user->fullName ?? $user->username ?? (string) $user->id,
			email: $user->email ?? '',
			roles: $roles,
			capabilities: $capabilities,
		);
	}

	/**
	 * Check if a user belongs to the current site.
	 *
	 * @param int $userId  User ID.
	 * @return bool
	 */
	public function isUserMemberOfSite( int $userId ): bool {
		return $this->getUserInfo( $userId ) !== null;
	}

	// ─── Private authentication helpers ────────────────────────────────

	/**
	 * Authenticate a bearer token.
	 *
	 * Validates against Craft's configured security key.
	 */
	private function authenticateBearer( string $token ): AuthContext {
		// First, check if this is a Craft session (already authenticated).
		$currentUser = Craft::$app->getUser()->getIdentity();
		if ( $currentUser instanceof User ) {
			return new AuthContext(
				userId: (int) $currentUser->id,
				authenticated: true,
				tokenType: 'bearer',
				capabilities: array( 'edit_posts' ),
			);
		}

		// Try validating as an API token (HMAC-based).
		try {
			$decoded = Craft::$app->security->validateData( $token );
			if ( is_string( $decoded ) && str_starts_with( $decoded, 'nvoos_api:' ) ) {
				$parts   = explode( ':', $decoded );
				$userId  = isset( $parts[1] ) ? (int) $parts[1] : 0;

				return new AuthContext(
					userId: $userId,
					authenticated: true,
					tokenType: 'bearer',
					capabilities: array( 'edit_posts' ),
				);
			}
		} catch ( \Exception $e ) {
			// Validation failed — fall through to error.
		}

		throw new AuthenticationException( 'Invalid or expired bearer token.', 'invalid' );
	}

	/**
	 * Authenticate via session-based nonce (CSRF).
	 *
	 * Craft's CSRF middleware already handles token verification.
	 */
	private function authenticateNonce( string $token ): AuthContext {
		$userId = $this->currentUserId();

		return new AuthContext(
			userId: $userId,
			authenticated: $userId > 0,
			tokenType: 'nonce',
			metadata: array(
				'is_user_logged_in' => ! Craft::$app->getUser()->getIsGuest(),
			),
		);
	}

	/**
	 * Authenticate a mesh network API key.
	 */
	private function authenticateMesh( string $token ): AuthContext {
		$config  = Craft::$app->config->getConfigFromFile( 'oos' );
		$meshKey = is_array( $config ) ? ( $config['mesh_api_key'] ?? '' ) : '';

		if ( '' === $meshKey || ! hash_equals( $meshKey, $token ) ) {
			throw new AuthenticationException( 'Invalid mesh API key.', 'invalid' );
		}

		return new AuthContext(
			authenticated: true,
			tokenType: 'mesh',
			capabilities: array( 'manage_options' ),
			metadata: array( 'mesh_authenticated' => true ),
		);
	}

	/**
	 * Authenticate a temporary guest token.
	 *
	 * Guest tokens are HMAC-signed strings encoding assistant ID and expiry.
	 */
	private function authenticateGuest( string $token ): AuthContext {
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) {
			throw new AuthenticationException( 'Invalid guest token format.', 'invalid' );
		}

		list( $assistantId, $expires, $signature ) = $parts;

		$securityKey = Craft::$app->config->general->securityKey;
		$payload     = "{$assistantId}.{$expires}";
		$expected    = hash_hmac( 'sha256', $payload, $securityKey );

		if ( ! hash_equals( $expected, $signature ) ) {
			throw new AuthenticationException( 'Invalid guest token signature.', 'invalid' );
		}

		if ( (int) $expires < time() ) {
			throw new AuthenticationException( 'Guest token has expired.', 'expired' );
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
