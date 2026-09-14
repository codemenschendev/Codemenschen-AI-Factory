import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Self-contained server bundle for the Docker image (infra/docker/web.Dockerfile)
  output: "standalone",
  // The pricing engine ships as TS source from the workspace package
  transpilePackages: ["@ai-factory/pricing"],
  // The DEV site (appwerk-dev.codemenschen.at) runs `next dev` behind Apache. Dev-only setting,
  // the production build ignores it.
  allowedDevOrigins: ["appwerk-dev.codemenschen.at"],
};

export default nextConfig;
