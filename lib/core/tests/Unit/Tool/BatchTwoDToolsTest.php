<?php declare(strict_types=1);
namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Tool\GenerateOpenAIImageTool;
use Nvoos\Core\Tool\GenerateGeminiImageTool;
use Nvoos\Core\Tool\EditOpenAIImageTool;
use Nvoos\Core\Tool\AnalyzeImageTool;
use Nvoos\Core\Tool\GenerateImageAltTextTool;
use Nvoos\Core\Tool\GenerateImageCaptionTool;
use PHPUnit\Framework\TestCase;

final class BatchTwoDToolsTest extends TestCase {
	private ErrorFactoryInterface $ef;
	private SettingsStoreInterface $s;
	private HttpClientInterface $h;

	protected function setUp(): void {
		$this->ef = $this->createMock( ErrorFactoryInterface::class );
		$this->s  = $this->createMock( SettingsStoreInterface::class );
		$this->h  = $this->createMock( HttpClientInterface::class );
	}

	public function testDalleSlug(): void { $this->assertSame('generate_openai_image', (new GenerateOpenAIImageTool($this->ef,$this->s,$this->h))->getSlug()); }
	public function testImagenSlug(): void { $this->assertSame('generate_gemini_image', (new GenerateGeminiImageTool($this->ef,$this->s,$this->h))->getSlug()); }
	public function testEditDalleSlug(): void { $this->assertSame('edit_openai_image', (new EditOpenAIImageTool($this->ef,$this->s,$this->h))->getSlug()); }
	public function testAnalyzeImageSlug(): void { $this->assertSame('analyze_image', (new AnalyzeImageTool($this->ef))->getSlug()); }
	public function testAltTextSlug(): void { $this->assertSame('generate_image_alt_text', (new GenerateImageAltTextTool($this->ef))->getSlug()); }
	public function testImageCaptionSlug(): void { $this->assertSame('generate_image_caption', (new GenerateImageCaptionTool($this->ef))->getSlug()); }

	public function testDalleSuccess(): void {
		$this->s->method('getApiKey')->willReturn('sk-test');
		$this->h->method('send')->willReturn(new HttpResponse(200, '{"data":[{"b64_json":"abc","revised_prompt":"A cat"}]}'));
		$r = (new GenerateOpenAIImageTool($this->ef,$this->s,$this->h))->execute(array('prompt'=>'a cat'),array());
		$this->assertTrue($r['success']);
		$this->assertCount(1, $r['data']['images']);
	}

	public function testImagenSuccess(): void {
		$this->s->method('getApiKey')->willReturn('key');
		$this->h->method('send')->willReturn(new HttpResponse(200, '{"predictions":[{"bytesBase64Encoded":"abc"}]}'));
		$r = (new GenerateGeminiImageTool($this->ef,$this->s,$this->h))->execute(array('prompt'=>'test'),array());
		$this->assertTrue($r['success']);
	}

	public function testAnalyzeImageSuccess(): void {
		$r = (new AnalyzeImageTool($this->ef))->execute(array('image_url'=>'https://x.jpg'),array());
		$this->assertTrue($r['success']);
	}

	public function testAltTextSuccess(): void {
		$r = (new GenerateImageAltTextTool($this->ef))->execute(array('image_url'=>'https://x.jpg'),array());
		$this->assertTrue($r['success']);
	}
}
