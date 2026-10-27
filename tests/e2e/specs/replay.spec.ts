import { expect, test } from '@playwright/test';

import { Admin, openApp } from '../helpers';

let admin: Admin;

test.beforeEach( async ( { baseURL } ) => {
	admin = await Admin.create( baseURL! );
	await admin.reset();
	await admin.call( 'POST', '/ub-test/v1/approved-comment', {
		author: 'Loyal Reader',
		content:
			'Our office mugs say e2e-spamword on them, it is an inside joke.',
	} );
	await admin.call( 'POST', '/ub-test/v1/approved-comment', {
		author: 'Gardener',
		content: 'The tomato tips worked, thank you!',
	} );
} );

test( 'replaying the list shows which accepted messages it would stop', async ( {
	page,
} ) => {
	await openApp( page, 'test' );
	const panel = page.locator( '.ub-replay' );
	await panel
		.getByRole( 'button', { name: 'Check recent messages' } )
		.click();

	await expect( panel ).toContainText(
		'1 of 2 accepted messages would have been stopped.'
	);
	await expect( panel ).toContainText( 'Checked 2 approved comments' );
	const match = panel.locator( '.ub-replay-list .ub-log-row' );
	await expect( match ).toHaveCount( 1 );
	await expect( match ).toContainText( 'Loyal Reader' );
	await expect( match.locator( '.ub-term' ) ).toHaveText( 'e2e-spamword' );
	await expect( match.getByRole( 'link', { name: 'Open' } ) ).toHaveAttribute(
		'href',
		/comment\.php\?action=editcomment&c=\d+/
	);

	// Allow it right there, then check again.
	await panel
		.locator( '.ub-replay-terms' )
		.getByRole( 'button', { name: 'Allow' } )
		.click();
	await expect( panel.locator( '.ub-replay-terms' ) ).toContainText(
		'Allowed'
	);
	await panel
		.getByRole( 'button', { name: 'Check recent messages' } )
		.click();
	await expect( panel ).toContainText( 'Nothing real gets caught.' );
} );

test( 'new terms can be checked before they are saved', async ( { page } ) => {
	await openApp( page, 'terms' );
	const editor = page.locator( '#ub-local' );
	await editor.fill( ( await editor.inputValue() ) + '\ntomato' );

	const panel = page.locator( '.ub-list-editor-foot .ub-replay' );
	await panel
		.getByRole( 'button', {
			name: 'Check 1 new term against recent messages',
		} )
		.click();
	await expect( panel ).toContainText(
		'1 of 2 accepted messages would have been stopped.'
	);
	await expect( panel.locator( '.ub-replay-list' ) ).toContainText(
		'Gardener'
	);

	// Checking saved nothing.
	expect( ( await admin.settings() ).local_terms ).not.toContain( 'tomato' );
	await page.getByRole( 'button', { name: 'Revert' } ).click();
	await expect( panel ).toBeHidden();
} );

test( 'Contact Form 7 messages saved by Flamingo are replayed too', async ( {
	page,
	baseURL,
} ) => {
	const { fixtures, plugins } = await admin.reset();
	if ( ( ! plugins.cf7 || ! plugins.flamingo ) && process.env.CI ) {
		throw new Error(
			'Contact Form 7 and Flamingo should be active in CI.'
		);
	}
	test.skip(
		! plugins.cf7 || ! plugins.flamingo,
		'Contact Form 7 or Flamingo is not installed here'
	);

	// A visitor sends a normal message, which Flamingo keeps.
	const visitor = await page
		.context()
		.browser()!
		.newContext( { baseURL, storageState: { cookies: [], origins: [] } } );
	const form = await visitor.newPage();
	await form.goto( fixtures.cf7! );
	await form.getByLabel( 'Your name' ).fill( 'Kari Nordmann' );
	await form.getByLabel( 'Your email' ).fill( 'kari@example.test' );
	await form
		.getByLabel( 'Your message' )
		.fill( 'Can you deliver firewood to Porsgrunn?' );
	await form.getByRole( 'button', { name: 'Send' } ).click();
	await expect( form.locator( 'form.wpcf7-form' ) ).toHaveAttribute(
		'data-status',
		'sent'
	);
	await visitor.close();

	await openApp( page, 'terms' );
	const editor = page.locator( '#ub-local' );
	await editor.fill( ( await editor.inputValue() ) + '\nfirewood' );
	const panel = page.locator( '.ub-list-editor-foot .ub-replay' );
	await panel
		.getByRole( 'button', {
			name: 'Check 1 new term against recent messages',
		} )
		.click();
	await expect( panel ).toContainText( 'Contact Form 7 messages' );
	await expect( panel.locator( '.ub-replay-list' ) ).toContainText(
		'Contact Form 7'
	);
} );
