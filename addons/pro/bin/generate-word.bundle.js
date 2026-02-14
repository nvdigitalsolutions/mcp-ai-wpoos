"use strict";

// addons/pro/scripts/generate-word.js
var fs = require("fs");
var { Document, Packer, Paragraph, TextRun, HeadingLevel, AlignmentType, Table, TableCell, TableRow, WidthType } = require("docx");
var cheerio = require("cheerio");
var [, , jsonFile, outputFile] = process.argv;
if (!jsonFile || !outputFile) {
  console.error("Usage: node generate-word.js <json_file> <output_file>");
  process.exit(1);
}
function htmlToDocx(html) {
  const $ = cheerio.load(html, { xmlMode: false, decodeEntities: true });
  const children = [];
  const processNode = (node) => {
    const $node = $(node);
    const tagName = node.name;
    if (node.type === "text") {
      const text = $(node).text().trim();
      if (text) {
        return new TextRun({ text });
      }
      return null;
    }
    switch (tagName) {
      case "h1":
      case "h2":
      case "h3":
      case "h4":
      case "h5":
      case "h6":
        const level = parseInt(tagName.charAt(1));
        const headingText = $node.text().trim();
        if (headingText) {
          return new Paragraph({
            text: headingText,
            heading: HeadingLevel[`HEADING_${level}`],
            spacing: { before: 240, after: 120 }
          });
        }
        break;
      case "p":
        const textRuns = [];
        $node.contents().each((i, child) => {
          const run = processInlineElement(child, $);
          if (run) {
            if (Array.isArray(run)) {
              textRuns.push(...run);
            } else {
              textRuns.push(run);
            }
          }
        });
        if (textRuns.length > 0) {
          return new Paragraph({
            children: textRuns,
            spacing: { after: 200 }
          });
        }
        break;
      case "ul":
      case "ol":
        const items = [];
        $node.find("> li").each((i, li) => {
          const itemText = $(li).text().trim();
          if (itemText) {
            items.push(new Paragraph({
              text: itemText,
              bullet: { level: 0 },
              spacing: { after: 100 }
            }));
          }
        });
        return items;
      case "table":
        return processTable($node, $);
      case "br":
        return new Paragraph({ text: "" });
    }
    return null;
  };
  const processInlineElement = (node, $2) => {
    if (node.type === "text") {
      const text2 = $2(node).text();
      if (text2) {
        return new TextRun({ text: text2 });
      }
      return null;
    }
    const $node = $2(node);
    const tagName = node.name;
    const text = $node.text();
    if (!text.trim()) {
      return null;
    }
    const options = { text };
    switch (tagName) {
      case "strong":
      case "b":
        options.bold = true;
        break;
      case "em":
      case "i":
        options.italics = true;
        break;
      case "u":
        options.underline = {};
        break;
      case "a":
        options.underline = {};
        options.color = "0066CC";
        break;
      case "code":
        options.font = "Courier New";
        options.shading = { fill: "F5F5F5" };
        break;
    }
    return new TextRun(options);
  };
  const processTable = ($table, $2) => {
    const rows = [];
    $table.find("tr").each((i, tr) => {
      const cells = [];
      $2(tr).find("th, td").each((j, cell) => {
        const $cell = $2(cell);
        const isHeader = cell.name === "th";
        cells.push(new TableCell({
          children: [new Paragraph({
            text: $cell.text().trim(),
            bold: isHeader
          })],
          shading: isHeader ? { fill: "F2F2F2" } : void 0
        }));
      });
      if (cells.length > 0) {
        rows.push(new TableRow({ children: cells }));
      }
    });
    if (rows.length > 0) {
      return new Table({
        rows,
        width: { size: 100, type: WidthType.PERCENTAGE }
      });
    }
    return null;
  };
  $("body").contents().each((i, node) => {
    const result = processNode(node);
    if (result) {
      if (Array.isArray(result)) {
        children.push(...result);
      } else {
        children.push(result);
      }
    }
  });
  return children;
}
try {
  const data = JSON.parse(fs.readFileSync(jsonFile, "utf8"));
  const sections = [];
  const children = [];
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
  if (data.html_content) {
    const htmlElements = htmlToDocx(data.html_content);
    children.push(...htmlElements);
  } else if (data.sections && Array.isArray(data.sections)) {
    data.sections.forEach((section) => {
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
        const paragraphs = section.content.split("\n\n");
        paragraphs.forEach((text) => {
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
    const paragraphs = data.content.split("\n\n");
    paragraphs.forEach((text) => {
      if (text.trim()) {
        const textRun = new TextRun({
          text: text.trim(),
          bold: data.formatting && data.formatting.bold,
          italics: data.formatting && data.formatting.italic,
          size: data.formatting && data.formatting.font_size ? data.formatting.font_size * 2 : 24
          // Convert to half-points
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
  const doc = new Document({
    creator: data.author || "WordPress MCP AI",
    title: data.title || "Generated Document",
    description: data.description || "",
    sections
  });
  Packer.toBuffer(doc).then((buffer) => {
    fs.writeFileSync(outputFile, buffer);
    console.log("Word document generated successfully");
    process.exit(0);
  }).catch((error) => {
    console.error("Error generating Word document:", error.message);
    process.exit(1);
  });
} catch (error) {
  console.error("Error generating Word document:", error.message);
  process.exit(1);
}
//# sourceMappingURL=generate-word.bundle.js.map
