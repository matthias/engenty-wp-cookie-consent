const defaults = require( '@wordpress/scripts/config/webpack.config' );

// Three bundles: the dialog on the site (no dependencies), the admin app (its pages under
// "Cookies") and the blocks' editor.
module.exports = {
	...defaults,
	entry: {
		dialog: './src/dialog/index.ts',
		admin: './src/admin/index.js',
		blocks: './src/blocks/index.js',
	},
};
