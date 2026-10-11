/**
 * Checkbox — Radix Checkbox primitive, unstyled.
 *
 * @since 0.3.0
 */

import * as CheckboxPrimitive from '@radix-ui/react-checkbox';
import type { ComponentPropsWithoutRef } from 'react';
import { cn } from '../../lib/utils';

export function Checkbox( {
	className,
	...props
}: ComponentPropsWithoutRef< typeof CheckboxPrimitive.Root > ) {
	return (
		<CheckboxPrimitive.Root
			className={ cn( 'nvoos-toolkit-shell-checkbox', className ) }
			{ ...props }
		/>
	);
}

export function CheckboxIndicator(
	props: ComponentPropsWithoutRef< typeof CheckboxPrimitive.Indicator >
) {
	return (
		<CheckboxPrimitive.Indicator
			className="nvoos-toolkit-shell-checkbox-indicator"
			{ ...props }
		/>
	);
}
