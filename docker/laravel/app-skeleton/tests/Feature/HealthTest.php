<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_reports_core_bindings_and_no_wordpress(): void
    {
        $response = $this->getJson('/health');

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'abspath' => false,
            ]);

        $this->assertContains('color_convert', $response->json('tools'));
        $this->assertContains('openai', $response->json('providers'));
        $this->assertContains('spike', $response->json('providers'));
        $this->assertContains('SseHandler', $response->json('bindings'));
    }
}
