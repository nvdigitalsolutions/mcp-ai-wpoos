// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * Pure payload builders for QuickBooks Online write operations.
 *
 * Kept free of I/O so the exact JSON shapes can be unit-tested — a malformed
 * payload is the most likely way a write call fails, and Intuit's error for it
 * is rarely self-explanatory.
 *
 * QuickBooks rules encoded here:
 * - Updates must be **sparse**: `Id` + current `SyncToken` + `sparse: true`.
 * - Supplying `Line` on an update **replaces every line**; there is no partial
 *   line edit.
 * - Void and delete both take `{ Id, SyncToken }` with the verb in the query
 *   string (`?operation=void` / `?operation=delete`).
 */

/**
 * Build sale lines shared by invoices and estimates.
 *
 * @param {Array<Object>} lines Line inputs.
 * @return {Array<Object>} QuickBooks Line array.
 */
export function buildSalesLines( lines ) {
	return lines.map( ( line, index ) => {
		const built = {
			LineNum: index + 1,
			Amount: line.amount,
			DetailType: line.item_id ? 'SalesItemLineDetail' : 'DescriptionOnly',
		};

		if ( line.description ) {
			built.Description = line.description;
		}

		if ( line.item_id ) {
			built.SalesItemLineDetail = {
				ItemRef: { value: line.item_id },
				...( line.quantity ? { Qty: line.quantity } : {} ),
			};
		}

		return built;
	} );
}

/**
 * Build bill lines.
 *
 * Expense lines must be item-based or account-based; one of the two IDs is
 * required or QuickBooks rejects the bill.
 *
 * @param {Array<Object>} lines Line inputs.
 * @return {Array<Object>} QuickBooks Line array.
 * @throws {Error} When a line carries neither an item nor an account.
 */
export function buildBillLines( lines ) {
	return lines.map( ( line, index ) => {
		const built = {
			LineNum: index + 1,
			Amount: line.amount,
		};

		if ( line.description ) {
			built.Description = line.description;
		}

		if ( line.item_id ) {
			built.DetailType = 'ItemBasedExpenseLineDetail';
			built.ItemBasedExpenseLineDetail = {
				ItemRef: { value: line.item_id },
				...( line.quantity ? { Qty: line.quantity } : {} ),
			};
		} else if ( line.account_id ) {
			built.DetailType = 'AccountBasedExpenseLineDetail';
			built.AccountBasedExpenseLineDetail = {
				AccountRef: { value: line.account_id },
			};
		} else {
			throw new Error(
				`Bill line ${ index + 1 } needs an account_id (expense account) or an item_id.`
			);
		}

		return built;
	} );
}

/**
 * Build an invoice create payload.
 *
 * @param {Object} input Input fields.
 * @return {Object} Invoice payload.
 */
export function buildInvoicePayload( input ) {
	const payload = {
		CustomerRef: { value: input.customer_id },
		Line: buildSalesLines( input.lines ),
	};

	if ( input.doc_number ) {
		payload.DocNumber = input.doc_number;
	}
	if ( input.txn_date ) {
		payload.TxnDate = input.txn_date;
	}
	if ( input.due_date ) {
		payload.DueDate = input.due_date;
	}
	if ( input.private_note ) {
		payload.PrivateNote = input.private_note;
	}

	return payload;
}

/**
 * Build a sparse invoice update payload.
 *
 * Only the fields present are touched. If `lines` is supplied it replaces the
 * invoice's entire line set — that is how QuickBooks models it, not a merge.
 *
 * @param {Object} input Input fields; `id` and `sync_token` are required.
 * @return {Object} Sparse update payload.
 */
export function buildInvoiceUpdatePayload( input ) {
	const payload = {
		Id: input.id,
		SyncToken: input.sync_token,
		sparse: true,
	};

	if ( input.doc_number ) {
		payload.DocNumber = input.doc_number;
	}
	if ( input.txn_date ) {
		payload.TxnDate = input.txn_date;
	}
	if ( input.due_date ) {
		payload.DueDate = input.due_date;
	}
	if ( input.private_note ) {
		payload.PrivateNote = input.private_note;
	}
	if ( Array.isArray( input.lines ) && input.lines.length > 0 ) {
		payload.Line = buildSalesLines( input.lines );
	}

	return payload;
}

/**
 * Build the body for a void or delete call.
 *
 * @param {string} id        Entity ID.
 * @param {string} syncToken Current SyncToken.
 * @return {Object} Operation body.
 */
export function buildOperationBody( id, syncToken ) {
	return { Id: id, SyncToken: syncToken };
}

/**
 * Build a customer payment payload.
 *
 * With `invoice_id`, the payment is linked to that invoice. Without it the
 * payment is an unapplied credit, which is legal in QuickBooks but will not
 * close any invoice.
 *
 * @param {Object} input Input fields.
 * @return {Object} Payment payload.
 */
export function buildPaymentPayload( input ) {
	const payload = {
		CustomerRef: { value: input.customer_id },
		TotalAmt: input.amount,
	};

	if ( input.invoice_id ) {
		payload.Line = [
			{
				Amount: input.amount,
				LinkedTxn: [ { TxnId: input.invoice_id, TxnType: 'Invoice' } ],
			},
		];
	}

	if ( input.txn_date ) {
		payload.TxnDate = input.txn_date;
	}
	if ( input.payment_method_id ) {
		payload.PaymentMethodRef = { value: input.payment_method_id };
	}
	if ( input.reference ) {
		payload.PaymentRefNum = input.reference;
	}
	if ( input.private_note ) {
		payload.PrivateNote = input.private_note;
	}

	return payload;
}

/**
 * Build an estimate create payload.
 *
 * @param {Object} input Input fields.
 * @return {Object} Estimate payload.
 */
export function buildEstimatePayload( input ) {
	const payload = {
		CustomerRef: { value: input.customer_id },
		Line: buildSalesLines( input.lines ),
	};

	if ( input.doc_number ) {
		payload.DocNumber = input.doc_number;
	}
	if ( input.txn_date ) {
		payload.TxnDate = input.txn_date;
	}
	if ( input.expiration_date ) {
		payload.ExpirationDate = input.expiration_date;
	}
	if ( input.private_note ) {
		payload.PrivateNote = input.private_note;
	}

	return payload;
}

/**
 * Build a bill create payload.
 *
 * @param {Object} input Input fields.
 * @return {Object} Bill payload.
 */
export function buildBillPayload( input ) {
	const payload = {
		VendorRef: { value: input.vendor_id },
		Line: buildBillLines( input.lines ),
	};

	if ( input.doc_number ) {
		payload.DocNumber = input.doc_number;
	}
	if ( input.txn_date ) {
		payload.TxnDate = input.txn_date;
	}
	if ( input.due_date ) {
		payload.DueDate = input.due_date;
	}
	if ( input.private_note ) {
		payload.PrivateNote = input.private_note;
	}

	return payload;
}
