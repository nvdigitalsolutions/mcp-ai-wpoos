<?php
/** Send Group Email (Validated). @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
class SendGroupEmailValidatedTool extends SendGroupEmailTool {
	public function getSlug(): string { return 'send_group_email_validated'; }
	public function getName(): string { return 'Send Group Email (Validated)'; }
}
