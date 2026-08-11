import { render, screen, waitFor } from "@testing-library/react";
import Link from "next/link";
import { beforeEach, describe, expect, it, vi } from "vitest";

import {
  IdentityCredentialSessionProvider,
  useInvitationSession,
  useResetCredentialSession,
} from "@/components/auth/identity-credential-session";

vi.mock("next/navigation", () => ({
  usePathname: () => window.location.pathname,
}));

const invitationBearer = "I".repeat(40);
const resetBearer = "R".repeat(48);

function SessionProbe() {
  const { token } = useInvitationSession();
  return (
    <div>
      <p>{token ? "Invitation ready" : "No invitation"}</p>
      <Link href="/login">Continue to sign in</Link>
      <Link href="/onboarding">Continue to onboarding</Link>
    </div>
  );
}

function renderProbe() {
  return render(
    <IdentityCredentialSessionProvider>
      <SessionProbe />
    </IdentityCredentialSessionProvider>,
  );
}

function ResetProbe() {
  const { resetCredential } = useResetCredentialSession();
  return (
    <div>
      <p>{resetCredential ? "Reset ready" : "No reset credential"}</p>
      <Link href="/reset-password">Continue password reset</Link>
    </div>
  );
}

function renderResetProbe() {
  return render(
    <IdentityCredentialSessionProvider>
      <ResetProbe />
    </IdentityCredentialSessionProvider>,
  );
}

describe("invitation session", () => {
  beforeEach(() => {
    window.history.replaceState({}, "", "/register");
  });

  it("imports a fragment, scrubs it immediately, and keeps links token-free", async () => {
    window.history.replaceState({}, "", `/register#invite=${invitationBearer}`);
    renderProbe();

    expect(await screen.findByText("Invitation ready")).toBeInTheDocument();
    expect(window.location.hash).toBe("");
    expect(window.location.search).toBe("");
    for (const link of screen.getAllByRole("link")) {
      expect(link.getAttribute("href")).not.toContain(invitationBearer);
      expect(link.getAttribute("href")).not.toContain("invite=");
    }
  });

  it("retains the invitation in memory and history state across identity navigation and reload", async () => {
    window.history.replaceState({}, "", `/register#invite=${invitationBearer}`);
    const first = renderProbe();
    expect(await screen.findByText("Invitation ready")).toBeInTheDocument();

    window.history.pushState({}, "", "/login");
    first.rerender(
      <IdentityCredentialSessionProvider>
        <SessionProbe />
      </IdentityCredentialSessionProvider>,
    );
    await waitFor(() => {
      expect(JSON.stringify(window.history.state)).toContain(invitationBearer);
    });
    first.unmount();

    renderProbe();
    expect(await screen.findByText("Invitation ready")).toBeInTheDocument();
    expect(window.location.href).not.toContain(invitationBearer);
  });

  it("rejects and scrubs legacy query-string invitation tokens", async () => {
    window.history.replaceState(
      {},
      "",
      "/register?invite=query-bearer&email=maya%40studio.test",
    );
    renderProbe();

    expect(await screen.findByText("No invitation")).toBeInTheDocument();
    expect(window.location.search).toBe("?email=maya%40studio.test");
    expect(JSON.stringify(window.history.state)).not.toContain("query-bearer");
  });

  it("scrubs malformed fragment credentials and treats them as absent", async () => {
    window.history.replaceState(
      {},
      "",
      "/register#invite=too-short&token=bad&email=not-an-email",
    );
    render(
      <IdentityCredentialSessionProvider>
        <SessionProbe />
        <ResetProbe />
      </IdentityCredentialSessionProvider>,
    );

    expect(await screen.findByText("No invitation")).toBeInTheDocument();
    expect(screen.getByText("No reset credential")).toBeInTheDocument();
    expect(window.location.hash).toBe("");
    expect(JSON.stringify(window.history.state)).not.toContain("too-short");
  });

  it("imports reset credentials from a fragment, scrubs them, and restores them after reload", async () => {
    window.history.replaceState(
      {},
      "",
      `/reset-password#token=${resetBearer}&email=MAYA%40STUDIO.TEST`,
    );
    const first = renderResetProbe();

    expect(await screen.findByText("Reset ready")).toBeInTheDocument();
    expect(window.location.hash).toBe("");
    expect(window.location.search).toBe("");
    expect(screen.getByRole("link")).toHaveAttribute("href", "/reset-password");
    expect(screen.getByRole("link").getAttribute("href")).not.toContain(
      resetBearer,
    );
    first.unmount();

    renderResetProbe();
    expect(await screen.findByText("Reset ready")).toBeInTheDocument();
    expect(window.location.href).not.toContain(resetBearer);
  });

  it("ignores and scrubs legacy reset query credentials", async () => {
    window.history.replaceState(
      {},
      "",
      "/reset-password?token=query-reset&email=maya%40studio.test",
    );
    renderResetProbe();

    expect(await screen.findByText("No reset credential")).toBeInTheDocument();
    expect(window.location.search).toBe("");
    expect(JSON.stringify(window.history.state)).not.toContain("query-reset");
    expect(JSON.stringify(window.history.state)).not.toContain(
      "maya@studio.test",
    );
  });
});
