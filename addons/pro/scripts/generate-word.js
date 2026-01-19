/**
 * Word Document Generation Script
 * 
 * Standalone Node.js script for generating Word (.docx) documents using docx.
 * This script is bundled with its dependencies to avoid requiring node_modules.
 * 
 * Usage: node generate-word.js <json_file> <output_file>
 * 
 * @package WP_MCP_AI
 * @since 1.1.0
 */

const fs = require('fs');
const { Document, Packer, Paragraph, TextRun, HeadingLevel, AlignmentType } = require('docx');

const [, , jsonFile, outputFile] = process.argv;

if (!jsonFile || !outputFile) {
	console.error('Usage: node generate-word.js <json_file> <output_file>');
	process.exit(1);
}

try {
	const data = JSON.parse(fs.readFileSync(jsonFile, 'utf8'));
	
	// Create document sections.
	const sections = [];
	const children = [];

	// Add title if present.
	if (data.title) {
		children.push(
			new Paragraph({
				text: data.title,
				heading: HeadingLevel.TITLE,
				alignment: AlignmentType.CENTER,
				spacing: { after: 400 }
			})
		);
	}

	// Handle different content types.
	if (data.sections && Array.isArray(data.sections)) {
		// Structured document with sections.
		data.sections.forEach(section => {
			if (section.heading) {
				const headingLevel = section.level || 1;
				children.push(
					new Paragraph({
						text: section.heading,
						heading: HeadingLevel[`HEADING_${headingLevel}`] || HeadingLevel.HEADING_1,
						spacing: { before: 240, after: 120 }
					})
				);
			}
			
			if (section.content) {
				// Split content into paragraphs.
				const paragraphs = section.content.split('\n\n');
				paragraphs.forEach(text => {
					if (text.trim()) {
						children.push(
							new Paragraph({
								children: [new TextRun(text.trim())],
								spacing: { after: 200 }
							})
						);
					}
				});
			}
		});
	} else if (data.content) {
		// Simple content - split into paragraphs.
		const paragraphs = data.content.split('\n\n');
		paragraphs.forEach(text => {
			if (text.trim()) {
				const textRun = new TextRun({
					text: text.trim(),
					bold: data.formatting && data.formatting.bold,
					italics: data.formatting && data.formatting.italic,
					size: data.formatting && data.formatting.font_size ? data.formatting.font_size * 2 : 24 // Convert to half-points
				});
				
				children.push(
					new Paragraph({
						children: [textRun],
						spacing: { after: 200 }
					})
				);
			}
		});
	}

	sections.push({ children });

	// Create document.
	const doc = new Document({
		creator: data.author || 'WordPress MCP AI',
		title: data.title || 'Generated Document',
		description: data.description || '',
		sections
	});

	// Write to file.
	Packer.toBuffer(doc).then(buffer => {
		fs.writeFileSync(outputFile, buffer);
		console.log('Word document generated successfully');
		process.exit(0);
	}).catch(error => {
		console.error('Error generating Word document:', error.message);
		process.exit(1);
	});

} catch (error) {
	console.error('Error generating Word document:', error.message);
	process.exit(1);
}
