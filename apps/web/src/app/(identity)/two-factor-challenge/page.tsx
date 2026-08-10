import type { Metadata } from "next";
import Link from "next/link";

import { AuthCard } from "@/components/auth/auth-shell";
import { TwoFactorChallengeForm } from "@/components/security/auth-challenges";
import { readAuthQuery } from "@/lib/auth/auth-query";

export const metadata: Metadata = {
  title: "Two-step verification · Maestro",
  description: "Complete the second step of your secure Maestro sign-in.",
};

export default async function TwoFactorChallengePage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const query = readAuthQuery(await searchParams);
  return (
    <AuthCard
      eyebrow="One more secure step"
      title="Confirm it’s really you."
      description="Use your authenticator app or one of the recovery codes you saved when you enabled two-step verification."
      footer={
        <Link href="/login" className="font-semibold text-[#684db7]">
          Start sign-in again
        </Link>
      }
    >
      <TwoFactorChallengeForm continueTo={query.returnTo} />
    </AuthCard>
  );
}
