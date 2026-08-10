import { describe, expect, it } from "vitest";

import { createFixtureAuthClient } from "@/lib/auth/auth-client";
import { readAuthQuery } from "@/lib/auth/auth-query";

describe("auth client boundary", () => {
  it("keeps the fixture adapter injectable without changing the transport contract", async () => {
    const client = createFixtureAuthClient({ delayMs: 0 });

    expect(client.security).toEqual({
      session: "http-only-cookie",
      csrf: "sanctum-xsrf-cookie",
      credentials: "same-origin",
    });

    const result = await client.login({
      email: "maya@studio.test",
      password: "Strong-Password-42!",
      remember: false,
    });

    expect(result.ok).toBe(true);
  });

  it("models registration as a generic unauthenticated delivery", async () => {
    const result = await createFixtureAuthClient().register({
      name: "Maya Ortiz",
      email: "maya@studio.test",
      password: "Strong-Password-42!",
      passwordConfirmation: "Strong-Password-42!",
    });

    expect(result).toEqual({
      ok: true,
      data: {
        sessionEstablished: false,
        message: "If those details can be used, check that email for next steps.",
      },
    });
  });

  it("sanitizes auth query values before they reach client components", () => {
    expect(
      readAuthQuery({
        email: ["MAYA@STUDIO.TEST", "ignored@studio.test"],
      }),
    ).toEqual({
      email: "maya@studio.test",
    });

    expect(
      readAuthQuery({
        email: "x".repeat(513),
        returnTo: "https://attacker.example/steal",
      }),
    ).toEqual({ email: undefined });

    expect(readAuthQuery({ returnTo: "/account/security" })).toEqual({
      email: undefined,
      returnTo: "/account/security",
    });
  });
});
