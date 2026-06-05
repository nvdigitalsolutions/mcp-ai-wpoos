<?php
declare(strict_types=1);
namespace Nvoos\Core\Tool;

class MathEvalTool extends AbstractTool {
	public function getSlug(): string { return 'math_eval'; }
	public function getName(): string { return 'Evaluate Math'; }
	public function getDescription(): string { return 'Safely evaluates arithmetic expressions (+, -, *, /, %, **, parentheses).'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('expression'=>array('type'=>'string','description'=>'Math expression (e.g., 2+3*4)')),'required'=>array('expression'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'read'; }
	public function execute(array $arguments=[],array $context=[]): mixed {
		$expr = $this->stringParam($arguments,'expression'); if(''===$expr) return $this->errors->validationFailed('expression required.',array('expression'=>array('Required.')));
		$clean = preg_replace('/[^0-9+\-*\/%.()]/','',str_replace(' ','',$expr));
		if(''===$clean) return $this->errors->create('invalid_expression','No valid math characters.');
		try { $r = $this->evalRec($clean); } catch(\Throwable $e){ return $this->errors->create('eval_error',$e->getMessage()); }
		return $this->success("$r",array('expression'=>$expr,'result'=>$r));
	}
	private function evalRec(string $e): float|int {
		while(preg_match('/\(([^()]+)\)/',$e,$m)){ $v=$this->evalRec($m[1]); $e=str_replace($m[0],(string)$v,$e); }
		if(preg_match('/([\d.]+)\*\*([\d.]+)/',$e,$m)) return pow((float)$m[1],(float)$m[2]);
		$tokens=array();$num='';for($i=0;$i<strlen($e);$i++){$c=$e[$i];if(ctype_digit($c)||'.'===$c){$num.=$c;}elseif('-'===$c&&(''===$num||preg_match('/[+\-*\/%]$/',implode('',$tokens)))){$num.=$c;}else{if(''!==$num){$tokens[]=$num;$num='';}$tokens[]=$c;}}if(''!==$num)$tokens[]=$num;
		$i=1;while($i<count($tokens)-1){if(in_array($tokens[$i],array('*','/','%'))){$a=(float)$tokens[$i-1];$b=(float)$tokens[$i+1];if('/'===$tokens[$i]&&0.0===$b)throw new \RuntimeException('Division by zero');$r='/'===$tokens[$i]?$a/$b:('*'===$tokens[$i]?$a*$b:$a%$b);array_splice($tokens,$i-1,3,(string)$r);}else $i++;}
		$i=1;while($i<count($tokens)-1){if(in_array($tokens[$i],array('+','-'))){$a=(float)$tokens[$i-1];$b=(float)$tokens[$i+1];$r='+'===$tokens[$i]?$a+$b:$a-$b;array_splice($tokens,$i-1,3,(string)$r);}else $i++;}
		$r=(float)$tokens[0];return $r==(int)$r?(int)$r:$r;
	}
}
