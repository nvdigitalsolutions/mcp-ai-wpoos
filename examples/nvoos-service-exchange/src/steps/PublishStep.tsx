import { useState } from 'react';
import type { ApiClient } from '../api/client';
import { ApiClientError } from '../api/client';
import { StatusPill } from '../components/Field';
import { useWizardStore } from '../state/wizardStore';
import type { EstateDetail, EstateSummary } from '../types';

interface DeployError {
	message: string;
	remediation?: string;
	pasteBlock?: string;
}

export function PublishStep( { client }: { client: ApiClient } ) {
	const profile = useWizardStore( ( state ) => state.profile );
	const seed = useWizardStore( ( state ) => state.seed );
	const gateway = useWizardStore( ( state ) => state.gateway );
	const commerce = useWizardStore( ( state ) => state.commerce );
	const estate = useWizardStore( ( state ) => state.estate );
	const setEstate = useWizardStore( ( state ) => state.setEstate );
	const composeBlueprint = useWizardStore( ( state ) => state.composeBlueprint );
	const reset = useWizardStore( ( state ) => state.reset );

	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< DeployError | null >( null );
	const [ detail, setDetail ] = useState< EstateDetail | null >( null );

	async function deploy() {
		const blueprint = composeBlueprint();
		if ( ! blueprint ) {
			setError( { message: 'Choose a blueprint before deploying.' } );
			return;
		}
		setBusy( true );
		setError( null );
		setDetail( null );
		try {
			let current: EstateSummary =
				estate ?? ( await client.createEstate( { blueprint } ) );
			setEstate( current );

			for ( let index = 0; index < 10 && ! current.complete; index += 1 ) {
				const result = await client.advance( current.slug );
				current = {
					...current,
					state: result.state,
					steps: result.steps,
					complete: result.state === 'live',
				};
				setEstate( current );
			}

			const fetched = await client.estate( current.slug );
			setDetail( fetched );
			setEstate( fetched );
		} catch ( caught ) {
			if ( caught instanceof ApiClientError ) {
				setError( {
					message: caught.message,
					remediation:
						typeof caught.extra.remediation === 'string'
							? caught.extra.remediation
							: undefined,
					pasteBlock:
						typeof caught.extra.pasteBlock === 'string'
							? caught.extra.pasteBlock
							: undefined,
				} );
			} else {
				setError( { message: caught instanceof Error ? caught.message : 'Deployment failed' } );
			}
		} finally {
			setBusy( false );
		}
	}

	const rows = [
		{ label: 'Expert', value: profile.brand || profile.name || '—' },
		{ label: 'Blueprint', value: seed ? `${ seed.name } (${ seed.slug })` : '—' },
		{ label: 'Gateway', value: `${ gateway.visibility } · ${ gateway.allowlist || 'no allowlist' }` },
		{
			label: 'Commerce',
			value: commerce.priceId
				? `${ commerce.priceId } · ${ commerce.trialDays }-day trial`
				: `No price yet · ${ commerce.trialDays }-day trial`,
		},
	];

	return (
		<section aria-labelledby="step-publish">
			<h2 id="step-publish">Review and deploy</h2>
			<p className="lede">
				Deployment runs the estate state machine one verified step at a time. Every
				step is idempotent — a failure returns the exact paste-block remediation.
			</p>

			<dl className="summary">
				{ rows.map( ( row ) => (
					<div key={ row.label } className="summary__row">
						<dt>{ row.label }</dt>
						<dd>{ row.value }</dd>
					</div>
				) ) }
			</dl>

			<div className="actions">
				<button type="button" className="button button--primary" onClick={ deploy } disabled={ busy }>
					{ busy ? 'Deploying…' : estate ? 'Continue deployment' : 'Deploy service' }
				</button>
				<button
					type="button"
					className="button"
					onClick={ () => {
						reset();
						setDetail( null );
						setError( null );
					} }
					disabled={ busy }
				>
					Start over
				</button>
			</div>

			{ error ? (
				<div className="error" role="alert">
					<strong>{ error.message }</strong>
					{ error.remediation ? <p>{ error.remediation }</p> : null }
					{ error.pasteBlock ? (
						<pre className="code" aria-label="Manual remediation paste-block">
							{ error.pasteBlock }
						</pre>
					) : null }
				</div>
			) : null }

			{ estate ? (
				<ol className="steps" aria-label="Estate provisioning steps">
					{ estate.steps.map( ( step ) => (
						<li key={ step.step }>
							<StatusPill tone={ step.ok ? 'ok' : step.step === estate.nextStep ? 'info' : 'pending' }>
								{ step.ok ? 'done' : step.step === estate.nextStep ? 'current' : 'pending' }
							</StatusPill>
							<span>{ step.label }</span>
						</li>
					) ) }
				</ol>
			) : null }

			{ detail?.snippets ? (
				<div className="snippets">
					<h3>Connect your customers</h3>
					<p className="muted">Tokens are masked. Reveal them only in the authenticated owner console.</p>
					<h4>Bridge (Zed / Claude Desktop / Cursor)</h4>
					<pre className="code">{ JSON.stringify( detail.snippets.bridge, null, 2 ) }</pre>
					<h4>ChatGPT connector</h4>
					<pre className="code">{ detail.snippets.chatgpt.url }</pre>
					<h4>Claude Code</h4>
					<pre className="code">{ detail.snippets.claudeCode.join( '\n' ) }</pre>
					<h4>Direct HTTP</h4>
					<pre className="code">{ detail.snippets.curl }</pre>
				</div>
			) : null }
		</section>
	);
}
