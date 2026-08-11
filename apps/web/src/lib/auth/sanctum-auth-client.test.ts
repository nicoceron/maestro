import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { createSanctumAuthClient } from "@/lib/auth/sanctum-auth-client";

const fetchMock = vi.fn<typeof fetch>();

function json(body: unknown, status = 200, headers?: HeadersInit) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json", ...headers },
  });
}

function csrfReady() {
  document.cookie = "XSRF-TOKEN=fixture%3Dtoken; path=/";
  fetchMock.mockResolvedValueOnce(new Response(null, { status: 204 }));
}

function verifiedUser() {
  return json({
    data: {
      id: 7,
      name: "Maya Ortiz",
      email: "maya@studio.test",
      email_verified_at: "2026-08-10T00:00:00Z",
    },
  });
}

function studios() {
  return json({
    data: [
      {
        id: 12,
        name: "Sonora House",
        slug: "sonora-house",
        timezone: "America/Bogota",
        currency: "USD",
        membership: { role: "owner", status: "active" },
        permissions: { manage: true },
      },
    ],
  });
}

beforeEach(() => {
  fetchMock.mockReset();
  vi.stubGlobal("fetch", fetchMock);
  document.cookie = "XSRF-TOKEN=; Max-Age=0; path=/";
});

afterEach(() => {
  document.cookie = "XSRF-TOKEN=; Max-Age=0; path=/";
  vi.unstubAllGlobals();
});

