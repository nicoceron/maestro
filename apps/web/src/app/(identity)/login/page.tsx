import type { Metadata } from "next";
import Link from "next/link";

import { LoginForm } from "@/components/auth/auth-forms";
import { AuthCard } from "@/components/auth/auth-shell";
import { authHref, readAuthQuery } from "@/lib/auth/auth-query";

export const metadata: Metadata = {
  title: "Sign in · Maestro",
  description: "Sign in to your secure Maestro music studio workspace.",
};

export default async function LoginPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const query = readAuthQuery(await searchParams);

  return (
    <AuthCard
      eyebrow="Welcome back"
      title="Your studio is waiting."
      description="Pick up the day with lessons, families, and billing in one calm view."
      footer={
        <>
          New to Maestro?{" "}
          <Link
            href={authHref("/register", query)}
            className="font-semibold text-[#684db7] hover:text-[#4e368a]"
          >
            Create an account
          </Link>
        </>
      }
    >
      <LoginForm initialEmail={query.email} continueTo={query.returnTo} />
    </AuthCard>
  );
}
