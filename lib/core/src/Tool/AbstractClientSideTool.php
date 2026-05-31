<?php
/** Client-side AI tools base — server-side is parameter validation only.
 * @package Oos\Core @since 1.0.0 @license MIT */
declare(strict_types=1);
namespace Oos\Core\Tool;
abstract class AbstractClientSideTool extends AbstractTool
{
    public function getRequiredCapability(): string { return 'read'; }
    protected function validateText(array $arguments): string|null {
        $text = $this->stringParam($arguments,'text');
        return ''===$text ? 'text' : null;
    }
}
