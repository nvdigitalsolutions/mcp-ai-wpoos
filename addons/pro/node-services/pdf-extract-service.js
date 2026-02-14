#!/usr/bin/env node
/**
 * PDF Text Extraction Service
 * 
 * Uses pdf-parse library to extract text content from PDF files.
 * Provides a pure Node.js solution for PDF text extraction.
 */
const fs = require('fs');
const pdfParse = require('pdf-parse');

/**
 * Extract text from PDF file.
 * 
 * @param {string} filePath - Path to PDF file
 * @param {object} options - Extraction options
 * @param {number} options.maxPages - Maximum number of pages to extract (0 = all)
 * @returns {Promise<object>} Extracted text and metadata
 */
async function extractPdfText(filePath, options = {}) {
	try {
		// Read PDF file
		const dataBuffer = fs.readFileSync(filePath);
		
		// Parse PDF with pdf-parse
		const pdfData = await pdfParse(dataBuffer, {
			max: options.maxPages || 0,
		});
		
		return {
			text: pdfData.text,
			pages: pdfData.numpages,
			info: pdfData.info,
			metadata: pdfData.metadata,
			version: pdfData.version,
		};
	} catch (error) {
		throw new Error(`PDF extraction failed: ${error.message}`);
	}
}

/**
 * Get PDF metadata without extracting text.
 * 
 * @param {string} filePath - Path to PDF file
 * @returns {Promise<object>} PDF metadata
 */
async function getPdfMetadata(filePath) {
	try {
		const dataBuffer = fs.readFileSync(filePath);
		const pdfData = await pdfParse(dataBuffer, {
			max: 0, // Don't extract text, just metadata
		});
		
		return {
			pages: pdfData.numpages,
			info: pdfData.info,
			metadata: pdfData.metadata,
			version: pdfData.version,
		};
	} catch (error) {
		throw new Error(`Failed to get PDF metadata: ${error.message}`);
	}
}

// CLI interface
if (require.main === module) {
	const action = process.argv[2];
	const dataJson = process.argv[3];
	
	if (!action || !dataJson) {
		console.error('Usage: node pdf-extract-service.js <action> <json-data>');
		console.error('Actions: extract, metadata');
		console.error('Example: node pdf-extract-service.js extract \'{"filePath":"/path/to/file.pdf","maxPages":10}\'');
		process.exit(1);
	}
	
	(async () => {
		try {
			const data = JSON.parse(dataJson);
			
			switch (action) {
				case 'extract':
					if (!data.filePath) {
						throw new Error('filePath is required');
					}
					const result = await extractPdfText(data.filePath, {
						maxPages: data.maxPages || 0,
					});
					console.log(JSON.stringify(result));
					break;
					
				case 'metadata':
					if (!data.filePath) {
						throw new Error('filePath is required');
					}
					const metadata = await getPdfMetadata(data.filePath);
					console.log(JSON.stringify(metadata));
					break;
					
				default:
					console.error(JSON.stringify({ error: `Unknown action: ${action}` }));
					process.exit(1);
			}
		} catch (error) {
			console.error(JSON.stringify({ error: error.message }));
			process.exit(1);
		}
	})();
}

module.exports = { extractPdfText, getPdfMetadata };
