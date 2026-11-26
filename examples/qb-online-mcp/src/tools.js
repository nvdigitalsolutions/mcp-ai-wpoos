// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * Tool registrations for the QuickBooks Online MCP server.
 *
 * Tool names follow Intuit's reference-server verb prefixes (get_/search_/
 * read_/create_/update_/delete_) so NV oOS's connection-level tool-category
 * switches (QUICKBOOKS_DISABLE_WRITE/UPDATE/DELETE) suppress the right
 * families. In particular `delete_invoice` defaults to **void** — the
 * accounting-safe operation — and hard deletion must be asked for explicitly,
 * so the delete switch governs both.
 */

import { z } from 'zod';
import {
	buildBillPayload,
	buildEstimatePayload,
	buildInvoicePayload,
	buildInvoiceUpdatePayload,
	buildOperationBody,
	buildPaymentPayload,
} from './payloads.js';
import { ACCOUNT_TYPES, ITEM_TYPES, buildEntitySearchQuery } from './query.js';

const DATE = z
	.string()
	.regex( /^\d{4}-\d{2}-\d{2}$/, 'Use YYYY-MM-DD.' )
	.describe( 'Date in YYYY-MM-DD format.' );

const ID = z
	.string()
	.regex( /^[A-Za-z0-9_-]+$/, 'QuickBooks IDs are alphanumeric.' )
	.describe( 'QuickBooks entity ID.' );

const SALE_LINE = z.object( {
	amount: z.number().positive().describe( 'Line amount.' ),
	description: z.string().optional().describe( 'Line description.' ),
	item_id: ID.optional(),
	quantity: z.number().positive().optional().describe( 'Quantity.' ),
} );

const BILL_LINE = z.object( {
	amount: z.number().positive().describe( 'Line amount.' ),
	description: z.string().optional().describe( 'Line description.' ),
	account_id: ID.optional().describe( 'Expense account ID (used when item_id is absent).' ),
	item_id: ID.optional().describe( 'Item ID (makes the line item-based).' ),
	quantity: z.number().positive().optional().describe( 'Quantity.' ),
} );

/**
 * Wrap a payload as MCP text content.
 *
 * @param {Object} payload Result payload.
 * @return {Object} Tool result.
 */
function ok( payload ) {
	let text = JSON.stringify( payload, null, 2 );

	if ( text.length > 100_000 ) {
		text = `${ text.slice( 0, 100_000 ) }\n… truncated (${ text.length } bytes total). Narrow the query or request a smaller report.`;
	}

	return { content: [ { type: 'text', text } ] };
}

/**
 * Wrap an error as an MCP error result.
 *
 * @param {Error} error Thrown error.
 * @return {Object} Tool result.
 */
function fail( error ) {
	const suffix = error.fault ? `\n${ JSON.stringify( error.fault, null, 2 ) }` : '';
	return {
		isError: true,
		content: [ { type: 'text', text: `${ error.message }${ suffix }` } ],
	};
}

/**
 * Read the current SyncToken for an entity.
 *
 * QuickBooks rejects writes with a stale token (fault 5010), so callers that
 * do not supply one get it from a fresh read. That read-then-write window is
 * not atomic: a concurrent edit between the two calls still yields 5010, which
 * the caller can retry.
 *
 * @param {Object} intuit IntuitClient instance.
 * @param {string} entity Entity name, e.g. Invoice.
 * @param {string} id     Entity ID.
 * @return {Promise<string>} SyncToken.
 */
async function resolveSyncToken( intuit, entity, id ) {
	const data = await intuit.read( entity, id );
	const token = data?.[ entity ]?.SyncToken;

	if ( undefined === token || null === token ) {
		throw new Error( `Could not read the SyncToken for ${ entity } ${ id }.` );
	}

	return String( token );
}

/**
 * Register every tool on a server instance.
 *
 * Note: `registerTool` returns the tool record, not the server — never chain it.
 *
 * @param {Object} server  McpServer instance.
 * @param {Object} intuit  IntuitClient instance.
 * @return {void}
 */
