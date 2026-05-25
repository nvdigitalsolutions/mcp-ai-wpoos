import { useState, useEffect } from '@wordpress/element';
import { loadBootstrap } from '../services/bootstrap';

export function useBootstrap() {
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState(null);

	useEffect(() => {
		loadBootstrap()
			.then(() => setLoading(false))
			.catch((err) => { setError(err.message); setLoading(false); });
	}, []);

	return { loading, error };
}
