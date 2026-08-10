import type { Metadata } from "next";

import { StudioHomeScaffold } from "@/components/studio/studio-home-scaffold";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ studio: string }>;
}): Promise<Metadata> {
  await params;

  return {
    title: "Studio home · Maestro",
    description: "Your authenticated Maestro studio workspace.",
  };
}

export default async function StudioHomePage({
  params,
}: {
  params: Promise<{ studio: string }>;
}) {
  await params;

  return <StudioHomeScaffold />;
}
