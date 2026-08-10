import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { StudioAppShell } from "@/components/studio/studio-app-shell";
import { getStudioShellFixture } from "@/lib/studio-fixtures";

const navigation = vi.hoisted(() => ({
  pathname: "/studio/sonora-house/home",
  push: vi.fn(),
}));

vi.mock("next/navigation", () => ({
  usePathname: () => navigation.pathname,
  useRouter: () => ({ push: navigation.push }),
}));

describe("studio app shell", () => {
  beforeEach(() => {
    navigation.push.mockReset();
  });

  it("marks the active workspace destination and scopes every navigation link", () => {
    render(
      <StudioAppShell shell={getStudioShellFixture("sonora-house")}>
        <p>Studio content</p>
      </StudioAppShell>,
    );

    const homeLink = screen.getByRole("link", { name: "Home" });
    expect(homeLink).toHaveAttribute("aria-current", "page");
    expect(homeLink).toHaveAttribute("href", "/studio/sonora-house/home");
    expect(screen.getByRole("link", { name: /messages/i })).toHaveAttribute(
      "href",
      "/studio/sonora-house/messages",
    );
    expect(screen.getByRole("link", { name: "Skip to studio overview" })).toHaveAttribute(
      "href",
      "#studio-main",
    );
  });

  it("switches tenants through the route boundary without mutating local data", () => {
    render(
      <StudioAppShell shell={getStudioShellFixture("sonora-house")}>
        <p>Studio content</p>
      </StudioAppShell>,
    );

    fireEvent.change(screen.getByLabelText("Switch studio workspace"), {
      target: { value: "northline-conservatory" },
    });

    expect(navigation.push).toHaveBeenCalledWith(
      "/studio/northline-conservatory/home",
    );
  });

  it("opens a labeled mobile drawer and dismisses it with Escape", () => {
    render(
      <StudioAppShell shell={getStudioShellFixture("sonora-house")}>
        <p>Studio content</p>
      </StudioAppShell>,
    );

    const openButton = screen.getByRole("button", { name: "Open studio navigation" });
    fireEvent.click(openButton);

    expect(
      screen.getByRole("dialog", { name: "Studio navigation" }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "Close studio navigation" }),
    ).toHaveFocus();

    fireEvent.keyDown(document, { key: "Escape" });

    expect(
      screen.queryByRole("dialog", { name: "Studio navigation" }),
    ).not.toBeInTheDocument();
  });

  it("keeps keyboard focus inside the mobile navigation dialog", () => {
    render(
      <StudioAppShell shell={getStudioShellFixture("sonora-house")}>
        <p>Studio content</p>
      </StudioAppShell>,
    );

    fireEvent.click(screen.getByRole("button", { name: "Open studio navigation" }));

    const dialog = screen.getByRole("dialog", { name: "Studio navigation" });
    const first = screen.getByRole("button", { name: "Close studio navigation" });
    const focusable = dialog.querySelectorAll<HTMLElement>(
      'a[href], button:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])',
    );
    const last = focusable.item(focusable.length - 1);

    first.focus();
    fireEvent.keyDown(document, { key: "Tab", shiftKey: true });
    expect(last).toHaveFocus();

    fireEvent.keyDown(document, { key: "Tab" });
    expect(first).toHaveFocus();
  });
});
