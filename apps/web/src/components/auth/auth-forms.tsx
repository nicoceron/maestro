"use client";

import { useEffect, useId, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { LoaderCircle, RefreshCcw, ShieldCheck } from "lucide-react";

import {
  AuthSubmitButton,
  AuthTextField,
  FormAlert,
  PasswordField,
  PasswordRequirements,
  useFocusFirstInvalid,
} from "@/components/auth/auth-fields";
import { useAuthClient } from "@/components/auth/auth-client-provider";
import {
  useInvitationSession,
  useResetCredentialSession,
} from "@/components/auth/identity-credential-session";
import { PasskeyLoginButton } from "@/components/security/auth-challenges";
import type {
  AuthClient,
  AuthFailure,
  AuthFieldName,
  CurrentUserDto,
} from "@/lib/auth/auth-client";
import type { SafeAuthReturnPath } from "@/lib/auth/auth-query";

type FormFieldName = AuthFieldName | "terms";
type FieldErrors = Partial<Record<FormFieldName, string>>;

const loginFieldOrder = ["email", "password"] as const;
const registerFieldOrder = [
  "name",
  "email",
  "password",
  "passwordConfirmation",
  "terms",
] as const;
const forgotFieldOrder = ["email"] as const;
const resetFieldOrder = ["password", "passwordConfirmation"] as const;

const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function fieldValue(formData: FormData, name: string) {
  const value = formData.get(name);
  return typeof value === "string" ? value.trim() : "";
}

function validateEmail(email: string): string | undefined {
  if (!email) return "Enter your email address.";
  if (!emailPattern.test(email)) return "Enter a complete email address.";
  return undefined;
}

function validatePassword(password: string): string | undefined {
  if (password.length < 12) return "Use at least 12 characters.";
  return undefined;
}

function failureErrors(failure: AuthFailure) {
  return failure.fieldErrors ?? {};
}

function useInviteSessionGate(client: AuthClient, invitationToken?: string) {
  const { replace } = useRouter();
  const [checking, setChecking] = useState(Boolean(invitationToken));
  const [error, setError] = useState<AuthFailure | null>(null);
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    if (!invitationToken) return;
    let active = true;
    void client.getCurrentUser().then((result) => {
      if (!active) return;
      if (result.ok) {
        replace("/onboarding");
        return;
      }
      if (result.error.code !== "authentication_required") {
        setError(result.error);
      }
      setChecking(false);
    });
    return () => {
      active = false;
    };
  }, [attempt, client, invitationToken, replace]);

  return {
    checking,
    error,
    retry: () => {
      setChecking(true);
      setError(null);
      setAttempt((current) => current + 1);
    },
  };
}

function InviteSessionCheck() {
  return (
    <div role="status" className="flex items-center justify-center gap-2 py-10 text-sm text-[#6f6478]">
      <LoaderCircle className="size-4 animate-spin" aria-hidden="true" />
      Checking your secure session…
    </div>
  );
}

function InviteSessionFailure({
  error,
  onRetry,
}: {
  error: AuthFailure;
  onRetry: () => void;
}) {
  return (
    <div className="space-y-4">
      <FormAlert>{error.message}</FormAlert>
      <button
        type="button"
        onClick={onRetry}
        className="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl border border-[#d8d2dc] text-sm font-semibold text-[#55475f] hover:bg-[#faf8fc]"
      >
        <RefreshCcw className="size-3.5" aria-hidden="true" />
        Check secure session again
      </button>
    </div>
  );
}

