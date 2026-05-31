<?php
/**
 * Immutable user info value object.
 *
 * Minimal, framework-agnostic representation of a user identity.
 * Returned by AuthProviderInterface::getUserInfo().
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Domain\Entity;

final readonly class UserInfo implements \JsonSerializable
{
    /**
     * @param int      $id
     * @param string   $login          Username or login handle.
     * @param string   $displayName    Human-readable display name.
     * @param string   $email          Email address (may be empty for privacy).
     * @param string[] $roles          Role slugs ('administrator', 'editor', etc.).
     * @param string[] $capabilities   Resolved capability strings.
     */
    public function __construct(
        public int $id,
        public string $login,
        public string $displayName,
        public string $email,
        public array $roles = [],
        public array $capabilities = [],
    ) {}

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function jsonSerialize(): array
    {
        return [
            'id'            => $this->id,
            'login'         => $this->login,
            'display_name'  => $this->displayName,
            'email'         => $this->email,
            'roles'         => $this->roles,
            'capabilities'  => $this->capabilities,
        ];
    }
}
