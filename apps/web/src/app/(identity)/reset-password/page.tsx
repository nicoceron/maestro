import type { Metadata } from "next";
import Link from "next/link";

import { ResetPasswordForm } from "@/components/auth/auth-forms";
import { AuthCard } from "@/components/auth/auth-shell";

export const metadata: Metadata = {
  title: "Choose a new password · Maestro",
  description: "Secure your Maestro account with a new password.",
};

export default function ResetPasswordPage() {
  return (
    <AuthCard
      eyebrow="Secure your account"
      title="Choose a fresh password."
      description="A strong, unique password keeps studio and family information protected."
      footer={
        <>
          Remembered it?{" "}
          <Link href="/login" className="font-semibold text-[#684db7]">
            Sign in
          </Link>
        </>
      }
    >
      <ResetPasswordForm />
    </AuthCard>
  );
}
