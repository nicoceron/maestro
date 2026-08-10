import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AuthClientProvider } from "@/components/auth/auth-client-provider";
import {
  ForgotPasswordForm,
  LoginForm,
  RegisterForm,
  ResetPasswordForm,
  VerifyEmailPanel,
} from "@/components/auth/auth-forms";
import {
  createFixtureAuthClient,
  type AuthClient,
} from "@/lib/auth/auth-client";

const navigation = vi.hoisted(() => ({ replace: vi.fn() }));
const invitationBearer = "I".repeat(40);
const expiredInvitationBearer = "E".repeat(40);
const resetBearer = "R".repeat(48);

vi.mock("next/navigation", () => ({
  usePathname: () => window.location.pathname,
  useRouter: () => ({ replace: navigation.replace }),
}));

function renderAuth(
  children: React.ReactNode,
  client: AuthClient = createFixtureAuthClient({ delayMs: 0 }),
) {
  return render(
    <AuthClientProvider client={client}>
      {children}
    </AuthClientProvider>,
  );
}

function fixtureGuestClient(): AuthClient {
  return {
    ...createFixtureAuthClient({ delayMs: 0 }),
    getCurrentUser: async () => ({
      ok: false,
      error: {
        code: "authentication_required",
        message: "Your secure session has ended. Sign in and try again.",
      },
    }),
  };
}

