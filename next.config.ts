import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // This app is auth-gated and fully dynamic (per-request cookies + DB),
  // so Cache Components / PPR prerendering doesn't apply.
  cacheComponents: false,
  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
};

export default nextConfig;
