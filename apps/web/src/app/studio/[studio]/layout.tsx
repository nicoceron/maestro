import { AuthClientProvider } from "@/components/auth/auth-client-provider";
import { StudioRouteGate } from "@/components/studio/studio-route-gate";

export default async function StudioLayout({
  children,
  params,
}: {
  children: React.ReactNode;
  params: Promise<{ studio: string }>;
}) {
  const { studio } = await params;
  return (
    <AuthClientProvider>
      <StudioRouteGate requestedSlug={studio}>{children}</StudioRouteGate>
    </AuthClientProvider>
  );
}
