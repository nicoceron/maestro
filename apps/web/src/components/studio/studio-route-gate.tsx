"use client";

import Link from "next/link";
import { createContext, useContext, useEffect, useState } from "react";
import { LoaderCircle, RefreshCcw, ShieldAlert } from "lucide-react";

import { useAuthClient } from "@/components/auth/auth-client-provider";
import { StudioAppShell } from "@/components/studio/studio-app-shell";
import type {
  AuthFailure,
  CurrentUserDto,
  StudioDto,
} from "@/lib/auth/auth-client";
import { createStudioShell } from "@/lib/studio";

type StudioSession = {
  user: CurrentUserDto;
  studio: StudioDto;
  studios: StudioDto[];
};

const StudioSessionContext = createContext<StudioSession | null>(null);

export function useStudioSession() {
  const value = useContext(StudioSessionContext);
  if (!value) {
    throw new Error("useStudioSession must be used inside StudioRouteGate");
  }
  return value;
}

export function StudioRouteGate({
  requestedSlug,
  children,
}: {
  requestedSlug: string;
  children: React.ReactNode;
}) {
  const { client } = useAuthClient();
  const [attempt, setAttempt] = useState(0);
  const [status, setStatus] = useState<
    "checking" | "ready" | "authentication_required" | "unverified" | "forbidden" | "error"
  >("checking");
  const [failure, setFailure] = useState<AuthFailure>();
  const [session, setSession] = useState<StudioSession>();
  const [availableStudios, setAvailableStudios] = useState<StudioDto[]>([]);

  useEffect(() => {
    let active = true;

    void (async () => {
      const user = await client.getCurrentUser();
      if (!active) return;
      if (!user.ok) {
        setFailure(user.error);
        setStatus(
          user.error.code === "authentication_required"
            ? "authentication_required"
            : "error",
        );
        return;
      }
      if (!user.data.emailVerifiedAt) {
        setStatus("unverified");
        return;
      }

      const studios = await client.getStudios();
      if (!active) return;
      if (!studios.ok) {
        setFailure(studios.error);
        setStatus(
          studios.error.code === "authentication_required"
            ? "authentication_required"
            : "error",
        );
        return;
      }

      setAvailableStudios(studios.data);
      const studio = studios.data.find(({ slug }) => slug === requestedSlug);
      if (!studio) {
        setStatus("forbidden");
        return;
      }

      setSession({ user: user.data, studio, studios: studios.data });
      setStatus("ready");
    })();

    return () => {
      active = false;
    };
  }, [attempt, client, requestedSlug]);

  if (status === "checking") {
    return (
      <main className="grid min-h-screen place-items-center bg-[#f4f2ee] px-5">
        <div role="status" className="flex items-center gap-2 text-sm text-[#6f6876]">
          <LoaderCircle className="size-4 animate-spin" aria-hidden="true" />
          Confirming your studio access…
        </div>
      </main>
    );
  }

  if (status === "ready" && session) {
    const shell = createStudioShell(
      session.user,
      session.studios,
      session.studio,
    );
    return (
      <StudioSessionContext value={session}>
        <StudioAppShell shell={shell}>{children}</StudioAppShell>
      </StudioSessionContext>
    );
  }

  const primaryHref =
    status === "authentication_required"
      ? "/login"
      : status === "unverified"
        ? "/verify-email"
        : status === "forbidden" && availableStudios[0]
          ? `/studio/${availableStudios[0].slug}/home`
          : status === "forbidden"
            ? "/onboarding"
            : undefined;
  const primaryLabel =
    status === "authentication_required"
      ? "Sign in securely"
      : status === "unverified"
        ? "Verify your email"
        : status === "forbidden" && availableStudios[0]
          ? "Open an available studio"
          : status === "forbidden"
            ? "Set up a studio"
            : undefined;
  const message =
    status === "forbidden"
      ? "This workspace is not available to your account. No studio information has been revealed."
      : status === "unverified"
        ? "Verify your email before opening a studio workspace."
        : failure?.message ??
          "We could not confirm studio access. Check your connection and try again.";

  return (
    <main className="grid min-h-screen place-items-center bg-[#f4f2ee] px-5 py-12">
      <section className="w-full max-w-md rounded-[1.6rem] border border-black/[0.07] bg-white p-6 text-center shadow-[0_22px_70px_rgba(52,43,63,.10)] sm:p-8">
        <span className="mx-auto grid size-12 place-items-center rounded-2xl bg-[#f1ecfb] text-[#684db7]">
          <ShieldAlert className="size-5" aria-hidden="true" />
        </span>
        <h1 className="mt-5 text-2xl font-semibold tracking-[-0.04em] text-[#292230]">
          Studio access check
        </h1>
        <p role="alert" className="mt-3 text-sm leading-6 text-[#716a76]">
          {message}
        </p>
        <div className="mt-6 space-y-3">
          {primaryHref && primaryLabel ? (
            <Link
              href={primaryHref}
              className="inline-flex h-11 w-full items-center justify-center rounded-xl bg-[#7457d2] px-4 text-sm font-semibold text-white"
            >
              {primaryLabel}
            </Link>
          ) : null}
          {status === "error" ? (
            <button
              type="button"
              onClick={() => {
                setStatus("checking");
                setFailure(undefined);
                setSession(undefined);
                setAttempt((current) => current + 1);
              }}
              className="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl border border-[#d8d2dc] text-sm font-semibold text-[#55475f] hover:bg-[#faf8fc]"
            >
              <RefreshCcw className="size-3.5" aria-hidden="true" />
              Check access again
            </button>
          ) : null}
        </div>
      </section>
    </main>
  );
}
