import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AuthClientProvider } from "@/components/auth/auth-client-provider";
import { SecurityCenter } from "@/components/security/security-center";
import {
  createFixtureAuthClient,
  type AuthClient,
  type BrowserSessionDto,
} from "@/lib/auth/auth-client";
import type { WebAuthnCeremony } from "@/lib/auth/webauthn";

const navigation = vi.hoisted(() => ({ replace: vi.fn() }));

vi.mock("next/navigation", () => ({
  usePathname: () => window.location.pathname,
  useRouter: () => ({ replace: navigation.replace }),
}));

vi.mock("next/image", () => ({
  default: ({
    alt,
    ...props
  }: React.ImgHTMLAttributes<HTMLImageElement>) => {
    // eslint-disable-next-line @next/next/no-img-element
    return <img alt={alt ?? ""} {...props} />;
  },
}));

const supportedCeremony: WebAuthnCeremony = {
  isSupported: () => true,
  create: vi.fn(async () => ({ id: "credential" })),
  get: vi.fn(async () => ({ id: "credential" })),
};

function renderCenter(client: AuthClient, ceremony = supportedCeremony) {
  window.history.replaceState({}, "", "/account/security");
  return render(
    <AuthClientProvider client={client}>
      <SecurityCenter ceremony={ceremony} />
    </AuthClientProvider>,
  );
}

beforeEach(() => {
  navigation.replace.mockReset();
  vi.clearAllMocks();
});

