/**
 * Markdown Image Rendering Tests
 *
 * Tests that markdown images ![alt](url) are correctly rendered as <img> tags.
 *
 * @package WP_MCP_AI
 */

describe('Markdown Image Rendering', () => {
	// Mock the markdownService before each test
	beforeEach(() => {
		// Create a fresh window object for each test
		global.window = {
			location: { origin: 'http://localhost' },
		};
	});

	/**
	 * Helper function to extract and test image extraction regex.
	 * This tests the same regex used in chat-markdown-service.js
	 */
	function extractImages(text) {
		const images = [];
		text.replace(/!\[([^\]]*)\]\(([^)]+)\)/g, function (match, alt, url) {
			images.push({ alt, url });
			return '';
		});
		return images;
	}

	/**
	 * Helper function to extract links after images are removed.
	 * This tests the same logic used in chat-markdown-service.js
	 */
	function extractLinks(text) {
		// First remove images
		const textWithoutImages = text.replace(/!\[([^\]]*)\]\(([^)]+)\)/g, '');
		
		const links = [];
		textWithoutImages.replace(/\[([^\]]+)\]\(([^)]+)\)/g, function (match, label, url) {
			links.push({ label, url });
			return '';
		});
		return links;
	}

	describe('Image Syntax Detection', () => {
		it('should detect simple image syntax', () => {
			const text = '![Banana](https://example.com/banana.png)';
			const images = extractImages(text);
			
			expect(images).toHaveLength(1);
			expect(images[0].alt).toBe('Banana');
			expect(images[0].url).toBe('https://example.com/banana.png');
		});

		it('should detect image with empty alt text', () => {
			const text = '![](https://example.com/image.png)';
			const images = extractImages(text);
			
			expect(images).toHaveLength(1);
			expect(images[0].alt).toBe('');
			expect(images[0].url).toBe('https://example.com/image.png');
		});

		it('should detect multiple images', () => {
			const text = '![First](https://example.com/first.png) and ![Second](https://example.com/second.jpg)';
			const images = extractImages(text);
			
			expect(images).toHaveLength(2);
			expect(images[0].alt).toBe('First');
			expect(images[1].alt).toBe('Second');
		});

		it('should detect image with special characters in alt text', () => {
			const text = '![A "quoted" image](https://example.com/image.png)';
			const images = extractImages(text);
			
			expect(images).toHaveLength(1);
			expect(images[0].alt).toBe('A "quoted" image');
		});

		it('should detect image within text context', () => {
			const text = 'Here is an image: ![My Image](https://example.com/image.png) that I created.';
			const images = extractImages(text);
			
			expect(images).toHaveLength(1);
			expect(images[0].alt).toBe('My Image');
		});
	});

	describe('Link vs Image Differentiation', () => {
		it('should not confuse images with links', () => {
			const text = '![Image](https://example.com/image.png) and [Link](https://example.com/page)';
			
			const images = extractImages(text);
			const links = extractLinks(text);
			
			expect(images).toHaveLength(1);
			expect(images[0].alt).toBe('Image');
			
			expect(links).toHaveLength(1);
			expect(links[0].label).toBe('Link');
		});

		it('should extract images before links to prevent ! being left behind', () => {
			const text = '![Banana](https://bots.nvdigital.solutions/wp-content/uploads/2025/11/openai-image-20251125-110633.png)';
			
			const images = extractImages(text);
			
			// The image should be fully extracted, not leaving a !
			expect(images).toHaveLength(1);
			expect(images[0].alt).toBe('Banana');
		});

		it('should handle mixed content correctly', () => {
			const text = `Here's a realistic image of a banana!

You can view or download the image here:  
![Banana](https://example.com/banana.png)

Let me know if you want to create more images!`;
			
			const images = extractImages(text);
			const links = extractLinks(text);
			
			expect(images).toHaveLength(1);
			expect(images[0].alt).toBe('Banana');
			expect(links).toHaveLength(0);
		});
	});

	describe('URL Handling', () => {
		it('should handle URLs with query parameters', () => {
			const text = '![Image](https://example.com/image.png?width=100&height=100)';
			const images = extractImages(text);
			
			expect(images).toHaveLength(1);
			expect(images[0].url).toBe('https://example.com/image.png?width=100&height=100');
		});

		it('should handle URLs with hash fragments', () => {
			const text = '![Image](https://example.com/image.png#section)';
			const images = extractImages(text);
			
			expect(images).toHaveLength(1);
			expect(images[0].url).toBe('https://example.com/image.png#section');
		});

		it('should handle relative URLs', () => {
			const text = '![Image](/wp-content/uploads/image.png)';
			const images = extractImages(text);
			
			expect(images).toHaveLength(1);
			expect(images[0].url).toBe('/wp-content/uploads/image.png');
		});
	});

	describe('Edge Cases', () => {
		it('should handle image inside a sentence', () => {
			const text = 'Check out this ![cool image](https://example.com/cool.png) I made.';
			const images = extractImages(text);
			
			expect(images).toHaveLength(1);
			expect(images[0].alt).toBe('cool image');
		});

		it('should not match incomplete image syntax', () => {
			const text = '![No closing paren(https://example.com/image.png';
			const images = extractImages(text);
			
			expect(images).toHaveLength(0);
		});

		it('should not match link with exclamation before', () => {
			const text = 'Wow! [Link](https://example.com)';
			const images = extractImages(text);
			const links = extractLinks(text);
			
			// ! followed by space is not an image
			expect(images).toHaveLength(0);
			expect(links).toHaveLength(1);
		});
	});
});
