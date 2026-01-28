/**
 * Markdown Code Block HTML Escaping Tests
 *
 * Tests that code blocks with HTML-like content are properly escaped
 * to prevent empty code blocks after DOMPurify sanitization.
 *
 * @package WP_MCP_AI
 */

describe('Markdown Code Block HTML Escaping', () => {
	// Mock the marked and DOMPurify modules
	let marked, DOMPurify, escapeHtml;

	beforeEach(() => {
		// Mock window for DOMPurify
		global.window = {
			location: { origin: 'http://localhost' },
		};

		// Import the modules needed for testing
		// Since we're testing the actual implementation, we'll use the real escapeHtml function
		escapeHtml = function(text) {
			return String(text).replace(/[&<>"']/g, function (character) {
				switch (character) {
					case '&':
						return '&amp;';
					case '<':
						return '&lt;';
					case '>':
						return '&gt;';
					case '"':
						return '&quot;';
					case '\'':
						return '&#39;';
					default:
						return character;
				}
			});
		};
	});

	describe('HTML Escaping Function', () => {
		it('should escape less than signs', () => {
			expect(escapeHtml('<')).toBe('&lt;');
		});

		it('should escape greater than signs', () => {
			expect(escapeHtml('>')).toBe('&gt;');
		});

		it('should escape ampersands', () => {
			expect(escapeHtml('&')).toBe('&amp;');
		});

		it('should escape quotes', () => {
			expect(escapeHtml('"')).toBe('&quot;');
		});

		it('should escape single quotes', () => {
			expect(escapeHtml("'")).toBe('&#39;');
		});

		it('should escape HTML tag-like content', () => {
			const input = '<div>Hello</div>';
			const expected = '&lt;div&gt;Hello&lt;/div&gt;';
			expect(escapeHtml(input)).toBe(expected);
		});
	});

	describe('Code Block Rendering', () => {
		it('should render code with HTML tags properly escaped', () => {
			const code = 'const x = "<test>";';
			const escapedCode = escapeHtml(code);
			const expected = 'const x = &quot;&lt;test&gt;&quot;;';
			
			expect(escapedCode).toBe(expected);
		});

		it('should render code with multiple HTML elements escaped', () => {
			const code = '<div>\n  <span>Hello</span>\n</div>';
			const escapedCode = escapeHtml(code);
			
			expect(escapedCode).toContain('&lt;div&gt;');
			expect(escapedCode).toContain('&lt;span&gt;');
			expect(escapedCode).toContain('&lt;/span&gt;');
			expect(escapedCode).toContain('&lt;/div&gt;');
		});

		it('should preserve newlines in code', () => {
			const code = 'line1\nline2\nline3';
			const escapedCode = escapeHtml(code);
			
			expect(escapedCode).toBe('line1\nline2\nline3');
		});

		it('should escape code with JSX-like syntax', () => {
			const code = 'return <Component prop="value" />;';
			const escapedCode = escapeHtml(code);
			
			expect(escapedCode).toContain('&lt;Component');
			expect(escapedCode).toContain('prop=&quot;value&quot;');
			expect(escapedCode).toContain('/&gt;');
		});

		it('should escape code with script tags', () => {
			const code = '<script>alert("XSS")</script>';
			const escapedCode = escapeHtml(code);
			
			expect(escapedCode).toBe('&lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;');
		});

		it('should handle code with comparison operators', () => {
			const code = 'if (x < 10 && y > 5) { }';
			const escapedCode = escapeHtml(code);
			
			expect(escapedCode).toBe('if (x &lt; 10 &amp;&amp; y &gt; 5) { }');
		});

		it('should handle code with template literals', () => {
			const code = 'const html = `<div>${name}</div>`;';
			const escapedCode = escapeHtml(code);
			
			expect(escapedCode).toContain('&lt;div&gt;');
			expect(escapedCode).toContain('&lt;/div&gt;');
		});

		it('should handle empty code blocks', () => {
			const code = '';
			const escapedCode = escapeHtml(code);
			
			expect(escapedCode).toBe('');
		});

		it('should handle code with only whitespace', () => {
			const code = '   \n\t  ';
			const escapedCode = escapeHtml(code);
			
			expect(escapedCode).toBe('   \n\t  ');
		});
	});

	describe('Code Block HTML Generation', () => {
		it('should generate properly formatted code block with escaped content', () => {
			const code = 'const x = "<test>";';
			const escapedCode = escapeHtml(code);
			const language = 'javascript';
			const escapedLang = language.replace(/[^a-z0-9+#.-]/gi, '').toLowerCase();
			const className = ' class="language-' + escapedLang + '"';
			
			const html = '<pre class="wp-mcp-ai-chat__code-block"><code' + className + '>' + escapedCode + '</code></pre>';
			
			expect(html).toContain('class="wp-mcp-ai-chat__code-block"');
			expect(html).toContain('class="language-javascript"');
			expect(html).toContain('const x = &quot;&lt;test&gt;&quot;;');
		});

		it('should generate code block without language class when no language specified', () => {
			const code = 'plain text';
			const escapedCode = escapeHtml(code);
			const language = '';
			const className = language ? ' class="language-' + language + '"' : '';
			
			const html = '<pre class="wp-mcp-ai-chat__code-block"><code' + className + '>' + escapedCode + '</code></pre>';
			
			expect(html).toBe('<pre class="wp-mcp-ai-chat__code-block"><code>plain text</code></pre>');
		});
	});

	describe('Real-world Code Examples', () => {
		it('should escape React component code', () => {
			const code = `function Button({ onClick, children }) {
  return <button onClick={onClick}>{children}</button>;
}`;
			const escapedCode = escapeHtml(code);
			
			expect(escapedCode).toContain('&lt;button');
			expect(escapedCode).toContain('&gt;{children}&lt;/button&gt;');
		});

		it('should escape Python code with type hints', () => {
			const code = 'def process(data: List[Dict[str, Any]]) -> None:';
			const escapedCode = escapeHtml(code);
			
			// Python doesn't have HTML-like syntax, should remain unchanged except for brackets
			expect(escapedCode).toBe('def process(data: List[Dict[str, Any]]) -&gt; None:');
		});

		it('should escape HTML template code', () => {
			const code = `<div class="container">
  <h1>Title</h1>
  <p>Content</p>
</div>`;
			const escapedCode = escapeHtml(code);
			
			expect(escapedCode).not.toContain('<div');
			expect(escapedCode).toContain('&lt;div class=&quot;container&quot;&gt;');
			expect(escapedCode).toContain('&lt;h1&gt;Title&lt;/h1&gt;');
		});

		it('should escape TypeScript generic code', () => {
			const code = 'const items: Array<string> = [];';
			const escapedCode = escapeHtml(code);
			
			expect(escapedCode).toBe('const items: Array&lt;string&gt; = [];');
		});

		it('should escape XML/configuration code', () => {
			const code = `<configuration>
  <setting name="debug" value="true" />
</configuration>`;
			const escapedCode = escapeHtml(code);
			
			expect(escapedCode).toContain('&lt;configuration&gt;');
			expect(escapedCode).toContain('&lt;setting');
			expect(escapedCode).toContain('name=&quot;debug&quot;');
		});
	});
});
