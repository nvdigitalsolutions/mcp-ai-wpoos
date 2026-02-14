"use strict";

// addons/pro/scripts/generate-excel.js
var fs = require("fs");
var ExcelJS = require("exceljs");
var [, , jsonFile, outputFile] = process.argv;
if (!jsonFile || !outputFile) {
  console.error("Usage: node generate-excel.js <json_file> <output_file>");
  process.exit(1);
}
async function generateExcel() {
  try {
    const data = JSON.parse(fs.readFileSync(jsonFile, "utf8"));
    const workbook = new ExcelJS.Workbook();
    workbook.creator = data.author || "WordPress MCP AI";
    workbook.created = /* @__PURE__ */ new Date();
    workbook.modified = /* @__PURE__ */ new Date();
    workbook.lastModifiedBy = data.author || "WordPress MCP AI";
    if (data.sheets && Array.isArray(data.sheets)) {
      data.sheets.forEach((sheetData) => {
        const worksheet = workbook.addWorksheet(sheetData.name || "Sheet");
        if (sheetData.data && Array.isArray(sheetData.data)) {
          worksheet.addRows(sheetData.data);
        }
        if (sheetData.columns && Array.isArray(sheetData.columns)) {
          worksheet.columns = sheetData.columns;
        }
        if (sheetData.has_header && worksheet.rowCount > 0) {
          const headerRow = worksheet.getRow(1);
          headerRow.font = { bold: true };
          headerRow.fill = {
            type: "pattern",
            pattern: "solid",
            fgColor: { argb: "FFD3D3D3" }
          };
        }
      });
    } else if (data.data && Array.isArray(data.data)) {
      const worksheet = workbook.addWorksheet(data.sheet_name || "Sheet1");
      worksheet.addRows(data.data);
      if (data.columns && Array.isArray(data.columns)) {
        worksheet.columns = data.columns;
      }
      if (data.has_header && worksheet.rowCount > 0) {
        const headerRow = worksheet.getRow(1);
        headerRow.font = { bold: true };
        headerRow.fill = {
          type: "pattern",
          pattern: "solid",
          fgColor: { argb: "FFD3D3D3" }
        };
      }
    }
    await workbook.xlsx.writeFile(outputFile);
    console.log("Excel document generated successfully");
    process.exit(0);
  } catch (error) {
    console.error("Error generating Excel document:", error.message);
    process.exit(1);
  }
}
generateExcel().catch((error) => {
  console.error("Unhandled error:", error.message);
  process.exit(1);
});
//# sourceMappingURL=generate-excel.bundle.js.map
