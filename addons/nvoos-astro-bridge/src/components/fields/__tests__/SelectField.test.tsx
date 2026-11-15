/**
 * SelectField — native select, options from config, aria-invalid on error.
 */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { SelectField } from '../SelectField';

const OPTIONS = [
	{ value: 'draft', label: 'Draft' },
	{ value: 'pending', label: 'Pending review' },
	{ value: 'publish', label: 'Published' },
];

afterEach(() => {
	cleanup();
});

describe('SelectField', () => {
	it('renders every option and reports the current value', () => {
		render(
			<SelectField
				label="Status"
				value="pending"
				options={OPTIONS}
				onChange={() => {}}
			/>
		);

		const select = screen.getByLabelText('Status') as HTMLSelectElement;
		expect(select.value).toBe('pending');
		expect(screen.getByRole('option', { name: 'Draft' })).toBeTruthy();
		expect(screen.getByRole('option', { name: 'Published' })).toBeTruthy();
	});

	it('emits the chosen value and marks invalid state', () => {
		const onChange = vi.fn();
		render(
			<SelectField
				label="Status"
				value="draft"
				error="Not allowed."
				options={OPTIONS}
				onChange={onChange}
			/>
		);

		const select = screen.getByLabelText('Status') as HTMLSelectElement;
		expect(select.getAttribute('aria-invalid')).toBe('true');
		expect(screen.getByText('Not allowed.')).toBeTruthy();

		fireEvent.change(select, { target: { value: 'publish' } });
		expect(onChange).toHaveBeenCalledWith('publish');
	});
});
