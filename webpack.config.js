const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		'bom-editor/index': path.resolve( __dirname, 'assets/src/bom-editor/index.js' ),
		'inventory/index': path.resolve( __dirname, 'assets/src/inventory/index.js' ),
		'manufacture/index': path.resolve( __dirname, 'assets/src/manufacture/index.js' ),
		'purchasing/index': path.resolve( __dirname, 'assets/src/purchasing/index.js' ),
		'reports/index': path.resolve( __dirname, 'assets/src/reports/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'assets/build' ),
	},
};
