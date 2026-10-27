import { expect, test } from '@playwright/test';

import { Admin, openApp, postComment, type Fixtures } from '../helpers';

let admin: Admin;
let fixtures: Fixtures;

test.beforeEach( async ( { baseURL } ) => {
	admin = await Admin.create( baseURL! );
	( { fixtures } = await admin.reset() );
	await admin.call( 'POST', '/ub-test/v1/discussion', {
		value: 'e2e-only-term\n10.0.0.\ncheap viagra',
	} );
} );

test( 'Discussion terms move into the plugin and can be put back', async ( {
	page,
	baseURL,
} ) => {
	await openApp( page, 'terms' );
	const callout = page.locator( '.ub-callout', {
		hasText: 'Discussion lines on every comment',
	} );
	await expect( callout ).toContainText(
		'WordPress also checks 2 Discussion lines on every comment'
	);
	await expect( callout ).toContainText(
		'1 IP address stays in Discussion settings'
	);

	await callout.getByRole( 'button', { name: 'Move them here' } ).click();
	const toast = page.locator( '.ub-toast', { hasText: 'Moved 2 lines' } );
	await expect( toast ).toContainText( '1 new term was added to Your terms' );

	expect(
		( await admin.call( 'GET', '/ub-test/v1/discussion' ) ).value
	).toBe( '10.0.0.' );
	expect( ( await admin.settings() ).local_terms ).toContain(
		'e2e-only-term'
	);
	await expect(
		page.locator( '.ub-callout', { hasText: 'were moved here' } )
	).toBeVisible();

	// The moved term still stops comments, now through the plugin.
	await postComment(
		baseURL!,
		fixtures.postId,
		'this has e2e-only-term in it',
		'Bot'
	);
	expect( ( await admin.log() ).rows[ 0 ] ).toMatchObject( {
		context: 'comment',
		term: 'e2e-only-term',
	} );

	await toast.getByRole( 'button', { name: 'Undo' } ).click();
	await expect(
		page.locator( '.ub-toast', { hasText: 'back as it was' } )
	).toBeVisible();
	expect(
		( await admin.call( 'GET', '/ub-test/v1/discussion' ) ).value
	).toBe( 'e2e-only-term\n10.0.0.\ncheap viagra' );
	expect( ( await admin.settings() ).local_terms ).not.toContain(
		'e2e-only-term'
	);
} );
