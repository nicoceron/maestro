"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useLayoutEffect,
  useState,
} from "react";
import { usePathname } from "next/navigation";

const invitationStateKey = "maestroInvitationToken";
const resetTokenStateKey = "maestroResetToken";
const resetEmailStateKey = "maestroResetEmail";

type ResetCredential = { token: string; email: string };

type IdentityCredentialSessionValue = {
  ready: boolean;
  invitationToken?: string;
  resetCredential?: ResetCredential;
  clearInvitation: () => void;
  clearResetCredential: () => void;
};

const IdentityCredentialSessionContext =
  createContext<IdentityCredentialSessionValue | null>(null);

function safeInvitationToken(value: unknown) {
  if (typeof value !== "string") return undefined;
  const normalized = value.trim();
  return /^[A-Za-z0-9]{40,128}$/.test(normalized) ? normalized : undefined;
}

function safeResetToken(value: unknown) {
  if (typeof value !== "string") return undefined;
  const normalized = value.trim();
  return /^[A-Za-z0-9_-]{20,256}$/.test(normalized) ? normalized : undefined;
}

function safeEmail(value: unknown) {
  if (typeof value !== "string") return undefined;
  const normalized = value.trim().toLowerCase();
  return normalized.length <= 254 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(normalized)
    ? normalized
    : undefined;
}

function historyState() {
  return window.history.state && typeof window.history.state === "object"
    ? { ...window.history.state }
    : {};
}

function importCredentialsFromLocation() {
  const url = new URL(window.location.href);
  const fragment = new URLSearchParams(url.hash.replace(/^#/, ""));
  const state = historyState();
  const hasInvitationFragment = fragment.has("invite");
  const fragmentInvitation = safeInvitationToken(fragment.get("invite"));
  const historyInvitation = safeInvitationToken(state[invitationStateKey]);
  const invitationToken = hasInvitationFragment
    ? fragmentInvitation
    : historyInvitation;

  const hasResetFragment = fragment.has("token") || fragment.has("email");
  const fragmentResetToken = safeResetToken(fragment.get("token"));
  const fragmentResetEmail = safeEmail(fragment.get("email"));
  const historyResetToken = safeResetToken(state[resetTokenStateKey]);
  const historyResetEmail = safeEmail(state[resetEmailStateKey]);
  const resetCredential = hasResetFragment
    ? fragmentResetToken && fragmentResetEmail
      ? { token: fragmentResetToken, email: fragmentResetEmail }
      : undefined
    : historyResetToken && historyResetEmail
      ? { token: historyResetToken, email: historyResetEmail }
      : undefined;

  url.searchParams.delete("invite");
  url.searchParams.delete("token");
  if (url.pathname === "/reset-password") url.searchParams.delete("email");
  fragment.delete("invite");
  fragment.delete("token");
  fragment.delete("email");
  const remainingFragment = fragment.toString();
  url.hash = remainingFragment ? `#${remainingFragment}` : "";

  if (invitationToken) state[invitationStateKey] = invitationToken;
  else delete state[invitationStateKey];
  if (resetCredential) {
    state[resetTokenStateKey] = resetCredential.token;
    state[resetEmailStateKey] = resetCredential.email;
  } else {
    delete state[resetTokenStateKey];
    delete state[resetEmailStateKey];
  }

  window.history.replaceState(
    state,
    "",
    `${url.pathname}${url.search}${url.hash}`,
  );

  return { invitationToken, resetCredential };
}

export function IdentityCredentialSessionProvider({
  children,
}: {
  children: React.ReactNode;
}) {
  const pathname = usePathname();
  const [ready, setReady] = useState(false);
  const [invitationToken, setInvitationToken] = useState<string>();
  const [resetCredential, setResetCredential] = useState<ResetCredential>();

  const importCredentials = useCallback(() => {
    const imported = importCredentialsFromLocation();
    setInvitationToken(imported.invitationToken);
    setResetCredential(imported.resetCredential);
    setReady(true);
  }, []);

  useLayoutEffect(() => {
    const imported = importCredentialsFromLocation();
    const timer = window.setTimeout(() => {
      setInvitationToken(imported.invitationToken);
      setResetCredential(imported.resetCredential);
      setReady(true);
    }, 0);
    return () => window.clearTimeout(timer);
  }, []);

  useEffect(() => {
    if (!ready) return;
    const state = historyState();
    if (invitationToken) state[invitationStateKey] = invitationToken;
    else delete state[invitationStateKey];
    if (resetCredential) {
      state[resetTokenStateKey] = resetCredential.token;
      state[resetEmailStateKey] = resetCredential.email;
    } else {
      delete state[resetTokenStateKey];
      delete state[resetEmailStateKey];
    }
    window.history.replaceState(
      state,
      "",
      `${window.location.pathname}${window.location.search}${window.location.hash}`,
    );
  }, [invitationToken, pathname, ready, resetCredential]);

  useEffect(() => {
    window.addEventListener("hashchange", importCredentials);
    return () => window.removeEventListener("hashchange", importCredentials);
  }, [importCredentials]);

  const clearInvitation = useCallback(() => {
    const state = historyState();
    delete state[invitationStateKey];
    window.history.replaceState(
      state,
      "",
      `${window.location.pathname}${window.location.search}${window.location.hash}`,
    );
    setInvitationToken(undefined);
  }, []);

  const clearResetCredential = useCallback(() => {
    const state = historyState();
    delete state[resetTokenStateKey];
    delete state[resetEmailStateKey];
    window.history.replaceState(
      state,
      "",
      `${window.location.pathname}${window.location.search}${window.location.hash}`,
    );
    setResetCredential(undefined);
  }, []);

  return (
    <IdentityCredentialSessionContext
      value={{
        ready,
        invitationToken,
        resetCredential,
        clearInvitation,
        clearResetCredential,
      }}
    >
      {ready ? children : null}
    </IdentityCredentialSessionContext>
  );
}

function useIdentityCredentialSession() {
  const value = useContext(IdentityCredentialSessionContext);
  if (!value) {
    throw new Error(
      "Identity credential hooks require IdentityCredentialSessionProvider",
    );
  }
  return value;
}

export function useInvitationSession() {
  const { ready, invitationToken, clearInvitation } =
    useIdentityCredentialSession();
  return { ready, token: invitationToken, clearInvitation };
}

export function useResetCredentialSession() {
  const { ready, resetCredential, clearResetCredential } =
    useIdentityCredentialSession();
  return { ready, resetCredential, clearResetCredential };
}
