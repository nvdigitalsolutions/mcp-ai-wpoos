/**
 * DropdownMenu — Radix DropdownMenu primitives, unstyled.
 *
 * @since 0.3.0
 */

import * as DropdownMenuPrimitive from '@radix-ui/react-dropdown-menu';
import type { ComponentPropsWithoutRef } from 'react';

export const DropdownMenu = DropdownMenuPrimitive.Root;
export const DropdownMenuTrigger = DropdownMenuPrimitive.Trigger;
export const DropdownMenuPortal = DropdownMenuPrimitive.Portal;

export function DropdownMenuContent(
	props: ComponentPropsWithoutRef< typeof DropdownMenuPrimitive.Content >
) {
	return (
		<DropdownMenuPrimitive.Content
			className="nvoos-toolkit-shell-menu-content"
			{ ...props }
		/>
	);
}

export function DropdownMenuItem(
	props: ComponentPropsWithoutRef< typeof DropdownMenuPrimitive.Item >
) {
	return (
		<DropdownMenuPrimitive.Item
			className="nvoos-toolkit-shell-menu-item"
			{ ...props }
		/>
	);
}

export function DropdownMenuSeparator(
	props: ComponentPropsWithoutRef< typeof DropdownMenuPrimitive.Separator >
) {
	return (
		<DropdownMenuPrimitive.Separator
			className="nvoos-toolkit-shell-menu-separator"
			{ ...props }
		/>
	);
}
