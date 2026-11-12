<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_executes_color_convert(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/dev/tools/color_convert', [
            'color' => '#ff0000',
            'to_format' => 'rgb',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'output' => 'rgb(255,0,0)',
                ],
            ]);
    }

    public function test_tool_endpoint_requires_authentication(): void
    {
        $this->postJson('/dev/tools/color_convert', ['color' => '#ff0000'])
            ->assertUnauthorized();
    }
}
