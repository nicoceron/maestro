import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import Home from "../page";

describe("marketing home", () => {
  it("presents the product promise and primary actions", () => {
    render(<Home />);

    expect(
      screen.getByRole("heading", {
        level: 1,
        name: /run your studio\.\s*teach with presence\./i,
      }),
    ).toBeInTheDocument();
    const workspaceLinks = screen.getAllByRole("link", {
      name: /explore the workspace/i,
    });
    expect(workspaceLinks).toHaveLength(2);
    workspaceLinks.forEach((link) =>
      expect(link).toHaveAttribute("href", "#workspace"),
    );
    expect(
      screen.getByRole("link", { name: /see what makes it different/i }),
    ).toHaveAttribute("href", "#features");
  });

  it("uses meaningful regions and an accessible product preview", () => {
    render(<Home />);

    expect(screen.getByRole("navigation", { name: /^main$/i })).toBeInTheDocument();
    expect(
      screen.getByRole("region", { name: /maestro workspace preview/i }),
    ).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: /today at a glance/i })).toBeInTheDocument();
  });
});
