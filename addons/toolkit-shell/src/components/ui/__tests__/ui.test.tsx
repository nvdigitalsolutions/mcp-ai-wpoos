/**
 * ui primitives — unit tests.
 *
 * Covers Button variants, ConfirmDialog open/confirm/cancel flows, and
 * Radix Tabs switching (keyboard-accessible tab list used by the view
 * switcher in App.tsx).
 */

import { describe, it, expect, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { Button } from '../button';
import { ConfirmDialog } from '../confirm-dialog';
import { Tabs, TabsList, TabsTrigger } from '../tabs';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../select';

describe( 'Button', () => {
	it( 'renders with the default variant classes', () => {
		render( <Button>Save</Button> );
		const button = screen.getByRole( 'button', { name: 'Save' } );
		expect( button ).toHaveClass( 'nvoos-toolkit-shell-btn' );
		expect( button ).toHaveAttribute( 'type', 'button' );
	} );

	it( 'applies variant and size modifier classes', () => {
		render(
			<Button variant="destructive" size="sm">
				Delete
			</Button>
		);
		const button = screen.getByRole( 'button', { name: 'Delete' } );
		expect( button ).toHaveClass( 'is-destructive' );
		expect( button ).toHaveClass( 'is-sm' );
	} );

	it( 'fires onClick and supports disabled state', async () => {
		const user = userEvent.setup();
		const onClick = vi.fn();
		render(
			<>
				<Button onClick={ onClick }>Go</Button>
				<Button disabled>Nope</Button>
			</>
		);
		await user.click( screen.getByRole( 'button', { name: 'Go' } ) );
		expect( onClick ).toHaveBeenCalledTimes( 1 );
		expect( screen.getByRole( 'button', { name: 'Nope' } ) ).toBeDisabled();
	} );
} );

describe( 'ConfirmDialog', () => {
	it( 'renders title, description, and both actions while open', () => {
		render(
			<ConfirmDialog
				open={ true }
				onOpenChange={ () => {} }
				title="Delete record?"
				description="This cannot be undone."
				confirmLabel="Delete"
				onConfirm={ () => {} }
			/>
		);
		expect( screen.getByRole( 'dialog' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Delete record?' ) ).toBeInTheDocument();
		expect( screen.getByText( 'This cannot be undone.' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Delete' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Cancel' } ) ).toBeInTheDocument();
	} );

	it( 'calls onConfirm and closes when the destructive action is clicked', async () => {
		const user = userEvent.setup();
		const onConfirm = vi.fn();
		const onOpenChange = vi.fn();
		render(
			<ConfirmDialog
				open={ true }
				onOpenChange={ onOpenChange }
				title="Delete record?"
				confirmLabel="Delete"
				onConfirm={ onConfirm }
			/>
		);
		await user.click( screen.getByRole( 'button', { name: 'Delete' } ) );
		expect( onConfirm ).toHaveBeenCalledTimes( 1 );
		expect( onOpenChange ).toHaveBeenCalledWith( false );
	} );

	it( 'closes without confirming when cancelled', async () => {
		const user = userEvent.setup();
		const onConfirm = vi.fn();
		const onOpenChange = vi.fn();
		render(
			<ConfirmDialog
				open={ true }
				onOpenChange={ onOpenChange }
				title="Delete record?"
				confirmLabel="Delete"
				onConfirm={ onConfirm }
			/>
		);
		await user.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		expect( onConfirm ).not.toHaveBeenCalled();
		expect( onOpenChange ).toHaveBeenCalledWith( false );
	} );
} );

describe( 'Tabs', () => {
	it( 'switches active tab and marks selection state', async () => {
		const user = userEvent.setup();
		render(
			<Tabs defaultValue="table">
				<TabsList aria-label="Views">
					<TabsTrigger value="table">Table</TabsTrigger>
					<TabsTrigger value="kanban">Kanban</TabsTrigger>
				</TabsList>
			</Tabs>
		);
		const tableTab = screen.getByRole( 'tab', { name: 'Table' } );
		const kanbanTab = screen.getByRole( 'tab', { name: 'Kanban' } );
		expect( tableTab ).toHaveAttribute( 'data-state', 'active' );

		await user.click( kanbanTab );
		expect( kanbanTab ).toHaveAttribute( 'data-state', 'active' );
		expect( tableTab ).not.toHaveAttribute( 'data-state', 'active' );
	} );
} );

describe( 'Select (Radix)', () => {
	function SelectHarness( { onValue }: { onValue: ( v: string ) => void } ) {
		const [ value, setValue ] = useState( '' );
		return (
			<Select
				value={ value }
				onValueChange={ ( v ) => {
					setValue( v );
					onValue( v );
				} }
			>
				<SelectTrigger aria-label="Status">
					<SelectValue placeholder="—" />
				</SelectTrigger>
				<SelectContent>
					<SelectItem value="todo">todo</SelectItem>
					<SelectItem value="done">done</SelectItem>
				</SelectContent>
			</Select>
		);
	}

	it( 'opens on trigger click and reports selection via onValueChange', async () => {
		const user = userEvent.setup();
		const onValue = vi.fn();
		render( <SelectHarness onValue={ onValue } /> );

		await user.click( screen.getByRole( 'combobox' ) );
		await user.click( await screen.findByRole( 'option', { name: 'todo' } ) );

		await waitFor( () => expect( onValue ).toHaveBeenCalledWith( 'todo' ) );
		expect( onValue ).toHaveBeenCalledTimes( 1 );
	} );
} );
