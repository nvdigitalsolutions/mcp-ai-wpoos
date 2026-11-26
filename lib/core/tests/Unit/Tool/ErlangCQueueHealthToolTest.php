<?php

/**
 * Tests for the portable queue-health tool.
 *
 * §159: the port inherited the WordPress tool's `write`/`state-changing` flags
 * and was therefore refused by the necessity gate as an outbound mutation. Two
 * facts had to be pinned: the port persists nothing, and an empty call is a
 * validation error — which is also why the schema now says so with `anyOf`
 * instead of leaving `required` empty.
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErlangCInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;
use Nvoos\Core\Tool\ErlangCQueueHealthTool;
use PHPUnit\Framework\TestCase;

final class ErlangCQueueHealthToolTest extends TestCase
{
    public function test_the_port_is_a_read_and_persists_nothing(): void
    {
        $flags = $this->tool()->getCapabilityFlags();

        $this->assertTrue(CapabilityFlagVocabulary::has($flags, 'read-only'));
        $this->assertTrue(CapabilityFlagVocabulary::has($flags, 'external-api'));
        $this->assertFalse(CapabilityFlagVocabulary::has($flags, 'write'));
        $this->assertFalse(CapabilityFlagVocabulary::has($flags, 'state-changing'));
        $this->assertFalse(
            CapabilityFlagVocabulary::hasOutboundMutation($flags),
            'The endpoint fetch is a GET; nothing is persisted.'
        );
    }

    public function test_the_schema_declares_the_two_complete_shapes(): void
    {
        $schema = $this->tool()->getParametersSchema();

        $this->assertArrayNotHasKey(
            'required',
            $schema,
            'Neither shape is universally required; that belongs in anyOf.'
        );
        $this->assertCount(2, $schema['anyOf']);

        $this->assertSame(
            array('arrival_rate_per_hour', 'avg_handle_time', 'current_agents'),
            $schema['anyOf'][1]['required']
        );
        $this->assertSame(array('const' => true), $schema['anyOf'][0]['properties']['fetch_from_endpoint']);
    }

    public function test_an_empty_call_is_a_validation_error(): void
    {
        $errors = $this->createMock(ErrorFactoryInterface::class);
        $errors->expects($this->once())
            ->method('validationFailed')
            ->willReturn('validation_error');

        $tool = new ErlangCQueueHealthTool(
            $errors,
            $this->createMock(ErlangCInterface::class),
            $this->createMock(SettingsStoreInterface::class),
            $this->createMock(HttpClientInterface::class),
        );

        $this->assertSame(
            'validation_error',
            $tool->execute(array(), array('user_id' => 1))
        );
    }

    private function tool(): ErlangCQueueHealthTool
    {
        return new ErlangCQueueHealthTool(
            $this->createMock(ErrorFactoryInterface::class),
            $this->createMock(ErlangCInterface::class),
            $this->createMock(SettingsStoreInterface::class),
            $this->createMock(HttpClientInterface::class),
        );
    }
}
