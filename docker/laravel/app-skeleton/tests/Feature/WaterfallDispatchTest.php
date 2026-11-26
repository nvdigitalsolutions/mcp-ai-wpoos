<?php

namespace Tests\Feature;

use Nvoos\Core\Domain\Contract\EventDispatcherInterface;
use Nvoos\Core\Domain\Contract\WaterfallEventDispatcherInterface;
use Tests\TestCase;

/**
 * Waterfall/serial dispatch is a capability, not a base contract.
 *
 * ChatOrchestrator and ToolRegistry gate every waterfall hook on
 * `$events instanceof WaterfallEventDispatcherInterface`, so a dispatcher that
 * declares only the base interface does not fail — it silently degrades to
 * emit-only. The WordPress adapter implemented the capability; the Laravel one
 * did not, which meant every waterfall filter was skipped here and nowhere
 * reported it.
 *
 * These tests pin the capability on the bound dispatcher and pin the chain
 * semantics to the WordPress implementation: a listener that returns without
 * calling `$next()` short-circuits, a listener that delegates reaches the
 * fallback, and higher priorities run first.
 */
class WaterfallDispatchTest extends TestCase
{
    private function dispatcher(): WaterfallEventDispatcherInterface
    {
        $dispatcher = $this->app->make(EventDispatcherInterface::class);

        $this->assertInstanceOf(WaterfallEventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    public function test_the_bound_dispatcher_advertises_the_waterfall_capability(): void
    {
        $this->assertTrue(
            $this->app->make(EventDispatcherInterface::class) instanceof WaterfallEventDispatcherInterface
        );
    }

    public function test_a_listener_that_delegates_reaches_the_fallback(): void
    {
        $dispatcher = $this->dispatcher();
        $seen = [];

        $dispatcher->listenWaterfall('test.delegate', function (object $event, callable $next) use (&$seen) {
            $seen[] = $event->id;

            return $next($event);
        });

        $result = $dispatcher->waterfall(
            'test.delegate',
            (object) ['id' => 7],
            static fn (object $event): string => 'fallback:' . $event->id
        );

        $this->assertSame([7], $seen);
        $this->assertSame('fallback:7', $result);
    }

    public function test_a_listener_that_returns_short_circuits_the_chain(): void
    {
        $dispatcher = $this->dispatcher();
        $fallbackRan = false;

        $dispatcher->listenWaterfall('test.short', static fn (object $event, callable $next): string => 'blocked');
        $dispatcher->listenWaterfall('test.short', static function (object $event, callable $next): string {
            return $next($event);
        }, 5);

        $result = $dispatcher->waterfall(
            'test.short',
            (object) [],
            static function (object $event) use (&$fallbackRan): string {
                $fallbackRan = true;

                return 'fallback';
            }
        );

        $this->assertSame('blocked', $result);
        $this->assertFalse($fallbackRan);
    }

    public function test_higher_priority_runs_first(): void
    {
        $dispatcher = $this->dispatcher();
        $order = [];

        $dispatcher->listenWaterfall('test.order', function (object $event, callable $next) use (&$order) {
            $order[] = 'low';

            return $next($event);
        }, 10);

        $dispatcher->listenWaterfall('test.order', function (object $event, callable $next) use (&$order) {
            $order[] = 'high';

            return $next($event);
        }, 20);

        $dispatcher->waterfall('test.order', (object) [], static fn (object $event): string => 'done');

        $this->assertSame(['high', 'low'], $order);
    }

    public function test_a_serial_dispatch_runs_every_listener_in_order(): void
    {
        $dispatcher = $this->dispatcher();
        $order = [];

        $dispatcher->listenSerial('test.serial', static function (object $event) use (&$order): void {
            $order[] = 'first';
        }, 20);

        $dispatcher->listenSerial('test.serial', static function (object $event) use (&$order): void {
            $order[] = 'second';
        }, 10);

        $dispatcher->serial('test.serial', (object) []);

        $this->assertSame(['first', 'second'], $order);
    }

    public function test_removing_a_waterfall_listener_stops_it_running(): void
    {
        $dispatcher = $this->dispatcher();

        $listener = static fn (object $event, callable $next): string => 'blocked';

        $dispatcher->listenWaterfall('test.remove', $listener);

        $this->assertSame(
            'blocked',
            $dispatcher->waterfall('test.remove', (object) [], static fn (object $event): string => 'fallback')
        );

        $this->assertTrue($dispatcher->removeListener('test.remove', $listener));
        $this->assertSame(
            'fallback',
            $dispatcher->waterfall('test.remove', (object) [], static fn (object $event): string => 'fallback')
        );
    }
}
