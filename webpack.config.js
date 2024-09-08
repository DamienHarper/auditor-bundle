const Encore = require('@symfony/webpack-encore');
const glob = require('glob-all');
const path = require('path');

if (!Encore.isRuntimeEnvironmentConfigured()) {
    Encore.configureRuntimeEnvironment(process.env.NODE_ENV || 'dev');
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
