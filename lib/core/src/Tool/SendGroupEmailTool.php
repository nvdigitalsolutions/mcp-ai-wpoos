<?php
/** Send Group Email. @package Nvoos\Core @since 2.0.0 @license MIT */
declare(strict_types=1); namespace Nvoos\Core\Tool;
use Nvoos\Core\Domain\Contract\EmailServiceInterface; use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
class SendGroupEmailTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly EmailServiceInterface $mail ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'send_group_email'; } public function getName(): string { return 'Send Group Email'; } public function getDescription(): string { return 'Sends an email to a group of recipients.'; }
	public function getParametersSchema(): array { return array('type'=>'object','properties'=>array('to'=>array('type'=>'array','description'=>'Recipient email addresses.','items'=>array('type'=>'string','format'=>'email')), 'subject'=>array('type'=>'string','description'=>'Email subject.'), 'body'=>array('type'=>'string','description'=>'Email body (HTML or plain text).'), 'is_html'=>array('type'=>'boolean','description'=>'Whether body is HTML.','default'=>true)),'required'=>array('to','subject','body'),'additionalProperties'=>false); }
	public function getRequiredCapability(): string { return 'manage_options'; }
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$to = $this->arrayParam($arguments,'to'); $subject = $this->stringParam($arguments,'subject'); $body = $this->stringParam($arguments,'body');
		if (array()===$to||''===$subject||''===$body) return $this->errors->validationFailed('to, subject, and body required.',array('to'=>array('Required.'),'subject'=>array('Required.'),'body'=>array('Required.')));
		if (!$this->mail->isAvailable()) return $this->errors->create('mail_unavailable','Email service not available.');
		$sent = 0; $failed = array();
		foreach ($to as $email) {
			if (!\is_string($email)||!$this->mail->validateRecipient($email)) { $failed[]=$email; continue; }
			try { $this->mail->send(array('to'=>$email,'subject'=>$subject,'body'=>$body,'is_html'=>!empty($arguments['is_html']))); $sent++; }
			catch (\Throwable $e) { $failed[]=$email; }
		}
		return $this->success( "Sent {$sent} email(s).", array('sent'=>$sent,'failed'=>\count($failed),'failed_recipients'=>$failed,'total'=>\count($to)) );
	}
}
