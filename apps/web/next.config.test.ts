import { describe, expect, it } from "vitest";

import nextConfig, { securityHeaders } from "./next.config";

describe("Next security headers", () => {
  it("applies a token-safe baseline to every route", async () => {
    const rules = await nextConfig.headers?.();
    expect(rules).toHaveLength(1);
    expect(rules?.[0].source).toBe("/:path*");
    expect(Object.fromEntries((rules?.[0].headers ?? []).map(({ key, value }) => [key, value]))).toMatchObject({
      "Referrer-Policy": "no-referrer",
      "X-Content-Type-Options": "nosniff",
      "X-Frame-Options": "DENY",
      "Content-Security-Policy":
        "base-uri 'self'; object-src 'none'; frame-ancestors 'none'",
      "Cross-Origin-Opener-Policy": "same-origin",
    });
  });

  it("adds HSTS only in production", () => {
    expect(
      securityHeaders("test").some(
        ({ key }) => key === "Strict-Transport-Security",
      ),
    ).toBe(false);
    expect(Object.fromEntries(securityHeaders("production").map(({ key, value }) => [key, value]))).toMatchObject({
      "Strict-Transport-Security":
        "max-age=63072000; includeSubDomains; preload",
    });
  });
});
