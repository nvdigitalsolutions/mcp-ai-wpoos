import { useWizardStore } from '../state/wizardStore';

export function ConsoleStep() {
	const consoleState = useWizardStore( ( state ) => state.console );
	const patch = useWizardStore( ( state ) => state.patchConsole );

	return (
		<section aria-labelledby="step-console">
			<h2 id="step-console">Deploy the console</h2>
			<p className="lede">
				The Complete Console is a branded, cookie-authenticated admin surface served
				from Cloudways Velocity. It proxies WordPress same-origin — no tokens in
				JavaScript.
			</p>
			<label className="checkbox">
				<input
					type="checkbox"
					checked={ consoleState.enabled }
					onChange={ ( event ) => patch( { enabled: event.target.checked } ) }
				/>
				<span>Deploy the Complete Console for this estate (recommended)</span>
			</label>
			<div className="note">
				<strong>How it deploys:</strong> the Exchange pushes the prebuilt console
				artifact to a per-estate branch of the console mirror repository. Velocity
				connects that branch once; env values are applied in the dashboard and then
				verified by health check.
			</div>
		</section>
	);
}
