"use strict";

// addons/pro/scripts/generate-pdf.js
var fs = require("fs");
var PDFDocument = require("pdfkit");
var cheerio = require("cheerio");
var [, , jsonFile, outputFile] = process.argv;
if (!jsonFile || !outputFile) {
  console.error("Usage: node generate-pdf.js <json_file> <output_file>");
  process.exit(1);
}
function htmlToPdf(html, doc) {
  const $ = cheerio.load(html, { xmlMode: false, decodeEntities: true });
  const processNode = (node, parent = "body") => {
    const $node = $(node);
    const tagName = node.name;
    if (node.type === "text") {
      const text = $(node).text();
      if (text.trim() && parent !== "p") {
        doc.fontSize(12).font("Helvetica").text(text, {
          continued: true
        });
      }
      return;
    }
    switch (tagName) {
      case "h1":
        doc.fontSize(24).font("Helvetica-Bold").text($node.text().trim());
        doc.moveDown(0.5);
        break;
      case "h2":
        doc.fontSize(20).font("Helvetica-Bold").text($node.text().trim());
        doc.moveDown(0.5);
        break;
      case "h3":
        doc.fontSize(18).font("Helvetica-Bold").text($node.text().trim());
        doc.moveDown(0.5);
        break;
      case "h4":
        doc.fontSize(16).font("Helvetica-Bold").text($node.text().trim());
        doc.moveDown(0.5);
        break;
      case "h5":
        doc.fontSize(14).font("Helvetica-Bold").text($node.text().trim());
        doc.moveDown(0.5);
        break;
      case "h6":
        doc.fontSize(12).font("Helvetica-Bold").text($node.text().trim());
        doc.moveDown(0.5);
        break;
      case "p":
        const text = $node.text().trim();
        if (text) {
          if ($node.find("strong, b, em, i, u").length > 0) {
            processInlineFormatting($node, doc);
          } else {
            doc.fontSize(12).font("Helvetica").text(text, {
              align: "justify"
            });
          }
          doc.moveDown(1);
        }
        break;
      case "ul":
        $node.find("> li").each((i, li) => {
          const itemText = $(li).text().trim();
          if (itemText) {
            doc.fontSize(12).font("Helvetica").text("\u2022 " + itemText, {
              indent: 20,
              align: "left"
            });
            doc.moveDown(0.3);
          }
        });
        doc.moveDown(0.7);
        break;
      case "ol":
        $node.find("> li").each((i, li) => {
          const itemText = $(li).text().trim();
          if (itemText) {
            doc.fontSize(12).font("Helvetica").text(i + 1 + ". " + itemText, {
              indent: 20,
              align: "left"
            });
            doc.moveDown(0.3);
          }
        });
        doc.moveDown(0.7);
        break;
      case "table":
        processTable($node, doc, $);
        doc.moveDown(1);
        break;
      case "blockquote":
        const quoteText = $node.text().trim();
        if (quoteText) {
          doc.fontSize(11).font("Helvetica-Oblique").fillColor("#666666").text(quoteText, {
            indent: 30,
            align: "justify"
          }).fillColor("#333333");
          doc.moveDown(1);
        }
        break;
      case "code":
        const codeText = $node.text().trim();
        if (codeText) {
          doc.fontSize(10).font("Courier").fillColor("#000000").rect(doc.x, doc.y, doc.page.width - doc.x - 50, 20).fillAndStroke("#F5F5F5", "#DDDDDD").fillColor("#000000").text(codeText, {
            indent: 5
          });
          doc.moveDown(0.5);
        }
        break;
      case "pre":
        const preText = $node.text().trim();
        if (preText) {
          doc.fontSize(10).font("Courier").fillColor("#000000").text(preText, {
            align: "left"
          });
          doc.moveDown(1);
        }
        break;
      case "br":
        doc.moveDown(0.5);
        break;
    }
  };
  const processInlineFormatting = ($node, doc2) => {
    $node.contents().each((i, child) => {
      if (child.type === "text") {
        doc2.fontSize(12).font("Helvetica").text($(child).text(), { continued: true });
      } else {
        const $child = $(child);
        const tagName = child.name;
        const text = $child.text();
        switch (tagName) {
          case "strong":
          case "b":
            doc2.font("Helvetica-Bold").text(text, { continued: true });
            doc2.font("Helvetica");
            break;
          case "em":
          case "i":
            doc2.font("Helvetica-Oblique").text(text, { continued: true });
            doc2.font("Helvetica");
            break;
          case "u":
            doc2.underline().text(text, { continued: true });
            doc2.underline(false, {});
            break;
          case "a":
            doc2.fillColor("#0066CC").underline().text(text, { continued: true, link: $child.attr("href") || "" }).underline(false, {}).fillColor("#333333");
            break;
          case "code":
            doc2.font("Courier").text(text, { continued: true });
            doc2.font("Helvetica");
            break;
          default:
            doc2.text(text, { continued: true });
            break;
        }
      }
    });
    doc2.text("");
  };
  const processTable = ($table, doc2, $2) => {
    const tableData = [];
    let hasHeaders = false;
    $table.find("tr").each((i, tr) => {
      const row = [];
      $2(tr).find("th, td").each((j, cell) => {
        const $cell = $2(cell);
        row.push({
          text: $cell.text().trim(),
          isHeader: cell.name === "th"
        });
        if (cell.name === "th") hasHeaders = true;
      });
      if (row.length > 0) {
        tableData.push(row);
      }
    });
    if (tableData.length === 0) return;
    const numCols = tableData[0].length;
    const tableWidth = doc2.page.width - doc2.x - 50;
    const colWidth = tableWidth / numCols;
    let currentY = doc2.y;
    const cellPadding = 5;
    const rowHeight = 25;
    tableData.forEach((row, rowIndex) => {
      let currentX = doc2.x;
      row.forEach((cell, cellIndex) => {
        doc2.rect(currentX, currentY, colWidth, rowHeight).stroke();
        if (cell.isHeader) {
          doc2.rect(currentX, currentY, colWidth, rowHeight).fillAndStroke("#F2F2F2", "#DDDDDD");
        }
        doc2.fontSize(10).font(cell.isHeader ? "Helvetica-Bold" : "Helvetica").fillColor("#000000").text(cell.text, currentX + cellPadding, currentY + cellPadding, {
          width: colWidth - cellPadding * 2,
          height: rowHeight - cellPadding * 2,
          align: "left"
        });
        currentX += colWidth;
      });
      currentY += rowHeight;
      doc2.y = currentY;
    });
  };
  $("body").contents().each((i, node) => {
    processNode(node);
  });
}
try {
  const data = JSON.parse(fs.readFileSync(jsonFile, "utf8"));
  const doc = new PDFDocument({
    size: data.page_size || "A4",
    layout: data.orientation || "portrait",
    margin: 50
  });
  const stream = fs.createWriteStream(outputFile);
  doc.pipe(stream);
  if (data.title) {
    doc.info.Title = data.title;
  }
  if (data.author) {
    doc.info.Author = data.author;
  }
  if (data.title) {
    doc.fontSize(24).font("Helvetica-Bold").text(data.title, {
      align: "center"
    });
    doc.moveDown(2);
  }
  if (data.html_content) {
    htmlToPdf(data.html_content, doc);
  } else if (data.sections && Array.isArray(data.sections)) {
    data.sections.forEach((section) => {
      if (section.heading) {
        doc.fontSize(18).font("Helvetica-Bold").text(section.heading);
        doc.moveDown(0.5);
      }
      if (section.content) {
        doc.fontSize(12).font("Helvetica").text(section.content, {
          align: "justify"
        });
        doc.moveDown(1);
      }
    });
  } else if (data.content) {
    const fontSize = data.formatting && data.formatting.font_size || 12;
    const font = data.formatting && data.formatting.font_family || "Helvetica";
    doc.fontSize(fontSize).font(font).text(data.content, {
      align: "justify"
    });
  }
  doc.end();
  stream.on("finish", () => {
    console.log("PDF generated successfully");
    process.exit(0);
  });
  stream.on("error", (error) => {
    console.error("Error writing PDF file:", error.message);
    process.exit(1);
  });
} catch (error) {
  console.error("Error generating PDF:", error.message);
  process.exit(1);
}
//# sourceMappingURL=generate-pdf.bundle.js.map
