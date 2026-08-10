import { describe, expect, it } from "vitest";

import { createFixtureAuthClient } from "@/lib/auth/auth-client";
import {
  authHref,
  readAuthQuery,
  safeAuthReturnPath,
} from "@/lib/auth/auth-query";

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

    expect(
      readAuthQuery({ returnTo: "/studio/sonora-house/settings/team" }),
    ).toEqual({
      email: undefined,
      returnTo: "/studio/sonora-house/settings/team",
    });
  });

  it("allows only exact authenticated return destinations", () => {
    expect(safeAuthReturnPath("/account/security")).toBe("/account/security");
    expect(safeAuthReturnPath("/studio/Sonora_2/settings/team")).toBe(
      "/studio/Sonora_2/settings/team",
    );
    expect(
      authHref("/login", {
        returnTo: "/studio/sonora-house/settings/team",
      }),
    ).toBe("/login?returnTo=%2Fstudio%2Fsonora-house%2Fsettings%2Fteam");

    for (const unsafe of [
      "https://attacker.example/studio/x/settings/team",
      "//attacker.example/studio/x/settings/team",
      "/studio/../settings/team",
      "/studio/%2Faccount/settings/team",
      "/studio/sonora/settings/team?next=https://attacker.example",
      "/studio/sonora/settings/team#fragment",
      "/studio/sonora/settings/team/extra",
      `/studio/${"a".repeat(141)}/settings/team`,
    ]) {
      expect(safeAuthReturnPath(unsafe), unsafe).toBeUndefined();
    }
  });
});
