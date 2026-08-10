import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { useState } from "react";
import { describe, expect, it, vi } from "vitest";

import { AuthClientProvider } from "@/components/auth/auth-client-provider";
import { FormAlert } from "@/components/auth/auth-fields";
import { useRecentIdentityConfirmation } from "@/components/security/recent-identity-confirmation";
import {
  createFixtureAuthClient,
  type AuthClient,
  type AuthResult,
} from "@/lib/auth/auth-client";
import type { WebAuthnCeremony } from "@/lib/auth/webauthn";

const supportedCeremony: WebAuthnCeremony = {
  isSupported: () => true,
  create: vi.fn(async () => ({ id: "created-credential" })),
  get: vi.fn(async () => ({ id: "confirmed-credential" })),
};

function ProtectedActionHarness({
  request,
  ceremony = supportedCeremony,
}: {
  request: () => Promise<AuthResult<null>>;
  ceremony?: WebAuthnCeremony;
}) {
  const [completed, setCompleted] = useState(0);
  const recentIdentity = useRecentIdentityConfirmation({ ceremony });

  return (
    <>
      <button
        type="button"
        disabled={Boolean(recentIdentity.pendingAction)}
        onClick={() =>
          void recentIdentity.runProtected("remove-member", request, () =>
            setCompleted((value) => value + 1),
          )
        }
      >
        Remove member
      </button>
      <output aria-label="Completed actions">{completed}</output>
      {recentIdentity.failure ? (
        <FormAlert>{recentIdentity.failure.message}</FormAlert>
      ) : null}
      {recentIdentity.confirmationDialog}
    </>
  );
}

function renderHarness(
  client: AuthClient,
  request: () => Promise<AuthResult<null>>,
  ceremony?: WebAuthnCeremony,
) {
  return render(
    <AuthClientProvider client={client}>
      <ProtectedActionHarness request={request} ceremony={ceremony} />
    </AuthClientProvider>,
  );
}

describe("reusable recent identity confirmation", () => {
  it("confirms by password, retries once, and restores focus to the tenant action", async () => {
    const fixture = createFixtureAuthClient();
    const confirmPassword = vi.fn(fixture.confirmPassword);
    const request = vi
      .fn<() => Promise<AuthResult<null>>>()
      .mockResolvedValueOnce({
        ok: false,
        error: {
          code: "recent_password_required",
          message: "Password confirmation required.",
        },
      })
      .mockResolvedValueOnce({ ok: true, data: null });
    renderHarness({ ...fixture, confirmPassword }, request);
    const trigger = await screen.findByRole("button", {
      name: "Remove member",
    });

    trigger.focus();
    fireEvent.click(trigger);
    const password = await screen.findByLabelText("Current password");
    expect(password).toHaveFocus();
    expect(screen.getByRole("dialog")).toHaveAccessibleDescription(
      /recent identity confirmation.*password or a passkey/i,
    );
    fireEvent.change(password, { target: { value: "Correct-Horse-42!" } });
    fireEvent.click(screen.getByRole("button", { name: "Confirm password" }));

    await waitFor(() => expect(request).toHaveBeenCalledTimes(2));
    expect(confirmPassword).toHaveBeenCalledWith("Correct-Horse-42!");
    expect(screen.getByLabelText("Completed actions")).toHaveTextContent("1");
    await waitFor(() => expect(trigger).toHaveFocus());
  });

  it("preserves passkey confirmation for a protected tenant action", async () => {
    const fixture = createFixtureAuthClient();
    const confirmWithPasskey = vi.fn(fixture.confirmWithPasskey);
    const request = vi
      .fn<() => Promise<AuthResult<null>>>()
      .mockResolvedValueOnce({
        ok: false,
        error: {
          code: "recent_password_required",
          message: "Password confirmation required.",
        },
      })
      .mockResolvedValueOnce({ ok: true, data: null });
    renderHarness({ ...fixture, confirmWithPasskey }, request);

    fireEvent.click(
      await screen.findByRole("button", { name: "Remove member" }),
    );
    fireEvent.click(
      await screen.findByRole("button", {
        name: "Confirm with a passkey instead",
      }),
    );

    await waitFor(() => expect(request).toHaveBeenCalledTimes(2));
    expect(confirmWithPasskey).toHaveBeenCalledWith({
      id: "confirmed-credential",
    });
    expect(screen.getByLabelText("Completed actions")).toHaveTextContent("1");
  });

  it("does not reopen or retry again when the single retry is still stale", async () => {
    const fixture = createFixtureAuthClient();
    const stale = {
      ok: false as const,
      error: {
        code: "recent_password_required" as const,
        message: "Password confirmation required.",
      },
    };
    const request = vi
      .fn<() => Promise<AuthResult<null>>>()
      .mockResolvedValueOnce(stale)
      .mockResolvedValueOnce(stale);
    renderHarness(fixture, request);

    fireEvent.click(
      await screen.findByRole("button", { name: "Remove member" }),
    );
    fireEvent.change(await screen.findByLabelText("Current password"), {
      target: { value: "Correct-Horse-42!" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Confirm password" }));

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "Password confirmation required.",
    );
    expect(request).toHaveBeenCalledTimes(2);
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(screen.getByLabelText("Completed actions")).toHaveTextContent("0");
  });
});
