import type { Metadata } from "next";

import { SecurityCenter } from "@/components/security/security-center";

export const metadata: Metadata = {
  title: "Account security · Maestro",
  description: "Manage Maestro sign-in methods, recovery codes, and sessions.",
};

export default function AccountSecurityPage() {
  return <SecurityCenter />;
}
