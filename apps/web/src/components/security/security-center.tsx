"use client";

import Link from "next/link";
import Image from "next/image";
import { useRouter } from "next/navigation";
import {
  Check,
  Clipboard,
  KeyRound,
  Laptop,
  LoaderCircle,
  LockKeyhole,
  LogOut,
  MonitorSmartphone,
  RefreshCcw,
  ShieldAlert,
  ShieldCheck,
  Smartphone,
  Trash2,
  X,
} from "lucide-react";
import { useCallback, useEffect, useId, useRef, useState } from "react";

import { FormAlert, PasswordField } from "@/components/auth/auth-fields";
import { useAuthClient } from "@/components/auth/auth-client-provider";
import type {
  AuthFailure,
  AuthResult,
  BrowserSessionDto,
  CurrentUserDto,
  PasskeyDto,
  RecoveryCodesDto,
  TwoFactorSetupDto,
} from "@/lib/auth/auth-client";
import {
  browserWebAuthnCeremony,
  type WebAuthnCeremony,
  webAuthnErrorMessage,
} from "@/lib/auth/webauthn";

type RetryAction = () => Promise<void>;
type ConfirmationRequest = {
  title: string;
  description: string;
  label: string;
  action: RetryAction;
  returnFocus?: HTMLElement | null;
};

function useDialogFocus(
  open: boolean,
  onClose: () => void,
  returnFocus?: HTMLElement | null,
) {
  const dialogRef = useRef<HTMLDivElement>(null);
  const firstRef = useRef<HTMLInputElement | HTMLButtonElement>(null);
  const onCloseRef = useRef(onClose);

  useEffect(() => {
    onCloseRef.current = onClose;
  }, [onClose]);

  useEffect(() => {
    if (!open) return;
    firstRef.current?.focus();
    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === "Escape") {
        event.preventDefault();
        onCloseRef.current();
        return;
      }
      if (event.key !== "Tab") return;
      const focusable = dialogRef.current?.querySelectorAll<HTMLElement>(
        'button:not([disabled]), input:not([disabled]), [href]',
      );
      if (!focusable?.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
    document.addEventListener("keydown", handleKeyDown);
    return () => {
      document.removeEventListener("keydown", handleKeyDown);
      if (returnFocus) {
        window.setTimeout(() => {
          if (returnFocus.isConnected) returnFocus.focus();
        }, 0);
      }
    };
  }, [open, returnFocus]);

  return { dialogRef, firstRef };
}