export function LoginForm({
  initialEmail,
  continueTo,
}: {
  initialEmail?: string;
  continueTo?: SafeAuthReturnPath;
}) {
  const router = useRouter();
  const { client } = useAuthClient();
  const { token: invitationToken } = useInvitationSession();
  const inviteSession = useInviteSessionGate(client, invitationToken);
  const [pending, setPending] = useState(false);
  const [remember, setRemember] = useState(false);
  const [formError, setFormError] = useState<AuthFailure | null>(null);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
  const formRef = useFocusFirstInvalid(fieldErrors, loginFieldOrder);

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const formData = new FormData(event.currentTarget);
    const email = fieldValue(formData, "email").toLowerCase();
    const password = fieldValue(formData, "password");
    const nextErrors: FieldErrors = {
      email: validateEmail(email),
      password: password ? undefined : "Enter your password.",
    };

    if (nextErrors.email || nextErrors.password) {
      setFieldErrors(nextErrors);
      setFormError(null);
      return;
    }

    setPending(true);
    setFieldErrors({});
    setFormError(null);

    const result = await client.login({
      email,
      password,
      remember,
      invitationToken,
      continueTo,
    });

    setPending(false);
    if (!result.ok) {
      setFieldErrors(failureErrors(result.error));
      setFormError(result.error);
      return;
    }

    if (result.data.sessionEstablished || result.data.requiresTwoFactor) {
      router.replace(result.data.redirectTo);
    }
  }

  if (inviteSession.checking) return <InviteSessionCheck />;
  if (inviteSession.error) {
    return (
      <InviteSessionFailure
        error={inviteSession.error}
        onRetry={inviteSession.retry}
      />
    );
  }

  return (
    <form ref={formRef} onSubmit={handleSubmit} noValidate className="space-y-5">
      {formError ? (
        <FormAlert>
          <p>{formError.message}</p>
          {formError.code === "email_unverified" ? (
            <Link
              href="/verify-email"
              className="mt-1 inline-flex font-semibold underline underline-offset-2"
            >
              Open email verification
            </Link>
          ) : null}
        </FormAlert>
      ) : null}

      <AuthTextField
        label="Email address"
        name="email"
        type="email"
        inputMode="email"
        autoComplete="email"
        defaultValue={initialEmail}
        placeholder="you@studio.com"
        error={fieldErrors.email}
      />

      <div>
        <div className="mb-2 flex items-center justify-end">
          <Link
            href="/forgot-password"
            className="rounded text-xs font-semibold text-[#684db7] hover:text-[#4f368f]"
          >
            Forgot password?
          </Link>
        </div>
        <PasswordField
          autoComplete="current-password"
          error={fieldErrors.password}
        />
      </div>

      <label className="flex cursor-pointer items-start gap-3 text-sm leading-5 text-[#5f5766]">
        <input
          type="checkbox"
          name="remember"
          checked={remember}
          onChange={(event) => setRemember(event.currentTarget.checked)}
          className="mt-0.5 size-4 rounded border-[#c8c1cc] accent-[#7457d2]"
        />
        <span>
          Keep me signed in on this device
          <span className="block text-xs text-[#8b838f]">
            Extends the secure cookie session; no token is saved in browser storage.
          </span>
        </span>
      </label>

      <AuthSubmitButton
        pending={pending}
        idleLabel="Sign in"
        pendingLabel="Signing in…"
      />

      <PasskeyLoginButton remember={remember} continueTo={continueTo} />
    </form>
  );
}

