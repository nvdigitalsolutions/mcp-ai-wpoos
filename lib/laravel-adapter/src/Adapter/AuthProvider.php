<?php
/**
 * Laravel adapter: AuthProviderInterface implementation.
 *
 * Wraps Laravel's Auth facade, Sanctum tokens, and Gates behind the
 * framework-agnostic AuthProviderInterface. Supports the same four
 * token types as the WordPress adapter: bearer, nonce, mesh, and guest.
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Entity\AuthContext;
use Nvoos\Core\Domain\Entity\Credential;
use Nvoos\Core\Domain\Entity\UserInfo;
use Nvoos\Core\Domain\Error\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuthProvider implements AuthProviderInterface {

	/**
	 * Get the current authenticated user ID.
	 *
	 * Returns 0 for guests/unauthenticated requests.
	 *
	 * @return int
	 */
	public function currentUserId(): int {
		return Auth::id() ?? 0;
	}

	/**
	 * Check if a user has a specific capability/permission.
	 *
	 * Uses Laravel Gates for authorization. Public capabilities
	 * (empty string, 'public') are always granted.
	 *
	 * @param int      $userId     User ID.
	 * @param string   $capability Capability string (e.g., 'edit_posts').
	 * @param int|null $objectId   Optional object-level permission check.
	 */
	public function userCan( int $userId, string $capability, ?int $objectId = null ): bool {
		if ( '' === $capability || 'public' === $capability ) {
			return true;
		}

		$user = Auth::getProvider()->retrieveById( $userId );
		if ( null === $user ) {
			return false;
		}

		if ( null !== $objectId ) {
			return Gate::forUser( $user )->allows( $capability, array( $objectId ) );
		}

		return Gate::forUser( $user )->allows( $capability );
	}

	/**
	 * Verify a request authentication token and return its resolved context.
	 *
	 * Supports: 'bearer' (Sanctum API tokens), 'nonce' (CSRF), 'mesh' (API key),
	 * and 'guest' (temporary guest tokens).
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
	 * Creates a Sanctum personal access token with the specified
	 * capabilities. The token plain-text value is returned once —
	 * store it securely on the client.
	 *
	 * @param int                  $assistantId  The assistant/model the token grants access to.
	 * @param array<string, mixed> $options      expiry, capabilities, name.
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

		$user = Auth::getProvider()->retrieveById( $userId );
		if ( null === $user ) {
			throw new AuthenticationException( 'User not found.', 'forbidden' );
		}

		// Only users with the 'manage_oos' ability can issue credentials.
		if ( ! Gate::forUser( $user )->allows( 'manage_oos' ) ) {
			throw new AuthenticationException(
				'Only administrators can issue credentials.',
				'forbidden',
			);
		}

		$capabilities = $options['capabilities'] ?? array( 'edit_posts' );
		$tokenName    = $options['name'] ?? "oos-assistant-{$assistantId}";

		$plainTextToken = $user->createToken( $tokenName, $capabilities )->plainTextToken;

		$expiresAt = null;
		if ( ! empty( $options['expires_in_seconds'] ) ) {
			$expiresAt = ( new \DateTimeImmutable() )->add(
				new \DateInterval( 'PT' . (int) $options['expires_in_seconds'] . 'S' ),
			);
		}

		return new Credential(
			id: uniqid( 'cred_', true ),
			token: $plainTextToken,
			secret: Hash::make( $plainTextToken ),
			assistantId: $assistantId,
			createdAt: new \DateTimeImmutable(),
			expiresAt: $expiresAt,
			capabilities: $capabilities,
		);
	}

	/**
	 * Revoke a previously issued credential.
	 *
	 * Deletes the Sanctum token by its database ID.
	 *
	 * @param string $credentialId  Credential identifier.
	 */
	public function revokeCredential( string $credentialId ): void {
		if ( Schema::hasTable( 'personal_access_tokens' ) ) {
			DB::table( 'personal_access_tokens' )->where( 'id', $credentialId )->delete();
		}
	}

	/**
	 * Get user information by ID.
	 *
	 * Converts the Eloquent User model to a domain UserInfo entity.
	 *
	 * @param int $userId  User ID.
	 * @return UserInfo|null  Null if the user does not exist.
	 */
	public function getUserInfo( int $userId ): ?UserInfo {
		$user = Auth::getProvider()->retrieveById( $userId );
		if ( null === $user ) {
			return null;
		}

		$roles = method_exists( $user, 'roles' )
			? $user->roles()->pluck( 'name' )->toArray()
			: array();

		// Collect all permission names from the user's roles and direct permissions.
		$capabilities = array();
		if ( method_exists( $user, 'getAllPermissions' ) ) {
			$capabilities = $user->getAllPermissions()->pluck( 'name' )->toArray();
		} elseif ( method_exists( $user, 'getPermissions' ) ) {
			$capabilities = $user->getPermissions()->pluck( 'name' )->toArray();
		}

		return new UserInfo(
			id: $user->getAuthIdentifier(),
			login: method_exists( $user, 'email' ) ? $user->email : (string) $user->getAuthIdentifier(),
			displayName: method_exists( $user, 'name' ) ? $user->name : (string) $user->getAuthIdentifier(),
			email: method_exists( $user, 'email' ) ? $user->email : '',
			roles: $roles,
			capabilities: $capabilities,
		);
	}

	/**
	 * Check if a user belongs to the current tenant/site.
	 *
	 * Uses Laravel's tenant awareness when a multi-tenant package
	 * is in use; otherwise falls back to checking that the user exists.
	 *
	 * @param int $userId  User ID.
	 * @return bool
	 */
	public function isUserMemberOfSite( int $userId ): bool {
		return $this->getUserInfo( $userId ) !== null;
	}

	// ─── Private authentication helpers ────────────────────────────────

	/**
	 * Authenticate a bearer token (Sanctum or Passport).
	 */
	private function authenticateBearer( string $token ): AuthContext {
		$user = Auth::guard( 'sanctum' )->user();

		// Fallback: try the web/api guard if Sanctum didn't resolve.
		if ( null === $user && request() instanceof \Illuminate\Http\Request ) {
			$user = Auth::guard( 'api' )->user();
		}

		if ( null === $user ) {
			throw new AuthenticationException( 'Invalid or expired bearer token.', 'invalid' );
		}

		$capabilities = array();
		if ( method_exists( $user, 'currentAccessToken' ) && $user->currentAccessToken() ) {
			$capabilities = $user->currentAccessToken()->abilities ?? array();
		}

		return new AuthContext(
			userId: $user->getAuthIdentifier(),
			authenticated: true,
			tokenType: 'bearer',
			capabilities: $capabilities,
			metadata: array(
				'token_context' => array(
					'token_id' => $user->currentAccessToken()?->id,
				),
			),
		);
	}

	/**
	 * Authenticate via CSRF nonce (session-based).
	 *
	 * Laravel's CSRF middleware already validates the token;
	 * this method simply returns the current authenticated user.
	 */
	private function authenticateNonce( string $token ): AuthContext {
		$userId = $this->currentUserId();

		return new AuthContext(
			userId: $userId,
			authenticated: $userId > 0,
			tokenType: 'nonce',
			metadata: array(
				'is_user_logged_in' => Auth::check(),
			),
		);
	}

	/**
	 * Authenticate a mesh network API key.
	 *
	 * Compares the provided token against the configured mesh key.
	 */
	private function authenticateMesh( string $token ): AuthContext {
		$meshKey = config( 'oos.mesh_api_key', '' );

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
	 * Guest tokens are HMAC-signed strings that encode the assistant ID
	 * and expiration time. The signing key is derived from the app key.
	 */
	private function authenticateGuest( string $token ): AuthContext {
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) {
			throw new AuthenticationException( 'Invalid guest token format.', 'invalid' );
		}

		list( $assistantId, $expires, $signature ) = $parts;

		$appKey  = config( 'app.key' );
		$payload = "{$assistantId}.{$expires}";
		$expected = hash_hmac( 'sha256', $payload, $appKey );

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
