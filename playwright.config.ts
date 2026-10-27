import { defineConfig, devices } from '@playwright/test';

// The tests run against a real WordPress with Unified Blocklist, the form plugins listed in
// .wp-env.json and the test setup plugin in tests/e2e/fixtures. `npm run env:start` gives you
// one on http://localhost:8888 (wp-env, needs Docker). Point WP_BASE_URL somewhere else to
// use another site set up the same way.
const baseURL = process.env.WP_BASE_URL ?? 'http://localhost:8888';

export default defineConfig( {
	testDir: './tests/e2e/specs',
	globalSetup: './tests/e2e/global-setup.ts',
	// One WordPress, shared settings and log: tests can't run side by side.
	workers: 1,
	fullyParallel: false,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? [ [ 'list' ], [ 'html', { open: 'never' } ] ] : 'list',
	timeout: 45_000,
	use: {
		...devices[ 'Desktop Chrome' ],
		baseURL,
		storageState: 'tests/e2e/.auth/admin.json',
		viewport: { width: 1440, height: 1000 },
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		// Use a browser that's already installed instead of Playwright's own download.
		launchOptions: process.env.PW_CHROMIUM_PATH ? { executablePath: process.env.PW_CHROMIUM_PATH } : {},
	},
} );
