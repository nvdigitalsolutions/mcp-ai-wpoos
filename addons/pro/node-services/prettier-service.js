#!/usr/bin/env node
/**
 * Prettier Code Formatting Service
 * 
 * This Node.js script provides code formatting functionality using Prettier
 * for the WordPress MCP AI plugin.
 * 
 * Usage:
 *   node prettier-service.js format '{"code":"...","options":{...}}'
 *   node prettier-service.js check '{"code":"...","parser":"babel"}'
 * 
 * @package WP_MCP_AI
 * @since 1.1.0
 */

const prettier = require('prettier');

/**
 * Format code using Prettier
 * 
 * @param {string} code - Code to format
 * @param {object} options - Prettier options
 * @returns {string} Formatted code
 */
function formatCode(code, options = {}) {
    try {
        const defaultOptions = {
            parser: 'babel',
            printWidth: 80,
            tabWidth: 2,
            useTabs: true,
            semi: true,
            singleQuote: true,
            trailingComma: 'es5',
            bracketSpacing: true,
            arrowParens: 'always',
        };
        
        const mergedOptions = { ...defaultOptions, ...options };
        
        return prettier.format(code, mergedOptions);
    } catch (error) {
        throw new Error(`Prettier formatting failed: ${error.message}`);
    }
}

/**
 * Check code syntax using Prettier
 * 
 * @param {string} code - Code to check
 * @param {string} parser - Parser to use
 * @returns {boolean} True if valid
 */
function checkSyntax(code, parser = 'babel') {
    try {
        prettier.format(code, { parser });
        return true;
    } catch (error) {
        throw new Error(`Syntax error: ${error.message}`);
    }
}

/**
 * Main execution
 */
if (require.main === module) {
    const action = process.argv[2];
    const dataJson = process.argv[3];
    
    if (!action || !dataJson) {
        console.error('Usage: node prettier-service.js <action> <json-data>');
        console.error('Actions: format, check');
        process.exit(1);
    }
    
    try {
        const data = JSON.parse(dataJson);
        
        switch (action) {
            case 'format':
                const formatted = formatCode(data.code, data.options);
                console.log(formatted);
                break;
                
            case 'check':
                const valid = checkSyntax(data.code, data.parser);
                console.log(JSON.stringify({ valid }));
                break;
                
            default:
                console.error(`Unknown action: ${action}`);
                process.exit(1);
        }
    } catch (error) {
        console.error(JSON.stringify({ error: error.message }));
        process.exit(1);
    }
}

module.exports = { formatCode, checkSyntax };
