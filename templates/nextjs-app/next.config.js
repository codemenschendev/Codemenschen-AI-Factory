/** @type {import('next').NextConfig} */
// NEXT_OUTPUT=export + NEXT_BASE_PATH are set by the factory's release stage
// to produce the static web preview; production builds stay standalone.
const isExport = process.env.NEXT_OUTPUT === "export";
module.exports = {
  output: isExport ? "export" : "standalone",
  basePath: process.env.NEXT_BASE_PATH || "",
  ...(isExport ? { images: { unoptimized: true }, trailingSlash: true } : {}),
};
