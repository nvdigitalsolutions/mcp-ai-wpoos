/**
 * NV oOS SaaS Controller — Admin UI entry point.
 *
 * Scaffolding placeholder. Subsequent PRs will mount the One-Click Wizard,
 * Plan/Apply dashboard, drift banner, audit-log viewer, and smoke tests
 * onto the WP-Admin page registered by `includes/admin/`.
 *
 * @package NV_oOS_SaaS_Controller
 */

import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const TEXT_DOMAIN = 'nvoos-saas-controller';

function App(): JSX.Element {
	return (
		<div className="nvoos-saas-controller-app">
			<h1>{ __( 'NV oOS SaaS Controller', TEXT_DOMAIN ) }</h1>
			<p>
				{ __(
					'Scaffolding placeholder. The Plan/Apply dashboard will mount here.',
					TEXT_DOMAIN
				) }
			</p>
		</div>
	);
}

const mount = document.getElementById( 'nvoos-saas-controller-root' );
if ( mount ) {
	createRoot( mount ).render( <App /> );
}
