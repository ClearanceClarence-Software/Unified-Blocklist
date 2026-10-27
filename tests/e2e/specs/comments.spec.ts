import { expect, test } from '@playwright/test';

import { Admin, openApp, postComment, type Fixtures } from '../helpers';

let admin: Admin;
let fixtures: Fixtures;

test.beforeEach( async ( { baseURL } ) => {
	admin = await Admin.create( baseURL! );
	( { fixtures } = await admin.reset() );
} );

test( 'a comment with a listed term goes to Spam; repeats share one log row', async ( {
	page,
	baseURL,
} ) => {
	for ( let i = 0; i < 3; i++ ) {
		await postComment(
			baseURL!,
			fixtures.postId,
			'Get your cheap viagra deal',
			`Bot ${ i }`
		);
	}
	await postComment(
		baseURL!,
		fixtures.postId,
		'Lovely post, thanks for writing it.',
		'Kari'
	);

	const approved = await admin.call( 'GET', '/wp/v2/comments' );
	expect(
		approved.map( ( c: { author_name: string } ) => c.author_name )
	).toEqual( [ 'Kari' ] );

	const log = await admin.log();
	expect( log.total ).toBe( 1 );
	expect( log.rows[ 0 ] ).toMatchObject( {
		context: 'comment',
		action: 'spam',
		term: 'cheap viagra',
		hits: 3,
	} );

	await openApp( page, 'log' );
	const row = page.locator( '.ub-log-row' ).first();
	await expect( row.locator( '.ub-term' ) ).toHaveText( 'cheap viagra' );
	await expect( row.locator( '.ub-repeat' ) ).toHaveText( '×3' );
} );

test( 'rescuing a blocked comment suggests allowing the term', async ( {
	page,
	baseURL,
} ) => {
	await postComment(
		baseURL!,
		fixtures.postId,
		'Ha, the e2e-spamword mug is my favorite.',
		'Real Reader'
	);

	// A moderator clicks "Not Spam" in Comments › Spam.
	await page.goto( '/wp-admin/edit-comments.php?comment_status=spam' );
	const row = page.locator( '#the-comment-list tr', {
		hasText: 'Real Reader',
	} );
	await row.hover();
	await row
		.getByRole( 'button', {
			name: /Not Spam|Restore this comment from the spam/,
		} )
		.click();
	await expect( row ).toBeHidden();

	await openApp( page, 'overview' );
	const card = page.locator( '.ub-rescues' );
	await expect( card ).toContainText(
		'Moderators rescued messages stopped by 1 term'
	);
	await expect( card.locator( '.ub-term' ) ).toHaveText( 'e2e-spamword' );
	await expect( card ).toContainText( 'favorite' );

	await card.getByRole( 'button', { name: 'Allow' } ).click();
	await expect( card ).toBeHidden();
	expect( ( await admin.settings() ).exceptions ).toContain( 'e2e-spamword' );
} );

test( 'Keep blocking dismisses the suggestion and keeps the term', async ( {
	page,
	baseURL,
} ) => {
	await postComment(
		baseURL!,
		fixtures.postId,
		'casino-e2e night was fun',
		'Another Reader'
	);
	await page.goto( '/wp-admin/edit-comments.php?comment_status=spam' );
	const row = page.locator( '#the-comment-list tr', {
		hasText: 'Another Reader',
	} );
	await row.hover();
	await row
		.getByRole( 'button', {
			name: /Not Spam|Restore this comment from the spam/,
		} )
		.click();
	await expect( row ).toBeHidden();

	await openApp( page, 'log' );
	const card = page.locator( '.ub-rescues' );
	await expect( card.locator( '.ub-term' ) ).toHaveText( 'casino-e2e' );
	await card.getByRole( 'button', { name: 'Keep blocking' } ).click();
	await expect( card ).toBeHidden();
	expect( ( await admin.settings() ).exceptions ).not.toContain(
		'casino-e2e'
	);
	expect( ( await admin.state() ).rescues ).toEqual( [] );
} );
