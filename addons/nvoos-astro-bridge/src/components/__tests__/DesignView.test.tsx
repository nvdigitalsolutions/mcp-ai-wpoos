/**
 * DesignView consumption contract (Proposal 095 §4.1) — the MCP client is
 * mocked, so this suite pins what the tab asks the /mcp surface for: the
 * parallel mount reads, one tokens_set change per saved row, and
 * theme_apply per theme button. Wire correctness lives in mcp-client.test.
 */
import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';
import {
	cleanup,
	fireEvent,
	render,
	screen,
	waitFor,
} from '@testing-library/react';
import { DesignView } from '../DesignView';

const { mcpCallMock } = vi.hoisted(() => ({ mcpCallMock: vi.fn() }));

vi.mock('../../lib/mcp-client', () => ({
	mcpCall: (...args: unknown[]) => mcpCallMock(...args),
}));

// Vitest globals are off in this addon, so testing-library's auto-cleanup
// never registers — unmount between tests explicitly.
afterEach(() => {
	cleanup();
});

const TOKENS_RESULT = {
	active_theme: 'default',
	semantic: {
		'color.text': '#1a1a1a',
		'color.bg': '#ffffff',
	},
};

const THEMES_RESULT = {
	active: 'default',
	themes: [
		{ name: 'light', description: null, tokens: {} },
		{ name: 'dark', description: null, tokens: {} },
	],
};

beforeEach(() => {
	mcpCallMock.mockReset();
	mcpCallMock.mockImplementation(async (tool: string) => {
		if (tool === 'tokens_get') {
			return TOKENS_RESULT;
		}
		if (tool === 'theme_list') {
			return THEMES_RESULT;
		}
		if (tool === 'tokens_set') {
			return { applied: true, diff: [], report: { errors: [], warnings: [] } };
		}
		if (tool === 'theme_apply') {
			return { ok: true, message: 'active theme is now dark' };
		}
		return {};
	});
});

describe('DesignView mount', () => {
	it('reads tokens_get and theme_list in parallel and renders the token table', async () => {
		render(<DesignView />);

		expect(await screen.findByText('Design System')).toBeInTheDocument();

		await waitFor(() => {
			expect(screen.getByText('color.text')).toBeInTheDocument();
			expect(screen.getByText('#1a1a1a')).toBeInTheDocument();
			expect(screen.getByText('color.bg')).toBeInTheDocument();
		});

		expect(mcpCallMock).toHaveBeenCalledWith('tokens_get', { include_groups: false });
		expect(mcpCallMock).toHaveBeenCalledWith('theme_list', {});
		expect(screen.getByText(/Active theme:/)).toHaveTextContent('default');
	});

	it('renders one theme button per listed theme', async () => {
		render(<DesignView />);

		expect(await screen.findByRole('button', { name: 'light' })).toBeInTheDocument();
		expect(screen.getByRole('button', { name: 'dark' })).toBeInTheDocument();
	});
});

describe('DesignView writes', () => {
	it('saves a row through tokens_set with a single change entry', async () => {
		render(<DesignView />);

		await screen.findByText('color.text');

		fireEvent.change(screen.getByLabelText('Value for color.text'), {
			target: { value: '#222222' },
		});
		fireEvent.click(screen.getAllByRole('button', { name: 'Save' })[0]);

		await waitFor(() => {
			expect(mcpCallMock).toHaveBeenCalledWith('tokens_set', {
				changes: { 'color.text': '#222222' },
			});
		});
		expect(await screen.findByText(/Applied: yes/)).toBeInTheDocument();
	});

	it('applies a theme through theme_apply', async () => {
		render(<DesignView />);

		fireEvent.click(await screen.findByRole('button', { name: 'dark' }));

		await waitFor(() => {
			expect(mcpCallMock).toHaveBeenCalledWith('theme_apply', { name: 'dark' });
		});
		expect(await screen.findByText('active theme is now dark')).toBeInTheDocument();
	});
});
