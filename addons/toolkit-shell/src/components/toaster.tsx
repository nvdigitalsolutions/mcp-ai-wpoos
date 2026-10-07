/**
 * Toaster — sonner toast mount point.
 *
 * Mount once per app (done in App.tsx). sonner ships its own styles.
 *
 * @since 0.3.0
 */

import { Toaster as SonnerToaster } from 'sonner';

export function Toaster() {
	return <SonnerToaster position="bottom-right" richColors closeButton />;
}
