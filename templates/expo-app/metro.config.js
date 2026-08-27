// Metro config (factory-managed). expo-sqlite ships wa-sqlite.wasm for the web
// build; Metro only bundles it when "wasm" is an asset extension.
const { getDefaultConfig } = require("expo/metro-config");
const config = getDefaultConfig(__dirname);
if (!config.resolver.assetExts.includes("wasm")) config.resolver.assetExts.push("wasm");
module.exports = config;
