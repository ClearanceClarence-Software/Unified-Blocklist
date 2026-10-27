import {
	expect,
	request,
	type APIRequestContext,
	type Page,
} from '@playwright/test';

import { AUTH_FILE } from './global-setup';

export interface Fixtures {
	postId: number;
	post: string;
	cf7: string | null;
	wpforms: string | null;
	formidable: string | null;
}

export interface Plugins {
	cf7: boolean;
	flamingo: boolean;
	wpforms: boolean;
	formidable: boolean;
}

// The message every form plugin shows when Unified Blocklist stops a submission.
export const BLOCKED =
	'Your message could not be sent because it contains blocked content.';

/**
 * A REST client logged in as admin, with a wp_rest nonce.
 * Paths go through ?rest_route= so it works with any permalink setting.
 */
export class Admin {
	private constructor(
		public readonly ctx: APIRequestContext,
		private readonly nonce: string
	) {}

	static async create( baseURL: string ): Promise< Admin > {
		const ctx = await request.newContext( {
			baseURL,
			storageState: AUTH_FILE,
		} );
		const nonce = (
			await (
				await ctx.get( '/wp-admin/admin-ajax.php?action=rest-nonce' )
			).text()
		).trim();
		return new Admin( ctx, nonce );
	}

	async call(
		method: string,
		route: string,
		body?: unknown
	): Promise< any > {
		const res = await this.ctx.fetch(
			`/?${ new URLSearchParams( { rest_route: route } ) }`,
			{
				method,
				headers: { 'X-WP-Nonce': this.nonce },
				...( body !== undefined ? { data: body } : {} ),
			}
		);
		expect(
			res.status(),
			`${ method } ${ route }: ${ await res.text() }`
		).toBeLessThan( 300 );
		return res.json();
	}

	reset(): Promise< { fixtures: Fixtures; plugins: Plugins } > {
		return this.call( 'POST', '/ub-test/v1/reset' );
	}

	async log() {
		return ( await this.call( 'GET', '/unified-blocklist/v1/log' ) ) as {
			total: number;
			rows: Array< {
				context: string;
				action: string;
				term: string;
				field: string;
				hits: number;
			} >;
		};
	}

	state() {
		return this.call( 'GET', '/unified-blocklist/v1/state' );
	}

	settings() {
		return this.call( 'GET', '/unified-blocklist/v1/settings' );
	}
}

/**
 * Posts a comment as a logged-out visitor, through the real wp-comments-post.php.
 * @param baseURL
 * @param postId
 * @param comment
 * @param author
 */
export async function postComment(
	baseURL: string,
	postId: number,
	comment: string,
	author = 'Visitor'
) {
	// An explicit empty state: inside a test, new contexts otherwise pick up the admin login.
	const ctx = await request.newContext( {
		baseURL,
		storageState: { cookies: [], origins: [] },
	} );
	await ctx.post( '/wp-comments-post.php', {
		form: {
			comment_post_ID: String( postId ),
			author,
			email: `${ author.toLowerCase().replace( /\W+/g, '' ) || 'visitor' }@example.test`,
			url: '',
			comment,
			comment_parent: '0',
		},
		maxRedirects: 0,
	} );
	await ctx.dispose();
}

export async function openApp( page: Page, view = '' ) {
	await page.goto(
		`/wp-admin/options-general.php?page=unified-blocklist${ view ? '#' + view : '' }`
	);
	await expect( page.locator( '.ub-topbar' ) ).toBeVisible();
}
