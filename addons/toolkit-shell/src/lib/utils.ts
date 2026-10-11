/**
 * Class-name utility — combines conditional classes with clsx.
 *
 * Kept tailwind-merge-free: the Toolkit Shell ships hand-rolled CSS,
 * so conflicting utility merging is not needed. Add-ons that adopt
 * Tailwind should pair clsx with tailwind-merge in their own lib.
 *
 * @since 0.3.0
 */

import { clsx, type ClassValue } from 'clsx';

export function cn( ...inputs: ClassValue[] ): string {
	return clsx( inputs );
}
