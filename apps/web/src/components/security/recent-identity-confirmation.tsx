"use client";

import { KeyRound, LoaderCircle, LockKeyhole, X } from "lucide-react";
import { useCallback, useRef, useState } from "react";

import { FormAlert, PasswordField } from "@/components/auth/auth-fields";
import { useAuthClient } from "@/components/auth/auth-client-provider";
import { useDialogFocus } from "@/components/security/use-dialog-focus";
import type { AuthFailure, AuthResult } from "@/lib/auth/auth-client";
import {
  browserWebAuthnCeremony,
  type WebAuthnCeremony,
  webAuthnErrorMessage,
} from "@/lib/auth/webauthn";

type ConfirmationChallenge = {
  returnFocus: HTMLElement | null;
  retry: () => Promise<void>;
};

export function RecentIdentityConfirmationDialog({
  open,
  onCancel,
  onConfirmed,
  ceremony,
  returnFocus,
}: {
  open: boolean;
  onCancel: () => void;
  onConfirmed: () => Promise<void>;
  ceremony: WebAuthnCeremony;
  returnFocus?: HTMLElement | null;
}) {
  const { client } = useAuthClient();
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<AuthFailure>();
  const confirmationInFlight = useRef(false);
  const close = useCallback(() => {
    if (!confirmationInFlight.current) {
      setError(undefined);
      onCancel();
    }
  }, [onCancel]);
  const { dialogRef } = useDialogFocus(open, close, returnFocus);

  if (!open) return null;

  async function finishConfirmation(result: AuthResult<null>) {
    if (!result.ok) {
      setError(result.error);
      return;
    }
    await onConfirmed();
  }

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (confirmationInFlight.current) return;

    const password = String(
      new FormData(event.currentTarget).get("password") ?? "",
    );
    if (!password) {
      setError({
        code: "validation_failed",
        message: "Enter your current password.",
        fieldErrors: { password: "Enter your current password." },
      });
      return;
    }

    confirmationInFlight.current = true;
    setPending(true);
    setError(undefined);
    try {
      await finishConfirmation(await client.confirmPassword(password));
    } finally {
      confirmationInFlight.current = false;
      setPending(false);
    }
  }

  async function confirmUsingPasskey() {
    if (confirmationInFlight.current) return;
    confirmationInFlight.current = true;
    setPending(true);
    setError(undefined);
    try {
      const options = await client.getPasskeyConfirmationOptions();
      if (!options.ok) {
        setError(options.error);
        return;
      }
      const credential = await ceremony.get(options.data);
      await finishConfirmation(await client.confirmWithPasskey(credential));
    } catch (passkeyError) {
      setError({
        code: "service_unavailable",
        message: webAuthnErrorMessage(passkeyError),
      });
    } finally {
      confirmationInFlight.current = false;
      setPending(false);
    }
  }

  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-[#1d1729]/55 px-4 py-8 backdrop-blur-sm">
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby="recent-identity-title"
        aria-describedby="recent-identity-description"
        className="w-full max-w-md rounded-[1.5rem] border border-white/20 bg-white p-5 shadow-2xl sm:p-7"
      >
        <div className="flex items-start gap-4">
          <span className="grid size-11 shrink-0 place-items-center rounded-2xl bg-[#f0ebfb] text-[#684db7]">
            <LockKeyhole className="size-5" aria-hidden="true" />
          </span>
          <div className="min-w-0 flex-1">
            <h2
              id="recent-identity-title"
              className="text-xl font-semibold tracking-[-0.035em] text-[#292230]"
            >
              Confirm it’s you
            </h2>
            <p
              id="recent-identity-description"
              className="mt-1 text-sm leading-6 text-[#716a76]"
            >
              Sensitive actions require a recent identity confirmation. Use your
              password or a passkey; confirmation remains valid for up to 10
              minutes.
            </p>
          </div>
          <button
            type="button"
            onClick={close}
            disabled={pending}
            aria-label="Close identity confirmation"
            className="grid size-9 shrink-0 place-items-center rounded-lg text-[#817985] hover:bg-[#f2eff4] disabled:opacity-60"
          >
            <X className="size-4" aria-hidden="true" />
          </button>
        </div>
        <form onSubmit={submit} className="mt-6 space-y-4" noValidate>
          {error ? <FormAlert>{error.message}</FormAlert> : null}
          <PasswordField
            label="Current password"
            name="password"
            autoComplete="current-password"
            autoFocus
            error={error?.fieldErrors?.password}
          />
          {ceremony.isSupported() ? (
            <button
              type="button"
              onClick={confirmUsingPasskey}
              disabled={pending}
              className="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl border border-[#d8d2dc] text-sm font-semibold text-[#55475f] hover:bg-[#faf8fc] disabled:bg-[#f3f0f4]"
            >
              <KeyRound className="size-4" aria-hidden="true" />
              Confirm with a passkey instead
            </button>
          ) : null}
          <div className="grid gap-2 sm:grid-cols-2">
            <button
              type="button"
              onClick={close}
              disabled={pending}
              className="h-11 rounded-xl border border-[#d8d2dc] text-sm font-semibold text-[#55475f] hover:bg-[#faf8fc] disabled:opacity-60"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={pending}
              className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-[#7457d2] text-sm font-semibold text-white disabled:bg-[#a99bd4]"
            >
              {pending ? (
                <LoaderCircle className="size-4 animate-spin" aria-hidden="true" />
              ) : null}
              {pending ? "Confirming…" : "Confirm password"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

export function useRecentIdentityConfirmation({
  ceremony = browserWebAuthnCeremony,
}: {
  ceremony?: WebAuthnCeremony;
} = {}) {
  const [pendingAction, setPendingAction] = useState<string>();
  const [failure, setFailure] = useState<AuthFailure>();
  const [challenge, setChallenge] = useState<ConfirmationChallenge>();

  async function runProtected<T>(
    key: string,
    request: () => Promise<AuthResult<T>>,
    onSuccess: (data: T) => void | Promise<void>,
  ) {
    const returnFocus =
      document.activeElement instanceof HTMLElement
        ? document.activeElement
        : null;
    setFailure(undefined);

    async function attempt(allowConfirmation: boolean) {
      setPendingAction(key);
      const result = await request();
      setPendingAction(undefined);

      if (!result.ok) {
        if (
          allowConfirmation &&
          result.error.code === "recent_password_required"
        ) {
          let retryConsumed = false;
          setChallenge({
            returnFocus:
              returnFocus?.isConnected && returnFocus
                ? returnFocus
                : document.activeElement instanceof HTMLElement
                  ? document.activeElement
                  : null,
            retry: async () => {
              if (retryConsumed) return;
              retryConsumed = true;
              setChallenge(undefined);
              await attempt(false);
            },
          });
        } else {
          setFailure(result.error);
        }
        return;
      }

      await onSuccess(result.data);
    }

    await attempt(true);
  }

  const confirmationDialog = challenge ? (
    <RecentIdentityConfirmationDialog
      open
      ceremony={ceremony}
      returnFocus={challenge.returnFocus}
      onCancel={() => setChallenge(undefined)}
      onConfirmed={challenge.retry}
    />
  ) : null;

  return {
    runProtected,
    pendingAction,
    failure,
    setFailure,
    confirmationDialog,
  };
}