describe("account security center", () => {
  it("recovers honestly when the cookie session has expired", async () => {
    const client: AuthClient = {
      ...createFixtureAuthClient(),
      getCurrentUser: async () => ({
        ok: false,
        error: {
          code: "authentication_required",
          message: "Your session has expired. Please sign in again.",
        },
      }),
    };
    renderCenter(client);

    expect(await screen.findByRole("heading", { name: "Your secure session ended" })).toBeInTheDocument();
    expect(screen.getByRole("alert")).toHaveTextContent("session has expired");
    expect(screen.getByRole("link", { name: "Sign in again" })).toHaveAttribute(
      "href",
      "/login?returnTo=%2Faccount%2Fsecurity",
    );
  });

  it("prompts for a recent password, focuses it, then resumes TOTP setup", async () => {
    const fixture = createFixtureAuthClient();
    const enableTwoFactor = vi
      .fn<AuthClient["enableTwoFactor"]>()
      .mockResolvedValueOnce({
        ok: false,
        error: {
          code: "recent_password_required",
          message: "Password confirmation required.",
        },
      })
      .mockResolvedValueOnce({ ok: true, data: null });
    const confirmPassword = vi.fn(fixture.confirmPassword);
    const client: AuthClient = { ...fixture, enableTwoFactor, confirmPassword };
    renderCenter(client);

    fireEvent.click(await screen.findByRole("button", { name: "Enable two-step verification" }));
    const password = await screen.findByLabelText("Current password");
    expect(password).toHaveFocus();
    fireEvent.change(password, { target: { value: "Correct-Horse-42!" } });
    fireEvent.click(screen.getByRole("button", { name: "Confirm password" }));

    expect(await screen.findByAltText("QR code for adding Maestro to an authenticator app")).toBeInTheDocument();
    expect(confirmPassword).toHaveBeenCalledWith("Correct-Horse-42!");
    expect(enableTwoFactor).toHaveBeenCalledTimes(2);
  });

  it("can satisfy the recent-auth challenge with an existing passkey", async () => {
    const fixture = createFixtureAuthClient();
    const enableTwoFactor = vi
      .fn<AuthClient["enableTwoFactor"]>()
      .mockResolvedValueOnce({
        ok: false,
        error: {
          code: "recent_password_required",
          message: "Password confirmation required.",
        },
      })
      .mockResolvedValueOnce({ ok: true, data: null });
    const confirmWithPasskey = vi.fn(fixture.confirmWithPasskey);
    const ceremony: WebAuthnCeremony = {
      ...supportedCeremony,
      get: vi.fn(async () => ({ id: "recent-auth-credential" })),
    };
    renderCenter({ ...fixture, enableTwoFactor, confirmWithPasskey }, ceremony);

    fireEvent.click(await screen.findByRole("button", { name: "Enable two-step verification" }));
    fireEvent.click(
      await screen.findByRole("button", {
        name: "Confirm with a passkey instead",
      }),
    );

    expect(await screen.findByAltText("QR code for adding Maestro to an authenticator app")).toBeInTheDocument();
    expect(confirmWithPasskey).toHaveBeenCalledWith({
      id: "recent-auth-credential",
    });
    expect(enableTwoFactor).toHaveBeenCalledTimes(2);
  });

  it("restores focus when the recent-auth dialog is canceled", async () => {
    const fixture = createFixtureAuthClient();
    const client: AuthClient = {
      ...fixture,
      enableTwoFactor: async () => ({
        ok: false,
        error: {
          code: "recent_password_required",
          message: "Password confirmation required.",
        },
      }),
    };
    renderCenter(client);
    const trigger = await screen.findByRole("button", {
      name: "Enable two-step verification",
    });

    trigger.focus();
    fireEvent.click(trigger);
    fireEvent.click(
      await screen.findByRole("button", {
        name: "Close identity confirmation",
      }),
    );

    await waitFor(() => expect(trigger).toHaveFocus());
  });

  it("confirms TOTP and exposes one-time recovery codes without browser storage", async () => {
    const client = createFixtureAuthClient();
    const storageWrite = vi.spyOn(Storage.prototype, "setItem");
    renderCenter(client);

    fireEvent.click(await screen.findByRole("button", { name: "Enable two-step verification" }));
    const code = await screen.findByLabelText("Current 6-digit authenticator code");
    fireEvent.change(code, { target: { value: "123456" } });
    fireEvent.click(screen.getByRole("button", { name: "Confirm setup" }));

    expect(await screen.findByText("fixture-recovery-one")).toBeInTheDocument();
    expect(screen.getByRole("status")).toHaveTextContent("Two-step verification is on");
    expect(storageWrite).not.toHaveBeenCalled();
    storageWrite.mockRestore();
  });

  it("cancels an unconfirmed TOTP seed through the backend", async () => {
    const fixture = createFixtureAuthClient();
    const disableTwoFactor = vi.fn(fixture.disableTwoFactor);
    renderCenter({ ...fixture, disableTwoFactor });

    fireEvent.click(await screen.findByRole("button", { name: "Enable two-step verification" }));
    expect(await screen.findByAltText("QR code for adding Maestro to an authenticator app")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Cancel setup" }));

    await waitFor(() => expect(disableTwoFactor).toHaveBeenCalled());
    expect(screen.queryByAltText("QR code for adding Maestro to an authenticator app")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Enable two-step verification" })).toBeInTheDocument();
  });

  it("creates a passkey through the injected WebAuthn ceremony and reloads inventory", async () => {
    const fixture = createFixtureAuthClient();
    const passkey = {
      id: "passkey-1",
      name: "Maya’s MacBook",
      lastUsedAt: null,
      createdAt: "2026-08-10T00:00:00Z",
    };
    const getPasskeys = vi
      .fn<AuthClient["getPasskeys"]>()
      .mockResolvedValueOnce({ ok: true, data: [] })
      .mockResolvedValueOnce({ ok: true, data: [passkey] });
    const registerPasskey = vi.fn(fixture.registerPasskey);
    const ceremony: WebAuthnCeremony = {
      ...supportedCeremony,
      create: vi.fn(async () => ({ id: "credential-json" })),
    };
    const client: AuthClient = { ...fixture, getPasskeys, registerPasskey };
    renderCenter(client, ceremony);

    fireEvent.change(await screen.findByLabelText("Passkey name"), {
      target: { value: "Maya’s MacBook" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Add passkey" }));

    expect(await screen.findByText("Maya’s MacBook")).toBeInTheDocument();
    expect(ceremony.create).toHaveBeenCalled();
    expect(registerPasskey).toHaveBeenCalledWith("Maya’s MacBook", {
      id: "credential-json",
    });
  });

  it("requires explicit confirmation before revoking a session", async () => {
    const fixture = createFixtureAuthClient();
    const current: BrowserSessionDto = {
      id: "current",
      current: true,
      device: "Chrome on macOS",
      approximateLocation: "10.0.0.0/24 (approximate)",
      createdAt: "2026-08-10T00:00:00Z",
      lastSeenAt: "2026-08-10T01:00:00Z",
    };
    const other: BrowserSessionDto = {
      ...current,
      id: "other",
      current: false,
      device: "Firefox on Windows",
    };
    const getSessions = vi
      .fn<AuthClient["getSessions"]>()
      .mockResolvedValueOnce({ ok: true, data: [current, other] })
      .mockResolvedValueOnce({ ok: true, data: [current] });
    const revokeSession = vi.fn(fixture.revokeSession);
    renderCenter({ ...fixture, getSessions, revokeSession });

    const revokeButton = await screen.findByRole("button", { name: "Revoke" });
    revokeButton.focus();
    fireEvent.click(revokeButton);
    expect(screen.getByRole("alertdialog", { name: "Sign out Firefox on Windows?" })).toBeInTheDocument();
    expect(revokeSession).not.toHaveBeenCalled();
    fireEvent.click(
      within(screen.getByRole("alertdialog")).getByRole("button", {
        name: "Keep it",
      }),
    );
    await waitFor(() => expect(revokeButton).toHaveFocus());
    fireEvent.click(revokeButton);
    fireEvent.click(
      within(screen.getByRole("alertdialog")).getByRole("button", {
        name: "Sign out",
      }),
    );

    await waitFor(() => expect(revokeSession).toHaveBeenCalledWith("other"));
    await waitFor(() => expect(screen.queryByText("Firefox on Windows")).not.toBeInTheDocument());
  });
});
