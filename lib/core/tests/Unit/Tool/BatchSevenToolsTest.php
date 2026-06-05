<?php
declare(strict_types=1);
namespace Nvoos\Core\Tests\Unit\Tool;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentCollection;
use Nvoos\Core\Tool\ColorConvertTool;
use Nvoos\Core\Tool\CountPostsTool;
use Nvoos\Core\Tool\GetPostTaxonomiesTool;
use Nvoos\Core\Tool\MathEvalTool;
use Nvoos\Core\Tool\TruncateTextTool;
use PHPUnit\Framework\TestCase;

final class BatchSevenToolsTest extends TestCase {
	private function e(): ErrorFactoryInterface { return $this->createMock(ErrorFactoryInterface::class); }
	public function testGetTaxonomies(): void {
		$c=$this->createMock(ContentStoreInterface::class);
		$c->method('getTaxonomyTerms')->with(1)->willReturn(array('cat'=>array('News','Tech'),'tag'=>array('ai')));
		$r=(new GetPostTaxonomiesTool($this->e(),$c))->execute(array('post_id'=>1));
		$this->assertTrue($r['success']); $this->assertSame(3,$r['data']['total_terms']);
	}
	public function testCountPosts(): void {
		$c=$this->createMock(ContentStoreInterface::class);
		$c->method('query')->willReturn(new ContentCollection(items:array(),total:42,page:1,perPage:1,totalPages:42));
		$r=(new CountPostsTool($this->e(),$c))->execute(array('type'=>'post'));
		$this->assertSame(42,$r['data']['total']);
	}
	public function testTruncate(): void {
		$r=(new TruncateTextTool($this->e()))->execute(array('text'=>'Hello world test','max_length'=>11));
		$this->assertTrue($r['data']['truncated']);
	}
	public function testTruncateNone(): void {
		$r=(new TruncateTextTool($this->e()))->execute(array('text'=>'Hi','max_length'=>100));
		$this->assertFalse($r['data']['truncated']);
	}
	public function testMath(): void {
		$this->assertSame(14,(new MathEvalTool($this->e()))->execute(array('expression'=>'2+3*4'))['data']['result']);
	}
	public function testMathParens(): void {
		$this->assertSame(2.5,(new MathEvalTool($this->e()))->execute(array('expression'=>'(10-5)/2'))['data']['result']);
	}
	public function testColorHexToRgb(): void {
		$r=(new ColorConvertTool($this->e()))->execute(array('color'=>'#ff0000','to_format'=>'rgb'));
		$this->assertStringContainsString('255,0,0',$r['data']['output']);
	}
	public function testColorRgbToHex(): void {
		$r=(new ColorConvertTool($this->e()))->execute(array('color'=>'rgb(0,255,0)','to_format'=>'hex'));
		$this->assertSame('#00ff00',$r['data']['output']);
	}
}
