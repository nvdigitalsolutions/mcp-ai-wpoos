<?php
/**
 * Tests for CreateContentCommand value object.
 *
 * @package Nvoos\Core\Tests
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Entity;

use Nvoos\Core\Domain\Entity\CreateContentCommand;
use PHPUnit\Framework\TestCase;

final class CreateContentCommandTest extends TestCase {

	public function testFullCommand(): void {
		$cmd = new CreateContentCommand(
			title: 'New Post',
			type: 'post',
			authorId: 5,
			status: 'draft',
			content: 'Post body content here.',
			excerpt: 'Short summary.',
			meta: array( '_custom_field' => 'value' ),
			taxonomyInput: array( 'category' => array( 'News', 'Tech' ) ),
		);

		$this->assertSame( 'New Post', $cmd->title );
		$this->assertSame( 'post', $cmd->type );
		$this->assertSame( 5, $cmd->authorId );
		$this->assertSame( 'draft', $cmd->status );
		$this->assertSame( 'Post body content here.', $cmd->content );
		$this->assertSame( 'Short summary.', $cmd->excerpt );
		$this->assertSame( array( '_custom_field' => 'value' ), $cmd->meta );
		$this->assertSame(
			array( 'category' => array( 'News', 'Tech' ) ),
			$cmd->taxonomyInput,
		);
	}

	public function testMinimalCommandUsesDefaults(): void {
		$cmd = new CreateContentCommand(
			title: 'Minimal Post',
			type: 'page',
			authorId: 1,
		);

		$this->assertSame( 'Minimal Post', $cmd->title );
		$this->assertSame( 'page', $cmd->type );
		$this->assertSame( 1, $cmd->authorId );
		$this->assertSame( 'publish', $cmd->status ); // default
		$this->assertSame( '', $cmd->content ); // default
		$this->assertNull( $cmd->excerpt ); // default
		$this->assertSame( array(), $cmd->meta ); // default
		$this->assertSame( array(), $cmd->taxonomyInput ); // default
	}

	public function testCustomPostType(): void {
		$cmd = new CreateContentCommand(
			title: 'Assistant',
			type: 'mcp_ai_assistant',
			authorId: 1,
		);

		$this->assertSame( 'mcp_ai_assistant', $cmd->type );
	}

	public function testPrivateStatus(): void {
		$cmd = new CreateContentCommand(
			title: 'Private Note',
			type: 'post',
			authorId: 1,
			status: 'private',
		);

		$this->assertSame( 'private', $cmd->status );
	}

	public function testPendingStatus(): void {
		$cmd = new CreateContentCommand(
			title: 'Pending Review',
			type: 'post',
			authorId: 1,
			status: 'pending',
		);

		$this->assertSame( 'pending', $cmd->status );
	}

	public function testMultipleTaxonomies(): void {
		$cmd = new CreateContentCommand(
			title: 'Tagged Post',
			type: 'post',
			authorId: 1,
			taxonomyInput: array(
				'category' => array( 'News' ),
				'post_tag' => array( 'php', 'wordpress' ),
			),
		);

		$this->assertSame(
			array( 'php', 'wordpress' ),
			$cmd->taxonomyInput['post_tag'],
		);
		$this->assertCount( 2, $cmd->taxonomyInput );
	}
}
