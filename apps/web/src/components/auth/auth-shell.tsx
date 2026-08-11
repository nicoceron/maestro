import Link from "next/link";
import {
  ArrowLeft,
  CalendarDays,
  Check,
  LockKeyhole,
  MessageCircleMore,
  Music2,
  ReceiptText,
  ShieldCheck,
  Sparkles,
} from "lucide-react";

import { AuthClientProvider } from "@/components/auth/auth-client-provider";

const rhythmItems = [
  { icon: CalendarDays, label: "A calmer teaching day" },
  { icon: ReceiptText, label: "Billing that follows through" },
  { icon: MessageCircleMore, label: "Families kept in the loop" },
];

export function AuthShell({ children }: { children: React.ReactNode }) {
  return (
    <div className="relative min-h-screen overflow-hidden bg-[#f4f2ee] text-[#211c2b]">
      <a
        href="#auth-content"
        className="sr-only z-50 rounded-lg bg-white px-4 py-3 text-sm font-semibold focus:not-sr-only focus:fixed focus:left-4 focus:top-4"
      >
        Skip to account form
      </a>

      <div
        className="pointer-events-none absolute inset-0 opacity-55"
        aria-hidden="true"
        style={{
          backgroundImage:
            "radial-gradient(circle at 78% 12%, rgba(137,111,221,.20), transparent 28%), radial-gradient(circle at 64% 86%, rgba(214,135,82,.12), transparent 24%)",
        }}
      />

      <div className="relative grid min-h-screen lg:grid-cols-[minmax(360px,0.82fr)_minmax(560px,1.18fr)]">
        <aside className="relative hidden overflow-hidden bg-[#211a34] p-9 text-white lg:flex lg:min-h-screen lg:flex-col xl:p-12">
          <div
            className="pointer-events-none absolute inset-0 opacity-70"
            aria-hidden="true"
            style={{
              backgroundImage:
                "radial-gradient(circle at 18% 10%, rgba(148,119,236,.34), transparent 31%), radial-gradient(circle at 88% 76%, rgba(231,144,88,.20), transparent 31%)",
            }}
          />

          <Link
            href="/"
            className="relative inline-flex w-fit items-center gap-3 rounded-xl text-lg font-semibold tracking-[-0.03em]"
            aria-label="Maestro marketing home"
          >
            <span className="grid size-10 place-items-center rounded-xl bg-white text-[#2d2442] shadow-[0_10px_35px_rgba(0,0,0,.18)]">
              <Music2 className="size-5" aria-hidden="true" />
            </span>
            maestro
          </Link>

          <div className="relative my-auto max-w-md py-16">
            <div className="mb-7 inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.07] px-3 py-1.5 text-xs font-medium text-[#dcd2ff]">
              <Sparkles className="size-3.5" aria-hidden="true" />
              Your studio, in rhythm
            </div>
            <h2 className="max-w-sm text-4xl font-semibold leading-[1.04] tracking-[-0.055em] xl:text-5xl">
              Start the day knowing what needs you.
            </h2>
            <p className="mt-5 max-w-sm text-base leading-7 text-white/58">
              One thoughtful workspace for lessons, people, payments, and the
              moments between them.
            </p>

            <ul className="mt-10 space-y-3" aria-label="Maestro benefits">
              {rhythmItems.map(({ icon: Icon, label }) => (
                <li
                  key={label}
                  className="flex items-center gap-3 rounded-2xl border border-white/[0.08] bg-white/[0.055] px-4 py-3.5 text-sm text-white/82 backdrop-blur"
                >
                  <span className="grid size-9 place-items-center rounded-xl bg-[#8b6ee0]/18 text-[#dcd2ff]">
                    <Icon className="size-4" aria-hidden="true" />
                  </span>
                  {label}
                  <Check className="ml-auto size-4 text-[#94d6b8]" aria-hidden="true" />
                </li>
              ))}
            </ul>
          </div>

          <div className="relative flex items-center gap-3 border-t border-white/10 pt-6 text-xs text-white/47">
            <ShieldCheck className="size-4 text-[#a696df]" aria-hidden="true" />
            Cookie-backed sessions · CSRF protected · No browser token storage
          </div>
        </aside>

        <div className="flex min-h-screen flex-col">
          <header className="flex items-center justify-between px-5 py-5 sm:px-8 lg:px-10">
            <Link
              href="/"
              className="inline-flex items-center gap-2 rounded-xl text-sm font-semibold text-[#4c4556] transition-colors hover:text-[#211c2b] lg:hidden"
              aria-label="Maestro marketing home"
            >
              <span className="grid size-9 place-items-center rounded-xl bg-[#211a34] text-white">
                <Music2 className="size-4" aria-hidden="true" />
              </span>
              maestro
            </Link>
            <Link
              href="/"
              className="ml-auto inline-flex items-center gap-2 rounded-xl px-2 py-2 text-sm font-medium text-[#6f6876] transition-colors hover:text-[#332b3c]"
            >
              <ArrowLeft className="size-4" aria-hidden="true" />
              Back to website
            </Link>
          </header>

          <main
            id="auth-content"
            className="flex flex-1 items-center justify-center px-5 pb-12 pt-3 sm:px-8 lg:px-12 lg:pb-16"
          >
            <AuthClientProvider>{children}</AuthClientProvider>
          </main>

          <footer className="flex flex-wrap items-center justify-center gap-x-5 gap-y-2 px-6 pb-7 text-center text-xs text-[#827b87]">
            <span className="inline-flex items-center gap-1.5">
              <LockKeyhole className="size-3.5" aria-hidden="true" />
              Secure identity preview
            </span>
            <Link href="/privacy" className="rounded hover:text-[#51465d]">
              Privacy
            </Link>
            <Link href="/help" className="rounded hover:text-[#51465d]">
              Need help?
            </Link>
          </footer>
        </div>
      </div>
    </div>
  );
}

export function AuthCard({
  eyebrow,
  title,
  description,
  children,
  footer,
  wide = false,
}: {
  eyebrow?: string;
  title: string;
  description: string;
  children: React.ReactNode;
  footer?: React.ReactNode;
  wide?: boolean;
}) {
  return (
    <section className={`w-full ${wide ? "max-w-4xl" : "max-w-md"}`}>
      <div className="mb-7 text-center sm:text-left">
        {eyebrow ? (
          <p className="mb-3 text-[11px] font-bold uppercase tracking-[0.2em] text-[#7357c6]">
            {eyebrow}
          </p>
        ) : null}
        <h1 className="text-3xl font-semibold tracking-[-0.045em] text-[#211c2b] sm:text-[2.55rem] sm:leading-[1.05]">
          {title}
        </h1>
        <p className="mt-3 max-w-xl text-sm leading-6 text-[#716a76] sm:text-base">
          {description}
        </p>
      </div>

      <div className="rounded-[1.6rem] border border-black/[0.07] bg-white p-5 shadow-[0_22px_70px_rgba(52,43,63,.10)] sm:p-7">
        {children}
      </div>

      {footer ? (
        <div className="mt-5 text-center text-sm text-[#746d79]">{footer}</div>
      ) : null}
    </section>
  );
}
