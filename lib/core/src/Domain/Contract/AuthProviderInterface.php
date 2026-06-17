<?php
/**
 * Authentication provider contract for the oOS AI orchestration core.
 *
 * Abstracts user identity, capability checks, token validation, and
 * credential management so that tools and services never depend on
 * WordPress functions (current_user_can, wp_verify_nonce, etc.).
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

use Nvoos\Core\Domain\Entity\AuthContext;
use Nvoos\Core\Domain\Entity\Credential;
use Nvoos\Core\Domain\Entity\UserInfo;

interface AuthProviderInterface {

	/**
	 * Get the current authenticated user ID.
	 *
	 * Returns 0 for guests/unauthenticated requests.
	 */
	public function currentUserId(): int;

	/**
	 * Check if a user has a specific capability/permission.
	 *
	 * Capabilities are domain-specific strings:
	 *  - 'edit_posts', 'manage_options', 'read', 'public', 'manage_assistants', etc.
	 *
	 * @param int|null $objectId  Optional object-level permission check
	 *                            (e.g., "can user 1 edit post 42?").
	 */
	public function userCan( int $userId, string $capability, ?int $objectId = null ): bool;

	/**
	 * Verify a request authentication token and return its resolved context.
	 *
	 * Supports multiple token types:
	 *  - 'bearer': Authorization header with local credential or Auth0 JWT
	 *  - 'nonce':  WordPress-style nonce for same-origin requests
	 *  - 'mesh':   Mesh network API key
	 *  - 'guest':  Temporary guest token for public chat surfaces
	 *
	 * @return AuthContext  Context including user_id, token_type, scoped assistant, etc.
	 *
	 * @throws \Nvoos\Core\Domain\Error\AuthenticationException  When token is invalid or expired.
	 */
	public function authenticate( string $token, string $tokenType = 'bearer' ): AuthContext;

	/**
	 * Issue a new credential for external API access to an assistant.
	 *
	 * @param int   $assistantId  The assistant the credential grants access to.
	 * @param array $options      Additional options (expiration, capabilities, etc.).
	 *
	 * @return Credential  The issued credential with token and metadata.
	 */
	public function issueCredential( int $assistantId, array $options = array() ): Credential;

	/**
	 * Revoke a previously issued credential.
	 */
	public function revokeCredential( string $credentialId ): void;

	/**
	 * Get user information by ID.
	 *
	 * @return UserInfo|null  Null if the user does not exist.
	 */
	public function getUserInfo( int $userId ): ?UserInfo;

	/**
	 * Check if a user belongs to the current site/tenant.
	 *
	 * Used for multisite/multi-tenant awareness — a user may exist
	 * in the system but not be a member of the current site.
	 */
	public function isUserMemberOfSite( int $userId ): bool;
}
