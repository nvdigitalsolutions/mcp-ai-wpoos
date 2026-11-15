<?php

namespace Tests\Feature;

use App\Mcp\Servers\NvoosServer;
use App\Mcp\Tools\ColorConvertMcpTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_color_convert_tool_returns_structured_content(): void
    {
        $user = User::factory()->create();

        $response = NvoosServer::actingAs($user)->tool(ColorConvertMcpTool::class, [
            'color' => '#ff0000',
            'to_format' => 'hex',
        ]);

        $response->assertOk()
            ->assertSee('#ff0000');
    }
}