export function RegisterForm({
  initialEmail,
}: {
  initialEmail?: string;
}) {
  const { client } = useAuthClient();
  const { token: inviteToken } = useInvitationSession();
  const inviteSession = useInviteSessionGate(client, inviteToken);
  const [pending, setPending] = useState(false);
  const [password, setPassword] = useState("");
  const [formError, setFormError] = useState<AuthFailure | null>(null);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
  const [success, setSuccess] = useState<string>();
  const termsErrorId = useId();
  const successHeadingRef = useRef<HTMLHeadingElement>(null);
  const formRef = useFocusFirstInvalid(fieldErrors, registerFieldOrder);

  useEffect(() => {
    if (success) successHeadingRef.current?.focus();
  }, [success]);

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const formData = new FormData(event.currentTarget);
    const name = fieldValue(formData, "name");
    const email = fieldValue(formData, "email").toLowerCase();
    const submittedPassword = fieldValue(formData, "password");
    const passwordConfirmation = fieldValue(formData, "passwordConfirmation");
    const termsAccepted = formData.get("terms") === "on";
    const nextErrors: FieldErrors = {
      name: name.length >= 2 ? undefined : "Tell us what to call you.",
      email: validateEmail(email),
      password: validatePassword(submittedPassword),
      passwordConfirmation:
        submittedPassword === passwordConfirmation
          ? undefined
          : "The passwords do not match.",
      terms: termsAccepted
        ? undefined
        : "Accept the Terms and acknowledge the Privacy Policy.",
    };

    if (!termsAccepted) {
      setFormError({
        code: "validation_failed",
        message: "Review and accept the terms to create your account.",
      });
    } else {
      setFormError(null);
    }

    if (
      nextErrors.name ||
      nextErrors.email ||
      nextErrors.password ||
      nextErrors.passwordConfirmation ||
      nextErrors.terms
    ) {
      setFieldErrors(nextErrors);
      return;
    }

    setPending(true);
    setFieldErrors({});
    setFormError(null);
    const result = await client.register({
      name,
      email,
      password: submittedPassword,
      passwordConfirmation,
      invitationToken: inviteToken,
    });
    setPending(false);

    if (!result.ok) {
      setFieldErrors(failureErrors(result.error));
      setFormError(result.error);
      return;
    }

    setSuccess(result.data.message);
  }

  if (inviteSession.checking) return <InviteSessionCheck />;
  if (inviteSession.error) {
    return (
      <InviteSessionFailure
        error={inviteSession.error}
        onRetry={inviteSession.retry}
      />
    );
  }

  if (success) {
    return (
      <div className="space-y-5">
        <FormAlert tone="success">
          <div>
            <h2
              ref={successHeadingRef}
              tabIndex={-1}
              className="font-semibold outline-none focus-visible:rounded focus-visible:ring-4 focus-visible:ring-[#8768d8]/15"
            >
              Check your email for next steps
            </h2>
            <p className="mt-1">{success}</p>
          </div>
        </FormAlert>
        <p className="text-sm leading-6 text-[#716a76]">
          This confirmation is the same for every request. You have not been
          signed in. Follow any email instructions, or sign in if you already
          use Maestro.
        </p>
        {inviteToken ? (
          <p className="rounded-xl border border-[#ded7ef] bg-[#f7f4ff] px-3.5 py-3 text-sm leading-6 text-[#5d4b8d]">
            Your invitation remains secured in this browser. Studio access is
            not granted until you sign in and Maestro validates the invitation.
          </p>
        ) : null}
        <Link
          href="/login"
          className="inline-flex h-12 w-full items-center justify-center rounded-xl bg-[#7457d2] px-4 text-sm font-semibold text-white shadow-[0_9px_24px_rgba(106,76,195,.24)] transition hover:bg-[#684bc6]"
        >
          Continue to sign in
        </Link>
      </div>
    );
  }

  return (
    <form ref={formRef} onSubmit={handleSubmit} noValidate className="space-y-5">
      {inviteToken ? (
        <FormAlert tone="info">
          <strong className="block font-semibold">Continue from an invitation</strong>
          Submitting does not accept the invitation or reveal studio details.
          Maestro validates access after you verify or sign in.
        </FormAlert>
      ) : null}
      {formError ? <FormAlert>{formError.message}</FormAlert> : null}

      <AuthTextField
        label="Your name"
        name="name"
        autoComplete="name"
        placeholder="Maya Ortiz"
        error={fieldErrors.name}
      />
      <AuthTextField
        label="Email address"
        name="email"
        type="email"
        inputMode="email"
        autoComplete="email"
        defaultValue={initialEmail}
        placeholder="you@studio.com"
        error={fieldErrors.email}
        hint={inviteToken ? "Use the address where your invitation arrived." : undefined}
      />
      <PasswordField
        autoComplete="new-password"
        error={fieldErrors.password}
        onValueChange={setPassword}
      />
      <PasswordRequirements value={password} />
      <PasswordField
        label="Confirm password"
        name="passwordConfirmation"
        autoComplete="new-password"
        error={fieldErrors.passwordConfirmation}
      />

      <div>
        <label className="flex cursor-pointer items-start gap-3 text-xs leading-5 text-[#6f6875]">
          <input
            type="checkbox"
            name="terms"
            required
            aria-invalid={fieldErrors.terms ? "true" : undefined}
            aria-describedby={fieldErrors.terms ? termsErrorId : undefined}
            className="mt-0.5 size-4 shrink-0 rounded border-[#c8c1cc] accent-[#7457d2]"
          />
          <span>
            I agree to Maestro&apos;s{" "}
            <Link href="/terms" className="font-semibold text-[#684db7] underline-offset-2 hover:underline">
              Terms
            </Link>{" "}
            and acknowledge the{" "}
            <Link href="/privacy" className="font-semibold text-[#684db7] underline-offset-2 hover:underline">
              Privacy Policy
            </Link>
            .
          </span>
        </label>
        {fieldErrors.terms ? (
          <p id={termsErrorId} className="mt-1.5 pl-7 text-xs leading-5 text-[#a54e46]">
            {fieldErrors.terms}
          </p>
        ) : null}
      </div>

      <AuthSubmitButton
        pending={pending}
        idleLabel={inviteToken ? "Continue securely" : "Create account"}
        pendingLabel="Sending your secure request…"
      />
    </form>
  );
}

