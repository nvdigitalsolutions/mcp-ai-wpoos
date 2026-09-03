/**
 * docs-hub — unit tests.
 *
 * Tests CodeBlock (syntax-highlighted code block with copy button) and
 * ContentArea (Markdown renderer). All dependencies are pure-JS and work
 * safely in jsdom; no canvas or media APIs are used.
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';

import CodeBlock from '../components/CodeBlock';
import ContentArea from '../components/ContentArea';

afterEach( () => {
	vi.restoreAllMocks();
} );

// ---------------------------------------------------------------------------
// CodeBlock
// ---------------------------------------------------------------------------
describe( 'CodeBlock', () => {
	it( 'renders children inside a <pre> element', () => {
		const { container } = render(
			<CodeBlock rawCode="const x = 1;" children="const x = 1;" />
		);
		expect( container.querySelector( 'pre' ) ).not.toBeNull();
	} );

	it( 'renders the code string as content', () => {
		render( <CodeBlock rawCode="alert()" children="alert()" /> );
		expect( screen.getByText( 'alert()' ) ).toBeInTheDocument();
	} );

	it( 'does NOT render a language badge when no language prop is passed', () => {
		const { container } = render(
			<CodeBlock rawCode="x = 1" children="x = 1" />
		);
		expect( container.querySelector( '.dh-code-header' ) ).toBeNull();
	} );

	it( 'renders a language badge when language prop is supplied', () => {
		render( <CodeBlock language="typescript" rawCode="const x: number = 1;" children="const x: number = 1;" /> );
		expect( screen.getByText( 'typescript' ) ).toBeInTheDocument();
	} );

	it( 'adds language-{lang} class to the <code> element', () => {
		const { container } = render(
			<CodeBlock language="python" rawCode="print('hi')" children="print('hi')" />
		);
		expect( container.querySelector( '.language-python' ) ).not.toBeNull();
	} );

	it( 'renders a Copy button when language is supplied', () => {
		render(
			<CodeBlock language="js" rawCode="console.log(1)" children="console.log(1)" />
		);
		expect( screen.getByRole( 'button', { name: /copy/i } ) ).toBeInTheDocument();
	} );

	it( 'shows "Copied!" after the copy button is clicked (clipboard mock)', async () => {
		// Mock the clipboard API (not available in jsdom by default).
		const writeText = vi.fn().mockResolvedValue( undefined );
		Object.defineProperty( navigator, 'clipboard', {
			value:        { writeText },
			writable:     true,
			configurable: true,
		} );

		render(
			<CodeBlock language="js" rawCode="alert()" children="alert()" />
		);

		fireEvent.click( screen.getByRole( 'button', { name: /copy code/i } ) );

		// findByRole waits for the async clipboard promise → setCopied(true) → re-render.
		const copiedBtn = await screen.findByRole( 'button', { name: /copied!/i } );
		expect( copiedBtn ).toBeInTheDocument();
		expect( writeText ).toHaveBeenCalledWith( 'alert()' );
	} );
} );

// ---------------------------------------------------------------------------
// ContentArea
// ---------------------------------------------------------------------------
describe( 'ContentArea', () => {
	it( 'renders an <article> with the dh-content class', () => {
		const { container } = render( <ContentArea content="Hello" /> );
		expect( container.querySelector( 'article.dh-content' ) ).not.toBeNull();
	} );

	it( 'renders plain text content', () => {
		render( <ContentArea content="Hello world" /> );
		expect( screen.getByText( /Hello world/i ) ).toBeInTheDocument();
	} );

	it( 'renders markdown heading as an <h1>', () => {
		const { container } = render( <ContentArea content="# My Heading" /> );
		expect( container.querySelector( 'h1' ) ).not.toBeNull();
		expect( container.querySelector( 'h1' )?.textContent ).toContain( 'My Heading' );
	} );

	it( 'renders a markdown link', () => {
		render( <ContentArea content="[Click here](https://example.com)" /> );
		const link = screen.getByRole( 'link', { name: 'Click here' } );
		expect( link ).toBeInTheDocument();
	} );

	it( 'renders a GFM table', () => {
		const tableMarkdown = `
| Name | Value |
|------|-------|
| foo  | bar   |
`;
		const { container } = render( <ContentArea content={ tableMarkdown } /> );
		expect( container.querySelector( 'table' ) ).not.toBeNull();
	} );

	it( 'renders a fenced code block via CodeBlock', () => {
		const codeMarkdown = '```js\nconsole.log("hi")\n```';
		const { container } = render( <ContentArea content={ codeMarkdown } /> );
		// CodeBlock wraps its output in .dh-code-block.
		expect( container.querySelector( '.dh-code-block' ) ).not.toBeNull();
	} );

	it( 'opens remote links in a new tab when remoteUrl is supplied', () => {
		render(
			<ContentArea
				content="[External](https://other.com/page)"
				remoteUrl="https://github.com/owner/repo/blob/main/docs/page.md"
			/>
		);
		const link = screen.getByRole( 'link', { name: 'External' } );
		expect( link ).toHaveAttribute( 'target', '_blank' );
		expect( link ).toHaveAttribute( 'rel', 'noopener noreferrer' );
	} );

	it( 'rewrites a local .md link into a #/slug hash route', () => {
		const slugSet = new Set( [ 'features/chat' ] );
		render(
			<ContentArea
				content="[Chat](chat.md)"
				slugSet={ slugSet }
				pagePath="docs/features/chat.md"
			/>
		);
		const link = screen.getByRole( 'link', { name: 'Chat' } );
		expect( link ).toHaveAttribute( 'href', '#/features/chat' );
	} );

	it( 'preserves heading anchors when rewriting a local .md link', () => {
		const slugSet = new Set( [ 'features/chat' ] );
		render(
			<ContentArea
				content="[Chat setup](chat.md#setup)"
				slugSet={ slugSet }
				pagePath="docs/features/chat.md"
			/>
		);
		const link = screen.getByRole( 'link', { name: 'Chat setup' } );
		expect( link ).toHaveAttribute( 'href', '#/features/chat#setup' );
	} );

	it( 'resolves parent-relative local .md links against the page path', () => {
		const slugSet = new Set( [ 'reference/tools/tool-reference' ] );
		render(
			<ContentArea
				content="[Tools](../reference/tools/tool-reference.md)"
				slugSet={ slugSet }
				pagePath="docs/admin-guides/tools-manager.md"
			/>
		);
		const link = screen.getByRole( 'link', { name: 'Tools' } );
		expect( link ).toHaveAttribute( 'href', '#/reference/tools/tool-reference' );
	} );

	it( 'leaves links to unindexed files untouched', () => {
		const slugSet = new Set( [ 'features/chat' ] );
		render(
			<ContentArea
				content="[Internal](internal-notes.md)"
				slugSet={ slugSet }
				pagePath="docs/features/chat.md"
			/>
		);
		const link = screen.getByRole( 'link', { name: 'Internal' } );
		expect( link ).toHaveAttribute( 'href', 'internal-notes.md' );
	} );

	it( 'leaves absolute URLs untouched on local pages', () => {
		const slugSet = new Set( [ 'features/chat' ] );
		render(
			<ContentArea
				content="[Site](https://example.com/x.md)"
				slugSet={ slugSet }
				pagePath="docs/features/chat.md"
			/>
		);
		const link = screen.getByRole( 'link', { name: 'Site' } );
		expect( link ).toHaveAttribute( 'href', 'https://example.com/x.md' );
	} );
} );
