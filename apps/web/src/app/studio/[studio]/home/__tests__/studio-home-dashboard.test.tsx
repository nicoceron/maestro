import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { StudioHomeDashboard } from "@/components/studio/studio-home-dashboard";
import { getStudioHomeFixture } from "@/lib/studio-fixtures";

describe("studio home dashboard", () => {
  it("presents a realistic daily operating view from explicit fixture data", () => {
    render(<StudioHomeDashboard data={getStudioHomeFixture("sonora-house")} />);

    expect(
      screen.getByRole("heading", { level: 1, name: "Good morning, Maya" }),
    ).toBeInTheDocument();
    expect(screen.getByText("Fixture workspace")).toBeInTheDocument();
    expect(
      screen.getByText("Demonstration DTOs only — no live studio records"),
    ).toBeInTheDocument();

    expect(screen.getByRole("region", { name: "Studio pulse" })).toBeInTheDocument();
    expect(screen.getByText("Revenue collected")).toBeInTheDocument();
    expect(screen.getAllByText("$4,820")).toHaveLength(2);
  });

  it("uses navigable, named sections for the core studio workflows", () => {
    render(<StudioHomeDashboard data={getStudioHomeFixture("sonora-house")} />);

    expect(screen.getByRole("heading", { name: "Next lessons" })).toBeInTheDocument();
    expect(
      screen.getByRole("heading", { level: 3, name: "Piano foundations" }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "Open Piano foundations lesson" }),
    ).toHaveAttribute("href", "/studio/sonora-house/calendar?lesson=les_1001");

    expect(screen.getByRole("heading", { name: "Attention needed" })).toBeInTheDocument();
    expect(screen.getByText("Review two lesson notes")).toBeInTheDocument();
    expect(
      screen.getByRole("progressbar", { name: "August invoice collection progress" }),
    ).toHaveAttribute("aria-valuenow", "92");
    expect(screen.getByRole("heading", { name: "Teaching team" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Recent activity" })).toBeInTheDocument();
  });

  it("keeps tenant-scoped destination links under the current studio slug", () => {
    render(
      <StudioHomeDashboard data={getStudioHomeFixture("northline-conservatory")} />,
    );

    expect(screen.getByRole("link", { name: "Add lesson" })).toHaveAttribute(
      "href",
      "/studio/northline-conservatory/calendar?new=lesson",
    );
    expect(screen.getByText(/Northline Conservatory · America\/Bogota/i)).toBeInTheDocument();
  });
});