export function ForgotPasswordForm({ initialEmail }: { initialEmail?: string }) {
  const { client } = useAuthClient();
  const [pending, setPending] = useState(false);
  const [fieldError, setFieldError] = useState<string>();
  const [formError, setFormError] = useState<string>();
  const [success, setSuccess] = useState<string>();
  const fieldErrors: FieldErrors = { email: fieldError };
  const formRef = useFocusFirstInvalid(fieldErrors, forgotFieldOrder);

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const email = fieldValue(new FormData(event.currentTarget), "email").toLowerCase();
    const error = validateEmail(email);
    if (error) {
      setFieldError(error);
      return;
    }

    setPending(true);
    setFieldError(undefined);
    setFormError(undefined);
    const result = await client.requestPasswordReset({ email });
    setPending(false);
    if (!result.ok) {
      setFormError(result.error.message);
      return;
    }
    setSuccess(result.data.message);
  }

  if (success) {
    return (
      <div className="space-y-5">
        <FormAlert tone="success">{success}</FormAlert>
        <p className="text-sm leading-6 text-[#716a76]">
          For privacy, Maestro shows the same confirmation whether or not the address is registered.
        </p>
        <Link
          href="/login"
          className="inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl border border-[#d6d0da] bg-white text-sm font-semibold text-[#4b4057] hover:bg-[#faf8fc]"
        >
          Back to sign in
        </Link>
      </div>
    );
  }

  return (
    <form ref={formRef} onSubmit={handleSubmit} noValidate className="space-y-5">
      {formError ? <FormAlert>{formError}</FormAlert> : null}
      <AuthTextField
        label="Email address"
        name="email"
        type="email"
        inputMode="email"
        autoComplete="email"
        defaultValue={initialEmail}
        placeholder="you@studio.com"
        error={fieldError}
        hint="We’ll send a time-limited link if the address matches an account."
      />
      <AuthSubmitButton
        pending={pending}
        idleLabel="Send reset link"
        pendingLabel="Sending link…"
      />
    </form>
  );
}

export function ResetPasswordForm() {
  const router = useRouter();
  const { client } = useAuthClient();
  const { resetCredential, clearResetCredential } =
    useResetCredentialSession();
  const [pending, setPending] = useState(false);
  const [password, setPassword] = useState("");
  const [errors, setErrors] = useState<FieldErrors>({});
  const [formError, setFormError] = useState<AuthFailure | null>(null);
  const formRef = useFocusFirstInvalid(errors, resetFieldOrder);

  if (!resetCredential) {
    return (
      <div className="space-y-5">
        <FormAlert>
          This reset link is incomplete. Request a new one to protect your account.
        </FormAlert>
        <Link
          href="/forgot-password"
          className="inline-flex h-12 w-full items-center justify-center rounded-xl bg-[#7457d2] text-sm font-semibold text-white"
        >
          Request a new reset link
        </Link>
      </div>
    );
  }

  const resetToken = resetCredential.token;
  const resetEmail = resetCredential.email;

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const formData = new FormData(event.currentTarget);
    const nextPassword = fieldValue(formData, "password");
    const confirmation = fieldValue(formData, "passwordConfirmation");
    const nextErrors: FieldErrors = {
      password: validatePassword(nextPassword),
      passwordConfirmation:
        nextPassword === confirmation ? undefined : "The passwords do not match.",
    };
    if (nextErrors.password || nextErrors.passwordConfirmation) {
      setErrors(nextErrors);
      setFormError(null);
      return;
    }

    setPending(true);
    setErrors({});
    setFormError(null);
    const result = await client.resetPassword({
      email: resetEmail,
      password: nextPassword,
      passwordConfirmation: confirmation,
      token: resetToken,
    });
    setPending(false);
    if (!result.ok) {
      setErrors(failureErrors(result.error));
      setFormError(result.error);
      return;
    }
    if (result.data.sessionEstablished) {
      clearResetCredential();
      router.replace(result.data.redirectTo);
    }
  }

  return (
    <form ref={formRef} onSubmit={handleSubmit} noValidate className="space-y-5">
      {formError ? (
        <FormAlert>
          <p>{formError.message}</p>
          {formError.code === "token_expired" ? (
            <Link href="/forgot-password" className="mt-1 inline-flex font-semibold underline">
              Request another link
            </Link>
          ) : null}
        </FormAlert>
      ) : null}
      <PasswordField
        label="New password"
        autoComplete="new-password"
        error={errors.password}
        onValueChange={setPassword}
      />
      <PasswordRequirements value={password} />
      <PasswordField
        label="Confirm new password"
        name="passwordConfirmation"
        autoComplete="new-password"
        error={errors.passwordConfirmation}
      />
      <AuthSubmitButton
        pending={pending}
        idleLabel="Set new password"
        pendingLabel="Securing your account…"
      />
    </form>
  );
}

