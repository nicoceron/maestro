import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AuthClientProvider } from "@/components/auth/auth-client-provider";
import { StudioRouteGate } from "@/components/studio/studio-route-gate";
import {
  createFixtureAuthClient,
  type AuthClient,
  type StudioDto,
} from "@/lib/auth/auth-client";

const navigation = vi.hoisted(() => ({ push: vi.fn() }));

vi.mock("next/navigation", () => ({
  usePathname: () => window.location.pathname,
  useRouter: () => ({ push: navigation.push }),
}));

const currentUser = {
  id: "user-1",
  name: "Ari Bennett",
  email: "ari@studio.test",
  emailVerifiedAt: "2026-08-10T00:00:00Z",
  twoFactorEnabled: false,
  passkeysCount: 0,
};

function studio(
  slug: string,
  role: StudioDto["membership"]["role"],
): StudioDto {
  return {
    id: slug,
    name: slug === "aria-academy" ? "Aria Academy" : "Bell Music",
    slug,
    timezone: "America/Bogota",
    currency: "USD",
    membership: { role, status: "active" },
    permissions: { manage: role === "owner" || role === "administrator" },
  };
}

function renderGate(requestedSlug: string, client: AuthClient) {
  return render(
    <AuthClientProvider client={client}>
      <StudioRouteGate requestedSlug={requestedSlug}>
        <p>Protected studio content</p>
      </StudioRouteGate>
    </AuthClientProvider>,
  );
}

describe("studio route membership gate", () => {
  beforeEach(() => {
    navigation.push.mockReset();
    window.history.replaceState({}, "", "/studio/aria-academy/home");
  });

  it("blocks the shell when the cookie session is unavailable", async () => {
    const client: AuthClient = {
      ...createFixtureAuthClient(),
      getCurrentUser: async () => ({
        ok: false,
        error: {
          code: "authentication_required",
          message: "Your secure session has ended. Sign in and try again.",
        },
      }),
    };
    renderGate("aria-academy", client);

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "Your secure session has ended",
    );
    expect(screen.queryByText("Protected studio content")).not.toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Sign in securely" })).toHaveAttribute(
      "href",
      "/login",
    );
  });

  it("does not render arbitrary or non-member studio slugs", async () => {
    const client: AuthClient = {
      ...createFixtureAuthClient(),
      getCurrentUser: async () => ({ ok: true, data: currentUser }),
      getStudios: async () => ({
        ok: true,
        data: [studio("aria-academy", "owner")],
      }),
    };
    renderGate("private-studio", client);

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "not available to your account",
    );
    expect(screen.queryByText("Protected studio content")).not.toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "Open an available studio" }),
    ).toHaveAttribute("href", "/studio/aria-academy/home");
    expect(screen.queryByText(/private-studio/i)).not.toBeInTheDocument();
  });

  it("builds the authenticated shell from the real membership role", async () => {
    const client: AuthClient = {
      ...createFixtureAuthClient(),
      getCurrentUser: async () => ({ ok: true, data: currentUser }),
      getStudios: async () => ({
        ok: true,
        data: [
          studio("aria-academy", "owner"),
          studio("bell-music", "teacher"),
        ],
      }),
    };
    window.history.replaceState({}, "", "/studio/bell-music/home");
    renderGate("bell-music", client);

    expect(await screen.findByText("Protected studio content")).toBeInTheDocument();
    expect(screen.getAllByText("Teacher workspace").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Ari Bennett").length).toBeGreaterThan(0);
    expect(screen.queryByText("Owner workspace")).not.toBeInTheDocument();
    expect(screen.getAllByText("Bell Music").length).toBeGreaterThan(0);
  });
});
