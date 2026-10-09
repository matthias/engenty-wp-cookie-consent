// The site of bin/test-site.sh, served with `wp server`; third parties are never reached (see the spec).
const { defineConfig, devices } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './tests/e2e',
	timeout: 30000,
	workers: 1,
	reporter: process.env.CI ? 'github' : 'list',
	use: {
		baseURL: process.env.WP_URL || 'http://127.0.0.1:8920',
		...devices[ 'Desktop Chrome' ],
		viewport: { width: 1280, height: 800 },
		locale: 'de-DE',
		// Locally the installed Chrome will do (PW_CHANNEL=chrome); CI installs Playwright's own.
		...( process.env.PW_CHANNEL ? { channel: process.env.PW_CHANNEL } : {} ),
	},
} );
