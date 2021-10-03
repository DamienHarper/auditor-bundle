const Encore = require('@symfony/webpack-encore');
const PurgeCssPlugin = require('purgecss-webpack-plugin');
const glob = require('glob-all');
const path = require('path');

// Manually configure the runtime environment if not already configured yet by the "encore" command.
// It's useful when you use tools that rely on webpack.config.js file.
if (!Encore.isRuntimeEnvironmentConfigured()) {
    Encore.configureRuntimeEnvironment(process.env.NODE_ENV || 'dev');
}

if (Encore.isProduction()) {
    Encore.addPlugin(new PurgeCssPlugin({
        paths: glob.sync([
            path.join(__dirname, 'src/Resources/views/**/**/*.html.twig'),
            path.join(__dirname, 'src/Resources/views/**/*.html.twig'),
            path.join(__dirname, 'src/Resources/assets/**/*.css'),
            path.join(__dirname, 'src/Resources/assets/**/*.js'),
        ]),
        defaultExtractor: (content) => {
            return content.match(/[\w-./:]+(?<!:)/g) || [];
        }
    }));
}

Encore
    // directory where compiled assets will be stored
    .setOutputPath('src/Resources/public/')
    // public path used by the web server to access the output path
    .setPublicPath('/')
    // only needed for CDN's or sub-directory deploy
    .setManifestKeyPrefix('bundles/auditor')

    /*
     * ENTRY CONFIG
     *
     * Each entry will result in one JavaScript file (e.g. app.js)
     * and one CSS file (e.g. app.css) if your JavaScript imports CSS.
     */
    .addEntry('app', './src/Resources/assets/app.js')

    // enables the Symfony UX Stimulus bridge (used in assets/bootstrap.js)
    // .enableStimulusBridge('./assets/controllers.json')

    // When enabled, Webpack "splits" your files into smaller pieces for greater optimization.
//    .splitEntryChunks()

    // will require an extra script tag for runtime.js
    // but, you probably want this, unless you're building a single-page app
    .disableSingleRuntimeChunk()

    /*
     * FEATURE CONFIG
     *
     * Enable & configure other features below. For a full
     * list of features, see:
     * https://symfony.com/doc/current/frontend.html#adding-more-features
     */
    .cleanupOutputBeforeBuild()
    .enableSourceMaps(false)
    .enableVersioning(false)

    .enablePostCssLoader()
;

module.exports = Encore.getWebpackConfig();
