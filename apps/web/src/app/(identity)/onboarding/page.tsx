import type { Metadata } from "next";

import { AuthCard } from "@/components/auth/auth-shell";
import { OnboardingFlow } from "@/components/auth/onboarding-flow";

export const metadata: Metadata = {
  title: "Set up your studio · Maestro",
  description: "Personalize your Maestro music studio workspace.",
};

export default function OnboardingPage() {
  return (
    <AuthCard
      wide
      eyebrow="A thoughtful first run"
      title="Set the rhythm for your studio."
      description="A few useful defaults now make every schedule, invoice, and lesson feel more natural later."
    >
      <OnboardingFlow />
    </AuthCard>
  );
}
