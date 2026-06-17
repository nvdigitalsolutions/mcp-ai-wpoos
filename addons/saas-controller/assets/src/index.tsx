/**
 * NV oOS SaaS Controller — Admin UI entry point.
 *
 * Mounts the credentials wizard onto the `<div id="nvoos-saas-controller-wizard-root">`
 * placeholder rendered by the Overview tab in
 * `includes/admin/class-nvoos-saas-controller-admin-page.php`.
 *
 * @package NV_oOS_SaaS_Controller
 */

import { createRoot } from '@wordpress/element';
import App from './wizard/App';

const mount = document.getElementById( 'nvoos-saas-controller-wizard-root' );
if ( mount ) {
	mount.dataset.mounted = 'true';
	createRoot( mount ).render( <App /> );
}
