/**
 * Dialog — Radix Dialog primitives, unstyled (classes in main.css).
 *
 * @since 0.3.0
 */

import * as DialogPrimitive from '@radix-ui/react-dialog';
import type { ComponentPropsWithoutRef } from 'react';

export const Dialog = DialogPrimitive.Root;
export const DialogTrigger = DialogPrimitive.Trigger;
export const DialogPortal = DialogPrimitive.Portal;
export const DialogClose = DialogPrimitive.Close;

export function DialogOverlay(
	props: ComponentPropsWithoutRef< typeof DialogPrimitive.Overlay >
) {
	return (
		<DialogPrimitive.Overlay
			className="nvoos-toolkit-shell-dialog-overlay"
			{ ...props }
		/>
	);
}

export function DialogContent(
	props: ComponentPropsWithoutRef< typeof DialogPrimitive.Content >
) {
	return (
		<DialogPrimitive.Content
			className="nvoos-toolkit-shell-dialog-content"
			{ ...props }
		/>
	);
}

export function DialogTitle(
	props: ComponentPropsWithoutRef< typeof DialogPrimitive.Title >
) {
	return (
		<DialogPrimitive.Title
			className="nvoos-toolkit-shell-dialog-title"
			{ ...props }
		/>
	);
}

export function DialogDescription(
	props: ComponentPropsWithoutRef< typeof DialogPrimitive.Description >
) {
	return (
		<DialogPrimitive.Description
			className="nvoos-toolkit-shell-dialog-description"
			{ ...props }
		/>
	);
}
