import { AuthShell } from "@/components/auth/auth-shell";

export default function IdentityLayout({ children }: { children: React.ReactNode }) {
  return <AuthShell>{children}</AuthShell>;
}
