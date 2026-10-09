// The WordPress preset, with two rules eased for the admin app's JSX: props are documented
// without JSDoc types, and a view picks between loading, empty and a list in one expression.
module.exports = {
	extends: [ 'plugin:@wordpress/eslint-plugin/recommended' ],
	rules: {
		'jsdoc/require-param-type': 'off',
		'no-nested-ternary': 'off',
	},
};
