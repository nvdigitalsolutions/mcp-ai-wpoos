<?php
declare(strict_types=1);
namespace Nvoos\Core\Tool;

class ColorConvertTool extends AbstractTool {
	public function getSlug(): string { return 'color_convert'; }
	public function getName(): string { return 'Color Convert'; }
	public function getDescription(): string { return 'Converts colors between HEX, RGB, and HSL formats with auto-detection.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('color'=>array('type'=>'string','description'=>'Color: hex (#ff0000, #f00), rgb(255,0,0), or hsl(0,100%,50%)'),'to_format'=>array('type'=>'string','description'=>'Output: hex, rgb, hsl','default'=>'hex','enum'=>array('hex','rgb','hsl'))),'required'=>array('color'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'read'; }
	public function execute(array $arguments=[],array $context=[]): mixed {
		$color = $this->stringParam($arguments,'color'); $to = $this->stringParam($arguments,'to_format','hex');
		if(''===$color) return $this->errors->validationFailed('color required.',array('color'=>array('Color value required.')));
		$rgb = $this->parseToRgb($color); if(null===$rgb) return $this->errors->create('invalid_color',"Cannot parse: $color");
		$out = match($to){'hex'=>$this->rgbToHex($rgb),'rgb'=>"rgb({$rgb[0]},{$rgb[1]},{$rgb[2]})",'hsl'=>$this->rgbToHslStr($rgb),default=>$this->rgbToHex($rgb)};
		return $this->success("$out",array('input'=>$color,'output'=>$out,'format'=>$to,'hex'=>$this->rgbToHex($rgb),'rgb'=>array('r'=>$rgb[0],'g'=>$rgb[1],'b'=>$rgb[2])));
	}
	private function parseToRgb(string $c): ?array { $c=trim($c);
		if(preg_match('/^#?([a-f\d]{3}|[a-f\d]{6})$/i',$c,$m)){ $h=ltrim($m[1],'#'); if(strlen($h)===3)$h=$h[0].$h[0].$h[1].$h[1].$h[2].$h[2]; return array(hexdec(substr($h,0,2)),hexdec(substr($h,2,2)),hexdec(substr($h,4,2))); }
		if(preg_match('/rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)/i',$c,$m)) return array((int)$m[1],(int)$m[2],(int)$m[3]);
		if(preg_match('/hsl\(\s*(\d+)\s*,\s*(\d+)%\s*,\s*(\d+)%\s*\)/i',$c,$m)) return $this->hslToRgb((int)$m[1],(int)$m[2],(int)$m[3]); return null; }
	private function hslToRgb(int $h,int $s,int $l): array { $h/=360;$s/=100;$l/=100; if($s==0) return array((int)round($l*255),(int)round($l*255),(int)round($l*255)); $q=$l<0.5?$l*(1+$s):$l+$s-$l*$s; $p=2*$l-$q; return array((int)round($this->hue($p,$q,$h+1/3)*255),(int)round($this->hue($p,$q,$h)*255),(int)round($this->hue($p,$q,$h-1/3)*255)); }
	private function hue(float $p,float $q,float $t): float { if($t<0)$t+=1;if($t>1)$t-=1; if($t<1/6)return $p+($q-$p)*6*$t;if($t<1/2)return $q;if($t<2/3)return $p+($q-$p)*(2/3-$t)*6;return $p; }
	private function rgbToHex(array $rgb): string { return '#'.sprintf('%02x%02x%02x',$rgb[0],$rgb[1],$rgb[2]); }
	private function rgbToHslStr(array $rgb): string { $h=$this->rgbToHsl($rgb); return "hsl({$h[0]},{$h[1]}%,{$h[2]}%)"; }
	private function rgbToHsl(array $rgb): array { $r=$rgb[0]/255;$g=$rgb[1]/255;$b=$rgb[2]/255;$M=max($r,$g,$b);$m=min($r,$g,$b);$l=($M+$m)/2;if($M==$m){$h=$s=0;}else{$d=$M-$m;$s=$l>0.5?$d/(2-$M-$m):$d/($M+$m);$h=match($M){$r=>($g-$b)/$d+($g<$b?6:0),$g=>($b-$r)/$d+2,$b=>($r-$g)/$d+4,default=>0};$h/=6;} return array((int)round($h*360),(int)round($s*100),(int)round($l*100)); }
}
