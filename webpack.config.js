const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: async () => {
		const defaultEntries =
			typeof defaultConfig.entry === 'function'
				? await defaultConfig.entry()
				: defaultConfig.entry;

		return {
			...defaultEntries,
			'variations/index': path.resolve(
				__dirname,
				'src/variations/index.js'
			),
		};
	},
};
