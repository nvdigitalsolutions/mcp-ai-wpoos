<?php

namespace Tests\Feature;

use Tests\TestCase;

class ConfigIsolationTest extends TestCase
{
    public function test_test_suite_uses_sqlite_memory(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('sync', config('queue.default'));
        $this->assertSame('sync', config('oos.queue_connection'));
    }
}
