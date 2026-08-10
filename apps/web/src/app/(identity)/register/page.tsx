import type { Metadata } from "next";
import Link from "next/link";

import { RegisterForm } from "@/components/auth/auth-forms";
import { AuthCard } from "@/components/auth/auth-shell";
import { authHref, readAuthQuery } from "@/lib/auth/auth-query";

export const metadata: Metadata = {
  title: "Create your account · Maestro",
  description: "Create a secure Maestro account for your music studio.",
};

export default async function RegisterPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const query = readAuthQuery(await searchParams);

  return (
    <AuthCard
      eyebrow="Start your studio"
      title="Make room for better teaching."
      description="Share your details, then check your email or sign in to continue securely."
      footer={
        <>
          Already have an account?{" "}
          <Link
            href={authHref("/login", query)}
            className="font-semibold text-[#684db7] hover:text-[#4e368a]"
          >
            Sign in
          </Link>
        </>
      }
    >
      <RegisterForm initialEmail={query.email} />
    </AuthCard>
  );
}
