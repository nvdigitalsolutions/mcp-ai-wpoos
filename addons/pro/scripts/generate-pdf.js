/**
 * PDF Generation Script
 * 
 * Standalone Node.js script for generating PDF documents using pdfkit.
 * This script is bundled with its dependencies to avoid requiring node_modules.
 * 
 * Usage: node generate-pdf.js <json_file> <output_file>
 * 
 * @package WP_MCP_AI
 * @since 1.1.0
 */

const fs = require('fs');
const PDFDocument = require('pdfkit');

const [, , jsonFile, outputFile] = process.argv;

if (!jsonFile || !outputFile) {
	console.error('Usage: node generate-pdf.js <json_file> <output_file>');
	process.exit(1);
}

try {
	const data = JSON.parse(fs.readFileSync(jsonFile, 'utf8'));
	const doc = new PDFDocument({
		size: data.page_size || 'A4',
		layout: data.orientation || 'portrait',
		margin: 50
	});

	doc.pipe(fs.createWriteStream(outputFile));

	// Set document metadata.
	if (data.title) {
		doc.info.Title = data.title;
	}
	if (data.author) {
		doc.info.Author = data.author;
	}

	// Add title.
	if (data.title) {
		doc.fontSize(24).font('Helvetica-Bold').text(data.title, {
			align: 'center'
		});
		doc.moveDown(2);
	}

	// Handle different content types.
	if (data.sections && Array.isArray(data.sections)) {
		// Structured document with sections.
		data.sections.forEach(section => {
			if (section.heading) {
				doc.fontSize(18).font('Helvetica-Bold').text(section.heading);
				doc.moveDown(0.5);
			}
			if (section.content) {
				doc.fontSize(12).font('Helvetica').text(section.content, {
					align: 'justify'
				});
				doc.moveDown(1);
			}
		});
	} else if (data.content) {
		// Simple content.
		const fontSize = (data.formatting && data.formatting.font_size) || 12;
		const font = (data.formatting && data.formatting.font_family) || 'Helvetica';

		doc.fontSize(fontSize).font(font).text(data.content, {
			align: 'justify'
		});
	}

	doc.end();
	console.log('PDF generated successfully');
	process.exit(0);
} catch (error) {
	console.error('Error generating PDF:', error.message);
	process.exit(1);
}
