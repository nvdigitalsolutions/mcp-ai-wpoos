<?php
declare(strict_types=1);
namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Tool\GenerateOpenAISpeechTool;
use Nvoos\Core\Tool\GenerateOpenAISpeechValidatedTool;
use Nvoos\Core\Tool\GenerateMusicTool;
use Nvoos\Core\Tool\GenerateMusicValidatedTool;
use Nvoos\Core\Tool\TranscribeOpenAIAudioTool;
use Nvoos\Core\Tool\TranscribeOpenAIAudioValidatedTool;
use PHPUnit\Framework\TestCase;

final class BatchTwoAToolsTest extends TestCase {

	private SettingsStoreInterface $settings;
	private HttpClientInterface $http;
	private ErrorFactoryInterface $errorFactory;

	protected function setUp(): void {
		$this->settings     = $this->createMock( SettingsStoreInterface::class );
		$this->http         = $this->createMock( HttpClientInterface::class );
		$this->errorFactory = $this->createMock( ErrorFactoryInterface::class );
	}

	// ═══════════════════════════════════════════════════════════════════
	// GenerateOpenAISpeechTool
	// ═══════════════════════════════════════════════════════════════════

	public function testSpeechSlug(): void {
		$t = new GenerateOpenAISpeechTool( $this->errorFactory, $this->settings, $this->http );
		$this->assertSame( 'generate_openai_speech', $t->getSlug() );
	}

	public function testSpeechSuccess(): void {
		$this->settings->method( 'getApiKey' )->willReturn( 'sk-test' );
		$this->http->method( 'send' )->willReturn( new HttpResponse( 200, 'fake-audio-bytes' ) );

		$t   = new GenerateOpenAISpeechTool( $this->errorFactory, $this->settings, $this->http );
		$r   = $t->execute( array( 'text' => 'Hello world' ), array() );
		$this->assertTrue( $r['success'] );
		$this->assertNotEmpty( $r['data']['audio_base64'] );
	}

	public function testSpeechMissingText(): void {
		$this->errorFactory->method( 'validationFailed' )
			->willReturn( array( 'success' => false, 'error' => array( 'code' => 'x', 'message' => 'x' ) ) );
		$t = new GenerateOpenAISpeechTool( $this->errorFactory, $this->settings, $this->http );
		$this->assertFalse( $t->execute( array(), array() )['success'] );
	}

	// ═══════════════════════════════════════════════════════════════════
	// Validated variants
	// ═══════════════════════════════════════════════════════════════════

	public function testSpeechValidatedSlug(): void {
		$t = new GenerateOpenAISpeechValidatedTool( $this->errorFactory, $this->settings, $this->http );
		$this->assertSame( 'generate_openai_speech_validated', $t->getSlug() );
	}

	public function testMusicSlug(): void {
		$t = new GenerateMusicTool( $this->errorFactory, $this->settings, $this->http );
		$this->assertSame( 'generate_music', $t->getSlug() );
	}

	public function testMusicValidatedSlug(): void {
		$t = new GenerateMusicValidatedTool( $this->errorFactory, $this->settings, $this->http );
		$this->assertSame( 'generate_music_validated', $t->getSlug() );
	}

	public function testTranscribeSlug(): void {
		$t = new TranscribeOpenAIAudioTool( $this->errorFactory, $this->settings, $this->http );
		$this->assertSame( 'transcribe_openai_audio', $t->getSlug() );
	}

	public function testTranscribeValidatedSlug(): void {
		$t = new TranscribeOpenAIAudioValidatedTool( $this->errorFactory, $this->settings, $this->http );
		$this->assertSame( 'transcribe_openai_audio_validated', $t->getSlug() );
	}

	public function testTranscribeSuccess(): void {
		$this->settings->method( 'getApiKey' )->willReturn( 'sk-test' );
		$this->http->method( 'send' )->willReturn( new HttpResponse( 200, '{"text":"Hello world"}' ) );

		$t = new TranscribeOpenAIAudioTool( $this->errorFactory, $this->settings, $this->http );
		$r = $t->execute( array( 'audio_url' => 'https://example.com/audio.mp3' ), array() );
		$this->assertTrue( $r['success'] );
	}

	public function testTranscribeMissingSource(): void {
		$this->settings->method( 'getApiKey' )->willReturn( 'sk-test' );
		$this->errorFactory->method( 'validationFailed' )
			->willReturn( array( 'success' => false, 'error' => array( 'code' => 'x', 'message' => 'x' ) ) );
		$t = new TranscribeOpenAIAudioTool( $this->errorFactory, $this->settings, $this->http );
		$this->assertFalse( $t->execute( array(), array() )['success'] );
	}
}