export function VerifyEmailPanel() {
  const { client } = useAuthClient();
  const [resendPending, setResendPending] = useState(false);
  const [message, setMessage] = useState<string>();
  const [error, setError] = useState<AuthFailure | null>(null);
  const [identity, setIdentity] = useState<CurrentUserDto | null>(null);
  const [identityPending, setIdentityPending] = useState(true);

  useEffect(() => {
    let active = true;
    void client.getCurrentUser().then((result) => {
      if (!active) return;
      setIdentityPending(false);
      if (result.ok) {
        setIdentity(result.data);
      } else {
        setError(result.error);
      }
    });
    return () => {
      active = false;
    };
  }, [client]);

  async function resend() {
    setResendPending(true);
    setError(null);
    setMessage(undefined);
    const result = await client.resendVerification();
    setResendPending(false);
    if (!result.ok) {
      setError(result.error);
      return;
    }
    setMessage(result.data.message);
  }

  return (
    <div className="space-y-5">
      <div className="flex items-start gap-3 rounded-2xl bg-[#f5f1ff] p-4">
        <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-white text-[#7054c2] shadow-sm">
          <ShieldCheck className="size-5" aria-hidden="true" />
        </span>
        <div>
          <p className="text-sm font-semibold text-[#443653]">One quick security check</p>
          <p className="mt-1 text-xs leading-5 text-[#766a80]">
            Email verification protects studio schedules, family details, and payment records.
          </p>
        </div>
      </div>

      {error ? <FormAlert>{error.message}</FormAlert> : null}
      {message ? <FormAlert tone="success">{message}</FormAlert> : null}

      {identity?.emailVerifiedAt ? (
        <div className="space-y-4">
          <FormAlert tone="success">
            <strong className="block font-semibold">Email verified</strong>
            Continue to studio setup when you are ready.
          </FormAlert>
          <Link
            href="/onboarding"
            className="inline-flex h-11 w-full items-center justify-center rounded-xl bg-[#7457d2] text-sm font-semibold text-white"
          >
            Continue to studio setup
          </Link>
        </div>
      ) : (
        <>
          <FormAlert tone="info">
            Open the signed link in the email from Maestro. Verification is completed securely by the API.
          </FormAlert>
          {identity ? (
            <p className="text-center text-xs text-[#7b7380]">
              Signed in as <strong className="font-semibold text-[#51465a]">{identity.email}</strong>
            </p>
          ) : null}
          {error?.code === "authentication_required" ? (
            <Link
              href="/login"
              className="inline-flex h-11 w-full items-center justify-center rounded-xl border border-[#d8d2dc] text-sm font-semibold text-[#55475f]"
            >
              Sign in to resend securely
            </Link>
          ) : (
            <button
              type="button"
              onClick={resend}
              disabled={identityPending || resendPending || !identity}
              className="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl border border-[#d8d2dc] text-sm font-semibold text-[#55475f] hover:bg-[#faf8fc] disabled:cursor-wait disabled:opacity-60"
            >
              <RefreshCcw className={`size-3.5 ${resendPending ? "animate-spin" : ""}`} aria-hidden="true" />
              {identityPending
                ? "Checking secure session…"
                : resendPending
                  ? "Sending…"
                  : "Send a fresh verification link"}
            </button>
          )}
        </>
      )}
    </div>
  );
}
