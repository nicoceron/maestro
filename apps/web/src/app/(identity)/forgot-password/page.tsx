import type { Metadata } from "next";
import Link from "next/link";
import { ArrowLeft } from "lucide-react";

import { ForgotPasswordForm } from "@/components/auth/auth-forms";
import { AuthCard } from "@/components/auth/auth-shell";
import { readAuthQuery } from "@/lib/auth/auth-query";

export const metadata: Metadata = {
  title: "Reset your password · Maestro",
  description: "Request a secure, time-limited Maestro password reset link.",
};

export default async function ForgotPasswordPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const query = readAuthQuery(await searchParams);

  return (
    <AuthCard
      eyebrow="Account recovery"
      title="Let’s get you back in."
      description="Enter your email and we’ll send a time-limited reset link when it matches an account."
      footer={
        <Link href="/login" className="inline-flex items-center gap-2 font-semibold text-[#684db7]">
          <ArrowLeft className="size-3.5" aria-hidden="true" />
          Back to sign in
        </Link>
      }
    >
      <ForgotPasswordForm initialEmail={query.email} />
    </AuthCard>
  );
}