describe("Sanctum auth client contract", () => {
  it("bootstraps CSRF before login and uses decoded same-origin cookie headers", async () => {
    csrfReady();
    fetchMock
      .mockResolvedValueOnce(json({ two_factor: false }))
      .mockResolvedValueOnce(verifiedUser())
      .mockResolvedValueOnce(studios());
    const storageWrite = vi.spyOn(Storage.prototype, "setItem");
    const client = createSanctumAuthClient();

    const result = await client.login({
      email: "maya@studio.test",
      password: "Correct-Horse-42!",
      remember: true,
    });

    expect(result).toEqual({
      ok: true,
      data: {
        sessionEstablished: true,
        redirectTo: "/studio/sonora-house/home",
      },
    });
    expect(fetchMock.mock.calls.map(([path]) => path)).toEqual([
      "/sanctum/csrf-cookie",
      "/api/v1/auth/login",
      "/api/v1/auth/user",
      "/api/v1/studios",
    ]);
    expect(fetchMock.mock.calls[0][1]).toMatchObject({
      method: "GET",
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    });
    expect(fetchMock.mock.calls[1][1]).toMatchObject({
      method: "POST",
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-XSRF-TOKEN": "fixture=token",
      },
    });
    expect(JSON.parse(String(fetchMock.mock.calls[1][1]?.body))).toEqual({
      email: "maya@studio.test",
      password: "Correct-Horse-42!",
      remember: true,
    });
    expect(storageWrite).not.toHaveBeenCalled();
    storageWrite.mockRestore();
  });

  it("preserves an invitation through login without probing studio membership", async () => {
    csrfReady();
    fetchMock.mockResolvedValueOnce(json({ two_factor: false }));
    const client = createSanctumAuthClient();

    const result = await client.login({
      email: "teacher@studio.test",
      password: "Correct-Horse-42!",
      remember: false,
      invitationToken: "invite-token",
    });

    expect(result).toEqual({
      ok: true,
      data: {
        sessionEstablished: true,
        redirectTo: "/onboarding",
      },
    });
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it("uses current-user verification after login and keeps the authenticated session", async () => {
    csrfReady();
    fetchMock
      .mockResolvedValueOnce(json({ two_factor: false }))
      .mockResolvedValueOnce(
        json({
          data: {
            id: 7,
            name: "Maya Ortiz",
            email: "maya@studio.test",
            email_verified_at: null,
          },
        }),
      );

    const result = await createSanctumAuthClient().login({
      email: "maya@studio.test",
      password: "Correct-Horse-42!",
      remember: false,
    });

    expect(result).toMatchObject({ ok: false, error: { code: "email_unverified" } });
    expect(fetchMock.mock.calls.map(([path]) => path)).toEqual([
      "/sanctum/csrf-cookie",
      "/api/v1/auth/login",
      "/api/v1/auth/user",
    ]);
  });

  it("maps the invitation payload and keeps an accepted registration unauthenticated", async () => {
    csrfReady();
    fetchMock.mockResolvedValueOnce(
      json({ message: "Account-specific copy must not reach the UI." }, 202),
    );
    const storageWrite = vi.spyOn(Storage.prototype, "setItem");
    const client = createSanctumAuthClient();

    const result = await client.register({
      name: "Ari Bennett",
      email: "ari@studio.test",
      password: "Correct-Horse-42!",
      passwordConfirmation: "Correct-Horse-42!",
      invitationToken: "invite-token",
    });

    expect(JSON.parse(String(fetchMock.mock.calls[1][1]?.body))).toEqual({
      name: "Ari Bennett",
      email: "ari@studio.test",
      password: "Correct-Horse-42!",
      password_confirmation: "Correct-Horse-42!",
      invitation_token: "invite-token",
    });
    expect(fetchMock.mock.calls.map(([path]) => path)).toEqual([
      "/sanctum/csrf-cookie",
      "/api/v1/auth/register",
    ]);
    expect(result).toEqual({
      ok: true,
      data: {
        sessionEstablished: false,
        message: "If those details can be used, check that email for next steps.",
      },
    });
    expect(storageWrite).not.toHaveBeenCalled();
    storageWrite.mockRestore();
  });

  it("resets the password and logs in again to establish the new session", async () => {
    csrfReady();
    fetchMock
      .mockResolvedValueOnce(json({ message: "Password reset." }))
      .mockResolvedValueOnce(json({ two_factor: false }))
      .mockResolvedValueOnce(verifiedUser())
      .mockResolvedValueOnce(studios());
    const client = createSanctumAuthClient();

    const result = await client.resetPassword({
      email: "maya@studio.test",
      token: "reset-token",
      password: "Even-Stronger-84!",
      passwordConfirmation: "Even-Stronger-84!",
    });

    expect(fetchMock.mock.calls.map(([path]) => path)).toEqual([
      "/sanctum/csrf-cookie",
      "/api/v1/auth/reset-password",
      "/api/v1/auth/login",
      "/api/v1/auth/user",
      "/api/v1/studios",
    ]);
    expect(JSON.parse(String(fetchMock.mock.calls[1][1]?.body))).toEqual({
      email: "maya@studio.test",
      token: "reset-token",
      password: "Even-Stronger-84!",
      password_confirmation: "Even-Stronger-84!",
    });
    expect(result).toMatchObject({ ok: true, data: { sessionEstablished: true } });
  });

  it("maps Laravel validation, rate-limit, and network failures", async () => {
    csrfReady();
    fetchMock.mockResolvedValueOnce(
      json(
        {
          message: "The given data was invalid.",
          errors: {
            password: ["The password must be at least 12 characters."],
            password_confirmation: ["The password confirmation does not match."],
          },
        },
        422,
      ),
    );
    const validation = await createSanctumAuthClient().register({
      name: "Maya",
      email: "maya@studio.test",
      password: "weak",
      passwordConfirmation: "different",
    });
    expect(validation).toMatchObject({
      ok: false,
      error: {
        code: "validation_failed",
        fieldErrors: {
          password: "The password must be at least 12 characters.",
          passwordConfirmation: "The password confirmation does not match.",
        },
      },
    });

    fetchMock.mockReset();
    csrfReady();
    fetchMock.mockResolvedValueOnce(
      json({ message: "Too Many Attempts." }, 429, { "Retry-After": "45" }),
    );
    const limited = await createSanctumAuthClient().login({
      email: "maya@studio.test",
      password: "wrong",
      remember: false,
    });
    expect(limited).toMatchObject({
      ok: false,
      error: { code: "rate_limited", retryAfterSeconds: 45 },
    });

    fetchMock.mockReset();
    fetchMock.mockRejectedValueOnce(new TypeError("offline"));
    const network = await createSanctumAuthClient().requestPasswordReset({
      email: "maya@studio.test",
    });
    expect(network).toMatchObject({
      ok: false,
      error: { code: "service_unavailable" },
    });
  });

  it("sends onboarding preferences with a nested new-studio payload", async () => {
    csrfReady();
    fetchMock.mockResolvedValueOnce(
      json(
        {
          data: {
            id: 12,
            name: "North Star Music",
            slug: "north-star-music",
            timezone: "America/Bogota",
            currency: "USD",
            membership: { role: "owner", status: "active" },
            permissions: { manage: true },
          },
        },
        201,
      ),
    );
    const result = await createSanctumAuthClient().completeOnboarding({
      firstName: "Maya",
      workspaceMode: "administrator",
      studioName: "North Star Music",
      studioSlug: "north-star-music",
      timeZone: "America/Bogota",
      currency: "USD",
      primaryGoal: "billing",
    });

    expect(JSON.parse(String(fetchMock.mock.calls[1][1]?.body))).toEqual({
      preferred_name: "Maya",
      workspace_mode: "administrator",
      primary_goal: "billing",
      studio: {
        name: "North Star Music",
        slug: "north-star-music",
        timezone: "America/Bogota",
        currency: "USD",
      },
    });
    expect(result).toMatchObject({
      ok: true,
      data: { redirectTo: "/studio/north-star-music/home" },
    });
  });

  it("sends invitation onboarding preferences without client-authored studio data", async () => {
    const invitationToken = "a".repeat(64);
    csrfReady();
    fetchMock.mockResolvedValueOnce(
      json({
        data: {
          id: 18,
          name: "Inviting Studio",
          slug: "inviting-studio",
          timezone: "America/Bogota",
          currency: "USD",
          membership: { role: "teacher", status: "active" },
          permissions: { manage: false },
        },
      }),
    );

    const result = await createSanctumAuthClient().completeOnboarding({
      firstName: "Noah",
      workspaceMode: "teacher",
      timeZone: "America/Bogota",
      currency: "USD",
      primaryGoal: "teaching",
      invitationToken,
    });

    expect(JSON.parse(String(fetchMock.mock.calls[1][1]?.body))).toEqual({
      preferred_name: "Noah",
      workspace_mode: "teacher",
      primary_goal: "teaching",
      invitation_token: invitationToken,
    });
    expect(result).toMatchObject({
      ok: true,
      data: { redirectTo: "/studio/inviting-studio/home" },
    });
  });

  it("resends verification only through the authenticated session route", async () => {
    csrfReady();
    fetchMock.mockResolvedValueOnce(json({}, 202));

    const result = await createSanctumAuthClient().resendVerification();

    expect(result).toMatchObject({ ok: true });
    expect(fetchMock.mock.calls[1][0]).toBe(
      "/api/v1/auth/email/verification-notification",
    );
    expect(fetchMock.mock.calls[1][1]?.body).toBeUndefined();
  });

  it("maps authenticated endpoint failures to an intentional sign-in state", async () => {
    csrfReady();
    fetchMock.mockResolvedValueOnce(json({ message: "Unauthenticated." }, 401));

    const result = await createSanctumAuthClient().resendVerification();

    expect(result).toEqual({
      ok: false,
      error: {
        code: "authentication_required",
        message: "Your secure session has ended. Sign in and try again.",
      },
    });
  });

  it("reboots CSRF and replays once when a cached bootstrap loses its cookie", async () => {
    const setCsrfCookie = async () => {
      document.cookie = "XSRF-TOKEN=renewed%3Dtoken; path=/";
      return new Response(null, { status: 204 });
    };
    fetchMock
      .mockImplementationOnce(setCsrfCookie)
      .mockResolvedValueOnce(json({ message: "Reset requested." }));
    const client = createSanctumAuthClient();

    await client.requestPasswordReset({ email: "maya@studio.test" });
    document.cookie = "XSRF-TOKEN=; Max-Age=0; path=/";
    fetchMock
      .mockImplementationOnce(setCsrfCookie)
      .mockResolvedValueOnce(json({ message: "Reset requested again." }));

    const result = await client.requestPasswordReset({
      email: "maya@studio.test",
    });

    expect(result).toMatchObject({ ok: true });
    expect(fetchMock.mock.calls.map(([path]) => path)).toEqual([
      "/sanctum/csrf-cookie",
      "/api/v1/auth/forgot-password",
      "/sanctum/csrf-cookie",
      "/api/v1/auth/forgot-password",
    ]);
    expect(fetchMock.mock.calls[3][1]).toMatchObject({
      headers: expect.objectContaining({
        "X-XSRF-TOKEN": "renewed=token",
      }),
    });
  });

  it("returns the Fortify two-factor challenge without probing an authenticated user", async () => {
    csrfReady();
    fetchMock.mockResolvedValueOnce(json({ two_factor: true }));

    const result = await createSanctumAuthClient().login({
      email: "maya@studio.test",
      password: "Correct-Horse-42!",
      remember: true,
    });

    expect(result).toEqual({
      ok: true,
      data: {
        sessionEstablished: false,
        requiresTwoFactor: true,
        redirectTo: "/two-factor-challenge",
      },
    });
    expect(fetchMock.mock.calls.map(([path]) => path)).toEqual([
      "/sanctum/csrf-cookie",
      "/api/v1/auth/login",
    ]);
  });

  it("carries an exact tenant-team continuation through the two-factor challenge", async () => {
    csrfReady();
    fetchMock.mockResolvedValueOnce(json({ two_factor: true }));

    const result = await createSanctumAuthClient().login({
      email: "maya@studio.test",
      password: "Correct-Horse-42!",
      remember: true,
      continueTo: "/studio/sonora-house/settings/team",
    });

    expect(result).toEqual({
      ok: true,
      data: {
        sessionEstablished: false,
        requiresTwoFactor: true,
        redirectTo:
          "/two-factor-challenge?returnTo=%2Fstudio%2Fsonora-house%2Fsettings%2Fteam",
      },
    });
  });

  it("preserves the allowlisted security-center continuation", async () => {
    csrfReady();
    fetchMock
      .mockResolvedValueOnce(json({ two_factor: false }))
      .mockResolvedValueOnce(verifiedUser());

    const result = await createSanctumAuthClient().login({
      email: "maya@studio.test",
      password: "Correct-Horse-42!",
      remember: false,
      continueTo: "/account/security",
    });

    expect(result).toEqual({
      ok: true,
      data: {
        sessionEstablished: true,
        redirectTo: "/account/security",
      },
    });
    expect(fetchMock.mock.calls.map(([path]) => path)).toEqual([
      "/sanctum/csrf-cookie",
      "/api/v1/auth/login",
      "/api/v1/auth/user",
    ]);
  });

  it("maps stale recent-password middleware and confirms through Fortify", async () => {
    fetchMock.mockResolvedValueOnce(
      json({ message: "Password confirmation required." }, 423),
    );
    const client = createSanctumAuthClient();

    const status = await client.getPasswordConfirmationStatus();
    expect(status).toMatchObject({
      ok: false,
      error: { code: "recent_password_required" },
    });

    fetchMock.mockReset();
    csrfReady();
    fetchMock.mockResolvedValueOnce(json({}, 201));
    const confirmed = await client.confirmPassword("Correct-Horse-42!");
    expect(confirmed).toEqual({ ok: true, data: null });
    expect(fetchMock.mock.calls[1][0]).toBe(
      "/api/v1/auth/user/confirm-password",
    );
    expect(JSON.parse(String(fetchMock.mock.calls[1][1]?.body))).toEqual({
      password: "Correct-Horse-42!",
    });
  });

  it("uses official TOTP routes and refetches raw recovery-code arrays", async () => {
    csrfReady();
    fetchMock
      .mockResolvedValueOnce(json({}, 200))
      .mockResolvedValueOnce(json(["code-one", "code-two"]));
    const client = createSanctumAuthClient();

    const confirmation = await client.confirmTwoFactor("123456");

    expect(confirmation).toEqual({
      ok: true,
      data: { recoveryCodes: ["code-one", "code-two"] },
    });
    expect(fetchMock.mock.calls.map(([path]) => path)).toEqual([
      "/sanctum/csrf-cookie",
      "/api/v1/auth/user/confirmed-two-factor-authentication",
      "/api/v1/auth/user/two-factor-recovery-codes",
    ]);
    expect(JSON.parse(String(fetchMock.mock.calls[1][1]?.body))).toEqual({
      code: "123456",
    });

    fetchMock.mockReset();
    csrfReady();
    fetchMock
      .mockResolvedValueOnce(json({}, 200))
      .mockResolvedValueOnce(json(["fresh-one", "fresh-two"]));
    const regenerated = await createSanctumAuthClient().regenerateRecoveryCodes();
    expect(regenerated).toEqual({
      ok: true,
      data: { recoveryCodes: ["fresh-one", "fresh-two"] },
    });
  });

  it("maps passkey ceremonies and session revocation without token persistence", async () => {
    const storageWrite = vi.spyOn(Storage.prototype, "setItem");
    fetchMock.mockResolvedValueOnce(json({ options: { challenge: "YQ" } }));
    const client = createSanctumAuthClient();
    expect(await client.getPasskeyRegistrationOptions()).toEqual({
      ok: true,
      data: { challenge: "YQ" },
    });

    fetchMock.mockReset();
    fetchMock.mockResolvedValueOnce(json({ options: { challenge: "Yg" } }));
    expect(await client.getPasskeyConfirmationOptions()).toEqual({
      ok: true,
      data: { challenge: "Yg" },
    });

    fetchMock.mockReset();
    csrfReady();
    fetchMock.mockResolvedValueOnce(json({ status: "passkey-created" }));
    const credential = { id: "credential", response: { signature: "abc" } };
    await client.registerPasskey("Studio Mac", credential);
    expect(fetchMock.mock.calls[1][0]).toBe("/api/v1/auth/user/passkeys");
    expect(JSON.parse(String(fetchMock.mock.calls[1][1]?.body))).toEqual({
      name: "Studio Mac",
      credential,
    });

    fetchMock.mockReset();
    fetchMock.mockResolvedValueOnce(json({ status: "confirmed" }));
    await client.confirmWithPasskey(credential);
    expect(fetchMock.mock.calls[0][0]).toBe("/api/v1/auth/passkeys/confirm");
    expect(JSON.parse(String(fetchMock.mock.calls[0][1]?.body))).toEqual({
      credential,
    });

    fetchMock.mockReset();
    csrfReady();
    fetchMock.mockResolvedValueOnce(new Response(null, { status: 204 }));
    await createSanctumAuthClient().revokeSession("01JSESSION");
    expect(fetchMock.mock.calls[1][0]).toBe(
      "/api/v1/auth/sessions/01JSESSION",
    );
    expect(fetchMock.mock.calls[1][1]).toMatchObject({ method: "DELETE" });
    expect(storageWrite).not.toHaveBeenCalled();
    storageWrite.mockRestore();
  });
});
