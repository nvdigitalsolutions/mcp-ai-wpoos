/**
 * Excel Document Generation Script
 * 
 * Standalone Node.js script for generating Excel (.xlsx) documents using exceljs.
 * This script is bundled with its dependencies to avoid requiring node_modules.
 * 
 * Usage: node generate-excel.js <json_file> <output_file>
 * 
 * @package WP_MCP_AI
 * @since 1.1.0
 */

const fs = require('fs');
const ExcelJS = require('exceljs');

const [, , jsonFile, outputFile] = process.argv;

if (!jsonFile || !outputFile) {
	console.error('Usage: node generate-excel.js <json_file> <output_file>');
	process.exit(1);
}

async function generateExcel() {
	try {
		const data = JSON.parse(fs.readFileSync(jsonFile, 'utf8'));
		const workbook = new ExcelJS.Workbook();
		
		// Set workbook properties.
		workbook.creator = data.author || 'WordPress MCP AI';
		workbook.created = new Date();
		workbook.modified = new Date();
		workbook.lastModifiedBy = data.author || 'WordPress MCP AI';
		
		// Handle different data structures.
		if (data.sheets && Array.isArray(data.sheets)) {
			// Multi-sheet workbook.
			data.sheets.forEach(sheetData => {
				const worksheet = workbook.addWorksheet(sheetData.name || 'Sheet');
				if (sheetData.data && Array.isArray(sheetData.data)) {
					worksheet.addRows(sheetData.data);
				}
				
				// Apply column widths if specified.
				if (sheetData.columns && Array.isArray(sheetData.columns)) {
					worksheet.columns = sheetData.columns;
				}
				
				// Style header row if present.
				if (sheetData.has_header && worksheet.rowCount > 0) {
					const headerRow = worksheet.getRow(1);
					headerRow.font = { bold: true };
					headerRow.fill = {
						type: 'pattern',
						pattern: 'solid',
						fgColor: { argb: 'FFD3D3D3' }
					};
				}
			});
		} else if (data.data && Array.isArray(data.data)) {
			// Single sheet workbook.
			const worksheet = workbook.addWorksheet(data.sheet_name || 'Sheet1');
			worksheet.addRows(data.data);
			
			// Apply column widths if specified.
			if (data.columns && Array.isArray(data.columns)) {
				worksheet.columns = data.columns;
			}
			
			// Style header row if present.
			if (data.has_header && worksheet.rowCount > 0) {
				const headerRow = worksheet.getRow(1);
				headerRow.font = { bold: true };
				headerRow.fill = {
					type: 'pattern',
					pattern: 'solid',
					fgColor: { argb: 'FFD3D3D3' }
				};
			}
		}
		
		// Write to file.
		await workbook.xlsx.writeFile(outputFile);
		console.log('Excel document generated successfully');
		process.exit(0);
	} catch (error) {
		console.error('Error generating Excel document:', error.message);
		process.exit(1);
	}
}

generateExcel().catch(error => {
	console.error('Unhandled error:', error.message);
	process.exit(1);
});
