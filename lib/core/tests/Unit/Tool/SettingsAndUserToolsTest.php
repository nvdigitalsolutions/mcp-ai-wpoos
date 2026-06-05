<?php
/**
 * Tests for GetSettingTool, UpdateSettingTool, ListSettingsTool, and GetCurrentUserTool.
 *
 * @package Nvoos\Core\Tests
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\UserInfo;
use Nvoos\Core\Tool\GetCurrentUserTool;
use Nvoos\Core\Tool\GetSettingTool;
use Nvoos\Core\Tool\ListSettingsTool;
use Nvoos\Core\Tool\UpdateSettingTool;
use PHPUnit\Framework\TestCase;

final class SettingsAndUserToolsTest extends TestCase {

	// ─── GetSettingTool ──────────────────────────────────────────────

	public function testGetSettingReturnsValue(): void {
		$settings = $this->createMock( SettingsStoreInterface::class );
		$settings->method( 'get' )->with( 'default_provider', null )->willReturn( 'openai' );

		$tool   = new GetSettingTool( $this->stubErrors(), $settings );
		$result = $tool->execute( array( 'key' => 'default_provider' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'openai', $result['data']['value'] );
		$this->assertTrue( $result['data']['found'] );
	}

	public function testGetSettingReturnsNullWhenMissing(): void {
		$settings = $this->createMock( SettingsStoreInterface::class );
		$settings->method( 'get' )->willReturn( null );

		$tool   = new GetSettingTool( $this->stubErrors(), $settings );
		$result = $tool->execute( array( 'key' => 'nonexistent' ) );

		$this->assertTrue( $result['success'] );
		$this->assertNull( $result['data']['value'] );
		$this->assertFalse( $result['data']['found'] );
	}

	// ─── UpdateSettingTool ──────────────────────────────────────────

	public function testUpdateSettingStoresValue(): void {
		$settings = $this->createMock( SettingsStoreInterface::class );
		$settings->expects( $this->once() )
			->method( 'set' )
			->with( 'default_provider', 'gemini' );

		$tool   = new UpdateSettingTool( $this->stubErrors(), $settings );
		$result = $tool->execute( array(
			'key'   => 'default_provider',
			'value' => 'gemini',
		) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'gemini', $result['data']['value'] );
	}

	public function testUpdateSettingRejectsForbiddenKey(): void {
		$expectedError = array( 'success' => false, 'error' => array( 'code' => 'forbidden_key' ) );

		$errors = $this->createMock( ErrorFactoryInterface::class );
		$errors->method( 'create' )->willReturn( $expectedError );

		$tool   = new UpdateSettingTool( $errors, $this->createMock( SettingsStoreInterface::class ) );
		$result = $tool->execute( array(
			'key'   => 'openai_api_key',
			'value' => 'sk-evil',
		) );

		$this->assertSame( $expectedError, $result );
	}

	// ─── ListSettingsTool ───────────────────────────────────────────

	public function testListSettingsReturnsAll(): void {
		$settings = $this->createMock( SettingsStoreInterface::class );
		$settings->method( 'all' )->willReturn( array(
			'default_provider' => 'openai',
			'default_model'    => 'gpt-4o-mini',
			'openai_api_key'   => 'sk-secret1234567890',
		) );

		$tool   = new ListSettingsTool( $this->stubErrors(), $settings );
		$result = $tool->execute();

		$this->assertTrue( $result['success'] );
		$this->assertSame( 3, $result['data']['count'] );
		$this->assertSame( 'openai', $result['data']['settings']['default_provider'] );
		// API key should be redacted.
		$this->assertStringContainsString( '****', $result['data']['settings']['openai_api_key'] );
		$this->assertStringStartsWith( 'sk-s', $result['data']['settings']['openai_api_key'] );
	}

	// ─── GetCurrentUserTool ─────────────────────────────────────────

	public function testGetCurrentUserReturnsGuest(): void {
		$auth = $this->createMock( AuthProviderInterface::class );
		$auth->method( 'currentUserId' )->willReturn( 0 );

		$tool   = new GetCurrentUserTool( $this->stubErrors(), $auth );
		$result = $tool->execute();

		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['data']['authenticated'] );
		$this->assertSame( 0, $result['data']['user_id'] );
	}

	public function testGetCurrentUserReturnsAuthenticatedUser(): void {
		$userInfo = new UserInfo(
			id: 5,
			login: 'admin',
			displayName: 'Admin User',
			email: 'admin@example.com',
			roles: array( 'administrator' ),
			capabilities: array( 'manage_options' => true ),
		);

		$auth = $this->createMock( AuthProviderInterface::class );
		$auth->method( 'currentUserId' )->willReturn( 5 );
		$auth->method( 'getUserInfo' )->with( 5 )->willReturn( $userInfo );

		$tool   = new GetCurrentUserTool( $this->stubErrors(), $auth );
		$result = $tool->execute();

		$this->assertTrue( $result['success'] );
		$this->assertSame( 5, $result['data']['id'] );
		$this->assertSame( 'admin', $result['data']['login'] );
		$this->assertSame( 'Admin User', $result['data']['display_name'] );
		$this->assertContains( 'administrator', $result['data']['roles'] );
	}

	public function testGetCurrentUserNotFound(): void {
		$expectedError = array( 'success' => false, 'error' => array( 'code' => 'not_found' ) );

		$errors = $this->createMock( ErrorFactoryInterface::class );
		$errors->method( 'notFound' )->willReturn( $expectedError );

		$auth = $this->createMock( AuthProviderInterface::class );
		$auth->method( 'currentUserId' )->willReturn( 99 );
		$auth->method( 'getUserInfo' )->with( 99 )->willReturn( null );

		$tool   = new GetCurrentUserTool( $errors, $auth );
		$result = $tool->execute();

		$this->assertSame( $expectedError, $result );
	}

	// ─── Helpers ────────────────────────────────────────────────────

	private function stubErrors(): ErrorFactoryInterface {
		return $this->createMock( ErrorFactoryInterface::class );
	}
}
