"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { KeyRound, LoaderCircle, ShieldCheck } from "lucide-react";
import { useId, useState } from "react";

import { FormAlert } from "@/components/auth/auth-fields";
import { useAuthClient } from "@/components/auth/auth-client-provider";
import { useInvitationSession } from "@/components/auth/identity-credential-session";
import type { SafeAuthReturnPath } from "@/lib/auth/auth-query";
import {
  browserWebAuthnCeremony,
  type WebAuthnCeremony,
  webAuthnErrorMessage,
} from "@/lib/auth/webauthn";

export function PasskeyLoginButton({
  remember,
  continueTo,
  ceremony = browserWebAuthnCeremony,
}: {
  remember: boolean;
  continueTo?: SafeAuthReturnPath;
  ceremony?: WebAuthnCeremony;
}) {
  const router = useRouter();
  const { client } = useAuthClient();
  const { token: invitationToken } = useInvitationSession();
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string>();

  async function handlePasskeyLogin() {
    setPending(true);
    setError(undefined);
    const options = await client.getPasskeyLoginOptions();
    if (!options.ok) {
      setPending(false);
      setError(options.error.message);
      return;
    }

    try {
      const credential = await ceremony.get(options.data);
      const result = await client.loginWithPasskey(
        credential,
        remember,
        invitationToken,
        continueTo,
      );
      setPending(false);
      if (!result.ok) {
        setError(result.error.message);
        return;
      }
      router.replace(result.data.redirectTo);
    } catch (passkeyError) {
      setPending(false);
      setError(webAuthnErrorMessage(passkeyError));
    }
  }

  const supported = ceremony.isSupported();

  return (
    <div className="space-y-3">
      <div className="flex items-center gap-3" aria-hidden="true">
        <span className="h-px flex-1 bg-[#e4dfe6]" />
        <span className="text-[11px] font-semibold uppercase tracking-[0.14em] text-[#8c8490]">
          or
        </span>
        <span className="h-px flex-1 bg-[#e4dfe6]" />
      </div>
      <button
        type="button"
        disabled={pending || !supported}
        onClick={handlePasskeyLogin}
        className="inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl border border-[#d8d2dc] bg-white px-4 text-sm font-semibold text-[#55475f] transition hover:bg-[#faf8fc] disabled:cursor-not-allowed disabled:bg-[#f4f1f5] disabled:text-[#918996]"
      >
        {pending ? (
          <LoaderCircle className="size-4 animate-spin" aria-hidden="true" />
        ) : (
          <KeyRound className="size-4" aria-hidden="true" />
        )}
        {pending ? "Waiting for your passkey…" : "Sign in with a passkey"}
      </button>
      {!supported ? (
        <p className="text-center text-xs leading-5 text-[#817a85]">
          Passkeys are not available in this browser. You can still use your password.
        </p>
      ) : null}
      {error ? <FormAlert>{error}</FormAlert> : null}
    </div>
  );
}

export function TwoFactorChallengeForm({
  continueTo,
}: {
  continueTo?: SafeAuthReturnPath;
}) {
  const router = useRouter();
  const { client } = useAuthClient();
  const { token: invitationToken } = useInvitationSession();
  const [mode, setMode] = useState<"code" | "recovery">("code");
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string>();
  const [fieldError, setFieldError] = useState<string>();
  const fieldId = useId();
  const errorId = fieldError ? `${fieldId}-error` : undefined;

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const value = String(new FormData(event.currentTarget).get("challenge") ?? "").trim();
    const invalid =
      mode === "code"
        ? !/^\d{6}$/.test(value)
        : value.length < 8 || value.length > 128;
    if (invalid) {
      setFieldError(
        mode === "code"
          ? "Enter the current 6-digit code from your authenticator app."
          : "Enter one complete recovery code.",
      );
      setError(undefined);
      return;
    }

    setPending(true);
    setFieldError(undefined);
    setError(undefined);
    const result = await client.completeTwoFactorChallenge({
      ...(mode === "code" ? { code: value } : { recoveryCode: value }),
      ...(invitationToken ? { invitationToken } : {}),
      ...(continueTo ? { continueTo } : {}),
    });
    setPending(false);
    if (!result.ok) {
      setFieldError(
        result.error.fieldErrors?.code ??
          result.error.fieldErrors?.recoveryCode,
      );
      setError(result.error.message);
      return;
    }
    router.replace(result.data.redirectTo);
  }

  function switchMode(next: "code" | "recovery") {
    setMode(next);
    setError(undefined);
    setFieldError(undefined);
  }

  return (
    <form onSubmit={handleSubmit} noValidate className="space-y-5">
      <div className="rounded-xl border border-[#ded7ef] bg-[#f7f4ff] p-3.5 text-sm leading-6 text-[#5d4b8d]">
        <span className="flex items-start gap-2">
          <ShieldCheck className="mt-1 size-4 shrink-0" aria-hidden="true" />
          Your password was accepted. Complete this second check to establish the session.
        </span>
      </div>

      {error ? (
        <FormAlert>
          <p>{error}</p>
          {error.toLowerCase().includes("session") ? (
            <Link href="/login" className="mt-1 inline-flex font-semibold underline">
              Return to sign in
            </Link>
          ) : null}
        </FormAlert>
      ) : null}

      <div>
        <label htmlFor={fieldId} className="mb-2 block text-sm font-semibold text-[#352e3d]">
          {mode === "code" ? "Authenticator code" : "Recovery code"}
        </label>
        <input
          key={mode}
          id={fieldId}
          name="challenge"
          type="text"
          inputMode={mode === "code" ? "numeric" : "text"}
          autoComplete={mode === "code" ? "one-time-code" : "off"}
          autoFocus
          maxLength={mode === "code" ? 6 : 128}
          pattern={mode === "code" ? "[0-9]{6}" : undefined}
          aria-invalid={fieldError ? "true" : undefined}
          aria-describedby={errorId}
          className={`h-12 w-full rounded-xl border bg-[#fbfaf8] px-3.5 font-mono text-[16px] tracking-[0.12em] text-[#292330] focus:outline-none focus:ring-4 focus:ring-[#8768d8]/10 ${
            fieldError ? "border-[#c96b61]" : "border-[#dcd7df] focus:border-[#8a70dc]"
          }`}
        />
        {fieldError ? (
          <p id={errorId} className="mt-1.5 text-xs leading-5 text-[#a54e46]">
            {fieldError}
          </p>
        ) : null}
      </div>

      <button
        type="submit"
        disabled={pending}
        className="inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-[#7457d2] px-4 text-sm font-semibold text-white disabled:cursor-wait disabled:bg-[#a99bd4]"
      >
        {pending ? <LoaderCircle className="size-4 animate-spin" aria-hidden="true" /> : null}
        {pending ? "Verifying…" : "Verify and continue"}
      </button>

      <button
        type="button"
        onClick={() => switchMode(mode === "code" ? "recovery" : "code")}
        className="w-full rounded-lg py-1 text-sm font-semibold text-[#684db7] hover:text-[#4f368f]"
      >
        {mode === "code" ? "Use a recovery code instead" : "Use an authenticator code instead"}
      </button>
    </form>
  );
}
