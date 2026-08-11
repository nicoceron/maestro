"use client";

import { createContext, useContext } from "react";
import { IdentityCredentialSessionProvider } from "@/components/auth/identity-credential-session";
import type { AuthClient } from "@/lib/auth/auth-client";
import { sanctumAuthClient } from "@/lib/auth/sanctum-auth-client";

type AuthClientContextValue = {
  client: AuthClient;
};

const AuthClientContext = createContext<AuthClientContextValue | null>(null);

export function AuthClientProvider({
  children,
  client = sanctumAuthClient,
}: {
  children: React.ReactNode;
  client?: AuthClient;
}) {
  return (
    <IdentityCredentialSessionProvider>
      <AuthClientContext value={{ client }}>
        {children}
      </AuthClientContext>
    </IdentityCredentialSessionProvider>
  );
}

export function useAuthClient() {
  const value = useContext(AuthClientContext);
  if (!value) {
    throw new Error("useAuthClient must be used inside AuthClientProvider");
  }

  return value;
}
