import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AuthClientProvider } from "@/components/auth/auth-client-provider";
import { OnboardingFlow } from "@/components/auth/onboarding-flow";
import {
  createFixtureAuthClient,
  type AuthClient,
} from "@/lib/auth/auth-client";

const navigation = vi.hoisted(() => ({ replace: vi.fn() }));

vi.mock("next/navigation", () => ({
  usePathname: () => window.location.pathname,
  useRouter: () => ({ replace: navigation.replace }),
}));

function renderFlow(
  client: AuthClient = createFixtureAuthClient({ delayMs: 0 }),
) {
  return render(
    <AuthClientProvider client={client}>
      <OnboardingFlow />
    </AuthClientProvider>,
  );
}

const invitationBearer = "I".repeat(40);

function importInvite(token = invitationBearer) {
  window.history.replaceState({}, "", `/onboarding#invite=${token}`);
}

async function reachPriorities() {
  fireEvent.change(await screen.findByLabelText("First name"), {
    target: { value: "Maya" },
  });
  fireEvent.click(screen.getByRole("button", { name: "Continue" }));
  fireEvent.change(await screen.findByLabelText("Studio name"), {
    target: { value: "North Star Music" },
  });
  fireEvent.click(
    screen.getByRole("button", { name: "Create studio home" }),
  );
  await screen.findByRole("heading", {
    name: /What should Maestro improve first/i,
  });
}

describe("onboarding flow", () => {
  beforeEach(() => {
    navigation.replace.mockReset();
    window.history.replaceState({}, "", "/onboarding");
  });

  it("focuses invalid fields and announces focused step transitions", async () => {
    renderFlow();

    expect(
      await screen.findByRole("list", { name: "Step 1 of 3" }),
    ).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Continue" }));
    const firstName = screen.getByLabelText("First name");
    expect(screen.getByText(/Tell us what you would like/i)).toBeInTheDocument();
    await waitFor(() => expect(firstName).toHaveFocus());

    fireEvent.change(firstName, { target: { value: "Maya" } });
    fireEvent.click(
      screen.getByRole("radio", { name: /Studio administrator/i }),
    );
    fireEvent.click(screen.getByRole("button", { name: "Continue" }));

    const studioHeading = await screen.findByRole("heading", {
      name: "Give your studio a home",
    });
    await waitFor(() => expect(studioHeading).toHaveFocus());
    expect(screen.getByRole("status")).toHaveTextContent(
      "Step 2 of 3: Give your studio a home",
    );
  });

  it("creates a tenant-scoped studio route from validated preferences", async () => {
    renderFlow();
    await reachPriorities();

    fireEvent.click(screen.getByRole("radio", { name: /Reliable billing/i }));
    fireEvent.click(screen.getByRole("button", { name: "Open my studio" }));

    await waitFor(() => {
      expect(navigation.replace).toHaveBeenCalledWith(
        "/studio/north-star-music/home",
      );
    });
  });

  it("binds server field errors, returns to their step, and focuses the control", async () => {
    const client: AuthClient = {
      ...createFixtureAuthClient({ delayMs: 0 }),
      completeOnboarding: async () => ({
        ok: false,
        error: {
          code: "validation_failed",
          message: "Review the highlighted fields and try again.",
          fieldErrors: {
            studioSlug: "That studio address is already in use.",
          },
        },
      }),
    };
    renderFlow(client);
    await reachPriorities();
    fireEvent.click(screen.getByRole("button", { name: "Open my studio" }));

    const slug = await screen.findByLabelText("Studio address");
    expect(slug).toHaveAttribute("aria-invalid", "true");
    expect(screen.getByText("That studio address is already in use.")).toBeInTheDocument();
    await waitFor(() => expect(slug).toHaveFocus());
  });

  it("gates unauthenticated onboarding and offers token-free sign-in recovery", async () => {
    importInvite("P".repeat(40));
    const client: AuthClient = {
      ...createFixtureAuthClient({ delayMs: 0 }),
      getCurrentUser: async () => ({
        ok: false,
        error: {
          code: "authentication_required",
          message: "Your secure session has ended. Sign in and try again.",
        },
      }),
    };
    renderFlow(client);

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "Your secure session has ended",
    );
    expect(screen.queryByLabelText("First name")).not.toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Sign in to continue" })).toHaveAttribute(
      "href",
      "/login",
    );
    expect(window.location.hash).toBe("");
    expect(window.location.search).toBe("");
  });

  it("keeps invite membership private and omits tenant-owned regional and billing controls", async () => {
    importInvite();
    renderFlow();

    fireEvent.change(await screen.findByLabelText("First name"), {
      target: { value: "Noah" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Continue" }));

    expect(
      await screen.findByRole("heading", { name: "Connect to your invitation" }),
    ).toBeInTheDocument();
    expect(screen.getByText(/No membership information is exposed/i)).toBeInTheDocument();
    expect(screen.queryByText(/Sonora House/i)).not.toBeInTheDocument();
    fireEvent.click(
      screen.getByRole("button", { name: "Continue securely" }),
    );

    expect(
      await screen.findByRole("button", { name: "Join studio securely" }),
    ).toBeInTheDocument();
    expect(screen.queryByLabelText("Time zone")).not.toBeInTheDocument();
    expect(screen.queryByLabelText("Billing currency")).not.toBeInTheDocument();
    expect(screen.queryByText(/Billing starts in preview mode/i)).not.toBeInTheDocument();
    expect(screen.getByText(/without changing the studio's billing/i)).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Join studio securely" }));
    await waitFor(() => {
      expect(navigation.replace).toHaveBeenCalledWith(
        "/studio/sonora-house/home",
      );
    });
    expect(JSON.stringify(window.history.state)).not.toContain(invitationBearer);
  });

  it("supports going back without losing the entered profile", async () => {
    renderFlow();

    fireEvent.change(await screen.findByLabelText("First name"), {
      target: { value: "Inez" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Continue" }));
    fireEvent.click(await screen.findByRole("button", { name: "Back" }));

    expect(await screen.findByLabelText("First name")).toHaveValue("Inez");
    expect(screen.getByRole("list", { name: "Step 1 of 3" })).toBeInTheDocument();
  });
});
