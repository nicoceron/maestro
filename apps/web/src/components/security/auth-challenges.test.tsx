import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AuthClientProvider } from "@/components/auth/auth-client-provider";
import { TwoFactorChallengeForm } from "@/components/security/auth-challenges";
import {
  createFixtureAuthClient,
  type AuthClient,
} from "@/lib/auth/auth-client";

const navigation = vi.hoisted(() => ({ replace: vi.fn() }));

vi.mock("next/navigation", () => ({
  usePathname: () => window.location.pathname,
  useRouter: () => ({ replace: navigation.replace }),
}));

function renderChallenge(client: AuthClient = createFixtureAuthClient()) {
  return render(
    <AuthClientProvider client={client}>
      <TwoFactorChallengeForm />
    </AuthClientProvider>,
  );
}

describe("two-factor sign-in challenge", () => {
  beforeEach(() => {
    navigation.replace.mockReset();
    window.history.replaceState({}, "", "/two-factor-challenge");
  });

  it("validates and focuses the one-time-code field", async () => {
    renderChallenge();
    const field = await screen.findByLabelText("Authenticator code");
    expect(field).toHaveFocus();

    fireEvent.click(screen.getByRole("button", { name: "Verify and continue" }));
    expect(field).toHaveAttribute("aria-invalid", "true");
    expect(screen.getByText(/current 6-digit code/i)).toBeInTheDocument();
    expect(navigation.replace).not.toHaveBeenCalled();
  });

  it("switches to recovery mode and submits only a recovery code", async () => {
    const fixture = createFixtureAuthClient();
    const completeTwoFactorChallenge = vi.fn(fixture.completeTwoFactorChallenge);
    renderChallenge({ ...fixture, completeTwoFactorChallenge });

    fireEvent.click(await screen.findByRole("button", { name: "Use a recovery code instead" }));
    fireEvent.change(screen.getByLabelText("Recovery code"), {
      target: { value: "fixture-recovery-one" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Verify and continue" }));

    await waitFor(() => {
      expect(completeTwoFactorChallenge).toHaveBeenCalledWith({
        recoveryCode: "fixture-recovery-one",
      });
      expect(navigation.replace).toHaveBeenCalledWith(
        "/studio/sonora-house/home",
      );
    });
  });

  it("offers sign-in recovery when the pre-auth challenge session expires", async () => {
    const fixture = createFixtureAuthClient();
    const client: AuthClient = {
      ...fixture,
      completeTwoFactorChallenge: async () => ({
        ok: false,
        error: {
          code: "authentication_required",
          message: "Your session has expired. Please sign in again.",
        },
      }),
    };
    renderChallenge(client);

    fireEvent.change(await screen.findByLabelText("Authenticator code"), {
      target: { value: "123456" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Verify and continue" }));

    expect(await screen.findByRole("link", { name: "Return to sign in" })).toHaveAttribute(
      "href",
      "/login",
    );
  });
});
