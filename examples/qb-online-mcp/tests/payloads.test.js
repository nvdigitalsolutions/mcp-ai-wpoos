// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * Payload-shape tests.
 *
 * These assert the exact JSON sent to Intuit, because a wrong shape is the
 * most likely cause of a write failure and QuickBooks' error text rarely
 * points at the offending field.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
	buildBillLines,
	buildBillPayload,
	buildEstimatePayload,
	buildInvoicePayload,
	buildInvoiceUpdatePayload,
	buildOperationBody,
	buildPaymentPayload,
	buildSalesLines,
} from '../src/payloads.js';

const LINES = [
	{ amount: 250.5, description: 'Consulting', item_id: '7', quantity: 2 },
	{ amount: 100, description: 'Expenses' },
];

test( 'sale lines mark item vs description-only lines', () => {
	const lines = buildSalesLines( LINES );

	assert.equal( lines.length, 2 );
	assert.deepEqual( lines[ 0 ], {
		LineNum: 1,
		Amount: 250.5,
		Description: 'Consulting',
		DetailType: 'SalesItemLineDetail',
		SalesItemLineDetail: { ItemRef: { value: '7' }, Qty: 2 },
	} );
	assert.deepEqual( lines[ 1 ], {
		LineNum: 2,
		Amount: 100,
		Description: 'Expenses',
		DetailType: 'DescriptionOnly',
	} );
} );

test( 'invoice payload omits absent optional fields', () => {
	const payload = buildInvoicePayload( {
		customer_id: '58',
		lines: [ { amount: 10 } ],
	} );

	assert.deepEqual( Object.keys( payload ).sort(), [ 'CustomerRef', 'Line' ] );
	assert.deepEqual( payload.CustomerRef, { value: '58' } );
} );

test( 'invoice payload carries the optional fields when supplied', () => {
	const payload = buildInvoicePayload( {
		customer_id: '58',
		lines: [ { amount: 10 } ],
		doc_number: 'INV-9',
		txn_date: '2026-01-02',
		due_date: '2026-02-01',
		private_note: 'Net 30',
	} );

	assert.equal( payload.DocNumber, 'INV-9' );
	assert.equal( payload.TxnDate, '2026-01-02' );
	assert.equal( payload.DueDate, '2026-02-01' );
	assert.equal( payload.PrivateNote, 'Net 30' );
} );

test( 'invoice update is sparse and touches only supplied fields', () => {
	const payload = buildInvoiceUpdatePayload( {
		id: '184',
		sync_token: '3',
		due_date: '2026-03-01',
	} );

	assert.deepEqual( payload, {
		Id: '184',
		SyncToken: '3',
		sparse: true,
		DueDate: '2026-03-01',
	} );
} );

test( 'invoice update replaces lines wholesale when supplied', () => {
	const payload = buildInvoiceUpdatePayload( {
		id: '184',
		sync_token: '3',
		lines: [ { amount: 42, description: 'Revised' } ],
	} );

	assert.equal( payload.Line.length, 1 );
	assert.equal( payload.Line[ 0 ].Amount, 42 );
	assert.equal( payload.Line[ 0 ].DetailType, 'DescriptionOnly' );
} );

test( 'invoice update omits Line when no lines are passed', () => {
	const payload = buildInvoiceUpdatePayload( {
		id: '184',
		sync_token: '3',
		private_note: 'note only',
	} );

	assert.equal( 'Line' in payload, false );
} );

test( 'operation body is the Id/SyncToken pair QuickBooks expects', () => {
	assert.deepEqual( buildOperationBody( '184', '0' ), { Id: '184', SyncToken: '0' } );
} );

test( 'payment links to an invoice when one is given', () => {
	const payload = buildPaymentPayload( {
		customer_id: '58',
		amount: 250.5,
		invoice_id: '184',
		reference: 'CHK-1',
	} );

	assert.equal( payload.TotalAmt, 250.5 );
	assert.deepEqual( payload.Line, [
		{ Amount: 250.5, LinkedTxn: [ { TxnId: '184', TxnType: 'Invoice' } ] },
	] );
	assert.equal( payload.PaymentRefNum, 'CHK-1' );
} );

test( 'payment without an invoice becomes an unapplied credit', () => {
	const payload = buildPaymentPayload( { customer_id: '58', amount: 75 } );

	assert.equal( 'Line' in payload, false );
	assert.equal( payload.TotalAmt, 75 );
} );

test( 'bill lines prefer item-based detail when an item is supplied', () => {
	const lines = buildBillLines( [
		{ amount: 20, item_id: '9', quantity: 3 },
		{ amount: 30, account_id: '41' },
	] );

	assert.equal( lines[ 0 ].DetailType, 'ItemBasedExpenseLineDetail' );
	assert.deepEqual( lines[ 0 ].ItemBasedExpenseLineDetail, {
		ItemRef: { value: '9' },
		Qty: 3,
	} );
	assert.equal( lines[ 1 ].DetailType, 'AccountBasedExpenseLineDetail' );
	assert.deepEqual( lines[ 1 ].AccountBasedExpenseLineDetail, {
		AccountRef: { value: '41' },
	} );
} );

test( 'bill lines reject a line with neither account nor item', () => {
	assert.throws( () => buildBillLines( [ { amount: 10 } ] ), /needs an account_id/ );
} );

test( 'bill payload uses VendorRef and validates its lines', () => {
	const payload = buildBillPayload( {
		vendor_id: '77',
		lines: [ { amount: 300, account_id: '41', description: 'Rent' } ],
		due_date: '2026-04-01',
	} );

	assert.deepEqual( payload.VendorRef, { value: '77' } );
	assert.equal( payload.Line[ 0 ].Amount, 300 );
	assert.equal( payload.DueDate, '2026-04-01' );
} );

test( 'estimate shares the sale-line model and adds an expiration date', () => {
	const payload = buildEstimatePayload( {
		customer_id: '58',
		lines: [ { amount: 500, item_id: '7' } ],
		expiration_date: '2026-05-01',
	} );

	assert.equal( payload.Line[ 0 ].DetailType, 'SalesItemLineDetail' );
	assert.equal( payload.ExpirationDate, '2026-05-01' );
	assert.equal( 'DueDate' in payload, false );
} );