describe("identity forms", () => {
  beforeEach(() => {
    navigation.replace.mockReset();
    window.history.replaceState({}, "", "/login");
  });

  it("validates login fields and reports credential errors accessibly", async () => {
    renderAuth(<LoginForm />);

    const email = await screen.findByLabelText("Email address");
    fireEvent.submit(screen.getByRole("button", { name: "Sign in" }).closest("form")!);
    expect(screen.getByText("Enter your email address.")).toBeInTheDocument();
    expect(screen.getByText("Enter your password.")).toBeInTheDocument();
    await waitFor(() => expect(email).toHaveFocus());

    fireEvent.change(email, {
      target: { value: "maya@studio.test" },
    });
    fireEvent.change(screen.getByLabelText("Password"), {
      target: { value: "wrong-password" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Sign in" }));

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "That email and password combination was not recognized.",
    );
    expect(navigation.replace).not.toHaveBeenCalled();
  });

  it("supports password visibility and keyboard Caps Lock feedback", async () => {
    renderAuth(<LoginForm />);

    const password = await screen.findByLabelText("Password");
    const showButton = screen.getByRole("button", { name: "Show password" });
    expect(password).toHaveAttribute("type", "password");

    fireEvent.click(showButton);
    expect(password).toHaveAttribute("type", "text");
    expect(screen.getByRole("button", { name: "Hide password" })).toHaveAttribute(
      "aria-pressed",
      "true",
    );

    const capsLockEvent = new KeyboardEvent("keydown", {
      bubbles: true,
      key: "A",
    });
    Object.defineProperty(capsLockEvent, "getModifierState", {
      value: (key: string) => key === "CapsLock",
    });
    fireEvent(password, capsLockEvent);
    expect(screen.getByText("Caps Lock is on")).toBeInTheDocument();
  });

  it("redirects only after the adapter confirms a cookie session", async () => {
    renderAuth(<LoginForm initialEmail="maya@studio.test" />);

    fireEvent.change(await screen.findByLabelText("Password"), {
      target: { value: "Strong-Password-42!" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Sign in" }));

    await waitFor(() => {
      expect(navigation.replace).toHaveBeenCalledWith("/studio/sonora-house/home");
    });
  });

  it("routes a Fortify two-factor response to the second-step challenge", async () => {
    const fixture = createFixtureAuthClient();
    const client: AuthClient = {
      ...fixture,
      login: async () => ({
        ok: true,
        data: {
          sessionEstablished: false,
          requiresTwoFactor: true,
          redirectTo: "/two-factor-challenge",
        },
      }),
    };
    renderAuth(<LoginForm initialEmail="maya@studio.test" />, client);

    fireEvent.change(await screen.findByLabelText("Password"), {
      target: { value: "Strong-Password-42!" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Sign in" }));

    await waitFor(() => {
      expect(navigation.replace).toHaveBeenCalledWith("/two-factor-challenge");
    });
  });

  it("uses an enumeration-safe recovery confirmation", async () => {
    renderAuth(<ForgotPasswordForm />);

    fireEvent.change(await screen.findByLabelText("Email address"), {
      target: { value: "unknown@studio.test" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Send reset link" }));

    expect(await screen.findByRole("status")).toHaveTextContent(
      "If an account matches that address",
    );
    expect(screen.getByText(/same confirmation whether or not/i)).toBeInTheDocument();
  });

  it("keeps invite membership private and surfaces an expired invite", async () => {
    window.history.replaceState(
      {},
      "",
      `/register#invite=${expiredInvitationBearer}`,
    );
    const expiredClient: AuthClient = {
      ...fixtureGuestClient(),
      register: async () => ({
        ok: false,
        error: {
          code: "invite_invalid",
          message:
            "This invitation can no longer be used. Ask the sender for a new one.",
        },
      }),
    };
    renderAuth(
      <RegisterForm initialEmail="teacher@studio.test" />,
      expiredClient,
    );

    expect(await screen.findByText("Joining by invitation")).toBeInTheDocument();
    expect(screen.queryByText(/Sonora House/i)).not.toBeInTheDocument();

    fireEvent.change(screen.getByLabelText("Your name"), {
      target: { value: "Ari Bennett" },
    });
    fireEvent.change(screen.getByLabelText("Password"), {
      target: { value: "Strong-Password-42!" },
    });
    fireEvent.change(screen.getByLabelText("Confirm password"), {
      target: { value: "Strong-Password-42!" },
    });
    fireEvent.click(screen.getByRole("checkbox"));
    fireEvent.click(screen.getByRole("button", { name: "Accept invitation" }));

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "This invitation can no longer be used",
    );
  });

  it("routes an authenticated invite recipient around the guest-only register endpoint", async () => {
    window.history.replaceState({}, "", `/register#invite=${invitationBearer}`);
    renderAuth(<RegisterForm />);

    expect(await screen.findByRole("status")).toHaveTextContent(
      "Checking your secure session",
    );
    await waitFor(() => {
      expect(navigation.replace).toHaveBeenCalledWith(
        "/onboarding",
      );
    });
    expect(screen.queryByRole("button", { name: "Accept invitation" })).not.toBeInTheDocument();
  });

  it("routes an authenticated invite recipient around the guest-only login endpoint", async () => {
    window.history.replaceState({}, "", `/login#invite=${invitationBearer}`);
    renderAuth(<LoginForm />);

    await waitFor(() => {
      expect(navigation.replace).toHaveBeenCalledWith(
        "/onboarding",
      );
    });
    expect(screen.queryByRole("button", { name: "Sign in" })).not.toBeInTheDocument();
  });

  it("rejects mismatched reset passwords before calling navigation", async () => {
    window.history.replaceState(
      {},
      "",
      `/reset-password#token=${resetBearer}&email=maya%40studio.test`,
    );
    renderAuth(<ResetPasswordForm />);

    fireEvent.change(await screen.findByLabelText("New password"), {
      target: { value: "Strong-Password-42!" },
    });
    fireEvent.change(screen.getByLabelText("Confirm new password"), {
      target: { value: "Strong-Password-43!" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Set new password" }));

    expect(screen.getByText("The passwords do not match.")).toBeInTheDocument();
    expect(navigation.replace).not.toHaveBeenCalled();
  });

  it("clears scrubbed reset credentials after establishing the new session", async () => {
    window.history.replaceState(
      {},
      "",
      `/reset-password#token=${resetBearer}&email=maya%40studio.test`,
    );
    renderAuth(<ResetPasswordForm />);

    fireEvent.change(await screen.findByLabelText("New password"), {
      target: { value: "Strong-Password-42!" },
    });
    fireEvent.change(screen.getByLabelText("Confirm new password"), {
      target: { value: "Strong-Password-42!" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Set new password" }));

    await waitFor(() => {
      expect(navigation.replace).toHaveBeenCalledWith("/onboarding");
    });
    expect(JSON.stringify(window.history.state)).not.toContain(resetBearer);
    expect(window.location.hash).toBe("");
    expect(window.location.search).toBe("");
  });

  it("uses the authenticated session for verification resend without an email form", async () => {
    const fixture = createFixtureAuthClient({ delayMs: 0 });
    const unverifiedClient: AuthClient = {
      ...fixture,
      getCurrentUser: async () => ({
        ok: true,
        data: {
          id: "user-1",
          name: "Maya Ortiz",
          email: "maya@studio.test",
          emailVerifiedAt: null,
          twoFactorEnabled: false,
          passkeysCount: 0,
        },
      }),
    };
    renderAuth(<VerifyEmailPanel />, unverifiedClient);

    expect(await screen.findByText(/Signed in as/i)).toHaveTextContent(
      "maya@studio.test",
    );
    expect(screen.queryByRole("textbox", { name: "Email address" })).not.toBeInTheDocument();
    fireEvent.click(
      screen.getByRole("button", { name: "Send a fresh verification link" }),
    );
    expect(
      await screen.findByText("A fresh verification link is on its way."),
    ).toBeInTheDocument();
  });

  it("focuses and describes the terms checkbox when it is the first invalid control", async () => {
    renderAuth(<RegisterForm initialEmail="maya@studio.test" />);

    fireEvent.change(await screen.findByLabelText("Your name"), {
      target: { value: "Maya Ortiz" },
    });
    fireEvent.change(screen.getByLabelText("Password"), {
      target: { value: "Strong-Password-42!" },
    });
    fireEvent.change(screen.getByLabelText("Confirm password"), {
      target: { value: "Strong-Password-42!" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Create account" }));

    const terms = screen.getByRole("checkbox");
    await waitFor(() => expect(terms).toHaveFocus());
    expect(terms).toHaveAttribute("aria-invalid", "true");
    const descriptionId = terms.getAttribute("aria-describedby");
    expect(descriptionId).toBeTruthy();
    expect(document.getElementById(descriptionId!)).toHaveTextContent(
      "Accept the Terms",
    );
  });

  it("keeps transient invite-session failures out of guest forms and supports retry", async () => {
    window.history.replaceState({}, "", `/login#invite=${invitationBearer}`);
    const currentUser = vi
      .fn<AuthClient["getCurrentUser"]>()
      .mockResolvedValueOnce({
        ok: false,
        error: {
          code: "service_unavailable",
          message: "We could not reach Maestro.",
        },
      })
      .mockResolvedValueOnce({
        ok: false,
        error: {
          code: "authentication_required",
          message: "Sign in again.",
        },
      });
    renderAuth(
      <LoginForm />,
      { ...createFixtureAuthClient({ delayMs: 0 }), getCurrentUser: currentUser },
    );

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "We could not reach Maestro",
    );
    expect(screen.queryByRole("button", { name: "Sign in" })).not.toBeInTheDocument();
    fireEvent.click(
      screen.getByRole("button", { name: "Check secure session again" }),
    );

    expect(await screen.findByRole("button", { name: "Sign in" })).toBeInTheDocument();
    expect(currentUser).toHaveBeenCalledTimes(2);
  });
});