export function registerTools( server, intuit ) {
	const readOnly = { readOnlyHint: true, destructiveHint: false, openWorldHint: true };
	const writes = { readOnlyHint: false, destructiveHint: false, openWorldHint: true };
	const destructive = { readOnlyHint: false, destructiveHint: true, openWorldHint: true };

	server.registerTool(
		'get_company_info',
		{
			title: 'Get company info',
			description:
				'Return the QuickBooks company profile for the connected realm: legal name, address, fiscal year, and country.',
			inputSchema: {},
			annotations: readOnly,
		},
		async () => {
			try {
				return ok( await intuit.read( 'companyinfo', intuit.config.realmId ) );
			} catch ( error ) {
				return fail( error );
			}
		}
	);

	server.registerTool(
		'get_report',
		{
			title: 'Get financial report',
			description:
				'Run a QuickBooks report such as ProfitAndLoss, BalanceSheet, CashFlow, SalesByCustomer, AgedReceivables, or TrialBalance.',
			inputSchema: {
				report: z.string().min( 1 ).describe( 'Report name, e.g. ProfitAndLoss.' ),
				start_date: DATE.optional().describe( 'Report start date.' ),
				end_date: DATE.optional().describe( 'Report end date.' ),
				accounting_method: z
					.enum( [ 'Accrual', 'Cash' ] )
					.optional()
					.describe( 'Accounting method.' ),
				summarize_column_by: z
					.enum( [ 'Month', 'Week', 'Year', 'Total' ] )
					.optional()
					.describe( 'Column grouping.' ),
			},
			annotations: readOnly,
		},
		async ( args ) => {
			try {
				return ok(
					await intuit.report( args.report, {
						start_date: args.start_date,
						end_date: args.end_date,
						accounting_method: args.accounting_method,
						summarize_column_by: args.summarize_column_by,
					} )
				);
			} catch ( error ) {
				return fail( error );
			}
		}
	);

	server.registerTool(
		'search_invoices',
		{
			title: 'Search invoices',
			description:
				'Query invoices, optionally bounded by transaction date and customer. Returns the newest first.',
			inputSchema: {
				customer_id: ID.optional().describe( 'Filter to one customer ID.' ),
				start_date: DATE.optional().describe( 'Earliest TxnDate.' ),
				end_date: DATE.optional().describe( 'Latest TxnDate.' ),
				limit: z
					.number()
					.int()
					.min( 1 )
					.max( 1000 )
					.optional()
					.describe( 'Max rows (default 50).' ),
			},
			annotations: readOnly,
		},
		async ( args ) => {
			try {
				const where = [];

				if ( args.start_date ) {
					where.push( `TxnDate >= '${ args.start_date }'` );
				}
				if ( args.end_date ) {
					where.push( `TxnDate <= '${ args.end_date }'` );
				}
				if ( args.customer_id ) {
					where.push( `CustomerRef = '${ args.customer_id }'` );
				}

				const statement =
					'select * from Invoice' +
					( where.length ? ` where ${ where.join( ' and ' ) }` : '' ) +
					` orderby TxnDate desc maxresults ${ args.limit || 50 }`;

				return ok( await intuit.query( statement ) );
			} catch ( error ) {
				return fail( error );
			}
		}
	);

	server.registerTool(
		'search_customers',
		{
			title: 'Search customers',
			description:
				'Find customers by partial display name and/or active flag. Sorted by DisplayName. Use this to resolve a customer name to the ID that create_invoice and create_payment need.',
			inputSchema: {
				display_name: z
					.string()
					.optional()
					.describe( 'Partial DisplayName match.' ),
				active: z
					.boolean()
					.optional()
					.describe( 'true for active customers only, false for inactive.' ),
				limit: z
					.number()
					.int()
					.min( 1 )
					.max( 1000 )
					.optional()
					.describe( 'Max rows (default 50).' ),
			},
			annotations: readOnly,
		},
		async ( args ) => {
			try {
				return ok(
					await intuit.query(
						buildEntitySearchQuery( 'Customer', {
							name: args.display_name,
							active: args.active,
							limit: args.limit,
						} )
					)
				);
			} catch ( error ) {
				return fail( error );
			}
		}
	);

	server.registerTool(
		'search_vendors',
		{
			title: 'Search vendors',
			description:
				'Find vendors by partial display name and/or active flag. Sorted by DisplayName. Use this to resolve a vendor name to the ID that create_bill needs.',
			inputSchema: {
				display_name: z
					.string()
					.optional()
					.describe( 'Partial DisplayName match.' ),
				active: z
					.boolean()
					.optional()
					.describe( 'true for active vendors only, false for inactive.' ),
				limit: z
					.number()
					.int()
					.min( 1 )
					.max( 1000 )
					.optional()
					.describe( 'Max rows (default 50).' ),
			},
			annotations: readOnly,
		},
		async ( args ) => {
			try {
				return ok(
					await intuit.query(
						buildEntitySearchQuery( 'Vendor', {
							name: args.display_name,
							active: args.active,
							limit: args.limit,
						} )
					)
				);
			} catch ( error ) {
				return fail( error );
			}
		}
	);

	server.registerTool(
		'search_accounts',
		{
			title: 'Search accounts',
			description:
				'Find accounts by name, type, and active flag. Use account_type "Expense" (or "Cost of Goods Sold") to resolve the account_id that create_bill requires on every line.',
			inputSchema: {
				name: z.string().optional().describe( 'Partial Name match.' ),
				account_type: z
					.enum( ACCOUNT_TYPES )
					.optional()
					.describe( 'Filter by QuickBooks AccountType.' ),
				active: z.boolean().optional().describe( 'Active flag filter.' ),
				limit: z
					.number()
					.int()
					.min( 1 )
					.max( 1000 )
					.optional()
					.describe( 'Max rows (default 50).' ),
			},
			annotations: readOnly,
		},
		async ( args ) => {
			try {
				return ok(
					await intuit.query(
						buildEntitySearchQuery( 'Account', {
							name: args.name,
							active: args.active,
							type: args.account_type,
							limit: args.limit,
						} )
					)
				);
			} catch ( error ) {
				return fail( error );
			}
		}
	);

	server.registerTool(
		'search_items',
		{
			title: 'Search items',
			description:
				'Find products and services by name, type, and active flag. Use item_type "Service" to resolve the item_id that itemised invoice and estimate lines need.',
			inputSchema: {
				name: z.string().optional().describe( 'Partial Name match.' ),
				item_type: z
					.enum( ITEM_TYPES )
					.optional()
					.describe( 'Filter by QuickBooks Item Type.' ),
				active: z.boolean().optional().describe( 'Active flag filter.' ),
				limit: z
					.number()
					.int()
					.min( 1 )
					.max( 1000 )
					.optional()
					.describe( 'Max rows (default 50).' ),
			},
			annotations: readOnly,
		},
		async ( args ) => {
			try {
				return ok(
					await intuit.query(
						buildEntitySearchQuery( 'Item', {
							name: args.name,
							active: args.active,
							type: args.item_type,
							limit: args.limit,
						} )
					)
				);
			} catch ( error ) {
				return fail( error );
			}
		}
	);

	server.registerTool(
		'search_payments',
		{
			title: 'Search payments',
			description:
				'Query customer payments by date range and/or customer, newest first. Use it to confirm the payment_id before delete_payment.',
			inputSchema: {
				customer_id: ID.optional().describe( 'Filter to one customer ID.' ),
				start_date: DATE.optional().describe( 'Earliest TxnDate.' ),
				end_date: DATE.optional().describe( 'Latest TxnDate.' ),
				limit: z
					.number()
					.int()
					.min( 1 )
					.max( 1000 )
					.optional()
					.describe( 'Max rows (default 50).' ),
			},
			annotations: readOnly,
		},
		async ( args ) => {
			try {
				const where = [];

				if ( args.start_date ) {
					where.push( `TxnDate >= '${ args.start_date }'` );
				}
				if ( args.end_date ) {
					where.push( `TxnDate <= '${ args.end_date }'` );
				}
				if ( args.customer_id ) {
					where.push( `CustomerRef = '${ args.customer_id }'` );
				}

				const statement =
					'select * from Payment' +
					( where.length ? ` where ${ where.join( ' and ' ) }` : '' ) +
					` orderby TxnDate desc maxresults ${ args.limit || 50 }`;

				return ok( await intuit.query( statement ) );
			} catch ( error ) {
				return fail( error );
			}
		}
	);

	server.registerTool(
		'get_invoice',
		{
			title: 'Get invoice',
			description: 'Read one invoice by QuickBooks ID.',
			inputSchema: { id: ID.describe( 'Invoice ID.' ) },
			annotations: readOnly,
		},
		async ( args ) => {
			try {
				return ok( await intuit.read( 'Invoice', args.id ) );
			} catch ( error ) {
				return fail( error );
			}
		}
	);

	server.registerTool(
		'create_customer',
		{
			title: 'Create customer',
			description: 'Create a QuickBooks customer. DisplayName is required and must be unique.',
			inputSchema: {
				display_name: z.string().min( 1 ).describe( 'Customer display name (unique).' ),
				email: z.email().optional().describe( 'Primary email address.' ),
				company_name: z.string().optional().describe( 'Company name.' ),
				phone: z.string().optional().describe( 'Primary phone (free form).' ),
			},
			annotations: writes,
		},
		async ( args ) => {
			try {
				const payload = { DisplayName: args.display_name };

				if ( args.email ) {
					payload.PrimaryEmailAddr = { Address: args.email };
				}
				if ( args.company_name ) {
					payload.CompanyName = args.company_name;
				}
				if ( args.phone ) {
					payload.PrimaryPhone = { FreeFormNumber: args.phone };
				}

				return ok( await intuit.create( 'Customer', payload ) );
			} catch ( error ) {
				return fail( error );
			}
		}
	);

	server.registerTool(
		'create_invoice',
		{
			title: 'Create invoice',
			description:
				'Create an invoice for an existing customer. Each line needs an amount; supply item_id to book it against a QuickBooks item.',
			inputSchema: {
				customer_id: ID.describe( 'Customer ID to bill.' ),
				lines: z.array( SALE_LINE ).min( 1 ).describe( 'Invoice lines.' ),
				doc_number: z.string().optional().describe( 'Optional DocNumber.' ),
				txn_date: DATE.optional().describe( 'Transaction date.' ),
				due_date: DATE.optional().describe( 'Due date.' ),
				private_note: z.string().optional().describe( 'Internal memo.' ),
			},
			annotations: writes,
		},
		async ( args ) => {
			try {
				return ok( await intuit.create( 'Invoice', buildInvoicePayload( args ) ) );
			} catch ( error ) {
				return fail( error );
			}
		}
	);

	server.registerTool(
		'update_invoice',
		{
			title: 'Update invoice',
			description:
				'Apply a sparse update to an invoice (dates, number, memo, or lines). Supplying lines REPLACES every existing line — QuickBooks has no partial line edit. The current SyncToken is read automatically when omitted.',
			inputSchema: {
				id: ID.describe( 'Invoice ID.' ),
				sync_token: z
					.string()
					.optional()
					.describe( 'Current SyncToken. Omit to read it first.' ),
				doc_number: z.string().optional().describe( 'New DocNumber.' ),
				txn_date: DATE.optional().describe( 'New transaction date.' ),
				due_date: DATE.optional().describe( 'New due date.' ),
				private_note: z.string().optional().describe( 'New internal memo.' ),
				lines: z
					.array( SALE_LINE )
					.min( 1 )
					.optional()
					.describe( 'Replacement line set.' ),
			},
			annotations: writes,
		},
		async ( args ) => {
			try {
				const syncToken =
					args.sync_token || ( await resolveSyncToken( intuit, 'Invoice', args.id ) );

				const payload = buildInvoiceUpdatePayload( {
					...args,
					sync_token: syncToken,
				} );

				return ok( await intuit.request( 'POST', '/invoice', { body: payload } ) );
			} catch ( error ) {
				return fail( error );
			}
		}
	);

	server.registerTool(
		'delete_invoice',
		{
			title: 'Delete or void invoice',
			description:
				'Void an invoice (default, accounting-safe: keeps the record and zeroes the balance) or permanently delete it with mode="purge". Purging fails when the invoice has linked payments.',
			inputSchema: {
				id: ID.describe( 'Invoice ID.' ),
				mode: z
					.enum( [ 'void', 'purge' ] )
					.optional()
					.describe( 'void (default) keeps an audit trail; purge removes the record.' ),
				sync_token: z
					.string()
					.optional()
					.describe( 'Current SyncToken. Omit to read it first.' ),
			},
			annotations: destructive,
		},
		async ( args ) => {
			try {
				const mode = 'purge' === args.mode ? 'delete' : 'void';
				const syncToken =
					args.sync_token || ( await resolveSyncToken( intuit, 'Invoice', args.id ) );

				return ok(
					await intuit.request( 'POST', '/invoice', {
						query: { operation: mode },
						body: buildOperationBody( args.id, syncToken ),
					} )
				);
			} catch ( error ) {
				return fail( error );
			}
		}
	);

	server.registerTool(
		'delete_payment',
		{
			title: 'Delete payment',
			description:
				'PERMANENTLY delete a customer payment and un-apply it from any linked invoices, restoring their open balances. QuickBooks has no void for payments, so this cannot be undone. Run search_payments first to confirm the ID.',
			inputSchema: {
				id: ID.describe( 'Payment ID.' ),
				sync_token: z
					.string()
					.optional()
					.describe( 'Current SyncToken. Omit to read it first.' ),
			},
			annotations: destructive,
		},
		async ( args ) => {
			try {
				const syncToken =
					args.sync_token || ( await resolveSyncToken( intuit, 'Payment', args.id ) );

				return ok(
					await intuit.request( 'POST', '/payment', {
						query: { operation: 'delete' },
						body: buildOperationBody( args.id, syncToken ),
					} )
				);
			} catch ( error ) {
				return fail( error );
			}
		}
	);

	server.registerTool(
		'create_payment',
		{
			title: 'Create customer payment',
			description:
				'Record a customer payment. With invoice_id the payment is applied to that invoice; without it the payment becomes an unapplied credit that closes nothing.',
			inputSchema: {
				customer_id: ID.describe( 'Customer ID.' ),
				amount: z.number().positive().describe( 'Payment total.' ),
				invoice_id: ID.optional().describe( 'Invoice to apply the payment to.' ),
				txn_date: DATE.optional().describe( 'Payment date.' ),
				payment_method_id: ID.optional().describe( 'PaymentMethod ID.' ),
				reference: z.string().optional().describe( 'Payment reference number.' ),
				private_note: z.string().optional().describe( 'Internal memo.' ),
			},
			annotations: writes,
		},
		async ( args ) => {
			try {
				return ok( await intuit.create( 'Payment', buildPaymentPayload( args ) ) );
			} catch ( error ) {
				return fail( error );
			}
		}
	);

	server.registerTool(
		'create_estimate',
		{
			title: 'Create estimate',
			description:
				'Create an estimate (quote) for an existing customer, using the same line model as an invoice.',
			inputSchema: {
				customer_id: ID.describe( 'Customer ID.' ),
				lines: z.array( SALE_LINE ).min( 1 ).describe( 'Estimate lines.' ),
				doc_number: z.string().optional().describe( 'Optional DocNumber.' ),
				txn_date: DATE.optional().describe( 'Transaction date.' ),
				expiration_date: DATE.optional().describe( 'Expiration date.' ),
				private_note: z.string().optional().describe( 'Internal memo.' ),
			},
			annotations: writes,
		},
		async ( args ) => {
			try {
				return ok( await intuit.create( 'Estimate', buildEstimatePayload( args ) ) );
			} catch ( error ) {
				return fail( error );
			}
		}
	);

	server.registerTool(
		'create_bill',
		{
			title: 'Create bill',
			description:
				'Create a vendor bill. Every line needs an account_id (expense account) or an item_id; lines are otherwise rejected by QuickBooks.',
			inputSchema: {
				vendor_id: ID.describe( 'Vendor ID.' ),
				lines: z.array( BILL_LINE ).min( 1 ).describe( 'Bill lines.' ),
				doc_number: z.string().optional().describe( 'Vendor reference number.' ),
				txn_date: DATE.optional().describe( 'Bill date.' ),
				due_date: DATE.optional().describe( 'Due date.' ),
				private_note: z.string().optional().describe( 'Internal memo.' ),
			},
			annotations: writes,
		},
		async ( args ) => {
			try {
				return ok( await intuit.create( 'Bill', buildBillPayload( args ) ) );
			} catch ( error ) {
				return fail( error );
			}
		}
	);
}
