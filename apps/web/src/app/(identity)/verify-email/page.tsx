import type { Metadata } from "next";
import Link from "next/link";

import { VerifyEmailPanel } from "@/components/auth/auth-forms";
import { AuthCard } from "@/components/auth/auth-shell";

export const metadata: Metadata = {
  title: "Verify your email · Maestro",
  description: "Verify the email address for your Maestro account.",
};

export default function VerifyEmailPage() {
  return (
    <AuthCard
      eyebrow="Protect your studio"
      title="Verify your email."
      description="This confirms where important account and security notices should reach you."
      footer={
        <>
          Already verified?{" "}
          <Link href="/login" className="font-semibold text-[#684db7]">
            Sign in
          </Link>
        </>
      }
    >
      <VerifyEmailPanel />
    </AuthCard>
  );
}
