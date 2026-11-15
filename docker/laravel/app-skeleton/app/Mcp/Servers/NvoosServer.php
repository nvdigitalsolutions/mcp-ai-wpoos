<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\ColorConvertMcpTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('NV oOS Laravel Spike')]
#[Version('1.0.0')]
#[Instructions('NV oOS core tools exposed over the Model Context Protocol.')]
class NvoosServer extends Server
{
    /**
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        ColorConvertMcpTool::class,
    ];
}
