<?php declare(strict_types=1);
namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\QueueClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Domain\Entity\JobStatus;
use Nvoos\Core\Tool\CheckVideoStatusTool;
use Nvoos\Core\Tool\AnalyzeVideoTool;
use Nvoos\Core\Tool\GenerateVideoCaptionTool;
use Nvoos\Core\Tool\GenerateSoraVideoTool;
use Nvoos\Core\Tool\GenerateVeoVideoTool;
use Nvoos\Core\Tool\GenerateOmniVideoTool;
use Nvoos\Core\Tool\EditOmniVideoTool;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

final class BatchTwoBToolsTest extends TestCase {
	private ErrorFactoryInterface $ef;
	private SettingsStoreInterface $s;
	private HttpClientInterface $h;
	private QueueClientInterface $q;

	protected function setUp(): void {
		$this->ef = $this->createMock( ErrorFactoryInterface::class );
		$this->s  = $this->createMock( SettingsStoreInterface::class );
		$this->h  = $this->createMock( HttpClientInterface::class );
		$this->q  = $this->createMock( QueueClientInterface::class );
	}

	public function testCheckVideoStatusSlug(): void {
		$this->assertSame( 'check_video_status', (new CheckVideoStatusTool( $this->ef, $this->q ))->getSlug() );
	}
	public function testAnalyzeVideoSlug(): void {
		$this->assertSame( 'analyze_video', (new AnalyzeVideoTool( $this->ef ))->getSlug() );
	}
	public function testVideoCaptionSlug(): void {
		$this->assertSame( 'generate_video_caption', (new GenerateVideoCaptionTool( $this->ef ))->getSlug() );
	}
	public function testSoraSlug(): void {
		$this->assertSame( 'generate_sora_video', (new GenerateSoraVideoTool( $this->ef, $this->s, $this->h ))->getSlug() );
	}
	public function testVeoSlug(): void {
		$this->assertSame( 'generate_veo_video', (new GenerateVeoVideoTool( $this->ef, $this->s, $this->h ))->getSlug() );
	}
	public function testOmniSlug(): void {
		$this->assertSame( 'generate_omni_video', (new GenerateOmniVideoTool( $this->ef, $this->s, $this->h ))->getSlug() );
	}
	public function testEditOmniSlug(): void {
		$this->assertSame( 'edit_omni_video', (new EditOmniVideoTool( $this->ef, $this->s, $this->h ))->getSlug() );
	}

	public function testSoraSuccess(): void {
		$this->s->method( 'getApiKey' )->willReturn( 'sk-test' );
		$this->h->method( 'send' )->willReturn( new HttpResponse( 200, '{"id":"sora-1","status":"queued"}' ) );
		$r = (new GenerateSoraVideoTool( $this->ef, $this->s, $this->h ))->execute( array('prompt'=>'test'), array() );
		$this->assertTrue( $r['success'] );
	}

	public function testVeoSuccess(): void {
		$this->s->method( 'getApiKey' )->willReturn( 'key' );
		$this->h->method( 'send' )->willReturn( new HttpResponse( 200, '{"name":"veo-1"}' ) );
		$r = (new GenerateVeoVideoTool( $this->ef, $this->s, $this->h ))->execute( array('prompt'=>'test'), array() );
		$this->assertTrue( $r['success'] );
	}

	public function testAnalyzeVideoSuccess(): void {
		$r = (new AnalyzeVideoTool( $this->ef ))->execute( array('video_url'=>'https://x.mp4'), array() );
		$this->assertTrue( $r['success'] );
	}

	public function testCheckVideoStatusSuccess(): void {
		$s = new JobStatus( 'j1', 'completed', queuedAt:new DateTimeImmutable() );
		$this->q->method( 'getStatus' )->willReturn( $s );
		$r = (new CheckVideoStatusTool( $this->ef, $this->q ))->execute( array('job_id'=>'j1'), array() );
		$this->assertTrue( $r['success'] );
		$this->assertSame( 'completed', $r['data']['status'] );
	}
}
