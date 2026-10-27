import { request, type FullConfig } from '@playwright/test';
import { mkdirSync } from 'node:fs';

export const AUTH_FILE = 'tests/e2e/.auth/admin.json';

// Logs in once and saves the cookies, so each test starts logged in as admin.
export default async function globalSetup( config: FullConfig ) {
	const baseURL = config.projects[ 0 ].use.baseURL as string;
	mkdirSync( 'tests/e2e/.auth', { recursive: true } );

	const ctx = await request.newContext( { baseURL } );
	const res = await ctx.get( '/wp-login.php' );
	if ( ! res.ok() ) {
		throw new Error(
			`WordPress isn't answering at ${ baseURL } (${ res.status() }). Start it with "npm run env:start".`
		);
	}
	await ctx.post( '/wp-login.php', {
		form: {
			log: process.env.WP_USERNAME ?? 'admin',
			pwd: process.env.WP_PASSWORD ?? 'password',
			testcookie: '1',
			redirect_to: '/wp-admin/',
		},
		maxRedirects: 0,
	} );
	const cookies = ( await ctx.storageState() ).cookies;
	if (
		! cookies.some( ( c ) => c.name.startsWith( 'wordpress_logged_in_' ) )
	) {
		throw new Error(
			'Could not log in as admin with password "password".'
		);
	}
	await ctx.storageState( { path: AUTH_FILE } );
	await ctx.dispose();
}
