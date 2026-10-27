import { expect, test, type Page } from '@playwright/test';

import { Admin, BLOCKED, type Fixtures, type Plugins } from '../helpers';

// Real form plugins, real front-end submissions, as a logged-out visitor.
test.use( { storageState: { cookies: [], origins: [] } } );

let admin: Admin;
let fixtures: Fixtures;
let plugins: Plugins;

test.beforeEach( async ( { baseURL } ) => {
	admin = await Admin.create( baseURL! );
	( { fixtures, plugins } = await admin.reset() );
} );

// Plugins that can't be installed where the tests run locally are skipped there. In CI every
// plugin in .wp-env.json is installed, so a missing one fails instead of hiding a broken setup.
function need( plugin: keyof Plugins ) {
	if ( ! plugins[ plugin ] && process.env.CI ) {
		throw new Error( `${ plugin } is not active in the test site.` );
	}
	test.skip( ! plugins[ plugin ], `${ plugin } is not installed here` );
}

async function lastLog( context: string ) {
	const log = await admin.log();
	return log.rows.find( ( r ) => r.context === context );
}

test.describe( 'Contact Form 7', () => {
	async function send( page: Page, message: string ) {
		await page.goto( fixtures.cf7! );
		const form = page.locator( 'form.wpcf7-form' );
		await form.getByLabel( 'Your name' ).fill( 'Kari Nordmann' );
		await form.getByLabel( 'Your email' ).fill( 'kari@example.test' );
		await form.getByLabel( 'Your message' ).fill( message );
		await form.getByRole( 'button', { name: 'Send' } ).click();
		// CF7 goes init → submitting → resetting → a final status.
		await expect( form ).toHaveAttribute(
			'data-status',
			/^(sent|spam|mail_failed|invalid|aborted|failed)$/
		);
		return form.getAttribute( 'data-status' );
	}

	test( 'a normal message is sent', async ( { page } ) => {
		need( 'cf7' );
		expect(
			await send( page, 'Do you deliver to Porsgrunn on Saturdays?' )
		).toBe( 'sent' );
		expect( await lastLog( 'cf7' ) ).toBeUndefined();
	} );

	test( 'a message with a listed term is marked as spam and logged', async ( {
		page,
	} ) => {
		need( 'cf7' );
		expect( await send( page, 'Get CHEAP   viagra here' ) ).toBe( 'spam' );
		const row = await lastLog( 'cf7' );
		expect( row ).toMatchObject( {
			action: 'spam',
			term: 'cheap viagra',
			field: 'your-message',
		} );
	} );
} );

test.describe( 'WPForms', () => {
	async function send( page: Page, message: string ) {
		await page.goto( fixtures.wpforms! );
		const form = page.locator( 'form.wpforms-form' );
		await form.getByLabel( 'Your name' ).fill( 'Kari Nordmann' );
		await form.getByLabel( 'Your message' ).fill( message );
		await form.getByRole( 'button', { name: 'Submit' } ).click();
		await page.waitForLoadState( 'load' );
	}

	test( 'a normal message is sent', async ( { page } ) => {
		need( 'wpforms' );
		await send( page, 'Do you deliver to Porsgrunn on Saturdays?' );
		await expect(
			page.getByText( 'Thanks, WPForms received it.' )
		).toBeVisible();
	} );

	test( 'a message with a listed term is rejected with our message', async ( {
		page,
	} ) => {
		need( 'wpforms' );
		await send( page, 'Visit e2e-spamword today' );
		await expect( page.getByText( BLOCKED ) ).toBeVisible();
		await expect(
			page.getByText( 'Thanks, WPForms received it.' )
		).toHaveCount( 0 );
		expect( await lastLog( 'wpforms' ) ).toMatchObject( {
			action: 'blocked',
			term: 'e2e-spamword',
			field: 'Your message',
		} );
	} );
} );

test.describe( 'Formidable Forms', () => {
	async function send( page: Page, message: string ) {
		await page.goto( fixtures.formidable! );
		const form = page.locator( 'form.frm-show-form' );
		await form.getByLabel( 'Your name' ).fill( 'Kari Nordmann' );
		await form.getByLabel( 'Your message' ).fill( message );
		await form.getByRole( 'button', { name: 'Submit' } ).click();
		await page.waitForLoadState( 'load' );
	}

	test( 'a normal message is sent', async ( { page } ) => {
		need( 'formidable' );
		await send( page, 'Do you deliver to Porsgrunn on Saturdays?' );
		await expect(
			page.getByText( 'Thanks, Formidable received it.' )
		).toBeVisible();
	} );

	test( 'a message with a listed term is rejected with our message', async ( {
		page,
	} ) => {
		need( 'formidable' );
		await send( page, 'Visit e2e-spamword today' );
		await expect( page.getByText( BLOCKED ) ).toBeVisible();
		await expect(
			page.getByText( 'Thanks, Formidable received it.' )
		).toHaveCount( 0 );
		expect( await lastLog( 'formidable' ) ).toMatchObject( {
			action: 'blocked',
			term: 'e2e-spamword',
			field: 'Your message',
		} );
	} );
} );

test.describe( 'Monitor only', () => {
	test( 'forms go through and the match is logged', async ( { page } ) => {
		need( 'formidable' );
		await admin.call( 'POST', '/unified-blocklist/v1/settings', {
			mode: 'monitor',
		} );
		await page.goto( fixtures.formidable! );
		const form = page.locator( 'form.frm-show-form' );
		await form.getByLabel( 'Your name' ).fill( 'Kari' );
		await form
			.getByLabel( 'Your message' )
			.fill( 'Visit e2e-spamword today' );
		await form.getByRole( 'button', { name: 'Submit' } ).click();
		await expect(
			page.getByText( 'Thanks, Formidable received it.' )
		).toBeVisible();
		expect( await lastLog( 'formidable' ) ).toMatchObject( {
			action: 'logged',
			term: 'e2e-spamword',
		} );
	} );
} );
