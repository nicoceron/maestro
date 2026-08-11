import { AuthClientProvider } from "@/components/auth/auth-client-provider";

export default function AccountLayout({ children }: { children: React.ReactNode }) {
  return (
    <AuthClientProvider>
      <a
        href="#security-content"
        className="sr-only z-50 rounded-lg bg-white px-4 py-3 text-sm font-semibold focus:not-sr-only focus:fixed focus:left-4 focus:top-4"
      >
        Skip to account security
      </a>
      {children}
    </AuthClientProvider>
  );
}
