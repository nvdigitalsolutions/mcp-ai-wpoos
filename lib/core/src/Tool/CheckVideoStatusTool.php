<?php
/**
 * Check Video Status — poll async video generation job status.
 *
 * Framework-agnostic equivalent of WP_MCP_AI_Tool_Check_Video_Status.
 *
 * @package Nvoos\Core @since 2.0.0 @license MIT
 */
declare(strict_types=1);
namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\QueueClientInterface;

class CheckVideoStatusTool extends AbstractTool {
	public function __construct( ErrorFactoryInterface $e, private readonly QueueClientInterface $q ) { parent::__construct( $e ); }
	public function getSlug(): string { return 'check_video_status'; }
	public function getName(): string { return 'Check Video Generation Status'; }
	public function getDescription(): string { return 'Checks the status of an async video generation job.'; }
	public function getParametersSchema(): array {
		return array( 'type'=>'object', 'properties'=>array( 'job_id'=>array( 'type'=>'string','description'=>'The job ID from async generation.','minLength'=>1 ) ), 'required'=>array('job_id'), 'additionalProperties'=>false );
	}
	public function getRequiredCapability(): string { return 'edit_posts'; }

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$jobId = $this->stringParam( $arguments, 'job_id' );
		if ( '' === $jobId ) return $this->errors->validationFailed( 'Job ID is required.', array( 'job_id'=>array('Required.') ) );

		try {
			$s = $this->q->getStatus( $jobId );
			$status = $s->isTerminal() ? ( $s->isSuccessful() ? 'completed' : 'failed' ) : $s->status;
			return $this->success( "Job {$jobId}: {$status}", array( 'job_id'=>$jobId, 'status'=>$status, 'attempts'=>$s->attempts, 'result'=>$s->result, 'error'=>$s->error ) );
		} catch ( \Throwable $e ) {
			return $this->errors->notFound( 'Job not found: ' . $jobId );
		}
	}
}
