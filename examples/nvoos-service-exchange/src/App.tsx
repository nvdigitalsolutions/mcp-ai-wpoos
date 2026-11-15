import { useEffect, useMemo, useState } from 'react';
import type { ApiClient } from './api/client';
import { createApiClient } from './api/client';
import { STEPS, useWizardStore } from './state/wizardStore';
import type { StepId } from './state/wizardStore';
import { ProfileStep } from './steps/ProfileStep';
import { BlueprintStep } from './steps/BlueprintStep';
import { AssistantStep } from './steps/AssistantStep';
import { SiteStep } from './steps/SiteStep';
import { ConsoleStep } from './steps/ConsoleStep';
import { GatewayStep } from './steps/GatewayStep';
import { CommerceStep } from './steps/CommerceStep';
import { PublishStep } from './steps/PublishStep';

function renderStep( step: StepId, client: ApiClient ) {
	switch ( step ) {
		case 'profile':
			return <ProfileStep />;
		case 'blueprint':
			return <BlueprintStep client={ client } />;
		case 'assistant':
			return <AssistantStep />;
		case 'site':
			return <SiteStep />;
		case 'console':
			return <ConsoleStep />;
		case 'gateway':
			return <GatewayStep />;
		case 'commerce':
			return <CommerceStep />;
		case 'publish':
			return <PublishStep client={ client } />;
		default:
			return <ProfileStep />;
	}
}

export default function App( { client }: { client?: ApiClient } ) {
	const api = useMemo( () => client ?? createApiClient(), [ client ] );
	const step = useWizardStore( ( state ) => state.step );
	const next = useWizardStore( ( state ) => state.next );
	const back = useWizardStore( ( state ) => state.back );
	const goTo = useWizardStore( ( state ) => state.goTo );
	const [ mode, setMode ] = useState< string | null >( null );

	useEffect( () => {
		let active = true;
		api
			.health()
			.then( ( health ) => {
				if ( active ) {
					setMode( health.mode );
				}
			} )
			.catch( () => {
				if ( active ) {
					setMode( null );
				}
			} );
		return () => {
			active = false;
		};
	}, [ api ] );

	const index = STEPS.findIndex( ( entry ) => entry.id === step );

	return (
		<div className="app">
			<header className="topbar">
				<div className="brand">
					<span className="brand__mark" aria-hidden="true">
						EX
					</span>
					<div>
						<h1>Service Exchange</h1>
						<p className="muted">Deploy your expertise as an MCP SaaS</p>
					</div>
				</div>
				<div className="topbar__meta">
					{ mode ? (
						<span className={ `mode mode--${ mode }` }>{ mode } mode</span>
					) : (
						<span className="mode">offline</span>
					) }
				</div>
			</header>

			<nav className="stepper" aria-label="Provisioning steps">
				<ol>
					{ STEPS.map( ( entry, entryIndex ) => (
						<li key={ entry.id }>
							<button
								type="button"
								className={ entry.id === step ? 'stepper__step stepper__step--active' : 'stepper__step' }
								aria-current={ entry.id === step ? 'step' : undefined }
								onClick={ () => goTo( entry.id ) }
							>
								<span className="stepper__number">{ entryIndex + 1 }</span>
								<span>{ entry.label }</span>
							</button>
						</li>
					) ) }
				</ol>
			</nav>

			<main className="panel">{ renderStep( step, api ) }</main>

			<footer className="footer">
				<button type="button" className="button" onClick={ back } disabled={ index === 0 }>
					Back
				</button>
				<span className="muted">
					Step { index + 1 } of { STEPS.length }
				</span>
				<button
					type="button"
					className="button button--primary"
					onClick={ next }
					disabled={ index === STEPS.length - 1 }
				>
					Continue
				</button>
			</footer>
		</div>
	);
}
