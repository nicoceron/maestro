import type { Metadata } from "next";

import { StudioHomeDashboard } from "@/components/studio/studio-home-dashboard";
import { getStudioHomeFixture, getStudioShellFixture } from "@/lib/studio-fixtures";

export function generateStaticParams() {
  return [{ studio: "sonora-house" }, { studio: "northline-conservatory" }];
}

export async function generateMetadata({
  params,
}: {
  params: Promise<{ studio: string }>;
}): Promise<Metadata> {
  const { studio } = await params;
  const shell = getStudioShellFixture(studio);

  return {
    title: `Studio home · ${shell.currentStudio.shortName} · Maestro`,
    description: `Daily schedule, revenue, teaching team, and studio activity for ${shell.currentStudio.name}.`,
  };
}

export default async function StudioHomePage({
  params,
}: {
  params: Promise<{ studio: string }>;
}) {
  const { studio } = await params;
  const data = getStudioHomeFixture(studio);

  return <StudioHomeDashboard data={data} />;
}
