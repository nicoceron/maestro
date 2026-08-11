"use client";

import { useEffect, useId, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import {
  ArrowLeft,
  ArrowRight,
  Banknote,
  Building2,
  CalendarCheck2,
  Check,
  CreditCard,
  GraduationCap,
  LoaderCircle,
  Music2,
  ShieldCheck,
  Sparkles,
  UserRoundCog,
  UsersRound,
} from "lucide-react";

import {
  AuthTextField,
  FormAlert,
  useFocusFirstInvalid,
} from "@/components/auth/auth-fields";
import { useAuthClient } from "@/components/auth/auth-client-provider";
import { useInvitationSession } from "@/components/auth/identity-credential-session";
import type {
  AuthFailure,
  AuthFieldName,
  OnboardingInput,
} from "@/lib/auth/auth-client";

type WorkspaceMode = OnboardingInput["workspaceMode"];
type Goal = OnboardingInput["primaryGoal"];

type OnboardingState = Omit<OnboardingInput, "invitationToken">;

const workspaceOptions: Array<{
  value: WorkspaceMode;
  label: string;
  description: string;
  icon: typeof Building2;
}> = [
  {
    value: "owner",
    label: "Studio owner",
    description: "I run the business and need the full operating view.",
    icon: Building2,
  },
  {
    value: "administrator",
    label: "Studio administrator",
    description: "I coordinate the calendar, families, and day-to-day details.",
    icon: UserRoundCog,
  },
  {
    value: "teacher",
    label: "Teacher",
    description: "I teach lessons and keep students moving forward.",
    icon: GraduationCap,
  },
];

const goals: Array<{
  value: Goal;
  label: string;
  description: string;
  icon: typeof CalendarCheck2;
}> = [
  {
    value: "schedule",
    label: "A smoother schedule",
    description: "Fewer gaps, conflicts, and back-and-forth messages.",
    icon: CalendarCheck2,
  },
  {
    value: "billing",
    label: "Reliable billing",
    description: "Know what is paid, due, or needs a gentle nudge.",
    icon: CreditCard,
  },
  {
    value: "teaching",
    label: "Better learning",
    description: "Connect lesson notes, assignments, and practice.",
    icon: Music2,
  },
  {
    value: "growth",
    label: "Grow with clarity",
    description: "Turn inquiries into the right students and teachers.",
    icon: UsersRound,
  },
];

const steps = ["About you", "Your studio", "First priorities"];
const profileFieldOrder = ["name", "workspaceMode"] as const;
const studioFieldOrder = ["studioName", "studioSlug"] as const;
const prioritiesFieldOrder = ["primaryGoal", "timeZone", "currency"] as const;

const initialState: OnboardingState = {
  firstName: "",
  workspaceMode: "owner",
  studioName: "",
  studioSlug: "",
  timeZone: "America/Bogota",
  currency: "USD",
  primaryGoal: "schedule",
};

function formValue(formData: FormData, name: string) {
  const value = formData.get(name);
  return typeof value === "string" ? value.trim() : "";
}

function slugify(value: string) {
  return value
    .toLowerCase()
    .normalize("NFKD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "")
    .slice(0, 48);
}

export function OnboardingFlow() {
  const router = useRouter();
  const { client } = useAuthClient();
  const { token: inviteToken, clearInvitation } = useInvitationSession();
  const [sessionStatus, setSessionStatus] = useState<
    "checking" | "ready" | "authentication_required" | "error"
  >("checking");
  const [sessionError, setSessionError] = useState<AuthFailure>();
  const [sessionAttempt, setSessionAttempt] = useState(0);
  const [step, setStep] = useState(0);
  const [state, setState] = useState(initialState);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string>();
  const [fieldErrors, setFieldErrors] = useState<
    Partial<Record<AuthFieldName, string>>
  >({});
  const stepHeadingRef = useRef<HTMLHeadingElement>(null);
  const previousStepRef = useRef(step);
  const workspaceErrorId = useId();
  const goalErrorId = useId();
  const timeZoneErrorId = useId();
  const currencyErrorId = useId();

  useEffect(() => {
    let active = true;
    void client.getCurrentUser().then((result) => {
      if (!active) return;
      if (result.ok) {
        setSessionStatus("ready");
        return;
      }
      setSessionError(result.error);
      setSessionStatus(
        result.error.code === "authentication_required"
          ? "authentication_required"
          : "error",
      );
    });
    return () => {
      active = false;
    };
  }, [client, sessionAttempt]);

  useEffect(() => {
    if (sessionStatus !== "ready" || previousStepRef.current === step) return;
    previousStepRef.current = step;
    stepHeadingRef.current?.focus();
  }, [sessionStatus, step]);

  const profileFormRef = useFocusFirstInvalid(fieldErrors, profileFieldOrder);
  const studioFormRef = useFocusFirstInvalid(fieldErrors, studioFieldOrder);
  const prioritiesFormRef = useFocusFirstInvalid(
    fieldErrors,
    prioritiesFieldOrder,
  );

  function saveProfile(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const formData = new FormData(event.currentTarget);
    const firstName = formValue(formData, "name");
    if (firstName.length < 2) {
      setFieldErrors({
        name: "Tell us what you would like the studio to call you.",
      });
      return;
    }
    setState((current) => ({
      ...current,
      firstName,
      workspaceMode: formValue(formData, "workspaceMode") as WorkspaceMode,
    }));
    setFieldErrors({});
    setError(undefined);
    setStep(1);
  }

  function saveStudio(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (inviteToken) {
      setFieldErrors({});
      setError(undefined);
      setStep(2);
      return;
    }

    const formData = new FormData(event.currentTarget);
    const studioName = formValue(formData, "studioName");
    const requestedSlug = slugify(formValue(formData, "studioSlug") || studioName);
    if (studioName.length < 2) {
      setFieldErrors({
        studioName: "Enter the name families know your studio by.",
      });
      return;
    }
    if (requestedSlug.length < 2) {
      setFieldErrors({
        studioSlug: "Choose a studio address using letters and numbers.",
      });
      return;
    }
    setState((current) => ({
      ...current,
      studioName,
      studioSlug: requestedSlug,
    }));
    setFieldErrors({});
    setError(undefined);
    setStep(2);
  }

  async function complete(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const formData = new FormData(event.currentTarget);
    const nextState: OnboardingState = {
      ...state,
      timeZone: inviteToken ? state.timeZone : formValue(formData, "timeZone"),
      currency: inviteToken
        ? state.currency
        : (formValue(formData, "currency") as OnboardingState["currency"]),
      primaryGoal: formValue(formData, "primaryGoal") as Goal,
    };
    setState(nextState);
    setPending(true);
    setError(undefined);
    setFieldErrors({});
    const result = await client.completeOnboarding({
      ...nextState,
      invitationToken: inviteToken,
    });
    setPending(false);
    if (!result.ok) {
      if (result.error.code === "authentication_required") {
        setSessionError(result.error);
        setSessionStatus("authentication_required");
        return;
      }
      const nextFieldErrors = result.error.fieldErrors ?? {};
      setFieldErrors(nextFieldErrors);
      setError(result.error.message);
      if (nextFieldErrors.name || nextFieldErrors.workspaceMode) {
        setStep(0);
      } else if (
        !inviteToken &&
        (nextFieldErrors.studioName || nextFieldErrors.studioSlug)
      ) {
        setStep(1);
      }
      return;
    }
    if (result.data.sessionEstablished) {
      clearInvitation();
      router.replace(result.data.redirectTo);
    }
  }

  const currentStepTitle =
    step === 0
      ? "How do you fit into the studio?"
      : step === 1
        ? inviteToken
          ? "Connect to your invitation"
          : "Give your studio a home"
        : "What should Maestro improve first?";

  if (sessionStatus === "checking") {
    return (
      <div role="status" className="flex items-center justify-center gap-2 py-12 text-sm text-[#6f6478]">
        <LoaderCircle className="size-4 animate-spin" aria-hidden="true" />
        Checking your secure session…
      </div>
    );
  }

  if (sessionStatus !== "ready") {
    const signInHref = "/login";
    return (
      <div className="space-y-4">
        <FormAlert>
          {sessionError?.message ??
            "We could not confirm your secure session. Try again."}
        </FormAlert>
        {sessionStatus === "authentication_required" ? (
          <Link
            href={signInHref}
            className="inline-flex h-11 w-full items-center justify-center rounded-xl bg-[#7457d2] px-4 text-sm font-semibold text-white"
          >
            Sign in to continue
          </Link>
        ) : null}
        <button
          type="button"
          onClick={() => {
            setSessionStatus("checking");
            setSessionError(undefined);
            setSessionAttempt((current) => current + 1);
          }}
          className="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl border border-[#d8d2dc] text-sm font-semibold text-[#55475f] hover:bg-[#faf8fc]"
        >
          <LoaderCircle className="size-3.5" aria-hidden="true" />
          Check secure session again
        </button>
      </div>
    );
  }

  return (
    <div className="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)] lg:gap-8">
      <p className="sr-only" role="status" aria-live="polite" aria-atomic="true">
        Step {step + 1} of {steps.length}: {currentStepTitle}
      </p>
      <nav aria-label="Onboarding progress" className="rounded-2xl bg-[#f5f1fb] p-4 lg:self-start lg:p-5">
        <div className="mb-4 flex items-center gap-2 text-xs font-bold uppercase tracking-[0.16em] text-[#755bc2]">
          <Sparkles className="size-3.5" aria-hidden="true" />
          Studio setup
        </div>
        <ol className="grid grid-cols-3 gap-2 lg:grid-cols-1" aria-label={`Step ${step + 1} of ${steps.length}`}>
          {steps.map((label, index) => {
            const complete = index < step;
            const current = index === step;
            return (
              <li
                key={label}
                aria-current={current ? "step" : undefined}
                className={`flex min-w-0 flex-col items-center gap-1 rounded-xl px-1.5 py-2 text-center text-[10px] font-medium sm:flex-row sm:gap-2.5 sm:px-2 sm:py-2.5 sm:text-left sm:text-xs lg:px-3 ${
                  current ? "bg-white text-[#433653] shadow-sm" : "text-[#817789]"
                }`}
              >
                <span
                  className={`grid size-6 shrink-0 place-items-center rounded-full text-[10px] font-bold ${
                    complete
                      ? "bg-[#dff1e8] text-[#337359]"
                      : current
                        ? "bg-[#7558cd] text-white"
                        : "border border-[#d1c8dd]"
                  }`}
                  aria-hidden="true"
                >
                  {complete ? <Check className="size-3" /> : index + 1}
                </span>
                <span className="leading-tight sm:truncate">{label}</span>
              </li>
            );
          })}
        </ol>
        <p className="mt-4 hidden text-xs leading-5 text-[#857b8d] lg:block">
          About two minutes. You can refine everything later in studio settings.
        </p>
      </nav>

      <div className="min-w-0">
        {error ? <div className="mb-5"><FormAlert>{error}</FormAlert></div> : null}

        {step === 0 ? (
          <form ref={profileFormRef} onSubmit={saveProfile} noValidate>
            <StepHeading
              ref={stepHeadingRef}
              title={currentStepTitle}
              description="We’ll shape the home view and defaults around the work you do most."
            />
            <div className="mt-6 max-w-sm">
              <AuthTextField
                label="First name"
                name="name"
                autoComplete="given-name"
                defaultValue={state.firstName}
                placeholder="Maya"
                error={fieldErrors.name}
              />
            </div>
            <fieldset
              className="mt-6"
              aria-describedby={fieldErrors.workspaceMode ? workspaceErrorId : undefined}
            >
              <legend className="text-sm font-semibold text-[#352e3d]">Preferred workspace view</legend>
              <div className="mt-3 grid gap-3 md:grid-cols-3">
                {workspaceOptions.map((option) => (
                  <ChoiceCard
                    key={option.value}
                    name="workspaceMode"
                    value={option.value}
                    defaultChecked={state.workspaceMode === option.value}
                    label={option.label}
                    description={option.description}
                    icon={option.icon}
                    ariaDescribedBy={
                      fieldErrors.workspaceMode ? workspaceErrorId : undefined
                    }
                  />
                ))}
              </div>
              {fieldErrors.workspaceMode ? (
                <p id={workspaceErrorId} className="mt-2 text-xs text-[#a54e46]">
                  {fieldErrors.workspaceMode}
                </p>
              ) : null}
            </fieldset>
            <StepActions nextLabel="Continue" />
          </form>
        ) : null}

        {step === 1 ? (
          <form ref={studioFormRef} onSubmit={saveStudio} noValidate>
            <StepHeading
              ref={stepHeadingRef}
              title={currentStepTitle}
              description={
                inviteToken
                  ? "For privacy, studio membership details appear only after the invite is securely validated."
                  : "Create a clear workspace name and address. Both can be changed later."
              }
            />
            {inviteToken ? (
              <div className="mt-6 flex gap-4 rounded-2xl border border-[#ded5f4] bg-[#f6f2ff] p-5">
                <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-white text-[#7155c2] shadow-sm">
                  <ShieldCheck className="size-5" aria-hidden="true" />
                </span>
                <div>
                  <h3 className="text-sm font-semibold text-[#463853]">Invitation ready for validation</h3>
                  <p className="mt-1 text-sm leading-6 text-[#766c7e]">
                    Maestro will connect the right workspace after you finish your preferences. No membership information is exposed on this page.
                  </p>
                </div>
              </div>
            ) : (
              <div className="mt-6 grid gap-5 sm:grid-cols-2">
                <AuthTextField
                  label="Studio name"
                  name="studioName"
                  autoComplete="organization"
                  defaultValue={state.studioName}
                  placeholder="Sonora House Music"
                  error={fieldErrors.studioName}
                />
                <AuthTextField
                  label="Studio address"
                  name="studioSlug"
                  defaultValue={state.studioSlug}
                  placeholder="sonora-house"
                  required={false}
                  hint="maestro.app/studio/your-address"
                  error={fieldErrors.studioSlug}
                />
              </div>
            )}
            <StepActions nextLabel={inviteToken ? "Continue securely" : "Create studio home"} onBack={() => setStep(0)} />
          </form>
        ) : null}

        {step === 2 ? (
          <form ref={prioritiesFormRef} onSubmit={complete} noValidate>
            <StepHeading
              ref={stepHeadingRef}
              title={currentStepTitle}
              description="We’ll tune your starting dashboard without hiding any studio capabilities."
            />
            <fieldset
              className="mt-6"
              aria-describedby={fieldErrors.primaryGoal ? goalErrorId : undefined}
            >
              <legend className="sr-only">Primary goal</legend>
              <div className="grid gap-3 sm:grid-cols-2">
                {goals.map((goal) => (
                  <ChoiceCard
                    key={goal.value}
                    name="primaryGoal"
                    value={goal.value}
                    defaultChecked={state.primaryGoal === goal.value}
                    label={goal.label}
                    description={goal.description}
                    icon={goal.icon}
                    ariaDescribedBy={
                      fieldErrors.primaryGoal ? goalErrorId : undefined
                    }
                  />
                ))}
              </div>
              {fieldErrors.primaryGoal ? (
                <p id={goalErrorId} className="mt-2 text-xs text-[#a54e46]">
                  {fieldErrors.primaryGoal}
                </p>
              ) : null}
            </fieldset>

            {!inviteToken ? (
              <><div className="mt-6 grid gap-4 sm:grid-cols-2">
              <label className="block text-sm font-semibold text-[#352e3d]">
                Time zone
                <select
                  name="timeZone"
                  defaultValue={state.timeZone}
                  aria-invalid={fieldErrors.timeZone ? "true" : undefined}
                  aria-describedby={fieldErrors.timeZone ? timeZoneErrorId : undefined}
                  className="mt-2 h-12 w-full rounded-xl border border-[#dcd7df] bg-[#fbfaf8] px-3.5 text-sm focus:border-[#8a70dc] focus:outline-none focus:ring-4 focus:ring-[#8768d8]/10"
                >
                  <option value="America/Bogota">Bogotá · UTC−5</option>
                  <option value="America/New_York">Eastern Time</option>
                  <option value="America/Chicago">Central Time</option>
                  <option value="America/Denver">Mountain Time</option>
                  <option value="America/Los_Angeles">Pacific Time</option>
                  <option value="Europe/London">London</option>
                </select>
                {fieldErrors.timeZone ? (
                  <span id={timeZoneErrorId} className="mt-1.5 block text-xs font-normal text-[#a54e46]">
                    {fieldErrors.timeZone}
                  </span>
                ) : null}
              </label>
              <label className="block text-sm font-semibold text-[#352e3d]">
                Billing currency
                <select
                  name="currency"
                  defaultValue={state.currency}
                  aria-invalid={fieldErrors.currency ? "true" : undefined}
                  aria-describedby={fieldErrors.currency ? currencyErrorId : undefined}
                  className="mt-2 h-12 w-full rounded-xl border border-[#dcd7df] bg-[#fbfaf8] px-3.5 text-sm focus:border-[#8a70dc] focus:outline-none focus:ring-4 focus:ring-[#8768d8]/10"
                >
                  <option value="USD">USD · US dollar</option>
                  <option value="CAD">CAD · Canadian dollar</option>
                  <option value="GBP">GBP · British pound</option>
                  <option value="EUR">EUR · Euro</option>
                  <option value="COP">COP · Colombian peso</option>
                </select>
                {fieldErrors.currency ? (
                  <span id={currencyErrorId} className="mt-1.5 block text-xs font-normal text-[#a54e46]">
                    {fieldErrors.currency}
                  </span>
                ) : null}
              </label>
            </div>

            <div className="mt-5 flex items-start gap-3 rounded-xl bg-[#f3f7f5] px-4 py-3 text-xs leading-5 text-[#587064]">
              <Banknote className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
              Billing starts in preview mode. Nothing is charged or sent until you explicitly publish it.
            </div></>
            ) : (
              <div className="mt-5 flex items-start gap-3 rounded-xl bg-[#f5f1ff] px-4 py-3 text-xs leading-5 text-[#655580]">
                <ShieldCheck className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                Your preferences personalize your view without changing the studio&apos;s billing or regional settings.
              </div>
            )}

            <div className="mt-7 flex flex-col-reverse gap-3 sm:flex-row sm:justify-between">
              <button
                type="button"
                onClick={() => setStep(1)}
                className="inline-flex h-11 items-center justify-center gap-2 rounded-xl px-4 text-sm font-semibold text-[#655a6d] hover:bg-[#f5f2f7]"
              >
                <ArrowLeft className="size-4" aria-hidden="true" />
                Back
              </button>
              <button
                type="submit"
                disabled={pending}
                className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-[#7457d2] px-5 text-sm font-semibold text-white shadow-[0_9px_24px_rgba(106,76,195,.22)] disabled:cursor-wait disabled:bg-[#a99bd4]"
              >
                {pending ? <LoaderCircle className="size-4 animate-spin" aria-hidden="true" /> : <Sparkles className="size-4" aria-hidden="true" />}
                {pending
                  ? inviteToken
                    ? "Joining securely…"
                    : "Preparing your studio…"
                  : inviteToken
                    ? "Join studio securely"
                    : "Open my studio"}
              </button>
            </div>
          </form>
        ) : null}
      </div>
    </div>
  );
}

function StepHeading({
  ref,
  title,
  description,
}: {
  ref?: React.Ref<HTMLHeadingElement>;
  title: string;
  description: string;
}) {
  return (
    <div>
      <h2
        ref={ref}
        tabIndex={-1}
        className="rounded text-2xl font-semibold tracking-[-0.035em] text-[#28202f] outline-none focus-visible:ring-4 focus-visible:ring-[#8768d8]/10"
      >
        {title}
      </h2>
      <p className="mt-2 max-w-2xl text-sm leading-6 text-[#756d7b]">{description}</p>
    </div>
  );
}

function StepActions({
  nextLabel,
  onBack,
}: {
  nextLabel: string;
  onBack?: () => void;
}) {
  return (
    <div className={`mt-7 flex flex-col-reverse gap-3 sm:flex-row ${onBack ? "sm:justify-between" : "sm:justify-end"}`}>
      {onBack ? (
        <button
          type="button"
          onClick={onBack}
          className="inline-flex h-11 items-center justify-center gap-2 rounded-xl px-4 text-sm font-semibold text-[#655a6d] hover:bg-[#f5f2f7]"
        >
          <ArrowLeft className="size-4" aria-hidden="true" />
          Back
        </button>
      ) : null}
      <button
        type="submit"
        className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-[#7457d2] px-5 text-sm font-semibold text-white shadow-[0_9px_24px_rgba(106,76,195,.22)]"
      >
        {nextLabel}
        <ArrowRight className="size-4" aria-hidden="true" />
      </button>
    </div>
  );
}

function ChoiceCard({
  name,
  value,
  defaultChecked,
  label,
  description,
  icon: Icon,
  ariaDescribedBy,
}: {
  name: string;
  value: string;
  defaultChecked: boolean;
  label: string;
  description: string;
  icon: typeof Building2;
  ariaDescribedBy?: string;
}) {
  return (
    <label className="group relative cursor-pointer rounded-2xl border border-[#ded9e2] bg-[#fbfaf8] p-4 transition hover:border-[#bcb0db] focus-within:border-[#8168ce] focus-within:ring-4 focus-within:ring-[#8768d8]/10 has-[:checked]:border-[#8168ce] has-[:checked]:bg-[#f7f3ff] has-[:checked]:shadow-[0_0_0_3px_rgba(118,87,210,.09)]">
      <input
        type="radio"
        name={name}
        value={value}
        defaultChecked={defaultChecked}
        aria-describedby={ariaDescribedBy}
        className="peer absolute right-3.5 top-3.5 size-4 accent-[#7457d2]"
      />
      <span className="mb-3 grid size-9 place-items-center rounded-xl bg-white text-[#765bc0] shadow-sm transition group-has-[:checked]:bg-[#7457d2] group-has-[:checked]:text-white">
        <Icon className="size-4" aria-hidden="true" />
      </span>
      <span className="block pr-5 text-sm font-semibold text-[#382f41]">{label}</span>
      <span className="mt-1 block text-xs leading-5 text-[#7d7582]">{description}</span>
    </label>
  );
}
