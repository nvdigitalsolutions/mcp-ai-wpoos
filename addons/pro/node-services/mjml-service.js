#!/usr/bin/env node
/**
 * MJML Email Template Service
 */
const mjml2html = require('mjml');

function compileMJML(mjml, options = {}) {
    try {
        const result = mjml2html(mjml, {
            minify: options.minify || false,
            beautify: options.beautify !== false,
            validationLevel: options.validationLevel || 'soft',
        });
        
        if (result.errors && result.errors.length > 0) {
            const errors = result.errors.map(e => `${e.line}:${e.message}`).join('; ');
            throw new Error(`MJML errors: ${errors}`);
        }
        
        return result.html;
    } catch (error) {
        throw new Error(`MJML compilation failed: ${error.message}`);
    }
}

function validateMJML(mjml) {
    try {
        const result = mjml2html(mjml, { validationLevel: 'strict' });
        return {
            valid: result.errors.length === 0,
            errors: result.errors,
            warnings: result.warnings || [],
        };
    } catch (error) {
        return {
            valid: false,
            errors: [{ message: error.message }],
            warnings: [],
        };
    }
}

if (require.main === module) {
    const action = process.argv[2];
    const dataJson = process.argv[3];
    
    if (!action || !dataJson) {
        console.error('Usage: node mjml-service.js <action> <json-data>');
        process.exit(1);
    }
    
    try {
        const data = JSON.parse(dataJson);
        
        switch (action) {
            case 'compile':
                console.log(compileMJML(data.mjml, data.options));
                break;
            case 'validate':
                console.log(JSON.stringify(validateMJML(data.mjml)));
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

module.exports = { compileMJML, validateMJML };
