/**
 * Toast — sonner mount point. Mount once in the app root.
 */

import { Toaster as SonnerToaster } from 'sonner';

export function Toaster() {
  return <SonnerToaster position="bottom-right" richColors closeButton />;
}
