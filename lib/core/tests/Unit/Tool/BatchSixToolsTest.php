<?php
declare(strict_types=1);
namespace Nvoos\Core\Tests\Unit\Tool;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Tool\FormatDateTool;
use Nvoos\Core\Tool\GetPostMetaTool;
use Nvoos\Core\Tool\MergeArraysTool;
use Nvoos\Core\Tool\ParseCsvTool;
use Nvoos\Core\Tool\TimeAgoTool;
use PHPUnit\Framework\TestCase;

final class BatchSixToolsTest extends TestCase {
	private function e(): ErrorFactoryInterface { return $this->createMock(ErrorFactoryInterface::class); }

	public function testGetAllMeta(): void {
		$c = $this->createMock(ContentStoreInterface::class);
		$c->method('getMeta')->with(42)->willReturn(array('_a'=>'v1','_b'=>'v2'));
		$r = (new GetPostMetaTool($this->e(),$c))->execute(array('post_id'=>42));
		$this->assertTrue($r['success']); $this->assertSame(2,$r['data']['count']);
	}
	public function testGetSingleMeta(): void {
		$c = $this->createMock(ContentStoreInterface::class);
		$c->method('getMeta')->willReturn(array('_x'=>'blue'));
		$r = (new GetPostMetaTool($this->e(),$c))->execute(array('post_id'=>1,'key'=>'_x'));
		$this->assertSame('blue',$r['data']['value']);
	}
	public function testFormatDate(): void {
		$r = (new FormatDateTool($this->e()))->execute(array('date'=>'2026-01-15T10:30:00Z','to_format'=>'Y-m-d'));
		$this->assertSame('2026-01-15',$r['data']['output']);
	}
	public function testTimeAgo(): void {
		$r = (new TimeAgoTool($this->e()))->execute(array('date'=>(string)time()));
		$this->assertSame('just now',$r['data']['expression']);
	}
	public function testMergeShallow(): void {
		$r = (new MergeArraysTool($this->e()))->execute(array('arrays'=>array(array('a'=>1),array('b'=>2)),'strategy'=>'shallow'));
		$this->assertSame(2,$r['data']['result']['b']);
	}
	public function testMergeDeep(): void {
		$r = (new MergeArraysTool($this->e()))->execute(array('arrays'=>array(array('a'=>array('x'=>1)),array('a'=>array('y'=>2)))));
		$this->assertSame(2,$r['data']['result']['a']['y']);
	}
	public function testParseCsv(): void {
		$r = (new ParseCsvTool($this->e()))->execute(array('csv'=>"name,age\nAlice,30\nBob,25"));
		$this->assertSame(2,$r['data']['count']); $this->assertSame('Alice',$r['data']['rows'][0]['name']);
	}
}
