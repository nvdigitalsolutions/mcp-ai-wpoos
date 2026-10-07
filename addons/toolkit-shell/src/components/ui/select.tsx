/**
 * Select — Radix Select primitives, unstyled.
 *
 * @since 0.3.0
 */

import * as SelectPrimitive from '@radix-ui/react-select';
import type { ComponentPropsWithoutRef } from 'react';

export const Select = SelectPrimitive.Root;
export const SelectValue = SelectPrimitive.Value;

export function SelectTrigger(
	props: ComponentPropsWithoutRef< typeof SelectPrimitive.Trigger >
) {
	return (
		<SelectPrimitive.Trigger
			className="nvoos-toolkit-shell-select-trigger"
			{ ...props }
		/>
	);
}

export function SelectContent(
	props: ComponentPropsWithoutRef< typeof SelectPrimitive.Content >
) {
	return (
		<SelectPrimitive.Portal>
			<SelectPrimitive.Content
				className="nvoos-toolkit-shell-select-content"
				{ ...props }
			/>
		</SelectPrimitive.Portal>
	);
}

export function SelectItem(
	props: ComponentPropsWithoutRef< typeof SelectPrimitive.Item >
) {
	return (
		<SelectPrimitive.Item
			className="nvoos-toolkit-shell-select-item"
			{ ...props }
		/>
	);
}
