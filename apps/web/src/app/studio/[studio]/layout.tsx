import { StudioAppShell } from "@/components/studio/studio-app-shell";
import { getStudioShellFixture } from "@/lib/studio-fixtures";

export default async function StudioLayout({
  children,
  params,
}: {
  children: React.ReactNode;
  params: Promise<{ studio: string }>;
}) {
  const { studio } = await params;
  const shell = getStudioShellFixture(studio);

  return <StudioAppShell shell={shell}>{children}</StudioAppShell>;
}
