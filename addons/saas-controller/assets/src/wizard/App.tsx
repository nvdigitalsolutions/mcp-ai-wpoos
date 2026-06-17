/**
 * Wizard root — wraps `<Wizard />` in the react-query provider.
 *
 * @package NV_oOS_SaaS_Controller
 */

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import Wizard from './Wizard';

const queryClient = new QueryClient( {
	defaultOptions: {
		queries: {
			refetchOnWindowFocus: false,
			retry: false,
		},
	},
} );

export default function App(): JSX.Element {
	return (
		<QueryClientProvider client={ queryClient }>
			<Wizard />
		</QueryClientProvider>
	);
}
