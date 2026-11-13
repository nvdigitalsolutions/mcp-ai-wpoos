<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Nvoos\Core\Domain\Contract\PaperStoreInterface;

abstract class TestCase extends BaseTestCase
{
    /**
     * Per-test Paper Store root, removed in tearDown().
     */
    private string $paperStoreRoot = '';

    protected function setUp(): void
    {
        parent::setUp();

        // The Paper Store is file-backed, so it does not participate in the
        // database transaction RefreshDatabase wraps around a test. Give every
        // test its own directory, or records leak between tests and between
        // runs and `total` counts drift.
        $this->paperStoreRoot = sys_get_temp_dir().'/nvoos-paper-'.bin2hex(random_bytes(8));

        $this->app['config']->set('oos.paper_store_root', $this->paperStoreRoot);
        $this->app->forgetInstance(PaperStoreInterface::class);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->paperStoreRoot);

        parent::tearDown();
    }

    private function removeDirectory(string $path): void
    {
        if ($path === '' || ! is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.'/'.$entry;

            if (is_dir($child)) {
                $this->removeDirectory($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }
}
