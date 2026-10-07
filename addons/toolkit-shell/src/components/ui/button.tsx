/**
 * Button — CVA-based button with variant/size classes.
 *
 * Styles live in `src/styles/main.css` under `.nvoos-toolkit-shell-btn`
 * and its `.is-*` modifier classes.
 *
 * @since 0.3.0
 */

import { forwardRef, type ButtonHTMLAttributes } from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '../../lib/utils';

const buttonVariants = cva( 'nvoos-toolkit-shell-btn', {
	variants: {
		variant: {
			default: '',
			secondary: 'is-secondary',
			destructive: 'is-destructive',
			ghost: 'is-ghost',
		},
		size: {
			default: '',
			sm: 'is-sm',
			icon: 'is-icon',
		},
	},
	defaultVariants: {
		variant: 'default',
		size: 'default',
	},
} );

export interface ButtonProps
	extends ButtonHTMLAttributes< HTMLButtonElement >,
		VariantProps< typeof buttonVariants > {}

export const Button = forwardRef< HTMLButtonElement, ButtonProps >(
	( { className, variant, size, type = 'button', ...props }, ref ) => (
		<button
			ref={ ref }
			type={ type }
			className={ cn( buttonVariants( { variant, size } ), className ) }
			{ ...props }
		/>
	)
);
Button.displayName = 'Button';
