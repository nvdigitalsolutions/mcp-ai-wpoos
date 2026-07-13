<?php
/**
 * Tests for UpdateContentCommand value object.
 *
 * @package Nvoos\Core\Tests
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Entity;

use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use PHPUnit\Framework\TestCase;

final class UpdateContentCommandTest extends TestCase {

	public function testFullUpdate(): void {
		$cmd = new UpdateContentCommand(
			userId: 5,
			title: 'Updated Title',
			content: 'Updated content.',
			status: 'publish',
			excerpt: 'Updated excerpt.',
			meta: array( 'views' => 100 ),
			taxonomyInput: array( 'category' => array( 'Updated' ) ),
		);

		$this->assertSame( 5, $cmd->userId );
		$this->assertSame( 'Updated Title', $cmd->title );
		$this->assertSame( 'Updated content.', $cmd->content );
		$this->assertSame( 'publish', $cmd->status );
		$this->assertSame( 'Updated excerpt.', $cmd->excerpt );
		$this->assertSame( array( 'views' => 100 ), $cmd->meta );
		$this->assertSame(
			array( 'category' => array( 'Updated' ) ),
			$cmd->taxonomyInput,
		);
	}

	public function testPartialUpdateOnlyTitle(): void {
		$cmd = new UpdateContentCommand(
			userId: 3,
			title: 'New Title Only',
		);

		$this->assertSame( 3, $cmd->userId );
		$this->assertSame( 'New Title Only', $cmd->title );
		$this->assertNull( $cmd->content );
		$this->assertNull( $cmd->status );
		$this->assertNull( $cmd->excerpt );
		$this->assertSame( array(), $cmd->meta );
		$this->assertSame( array(), $cmd->taxonomyInput );
	}

	public function testPartialUpdateOnlyStatus(): void {
		$cmd = new UpdateContentCommand(
			userId: 1,
			status: 'draft',
		);

		$this->assertSame( 'draft', $cmd->status );
		$this->assertNull( $cmd->title );
		$this->assertNull( $cmd->content );
	}

	public function testMetaMerge(): void {
		// Meta fields should be merged, not replaced.
		// This is a documentation test — the entity itself is a data carrier;
		// merge logic lives in the ContentStore adapter.
		$cmd = new UpdateContentCommand(
			userId: 1,
			meta: array( 'new_field' => 'new_value' ),
		);

		$this->assertSame( array( 'new_field' => 'new_value' ), $cmd->meta );
	}

	public function testEmptyUpdateDoesNothing(): void {
		$cmd = new UpdateContentCommand( userId: 1 );

		$this->assertSame( 1, $cmd->userId );
		$this->assertNull( $cmd->title );
		$this->assertNull( $cmd->content );
		$this->assertNull( $cmd->status );
		$this->assertNull( $cmd->excerpt );
		$this->assertSame( array(), $cmd->meta );
		$this->assertSame( array(), $cmd->taxonomyInput );
	}
}
