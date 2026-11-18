/**
 * Query-builder tests.
 *
 * The escaped-literal cases matter: an unescaped apostrophe in a display name
 * (O'Brien, McDonald's) produces a malformed statement, and QuickBooks answers
 * with a generic parse error.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
	ACCOUNT_TYPES,
	ITEM_TYPES,
	buildEntitySearchQuery,
	escapeQueryLiteral,
	isSearchableEntity,
} from '../src/query.js';

test( 'apostrophes and backslashes are escaped', () => {
	assert.equal( escapeQueryLiteral( "O'Brien" ), "O\\'Brien" );
	assert.equal( escapeQueryLiteral( 'back\\slash' ), 'back\\\\slash' );
	assert.equal( escapeQueryLiteral( "a\\'b" ), "a\\\\\\'b" );
} );

test( 'only the four known entities are searchable', () => {
	assert.equal( isSearchableEntity( 'Customer' ), true );
	assert.equal( isSearchableEntity( 'Vendor' ), true );
	assert.equal( isSearchableEntity( 'Account' ), true );
	assert.equal( isSearchableEntity( 'Item' ), true );
	assert.equal( isSearchableEntity( 'Invoice' ), false );
} );

test( 'unfiltered search selects the entity and orders by its name field', () => {
	assert.equal(
		buildEntitySearchQuery( 'Customer', {} ),
		'select * from Customer orderby DisplayName maxresults 50'
	);
	assert.equal(
		buildEntitySearchQuery( 'Item', {} ),
		'select * from Item orderby Name maxresults 50'
	);
} );

test( 'Customer and Vendor match on DisplayName', () => {
	assert.equal(
		buildEntitySearchQuery( 'Vendor', { name: 'Acme' } ),
		"select * from Vendor where DisplayName like '%Acme%' orderby DisplayName maxresults 50"
	);
} );

test( 'Account and Item match on Name', () => {
	assert.equal(
		buildEntitySearchQuery( 'Account', { name: 'Rent' } ),
		"select * from Account where Name like '%Rent%' orderby Name maxresults 50"
	);
	assert.equal(
		buildEntitySearchQuery( 'Item', { name: 'Consult' } ),
		"select * from Item where Name like '%Consult%' orderby Name maxresults 50"
	);
} );

test( 'active filter renders a boolean literal', () => {
	assert.equal(
		buildEntitySearchQuery( 'Customer', { active: false } ),
		'select * from Customer where Active = false orderby DisplayName maxresults 50'
	);
} );

test( 'account type renders against AccountType', () => {
	assert.equal(
		buildEntitySearchQuery( 'Account', { type: 'Expense' } ),
		"select * from Account where AccountType = 'Expense' orderby Name maxresults 50"
	);
} );

test( 'item type renders against Type', () => {
	assert.equal(
		buildEntitySearchQuery( 'Item', { type: 'Service' } ),
		"select * from Item where Type = 'Service' orderby Name maxresults 50"
	);
} );

test( 'filters combine with and', () => {
	assert.equal(
		buildEntitySearchQuery( 'Vendor', { name: 'Acme', active: true, limit: 10 } ),
		"select * from Vendor where DisplayName like '%Acme%' and Active = true orderby DisplayName maxresults 10"
	);
	assert.equal(
		buildEntitySearchQuery( 'Account', { name: 'Rent', type: 'Expense', active: true } ),
		"select * from Account where Name like '%Rent%' and Active = true and AccountType = 'Expense' orderby Name maxresults 50"
	);
} );

test( 'an apostrophe in the term does not break the statement', () => {
	assert.equal(
		buildEntitySearchQuery( 'Customer', { name: "O'Brien" } ),
		"select * from Customer where DisplayName like '%O\\'Brien%' orderby DisplayName maxresults 50"
	);
} );

test( 'blank and whitespace-only terms are ignored', () => {
	assert.equal(
		buildEntitySearchQuery( 'Customer', { name: '   ' } ),
		'select * from Customer orderby DisplayName maxresults 50'
	);
} );

test( 'limit is clamped to 1..1000 and defaults to 50', () => {
	assert.match( buildEntitySearchQuery( 'Customer', { limit: 0 } ), /maxresults 1$/ );
	assert.match( buildEntitySearchQuery( 'Customer', { limit: 5000 } ), /maxresults 1000$/ );
	assert.match( buildEntitySearchQuery( 'Customer', { limit: 12.9 } ), /maxresults 12$/ );
	assert.match( buildEntitySearchQuery( 'Customer', { limit: 'nope' } ), /maxresults 50$/ );
} );

test( 'control characters are flattened', () => {
	assert.match(
		buildEntitySearchQuery( 'Customer', { name: "acme\nco" } ),
		/like '%acme co%'/
	);
} );

test( 'unknown entities are rejected', () => {
	assert.throws( () => buildEntitySearchQuery( 'Invoice', {} ), /Unsupported search entity/ );
} );

test( 'types outside the allowlist are rejected', () => {
	assert.throws(
		() => buildEntitySearchQuery( 'Account', { type: 'NotARealType' } ),
		/Unsupported AccountType/
	);
	assert.throws(
		() => buildEntitySearchQuery( 'Item', { type: 'Gadget' } ),
		/Unsupported Type/
	);
} );

test( 'a type filter on a party entity is rejected', () => {
	assert.throws(
		() => buildEntitySearchQuery( 'Customer', { type: 'Expense' } ),
		/has no type filter/
	);
} );

test( 'the exported type lists are non-empty and de-duplicated', () => {
	assert.ok( ACCOUNT_TYPES.includes( 'Expense' ) );
	assert.ok( ITEM_TYPES.includes( 'Service' ) );
	assert.equal( new Set( ACCOUNT_TYPES ).size, ACCOUNT_TYPES.length );
	assert.equal( new Set( ITEM_TYPES ).size, ITEM_TYPES.length );
} );
