<?php
/**
 * Tests for SessionLog — append-only event sourcing and the
 * deriveMessages() history projection.
 *
 * @package Nvoos\Core\Tests
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Session;

use Nvoos\Core\Application\Session\SessionLog;
use PHPUnit\Framework\TestCase;

final class SessionLogTest extends TestCase {

	public function testAppendAssignsMonotonicSeq(): void {
		$log = new SessionLog();

		$first  = $log->append( SessionLog::TYPE_TURN_STARTED, array() );
		$second = $log->append( SessionLog::TYPE_USER_MESSAGE, array( 'content' => 'hi' ) );

		$this->assertSame( 1, $first );
		$this->assertSame( 2, $second );
		$this->assertSame( 2, $log->count() );
		$this->assertSame( 2, $log->lastEvent()?->seq );
	}

	public function testDeriveMessagesProjectsModelVisibleFactsOnly(): void {
		$log = new SessionLog();

		$log->append( SessionLog::TYPE_TURN_STARTED, array() );
		$log->append( SessionLog::TYPE_USER_MESSAGE, array( 'content' => 'Say hello.' ) );
		$log->append(
			SessionLog::TYPE_ASSISTANT_MESSAGE,
			array(
				'content'    => null,
				'tool_calls' => array(
					array( 'id' => 'call_1', 'function' => array( 'name' => 'echo_tool', 'arguments' => '{}' ) ),
				),
			)
		);
		$log->append( SessionLog::TYPE_TOOL_CALL, array( 'tool_call_id' => 'call_1', 'name' => 'echo_tool', 'arguments' => array() ) );
		$log->append( SessionLog::TYPE_TOOL_RESULT, array( 'tool_call_id' => 'call_1', 'name' => 'echo_tool', 'content' => '{"success":true}' ) );
		$log->append( SessionLog::TYPE_ASSISTANT_MESSAGE, array( 'content' => 'Done.' ) );
		$log->append( SessionLog::TYPE_TURN_ENDED, array( 'reason' => 'completed' ) );

		$messages = $log->deriveMessages();

		$this->assertCount( 4, $messages, 'Bookkeeping entries must not produce messages.' );
		$this->assertSame( 'user', $messages[0]['role'] );
		$this->assertSame( 'Say hello.', $messages[0]['content'] );
		$this->assertSame( 'assistant', $messages[1]['role'] );
		$this->assertSame( 'call_1', $messages[1]['tool_calls'][0]['id'] );
		$this->assertSame( 'tool', $messages[2]['role'] );
		$this->assertSame( 'call_1', $messages[2]['tool_call_id'] );
		$this->assertSame( 'echo_tool', $messages[2]['name'] );
		$this->assertSame( 'Done.', $messages[3]['content'] );
	}

	public function testDeriveMessagesPrependsSystemPrompt(): void {
		$log = new SessionLog();
		$log->append( SessionLog::TYPE_USER_MESSAGE, array( 'content' => 'hi' ) );

		$messages = $log->deriveMessages( array( 'You are helpful.' ) );

		$this->assertCount( 2, $messages );
		$this->assertSame( 'system', $messages[0]['role'] );
		$this->assertSame( 'You are helpful.', $messages[0]['content'] );
	}

	public function testExportAndFromExportedRoundtrip(): void {
		$log = new SessionLog();
		$log->append( SessionLog::TYPE_TURN_STARTED, array( 'assistant_id' => 7 ) );
		$log->append( SessionLog::TYPE_USER_MESSAGE, array( 'content' => 'hello' ) );

		$rebuilt = SessionLog::fromExported( $log->export() );

		$this->assertSame( 2, $rebuilt->count() );
		$this->assertSame(
			$log->deriveMessages(),
			$rebuilt->deriveMessages(),
		);
	}

	public function testFromExportedIgnoresMalformedRows(): void {
		$rebuilt = SessionLog::fromExported(
			array(
				'not-an-array',
				array( 'seq' => 3, 'time' => 1.0, 'type' => 'user_message', 'data' => array( 'content' => 'x' ) ),
			)
		);

		$this->assertSame( 1, $rebuilt->count() );
		$this->assertSame( 3, $rebuilt->lastEvent()?->seq );
	}

	public function testSteeringMessagesAreModelVisible(): void {
		$log = new SessionLog();
		$log->append( SessionLog::TYPE_USER_MESSAGE, array( 'content' => 'first' ) );
		$log->append( SessionLog::TYPE_STEERING_MESSAGE, array( 'content' => 'stop and reconsider' ) );

		$messages = $log->deriveMessages();

		$this->assertCount( 2, $messages );
		$this->assertSame( 'stop and reconsider', $messages[1]['content'] );
	}
}