function RecentPasswordDialog({
  open,
  onClose,
  onConfirmed,
  ceremony,
  returnFocus,
}: {
  open: boolean;
  onClose: () => void;
  onConfirmed: () => Promise<void>;
  ceremony: WebAuthnCeremony;
  returnFocus?: HTMLElement | null;
}) {
  const { client } = useAuthClient();
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<AuthFailure>();
  const close = useCallback(() => {
    if (!pending) {
      setError(undefined);
      onClose();
    }
  }, [onClose, pending]);
  const { dialogRef } = useDialogFocus(open, close, returnFocus);

  if (!open) return null;

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const password = String(new FormData(event.currentTarget).get("password") ?? "");
    if (!password) {
      setError({
        code: "validation_failed",
        message: "Enter your current password.",
        fieldErrors: { password: "Enter your current password." },
      });
      return;
    }
    setPending(true);
    setError(undefined);
    const result = await client.confirmPassword(password);
    setPending(false);
    if (!result.ok) {
      setError(result.error);
      return;
    }
    onClose();
    await onConfirmed();
  }

  async function confirmUsingPasskey() {
    setPending(true);
    setError(undefined);
    const options = await client.getPasskeyConfirmationOptions();
    if (!options.ok) {
      setPending(false);
      setError(options.error);
      return;
    }
    try {
      const credential = await ceremony.get(options.data);
      const result = await client.confirmWithPasskey(credential);
      setPending(false);
      if (!result.ok) {
        setError(result.error);
        return;
      }
      onClose();
      await onConfirmed();
    } catch (passkeyError) {
      setPending(false);
      setError({
        code: "service_unavailable",
        message: webAuthnErrorMessage(passkeyError),
      });
    }
  }

  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-[#1d1729]/55 px-4 py-8 backdrop-blur-sm">
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby="recent-password-title"
        className="w-full max-w-md rounded-[1.5rem] border border-white/20 bg-white p-5 shadow-2xl sm:p-7"
      >
        <div className="flex items-start gap-4">
          <span className="grid size-11 shrink-0 place-items-center rounded-2xl bg-[#f0ebfb] text-[#684db7]">
            <LockKeyhole className="size-5" aria-hidden="true" />
          </span>
          <div className="min-w-0 flex-1">
            <h2 id="recent-password-title" className="text-xl font-semibold tracking-[-0.035em] text-[#292230]">
              Confirm it’s you
            </h2>
            <p className="mt-1 text-sm leading-6 text-[#716a76]">
              Security changes require a recent identity confirmation. Use your password or a passkey; confirmation remains valid for up to 10 minutes.
            </p>
          </div>
          <button
            type="button"
            onClick={close}
            aria-label="Close password confirmation"
            className="grid size-9 shrink-0 place-items-center rounded-lg text-[#817985] hover:bg-[#f2eff4]"
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
              className="h-11 rounded-xl border border-[#d8d2dc] text-sm font-semibold text-[#55475f] hover:bg-[#faf8fc]"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={pending}
              className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-[#7457d2] text-sm font-semibold text-white disabled:bg-[#a99bd4]"
            >
              {pending ? <LoaderCircle className="size-4 animate-spin" aria-hidden="true" /> : null}
              {pending ? "Confirming…" : "Confirm password"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function ConfirmDialog({
  title,
  description,
  actionLabel,
  pending,
  onCancel,
  onConfirm,
  returnFocus,
}: {
  title: string;
  description: string;
  actionLabel: string;
  pending: boolean;
  onCancel: () => void;
  onConfirm: () => void;
  returnFocus?: HTMLElement | null;
}) {
  const close = useCallback(() => {
    if (!pending) onCancel();
  }, [onCancel, pending]);
  const { dialogRef, firstRef } = useDialogFocus(true, close, returnFocus);
  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-[#1d1729]/55 px-4 py-8 backdrop-blur-sm">
      <div
        ref={dialogRef}
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="security-confirm-title"
        aria-describedby="security-confirm-description"
        className="w-full max-w-md rounded-[1.5rem] bg-white p-6 shadow-2xl"
      >
        <span className="grid size-11 place-items-center rounded-2xl bg-[#fff0ed] text-[#a44d43]">
          <ShieldAlert className="size-5" aria-hidden="true" />
        </span>
        <h2 id="security-confirm-title" className="mt-4 text-xl font-semibold text-[#292230]">
          {title}
        </h2>
        <p id="security-confirm-description" className="mt-2 text-sm leading-6 text-[#716a76]">
          {description}
        </p>
        <div className="mt-6 grid gap-2 sm:grid-cols-2">
          <button
            ref={firstRef as React.RefObject<HTMLButtonElement>}
            type="button"
            onClick={close}
            disabled={pending}
            className="h-11 rounded-xl border border-[#d8d2dc] text-sm font-semibold text-[#55475f]"
          >
            Keep it
          </button>
          <button
            type="button"
            onClick={onConfirm}
            disabled={pending}
            className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-[#a84f47] px-4 text-sm font-semibold text-white disabled:bg-[#c99a96]"
          >
            {pending ? <LoaderCircle className="size-4 animate-spin" aria-hidden="true" /> : null}
            {actionLabel}
          </button>
        </div>
      </div>
    </div>
  );
}

function Section({
  icon: Icon,
  title,
  description,
  children,
}: {
  icon: typeof ShieldCheck;
  title: string;
  description: string;
  children: React.ReactNode;
}) {
  return (
    <section className="rounded-[1.5rem] border border-black/[0.07] bg-white p-5 shadow-[0_16px_45px_rgba(52,43,63,.07)] sm:p-7">
      <div className="flex items-start gap-4">
        <span className="grid size-11 shrink-0 place-items-center rounded-2xl bg-[#f1ecfb] text-[#684db7]">
          <Icon className="size-5" aria-hidden="true" />
        </span>
        <div>
          <h2 className="text-xl font-semibold tracking-[-0.035em] text-[#292230]">{title}</h2>
          <p className="mt-1 text-sm leading-6 text-[#756e79]">{description}</p>
        </div>
      </div>
      <div className="mt-6">{children}</div>
    </section>
  );
}

function RecoveryCodes({ codes }: { codes: string[] }) {
  const [copied, setCopied] = useState(false);
  async function copy() {
    try {
      await navigator.clipboard.writeText(codes.join("\n"));
      setCopied(true);
    } catch {
      setCopied(false);
    }
  }
  return (
    <div className="rounded-2xl border border-[#ddd5ee] bg-[#f8f5ff] p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h3 className="text-sm font-semibold text-[#3b3146]">Save these recovery codes now</h3>
          <p className="mt-1 text-xs leading-5 text-[#746c79]">
            Each code works once. Keep them outside this browser and away from your password.
          </p>
        </div>
        <button
          type="button"
          onClick={copy}
          className="inline-flex h-9 items-center gap-2 rounded-lg border border-[#d5cce8] bg-white px-3 text-xs font-semibold text-[#5d479b]"
        >
          {copied ? <Check className="size-3.5" aria-hidden="true" /> : <Clipboard className="size-3.5" aria-hidden="true" />}
          {copied ? "Copied" : "Copy codes"}
        </button>
      </div>
      <ul aria-label="One-time recovery codes" className="mt-4 grid gap-2 font-mono text-sm sm:grid-cols-2">
        {codes.map((code) => (
          <li key={code} className="rounded-lg bg-white px-3 py-2 text-[#40364a] shadow-sm">{code}</li>
        ))}
      </ul>
    </div>
  );
}

function friendlyDate(value: string) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "Unknown";
  return new Intl.DateTimeFormat(undefined, {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(date);
}

export function SecurityCenter({
  ceremony = browserWebAuthnCeremony,
}: {
  ceremony?: WebAuthnCeremony;
}) {
  const router = useRouter();
  const { client } = useAuthClient();
  const [loadAttempt, setLoadAttempt] = useState(0);
  const [loading, setLoading] = useState(true);
  const [user, setUser] = useState<CurrentUserDto>();
  const [loadFailure, setLoadFailure] = useState<AuthFailure>();
  const [passkeys, setPasskeys] = useState<PasskeyDto[]>([]);
  const [passkeyFailure, setPasskeyFailure] = useState<AuthFailure>();
  const [sessions, setSessions] = useState<BrowserSessionDto[]>([]);
  const [sessionFailure, setSessionFailure] = useState<AuthFailure>();
  const [twoFactorEnabled, setTwoFactorEnabled] = useState(false);
  const [twoFactorSetup, setTwoFactorSetup] = useState<TwoFactorSetupDto>();
  const [recovery, setRecovery] = useState<RecoveryCodesDto>();
  const [notice, setNotice] = useState<string>();
  const [actionFailure, setActionFailure] = useState<AuthFailure>();
  const [pendingAction, setPendingAction] = useState<string>();
  const [passwordDialogOpen, setPasswordDialogOpen] = useState(false);
  const [retryAction, setRetryAction] = useState<RetryAction>();
  const [confirmDialog, setConfirmDialog] = useState<ConfirmationRequest>();
  const [recentReturnFocus, setRecentReturnFocus] = useState<HTMLElement | null>(null);
  const passkeyNameId = useId();
  const totpCodeId = useId();

  useEffect(() => {
    let active = true;
    void (async () => {
      const currentUser = await client.getCurrentUser();
      if (!active) return;
      if (!currentUser.ok) {
        setLoadFailure(currentUser.error);
        setLoading(false);
        return;
      }
      setUser(currentUser.data);
      setTwoFactorEnabled(currentUser.data.twoFactorEnabled);
      const [passkeyResult, sessionResult] = await Promise.all([
        client.getPasskeys(),
        client.getSessions(),
      ]);
      if (!active) return;
      if (passkeyResult.ok) setPasskeys(passkeyResult.data);
      else setPasskeyFailure(passkeyResult.error);
      if (sessionResult.ok) setSessions(sessionResult.data);
      else setSessionFailure(sessionResult.error);
      setLoading(false);
    })();
    return () => {
      active = false;
    };
  }, [client, loadAttempt]);

  function recoverRecentPassword(
    action: RetryAction,
    returnFocus?: HTMLElement | null,
  ) {
    setRecentReturnFocus(returnFocus ?? null);
    setRetryAction(() => action);
    setPasswordDialogOpen(true);
  }

  function openConfirmDialog(dialog: ConfirmationRequest) {
    setConfirmDialog({
      ...dialog,
      returnFocus:
        document.activeElement instanceof HTMLElement
          ? document.activeElement
          : null,
    });
  }

  async function runProtected<T>(
    key: string,
    request: () => Promise<AuthResult<T>>,
    onSuccess: (data: T) => void | Promise<void>,
  ) {
    const actionTrigger =
      document.activeElement instanceof HTMLElement
        ? document.activeElement
        : null;
    setPendingAction(key);
    setActionFailure(undefined);
    setNotice(undefined);
    const result = await request();
    setPendingAction(undefined);
    if (!result.ok) {
      if (result.error.code === "recent_password_required") {
        recoverRecentPassword(
          () => runProtected(key, request, onSuccess),
          actionTrigger?.isConnected
            ? actionTrigger
            : document.activeElement instanceof HTMLElement
              ? document.activeElement
              : null,
        );
      } else {
        setActionFailure(result.error);
      }
      return;
    }
    await onSuccess(result.data);
  }

  async function refreshPasskeys() {
    const result = await client.getPasskeys();
    if (result.ok) {
      setPasskeys(result.data);
      setPasskeyFailure(undefined);
    } else setPasskeyFailure(result.error);
  }

  async function refreshSessions() {
    const result = await client.getSessions();
    if (result.ok) {
      setSessions(result.data);
      setSessionFailure(undefined);
    } else setSessionFailure(result.error);
  }

  async function beginTwoFactor() {
    await runProtected("enable-totp", () => client.enableTwoFactor(), async () => {
      const setup = await client.getTwoFactorSetup();
      if (!setup.ok) {
        setActionFailure(setup.error);
        return;
      }
      setTwoFactorSetup(setup.data);
      setNotice("Two-step verification is ready to confirm. Scan the code below.");
    });
  }

  async function confirmTwoFactor(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const code = String(new FormData(event.currentTarget).get("code") ?? "").trim();
    if (!/^\d{6}$/.test(code)) {
      setActionFailure({
        code: "validation_failed",
        message: "Enter the current 6-digit code from your authenticator app.",
        fieldErrors: { code: "Enter a current 6-digit code." },
      });
      return;
    }
    setPendingAction("confirm-totp");
    setActionFailure(undefined);
    const result = await client.confirmTwoFactor(code);
    setPendingAction(undefined);
    if (!result.ok) {
      setActionFailure(result.error);
      return;
    }
    setTwoFactorEnabled(true);
    setTwoFactorSetup(undefined);
    setRecovery(result.data);
    setNotice("Two-step verification is on. Save your recovery codes now.");
  }

  async function cancelTwoFactorSetup() {
    await runProtected("cancel-totp", () => client.disableTwoFactor(), () => {
      setTwoFactorSetup(undefined);
      setRecovery(undefined);
      setNotice("Authenticator setup was canceled and its pending seed was removed.");
    });
  }

  async function addPasskey(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const name = String(new FormData(form).get("passkeyName") ?? "").trim();
    if (name.length < 2 || name.length > 80) {
      setActionFailure({
        code: "validation_failed",
        message: "Give this passkey a short name you’ll recognize.",
        fieldErrors: { passkeyName: "Use between 2 and 80 characters." },
      });
      return;
    }
    const register = async (): Promise<AuthResult<null>> => {
      const options = await client.getPasskeyRegistrationOptions();
      if (!options.ok) return options;
      try {
        const credential = await ceremony.create(options.data);
        return client.registerPasskey(name, credential);
      } catch (error) {
        return {
          ok: false,
          error: { code: "service_unavailable", message: webAuthnErrorMessage(error) },
        };
      }
    };
    await runProtected("add-passkey", register, async () => {
      await refreshPasskeys();
      setNotice(`Passkey “${name}” was added.`);
      form.reset();
    });
  }

  if (loading) {
    return (
      <main className="grid min-h-screen place-items-center bg-[#f4f2ee] px-5">
        <div role="status" className="flex items-center gap-2 text-sm text-[#6f6876]">
          <LoaderCircle className="size-4 animate-spin" aria-hidden="true" />
          Loading account security…
        </div>
      </main>
    );
  }

  if (loadFailure || !user) {
    const expired = loadFailure?.code === "authentication_required";
    return (
      <main className="grid min-h-screen place-items-center bg-[#f4f2ee] px-5 py-12">
        <section className="w-full max-w-md rounded-[1.6rem] border border-black/[0.07] bg-white p-7 text-center shadow-[0_22px_70px_rgba(52,43,63,.10)]">
          <span className="mx-auto grid size-12 place-items-center rounded-2xl bg-[#f1ecfb] text-[#684db7]">
            <ShieldAlert className="size-5" aria-hidden="true" />
          </span>
          <h1 className="mt-5 text-2xl font-semibold tracking-[-0.04em] text-[#292230]">
            {expired ? "Your secure session ended" : "Security center unavailable"}
          </h1>
          <p role="alert" className="mt-3 text-sm leading-6 text-[#716a76]">
            {loadFailure?.message ?? "We could not confirm your account session."}
          </p>
          <div className="mt-6 space-y-3">
            {expired ? (
              <Link href="/login?returnTo=%2Faccount%2Fsecurity" className="inline-flex h-11 w-full items-center justify-center rounded-xl bg-[#7457d2] text-sm font-semibold text-white">
                Sign in again
              </Link>
            ) : (
              <button
                type="button"
                onClick={() => {
                  setLoading(true);
                  setLoadFailure(undefined);
                  setLoadAttempt((value) => value + 1);
                }}
                className="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl border border-[#d8d2dc] text-sm font-semibold text-[#55475f]"
              >
                <RefreshCcw className="size-4" aria-hidden="true" />
                Try again
              </button>
            )}
          </div>
        </section>
      </main>
    );
  }

  const qrSource = twoFactorSetup
    ? `data:image/svg+xml;charset=utf-8,${encodeURIComponent(twoFactorSetup.svg)}`
    : undefined;

  return (
    <main id="security-content" className="min-h-screen bg-[#f4f2ee] px-4 py-8 sm:px-6 lg:px-8 lg:py-12">
      <div className="mx-auto max-w-5xl">
        <div className="mb-8 flex flex-wrap items-end justify-between gap-5">
          <div>
            <p className="text-[11px] font-bold uppercase tracking-[0.2em] text-[#7357c6]">Account protection</p>
            <h1 className="mt-3 text-3xl font-semibold tracking-[-0.05em] text-[#211c2b] sm:text-4xl">Security center</h1>
            <p className="mt-3 max-w-2xl text-sm leading-6 text-[#716a76] sm:text-base">
              Protect {user.email} with independent sign-in methods and review every active session.
            </p>
          </div>
          <Link href="/" className="rounded-xl border border-[#d8d2dc] bg-white px-4 py-2.5 text-sm font-semibold text-[#55475f] hover:bg-[#faf8fc]">
            Back to Maestro
          </Link>
        </div>

        <div aria-live="polite" className="mb-5 space-y-3">
          {notice ? <FormAlert tone="success">{notice}</FormAlert> : null}
          {actionFailure ? <FormAlert>{actionFailure.message}</FormAlert> : null}
        </div>

        <div className="space-y-5">
          <Section
            icon={Smartphone}
            title="Authenticator app"
            description="Require a rotating code after your password. Recovery codes keep you from being locked out."
          >
            {!twoFactorEnabled && !twoFactorSetup ? (
              <button
                type="button"
                onClick={beginTwoFactor}
                disabled={Boolean(pendingAction)}
                className="inline-flex h-11 items-center gap-2 rounded-xl bg-[#7457d2] px-4 text-sm font-semibold text-white disabled:bg-[#a99bd4]"
              >
                {pendingAction === "enable-totp" ? <LoaderCircle className="size-4 animate-spin" aria-hidden="true" /> : <ShieldCheck className="size-4" aria-hidden="true" />}
                Enable two-step verification
              </button>
            ) : null}

            {twoFactorSetup && qrSource ? (
              <div className="grid gap-6 lg:grid-cols-[220px_1fr]">
                <div className="rounded-2xl border border-[#e2dce7] bg-white p-4">
                  {/* The SVG is isolated as an encoded image rather than injected HTML. */}
                  <Image
                    src={qrSource}
                    alt="QR code for adding Maestro to an authenticator app"
                    width={188}
                    height={188}
                    unoptimized
                    className="mx-auto aspect-square w-full"
                  />
                </div>
                <div>
                  <h3 className="text-base font-semibold text-[#352e3d]">Scan, then confirm</h3>
                  <ol className="mt-2 list-decimal space-y-2 pl-5 text-sm leading-6 text-[#716a76]">
                    <li>Scan the QR code with your authenticator app.</li>
                    <li>If scanning fails, enter this setup key: <code className="break-all rounded bg-[#f1eef4] px-1.5 py-1 font-mono text-xs text-[#4c3b5a]">{twoFactorSetup.secretKey}</code></li>
                    <li>Enter the current 6-digit code to finish.</li>
                  </ol>
                  <form onSubmit={confirmTwoFactor} className="mt-5 flex flex-col gap-3 sm:flex-row" noValidate>
                    <div className="flex-1">
                      <label htmlFor={totpCodeId} className="sr-only">Current 6-digit authenticator code</label>
                      <input
                        id={totpCodeId}
                        name="code"
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        maxLength={6}
                        pattern="[0-9]{6}"
                        aria-invalid={actionFailure?.fieldErrors?.code ? "true" : undefined}
                        className="h-11 w-full rounded-xl border border-[#dcd7df] bg-[#fbfaf8] px-3.5 font-mono tracking-[0.14em] focus:outline-none focus:ring-4 focus:ring-[#8768d8]/10"
                        placeholder="123456"
                      />
                    </div>
                    <button type="submit" disabled={pendingAction === "confirm-totp"} className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-[#7457d2] px-5 text-sm font-semibold text-white disabled:bg-[#a99bd4]">
                      {pendingAction === "confirm-totp" ? <LoaderCircle className="size-4 animate-spin" aria-hidden="true" /> : null}
                      Confirm setup
                    </button>
                    <button
                      type="button"
                      onClick={cancelTwoFactorSetup}
                      disabled={pendingAction === "cancel-totp"}
                      className="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-[#d8d2dc] px-4 text-sm font-semibold text-[#625868] disabled:bg-[#f2eff3]"
                    >
                      {pendingAction === "cancel-totp" ? <LoaderCircle className="size-4 animate-spin" aria-hidden="true" /> : null}
                      Cancel setup
                    </button>
                  </form>
                </div>
              </div>
            ) : null}

            {twoFactorEnabled ? (
              <div className="space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-[#cce3d6] bg-[#f2faf6] p-4">
                  <span className="flex items-center gap-2 text-sm font-semibold text-[#2f6f56]"><Check className="size-4" aria-hidden="true" /> Two-step verification is on</span>
                  <div className="flex flex-wrap gap-2">
                    <button type="button" onClick={() => runProtected("show-recovery", () => client.getRecoveryCodes(), setRecovery)} className="h-9 rounded-lg border border-[#c7dbd0] bg-white px-3 text-xs font-semibold text-[#376b56]">Show recovery codes</button>
                    <button type="button" onClick={() => openConfirmDialog({ title: "Replace all recovery codes?", description: "Every existing recovery code will stop working immediately.", label: "Replace codes", action: () => runProtected("regenerate-recovery", () => client.regenerateRecoveryCodes(), (data) => { setRecovery(data); setNotice("New recovery codes created. Save them now."); }) })} className="h-9 rounded-lg border border-[#d8d2dc] bg-white px-3 text-xs font-semibold text-[#665d6d]">Replace codes</button>
                    <button type="button" onClick={() => openConfirmDialog({ title: "Turn off two-step verification?", description: "Your account will no longer require an authenticator code after password sign-in.", label: "Turn off", action: () => runProtected("disable-totp", () => client.disableTwoFactor(), () => { setTwoFactorEnabled(false); setRecovery(undefined); setNotice("Two-step verification was turned off."); }) })} className="h-9 rounded-lg border border-[#ebc9c5] bg-white px-3 text-xs font-semibold text-[#984a43]">Turn off</button>
                  </div>
                </div>
                {recovery?.recoveryCodes.length ? <RecoveryCodes codes={recovery.recoveryCodes} /> : null}
              </div>
            ) : null}
          </Section>

          <Section
            icon={KeyRound}
            title="Passkeys"
            description="Use your device lock, fingerprint, or face instead of typing a password. Maestro never receives biometric data."
          >
            {!ceremony.isSupported() ? (
              <FormAlert tone="info">This browser cannot create passkeys. Existing passkeys remain available on supported devices.</FormAlert>
            ) : (
              <form onSubmit={addPasskey} className="flex flex-col gap-3 sm:flex-row" noValidate>
                <div className="flex-1">
                  <label htmlFor={passkeyNameId} className="sr-only">Passkey name</label>
                  <input id={passkeyNameId} name="passkeyName" maxLength={80} placeholder="e.g. Maya’s MacBook" aria-invalid={actionFailure?.fieldErrors?.passkeyName ? "true" : undefined} className="h-11 w-full rounded-xl border border-[#dcd7df] bg-[#fbfaf8] px-3.5 text-sm focus:outline-none focus:ring-4 focus:ring-[#8768d8]/10" />
                </div>
                <button type="submit" disabled={pendingAction === "add-passkey"} className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-[#7457d2] px-4 text-sm font-semibold text-white disabled:bg-[#a99bd4]">
                  {pendingAction === "add-passkey" ? <LoaderCircle className="size-4 animate-spin" aria-hidden="true" /> : <KeyRound className="size-4" aria-hidden="true" />}
                  Add passkey
                </button>
              </form>
            )}
            {passkeyFailure ? <div className="mt-4"><FormAlert>{passkeyFailure.message}</FormAlert></div> : null}
            <ul className="mt-5 divide-y divide-[#ebe7ed]" aria-label="Registered passkeys">
              {passkeys.map((passkey) => (
                <li key={passkey.id} className="flex flex-wrap items-center gap-4 py-4 first:pt-0 last:pb-0">
                  <span className="grid size-10 place-items-center rounded-xl bg-[#f2eff5] text-[#63566d]"><KeyRound className="size-4" aria-hidden="true" /></span>
                  <div className="min-w-0 flex-1">
                    <p className="font-semibold text-[#3a3241]">{passkey.name}</p>
                    <p className="mt-1 text-xs text-[#817985]">Added {friendlyDate(passkey.createdAt)}{passkey.lastUsedAt ? ` · Last used ${friendlyDate(passkey.lastUsedAt)}` : " · Not used yet"}</p>
                  </div>
                  <button type="button" aria-label={`Delete passkey ${passkey.name}`} onClick={() => openConfirmDialog({ title: `Delete “${passkey.name}”?`, description: "This passkey will stop signing in to Maestro. Other sign-in methods are unchanged.", label: "Delete passkey", action: () => runProtected(`delete-${passkey.id}`, () => client.deletePasskey(passkey.id), async () => { await refreshPasskeys(); setNotice(`Passkey “${passkey.name}” was deleted.`); }) })} className="grid size-10 place-items-center rounded-xl text-[#9d4d46] hover:bg-[#fff0ed]"><Trash2 className="size-4" aria-hidden="true" /></button>
                </li>
              ))}
              {!passkeys.length && !passkeyFailure ? <li className="py-4 text-sm text-[#817985]">No passkeys registered yet.</li> : null}
            </ul>
          </Section>

          <Section
            icon={MonitorSmartphone}
            title="Active sessions"
            description="Review browsers signed in to this account. The server enforces an 8-hour idle limit and a 30-day absolute limit."
          >
            {sessionFailure ? <FormAlert>{sessionFailure.message}</FormAlert> : null}
            {sessions.some((session) => !session.current) ? (
              <button type="button" onClick={() => openConfirmDialog({ title: "Sign out every other session?", description: "All other browsers and devices will need to sign in again. This device stays signed in.", label: "Sign out others", action: () => runProtected("revoke-others", () => client.revokeOtherSessions(), async () => { await refreshSessions(); setNotice("Every other session was signed out."); }) })} className="mb-4 inline-flex h-10 items-center gap-2 rounded-xl border border-[#d8d2dc] px-3.5 text-xs font-semibold text-[#625868]"><LogOut className="size-4" aria-hidden="true" /> Sign out other sessions</button>
            ) : null}
            <ul className="divide-y divide-[#ebe7ed]" aria-label="Active account sessions">
              {sessions.map((session) => (
                <li key={session.id} className="flex flex-wrap items-center gap-4 py-4 first:pt-0 last:pb-0">
                  <span className="grid size-10 place-items-center rounded-xl bg-[#f2eff5] text-[#63566d]">{/iphone|ipad|android/i.test(session.device) ? <Smartphone className="size-4" aria-hidden="true" /> : <Laptop className="size-4" aria-hidden="true" />}</span>
                  <div className="min-w-0 flex-1">
                    <p className="font-semibold text-[#3a3241]">{session.device} {session.current ? <span className="ml-1 rounded-full bg-[#dff1e8] px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-[#347058]">This device</span> : null}</p>
                    <p className="mt-1 text-xs leading-5 text-[#817985]">Last active {friendlyDate(session.lastSeenAt)}{session.approximateLocation ? ` · ${session.approximateLocation}` : ""}</p>
                  </div>
                  <button type="button" onClick={() => openConfirmDialog({ title: session.current ? "Sign out this device?" : `Sign out ${session.device}?`, description: session.current ? "This secure session will end immediately and you’ll return to sign in." : "That browser will need to sign in again.", label: "Sign out", action: () => runProtected(`revoke-${session.id}`, () => client.revokeSession(session.id), async () => { if (session.current) router.replace("/login"); else { await refreshSessions(); setNotice("The selected session was signed out."); } }) })} className="h-9 rounded-lg border border-[#e4dfe6] px-3 text-xs font-semibold text-[#8f4943] hover:bg-[#fff4f2]">{session.current ? "Sign out" : "Revoke"}</button>
                </li>
              ))}
              {!sessions.length && !sessionFailure ? <li className="py-4 text-sm text-[#817985]">No active session records were returned.</li> : null}
            </ul>
          </Section>
        </div>
      </div>

      <RecentPasswordDialog
        open={passwordDialogOpen}
        onClose={() => setPasswordDialogOpen(false)}
        ceremony={ceremony}
        returnFocus={recentReturnFocus}
        onConfirmed={async () => {
          const retry = retryAction;
          setRetryAction(undefined);
          if (retry) await retry();
        }}
      />
      {confirmDialog ? (
        <ConfirmDialog
          title={confirmDialog.title}
          description={confirmDialog.description}
          actionLabel={confirmDialog.label}
          pending={Boolean(pendingAction)}
          returnFocus={confirmDialog.returnFocus}
          onCancel={() => setConfirmDialog(undefined)}
          onConfirm={() => {
            const action = confirmDialog.action;
            setConfirmDialog(undefined);
            void action();
          }}
        />
      ) : null}
    </main>
  );
}
