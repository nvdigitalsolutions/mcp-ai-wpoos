import { useWizardStore } from '../state/wizardStore';
import { Field } from '../components/Field';

export function SiteStep() {
	const site = useWizardStore( ( state ) => state.site );
	const patch = useWizardStore( ( state ) => state.patchSite );

	return (
		<section aria-labelledby="step-site">
			<h2 id="step-site">Provision the WordPress estate</h2>
			<p className="lede">
				One Cloudways app per expert — isolation is the blast-radius control. The
				Complete bundle is installed and your assistant imported during deployment.
			</p>
			<div className="grid grid--2">
				<Field label="Region" htmlFor="site-region">
					<select
						id="site-region"
						value={ site.region }
						onChange={ ( event ) => patch( { region: event.target.value } ) }
					>
						<option value="us">United States</option>
						<option value="eu">Europe</option>
						<option value="ap">Asia-Pacific</option>
					</select>
				</Field>
				<Field label="Size" htmlFor="site-size">
					<select
						id="site-size"
						value={ site.sizeHint }
						onChange={ ( event ) =>
							patch( { sizeHint: event.target.value as 'small' | 'medium' | 'large' } )
						}
					>
						<option value="small">Small — pilot / solo expert</option>
						<option value="medium">Medium — active practice</option>
						<option value="large">Large — multi-team</option>
					</select>
				</Field>
			</div>
			<Field
				label="Custom domain (optional)"
				htmlFor="site-domain"
				hint="Leave empty to use the Cloudways temporary domain. DNS verification is required before deploy."
			>
				<input
					id="site-domain"
					value={ site.domain }
					onChange={ ( event ) => patch( { domain: event.target.value } ) }
					placeholder="assistant.yourbrand.com"
				/>
			</Field>
			<label className="checkbox">
				<input
					type="checkbox"
					checked={ site.wpManager }
					onChange={ ( event ) => patch( { wpManager: event.target.checked } ) }
				/>
				<span>Prefer WP Manager for WP-CLI (SSH is the fallback, paste-block the last resort)</span>
			</label>
		</section>
	);
}
