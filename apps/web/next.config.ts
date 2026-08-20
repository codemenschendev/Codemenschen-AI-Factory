import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Self-contained server bundle for the Docker image (infra/docker/web.Dockerfile)
  output: "standalone",
  // The pricing engine ships as TS source from the workspace package
  transpilePackages: ["@ai-factory/pricing"],
};

export default nextConfig;
