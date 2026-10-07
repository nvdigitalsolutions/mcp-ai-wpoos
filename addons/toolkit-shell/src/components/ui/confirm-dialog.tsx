/**
 * ConfirmDialog — composable, accessible delete/confirm dialog.
 *
 * Replaces `window.confirm()` for destructive actions: proper focus
 * trapping, ESC handling, and screen-reader announcements via Radix.
 *
 * @since 0.3.0
 */

import { __ } from '@wordpress/i18n';
import {
	Dialog,
	DialogContent,
	DialogOverlay,
	DialogPortal,
	DialogTitle,
	DialogDescription,
	DialogClose,
} from './dialog';
import { Button } from './button';

interface ConfirmDialogProps {
	open: boolean;
	onOpenChange: ( open: boolean ) => void;
	title: string;
	description?: string;
	confirmLabel?: string;
	cancelLabel?: string;
	destructive?: boolean;
	onConfirm: () => void;
}

export function ConfirmDialog( {
	open,
	onOpenChange,
	title,
	description,
	confirmLabel,
	cancelLabel,
	destructive = true,
	onConfirm,
}: ConfirmDialogProps ) {
	return (
		<Dialog open={ open } onOpenChange={ onOpenChange }>
			<DialogPortal>
				<DialogOverlay />
				<DialogContent>
					<DialogTitle>{ title }</DialogTitle>
					{ description && <DialogDescription>{ description }</DialogDescription> }
					<div className="nvoos-toolkit-shell-dialog-footer">
						<DialogClose asChild>
							<Button variant="secondary">
								{ cancelLabel ?? __( 'Cancel', 'nvoos-toolkit-shell' ) }
							</Button>
						</DialogClose>
						<Button
							variant={ destructive ? 'destructive' : 'default' }
							onClick={ () => {
								onConfirm();
								onOpenChange( false );
							} }
						>
							{ confirmLabel ?? __( 'Confirm', 'nvoos-toolkit-shell' ) }
						</Button>
					</div>
				</DialogContent>
			</DialogPortal>
		</Dialog>
	);
}
