<?php
/**
 * Tests for UserInfo value object.
 *
 * @package Nvoos\Core\Tests
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Entity;

use Nvoos\Core\Domain\Entity\UserInfo;
use PHPUnit\Framework\TestCase;

final class UserInfoTest extends TestCase {

	private UserInfo $admin;
	private UserInfo $editor;

	protected function setUp(): void {
		$this->admin = new UserInfo(
			id: 1,
			login: 'admin_user',
			displayName: 'Admin User',
			email: 'admin@example.com',
			roles: array( 'administrator' ),
			capabilities: array( 'manage_options', 'edit_posts', 'delete_users' ),
		);

		$this->editor = new UserInfo(
			id: 2,
			login: 'editor_user',
			displayName: 'Editor User',
			email: 'editor@example.com',
			roles: array( 'editor' ),
			capabilities: array( 'edit_posts', 'edit_others_posts', 'publish_posts' ),
		);
	}

	public function testPropertiesAreAccessible(): void {
		$this->assertSame( 1, $this->admin->id );
		$this->assertSame( 'admin_user', $this->admin->login );
		$this->assertSame( 'Admin User', $this->admin->displayName );
		$this->assertSame( 'admin@example.com', $this->admin->email );
		$this->assertSame( array( 'administrator' ), $this->admin->roles );
		$this->assertSame(
			array( 'manage_options', 'edit_posts', 'delete_users' ),
			$this->admin->capabilities,
		);
	}

	public function testHasRoleReturnsTrueForMatchingRole(): void {
		$this->assertTrue( $this->admin->hasRole( 'administrator' ) );
		$this->assertTrue( $this->editor->hasRole( 'editor' ) );
	}

	public function testHasRoleReturnsFalseForNonMatchingRole(): void {
		$this->assertFalse( $this->admin->hasRole( 'editor' ) );
		$this->assertFalse( $this->editor->hasRole( 'administrator' ) );
	}

	public function testHasRoleWithMultipleRoles(): void {
		$user = new UserInfo(
			id: 3,
			login: 'multi_role',
			displayName: 'Multi Role',
			email: 'multi@example.com',
			roles: array( 'editor', 'author' ),
		);

		$this->assertTrue( $user->hasRole( 'editor' ) );
		$this->assertTrue( $user->hasRole( 'author' ) );
		$this->assertFalse( $user->hasRole( 'subscriber' ) );
	}

	public function testDefaultValues(): void {
		$user = new UserInfo(
			id: 4,
			login: 'minimal',
			displayName: 'Minimal',
			email: '',
		);

		$this->assertSame( array(), $user->roles );
		$this->assertSame( array(), $user->capabilities );
		$this->assertFalse( $user->hasRole( 'any_role' ) );
	}

	public function testJsonSerialize(): void {
		$json = $this->admin->jsonSerialize();

		$this->assertIsArray( $json );
		$this->assertSame( 1, $json['id'] );
		$this->assertSame( 'admin_user', $json['login'] );
		$this->assertSame( 'Admin User', $json['display_name'] );
		$this->assertSame( 'admin@example.com', $json['email'] );
		$this->assertSame( array( 'administrator' ), $json['roles'] );
		$this->assertSame(
			array( 'manage_options', 'edit_posts', 'delete_users' ),
			$json['capabilities'],
		);
	}

	public function testEmptyEmailIsAllowed(): void {
		$user = new UserInfo(
			id: 5,
			login: 'no_email',
			displayName: 'No Email',
			email: '',
		);

		$this->assertSame( '', $user->email );

		$json = $user->jsonSerialize();
		$this->assertSame( '', $json['email'] );
	}
}
