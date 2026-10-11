/**
 * FormView — component tests.
 *
 * Covers the runtime zod schema: required-field validation messages,
 * readonly field omission, empty-value stripping from the payload,
 * boolean toggling, and enum selection through Radix Select.
 */

import { describe, it, expect, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { isValidElement, Children, type ReactNode } from 'react';
import { FormView } from '../FormView';
import type { Resource } from '../../api/types';

/**
 * Stand-in for the Radix Select used inside FormView.
 *
 * Radix renders a hidden `BubbleSelect` for native form integration; in
 * jsdom its synthetic change event carries a bogus value that resets the
 * controlled field — but only when the Select lives inside a native
 * `<form>`. Real browsers filter the duplicate upstream. The real Radix
 * wrapper is integration-tested form-free in ui.test.tsx; here we swap in
 * a native-select double so FormView's RHF/zod/payload logic is what's
 * under test.
 */
vi.mock( '../ui/select', () => {
	const findChild = ( node: ReactNode, suffix: string ): Record<string, unknown> | null => {
		let found: Record<string, unknown> | null = null;
		Children.forEach( node, ( child ) => {
			if ( found || ! isValidElement( child ) ) {
				return;
			}
			const typeName = String( ( child.type as { name?: string } )?.name ?? '' );
			if ( typeName.endsWith( suffix ) ) {
				found = child.props as Record<string, unknown>;
			} else {
				found = findChild(
					( child.props as { children?: ReactNode } ).children,
					suffix
				);
			}
		} );
		return found;
	};

	const collectOptions = ( node: ReactNode ): ReactNode[] => {
		const options: ReactNode[] = [];
		Children.forEach( node, ( child ) => {
			if ( ! isValidElement( child ) ) {
				return;
			}
			const typeName = String( ( child.type as { name?: string } )?.name ?? '' );
			if ( typeName.endsWith( 'Item' ) ) {
				const props = child.props as { value: string; children: ReactNode };
				options.push(
					<option key={ props.value } value={ props.value }>
						{ props.children }
					</option>
				);
			} else {
				options.push(
					...collectOptions( ( child.props as { children?: ReactNode } ).children )
				);
			}
		} );
		return options;
	};

	const Select = ( {
		value,
		onValueChange,
		children,
	}: {
		value?: string;
		onValueChange?: ( value: string ) => void;
		children?: ReactNode;
	} ) => {
		const trigger = findChild( children, 'Trigger' );
		return (
			<select
				id={ trigger?.id as string | undefined }
				aria-label={ trigger?.[ 'aria-label' ] as string | undefined }
				value={ value ?? '' }
				onChange={ ( e ) => onValueChange?.( e.target.value ) }
			>
				<option value="">—</option>
				{ collectOptions( children ) }
			</select>
		);
	};
	const SelectTrigger = ( { children }: { children?: ReactNode } ) => <>{ children }</>;
	const SelectValue = () => null;
	const SelectContent = ( { children }: { children?: ReactNode } ) => <>{ children }</>;
	const SelectItem = () => null;
	return { Select, SelectTrigger, SelectValue, SelectContent, SelectItem };
} );

const RESOURCE: Resource = {
	name: 'posts',
	label: 'Posts',
	endpoint: '/posts',
	primary_key: 'id',
	fields: [
		{ name: 'title', type: 'string', label: 'Title', required: true, readonly: false },
		{ name: 'status', type: 'enum', label: 'Status', required: false, readonly: false, options: [ 'todo', 'done' ] },
		{ name: 'active', type: 'boolean', label: 'Active', required: false, readonly: false },
		{ name: 'count', type: 'integer', label: 'Count', required: false, readonly: false },
		{ name: 'author_id', type: 'integer', label: 'Author', required: false, readonly: true },
	],
};

describe( 'FormView', () => {
	it( 'omits readonly fields from the rendered form', () => {
		render(
			<FormView resource={ RESOURCE } row={ null } mode="create" onSubmit={ () => {} } />
		);
		expect( screen.getByLabelText( /Title/ ) ).toBeInTheDocument();
		expect( screen.queryByLabelText( /Author/ ) ).not.toBeInTheDocument();
	} );

	it( 'blocks submission and reports required-field errors', async () => {
		const user = userEvent.setup();
		const onSubmit = vi.fn();
		render(
			<FormView resource={ RESOURCE } row={ null } mode="create" onSubmit={ onSubmit } />
		);
		await user.click( screen.getByRole( 'button', { name: 'Create' } ) );

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent( 'Title is required.' );
		expect( onSubmit ).not.toHaveBeenCalled();
	} );

	it( 'submits a payload that strips empty values and includes toggled booleans', async () => {
		const user = userEvent.setup();
		const onSubmit = vi.fn();
		render(
			<FormView resource={ RESOURCE } row={ null } mode="create" onSubmit={ onSubmit } />
		);

		await user.type( screen.getByLabelText( /Title/ ), 'Hello world' );
		await user.click( screen.getByRole( 'checkbox', { name: 'Active' } ) );
		await user.click( screen.getByRole( 'button', { name: 'Create' } ) );

		await waitFor( () => expect( onSubmit ).toHaveBeenCalledTimes( 1 ) );
		expect( onSubmit ).toHaveBeenCalledWith( {
			title: 'Hello world',
			active: true,
		} );
	} );

	it( 'selects an enum value through the Radix select', async () => {
		const user = userEvent.setup();
		const onSubmit = vi.fn();
		render(
			<FormView resource={ RESOURCE } row={ null } mode="create" onSubmit={ onSubmit } />
		);

		await user.type( screen.getByLabelText( /Title/ ), 'Enum row' );
		await user.selectOptions( screen.getByRole( 'combobox' ), 'todo' );
		await user.click( screen.getByRole( 'button', { name: 'Create' } ) );

		await waitFor( () => expect( onSubmit ).toHaveBeenCalledTimes( 1 ) );
		expect( onSubmit ).toHaveBeenCalledWith( {
			title: 'Enum row',
			status: 'todo',
			active: false,
		} );
	} );

	it( 'prefills edit-mode values from the row and submits changes', async () => {
		const user = userEvent.setup();
		const onSubmit = vi.fn();
		render(
			<FormView
				resource={ RESOURCE }
				row={ { title: 'Old', active: true } }
				mode="edit"
				onSubmit={ onSubmit }
			/>
		);
		const titleInput = screen.getByLabelText( /Title/ ) as HTMLInputElement;
		expect( titleInput.value ).toBe( 'Old' );
		expect( screen.getByRole( 'checkbox', { name: 'Active' } ) ).toHaveAttribute(
			'data-state',
			'checked'
		);

		await user.clear( titleInput );
		await user.type( titleInput, 'New' );
		await user.click( screen.getByRole( 'button', { name: 'Save' } ) );

		await waitFor( () => expect( onSubmit ).toHaveBeenCalledTimes( 1 ) );
		expect( onSubmit ).toHaveBeenCalledWith( { title: 'New', active: true } );
	} );
} );
