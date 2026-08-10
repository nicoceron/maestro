import {
  ArrowRight,
  CalendarDays,
  Check,
  Clock3,
  CreditCard,
  Music2,
  Play,
  ShieldCheck,
  Sparkles,
  UsersRound,
  WalletCards,
  WandSparkles,
} from "lucide-react";

const agenda = [
  {
    time: "9:00",
    period: "AM",
    title: "Piano foundations",
    person: "Lena Ortiz",
    detail: "Studio 2 · 45 min",
    tone: "bg-[#7657d8]",
  },
  {
    time: "10:15",
    period: "AM",
    title: "Voice coaching",
    person: "Mateo Chen",
    detail: "Online · 60 min",
    tone: "bg-[#e9774f]",
  },
  {
    time: "1:30",
    period: "PM",
    title: "Junior strings",
    person: "Group · 6 students",
    detail: "Hall A · 50 min",
    tone: "bg-[#198b75]",
  },
];

const features = [
  {
    icon: CalendarDays,
    number: "01",
    title: "A schedule that thinks ahead",
    copy: "Build recurring programs, spot conflicts, rank open slots, and preview the billing impact before anything moves.",
    accent: "bg-[#dcd3ff] text-[#4d34a4]",
  },
  {
    icon: WalletCards,
    number: "02",
    title: "Money everyone can understand",
    copy: "Invoices, split payers, credits, packages, payroll, and refunds share one transparent ledger—not a maze of balances.",
    accent: "bg-[#ffe0ce] text-[#a94725]",
  },
  {
    icon: UsersRound,
    number: "03",
    title: "One story around every student",
    copy: "Lessons, practice, repertoire, messages, files, and family context stay together without leaking across studios.",
    accent: "bg-[#cdece2] text-[#126b59]",
  },
];

function MaestroMark() {
  return (
    <span className="flex size-9 items-center justify-center rounded-[12px] bg-[#201a36] text-white shadow-[0_8px_24px_rgba(32,26,54,0.18)]">
      <Music2 aria-hidden="true" className="size-[18px]" strokeWidth={2.2} />
    </span>
  );
}

function WorkspacePreview() {
  return (
    <section
      id="workspace"
      aria-label="Maestro workspace preview"
      className="relative mx-auto w-full max-w-[1180px] scroll-mt-24 px-4 sm:px-6 lg:px-8"
    >
      <div className="overflow-hidden rounded-[26px] border border-white/70 bg-[#fbfbf9] shadow-[0_42px_110px_rgba(40,31,74,0.20),0_4px_20px_rgba(40,31,74,0.08)]">
        <div className="flex h-10 items-center gap-2 border-b border-[#e8e5ee] bg-white px-4">
          <span className="size-2.5 rounded-full bg-[#ff876f]" />
          <span className="size-2.5 rounded-full bg-[#f5c75f]" />
          <span className="size-2.5 rounded-full bg-[#65c99c]" />
          <div className="mx-auto -translate-x-5 rounded-full bg-[#f4f2f7] px-12 py-1 text-[10px] font-medium text-[#827d8d] sm:px-20">
            app.maestro.studio
          </div>
        </div>

        <div className="grid min-h-[580px] grid-cols-1 md:grid-cols-[190px_1fr]">
          <aside className="hidden border-r border-[#e9e6ee] bg-[#f7f5f8] p-4 md:flex md:flex-col">
            <div className="mb-7 flex items-center gap-2 px-2">
              <span className="flex size-7 items-center justify-center rounded-lg bg-[#211b37] text-white">
                <Music2 aria-hidden="true" className="size-3.5" />
              </span>
              <span className="text-sm font-bold tracking-[-0.03em] text-[#211b37]">maestro</span>
            </div>
            <nav aria-label="Workspace preview" className="space-y-1 text-[12px] font-medium text-[#77717f]">
              {[
                [Sparkles, "Home", true],
                [CalendarDays, "Calendar", false],
                [UsersRound, "People", false],
                [CreditCard, "Billing", false],
                [Play, "Learning", false],
              ].map(([Icon, label, active]) => {
                const PreviewIcon = Icon as typeof Sparkles;
                return (
                  <div
                    key={label as string}
                    className={`flex items-center gap-2.5 rounded-lg px-2.5 py-2 ${active ? "bg-white text-[#30244e] shadow-sm" : ""}`}
                  >
                    <PreviewIcon aria-hidden="true" className="size-3.5" />
                    {label as string}
                  </div>
                );
              })}
            </nav>
            <div className="mt-auto rounded-xl border border-[#dfd8f1] bg-[#eee9fb] p-3">
              <div className="mb-2 flex size-7 items-center justify-center rounded-lg bg-white text-[#6549bf]">
                <WandSparkles aria-hidden="true" className="size-3.5" />
              </div>
              <p className="text-[11px] font-semibold text-[#3d315c]">Make room to teach</p>
              <p className="mt-1 text-[9px] leading-4 text-[#756b8c]">3 admin tasks automated this week.</p>
            </div>
          </aside>

          <div className="min-w-0 bg-[#fbfbf9] p-4 sm:p-6 lg:p-8">
            <div className="mb-7 flex items-start justify-between gap-4">
              <div>
                <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-[#8c8498]">Monday, August 10</p>
                <h2 className="mt-1 text-xl font-semibold tracking-[-0.04em] text-[#211b2d] sm:text-2xl">Today at a glance</h2>
              </div>
              <div className="flex items-center gap-2 rounded-full border border-[#e8e4eb] bg-white px-2 py-1.5 shadow-sm">
                <span className="flex size-7 items-center justify-center rounded-full bg-[#f3c5a9] text-[10px] font-bold text-[#713616]">MO</span>
                <span className="hidden pr-2 text-[11px] font-semibold text-[#4b4551] sm:block">Maya Ortiz</span>
              </div>
            </div>

            <div className="grid gap-3 sm:grid-cols-3">
              {[
                ["Lessons today", "8", "+2 open slots", "text-[#5b43b3]"],
                ["Studio revenue", "$4,820", "92% collected", "text-[#ce5c35]"],
                ["Practice pulse", "86%", "+12% this week", "text-[#177661]"],
              ].map(([label, value, note, color]) => (
                <article key={label} className="rounded-2xl border border-[#ebe8ed] bg-white p-4 shadow-[0_2px_10px_rgba(42,34,56,0.04)]">
                  <p className="text-[10px] font-medium text-[#8a8490]">{label}</p>
                  <p className={`mt-2 text-xl font-semibold tracking-[-0.04em] ${color}`}>{value}</p>
                  <p className="mt-1 text-[9px] text-[#96909b]">{note}</p>
                </article>
              ))}
            </div>

            <div className="mt-5 grid gap-4 lg:grid-cols-[1.45fr_0.8fr]">
              <article className="rounded-2xl border border-[#ebe8ed] bg-white p-4 sm:p-5">
                <div className="mb-3 flex items-center justify-between">
                  <div>
                    <h3 className="text-sm font-semibold text-[#302a37]">Your next lessons</h3>
                    <p className="mt-0.5 text-[9px] text-[#97909b]">Everything you need before they arrive.</p>
                  </div>
                  <span className="rounded-lg bg-[#f5f2fa] px-2 py-1 text-[9px] font-semibold text-[#665879]">View calendar</span>
                </div>
                <div className="divide-y divide-[#f0edf2]">
                  {agenda.map((item) => (
                    <div key={`${item.time}-${item.title}`} className="grid grid-cols-[42px_5px_1fr] items-center gap-3 py-3.5">
                      <div>
                        <p className="text-[11px] font-semibold text-[#413a47]">{item.time}</p>
                        <p className="text-[8px] font-medium text-[#aaa4af]">{item.period}</p>
                      </div>
                      <span className={`h-8 w-[3px] rounded-full ${item.tone}`} />
                      <div className="min-w-0">
                        <div className="flex items-center justify-between gap-3">
                          <p className="truncate text-[11px] font-semibold text-[#3a3440]">{item.title}</p>
                          <span className="hidden rounded-full bg-[#f7f5f8] px-2 py-1 text-[8px] text-[#7f7885] sm:block">Ready</span>
                        </div>
                        <p className="mt-1 truncate text-[9px] text-[#817a87]">{item.person} · {item.detail}</p>
                      </div>
                    </div>
                  ))}
                </div>
              </article>

              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-1">
                <article className="relative overflow-hidden rounded-2xl bg-[#251d3c] p-5 text-white">
                  <div className="absolute -right-8 -top-8 size-28 rounded-full border-[18px] border-white/[0.04]" />
                  <span className="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-2 py-1 text-[8px] font-semibold uppercase tracking-[0.12em] text-[#dcd2ff]">
                    <Sparkles aria-hidden="true" className="size-2.5" />
                    Studio insight
                  </span>
                  <p className="mt-4 text-sm font-medium leading-5 tracking-[-0.02em]">Thursday has room for two more trial lessons.</p>
                  <p className="mt-2 text-[9px] leading-4 text-[#b9b0cb]">Maestro found times that work for a teacher, room, and family.</p>
                  <div className="mt-4 flex items-center gap-1.5 text-[9px] font-semibold text-[#e1d9fa]">Review matches <ArrowRight aria-hidden="true" className="size-3" /></div>
                </article>

                <article className="rounded-2xl border border-[#d9eee7] bg-[#eef8f4] p-5">
                  <div className="flex items-center gap-2 text-[#176b59]">
                    <ShieldCheck aria-hidden="true" className="size-4" />
                    <span className="text-[10px] font-semibold">All caught up</span>
                  </div>
                  <p className="mt-3 text-[11px] font-medium leading-5 text-[#315d53]">Every attendance note is complete and family-ready.</p>
                </article>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}

export default function Home() {
  return (
    <div className="min-h-screen overflow-hidden bg-[#f4f2ee] text-[#211c2b]">
      <header className="relative z-20 mx-auto flex w-full max-w-[1240px] items-center justify-between px-5 py-5 sm:px-8 lg:px-10">
        <a href="#top" className="flex items-center gap-2.5 rounded-xl" aria-label="Maestro home">
          <MaestroMark />
          <span className="text-lg font-bold tracking-[-0.05em] text-[#211b37]">maestro</span>
        </a>
        <nav aria-label="Main" className="hidden items-center gap-7 text-sm font-medium text-[#655f6a] md:flex">
          <a className="transition-colors hover:text-[#2b2340]" href="#features">Why Maestro</a>
          <a className="transition-colors hover:text-[#2b2340]" href="#workspace">Workspace</a>
          <a className="transition-colors hover:text-[#2b2340]" href="#principles">Our approach</a>
        </nav>
        <a href="#workspace" className="group inline-flex items-center gap-2 rounded-full bg-[#241d39] px-4 py-2.5 text-xs font-semibold text-white shadow-[0_8px_24px_rgba(36,29,57,0.16)] transition-transform hover:-translate-y-0.5">
          Explore the workspace
          <ArrowRight aria-hidden="true" className="size-3.5 transition-transform group-hover:translate-x-0.5" />
        </a>
      </header>

      <main id="top">
        <section className="relative px-5 pb-20 pt-16 text-center sm:px-8 sm:pb-24 sm:pt-20 lg:pt-28">
          <div className="pointer-events-none absolute left-1/2 top-[-250px] size-[720px] -translate-x-1/2 rounded-full bg-[radial-gradient(circle,rgba(130,105,213,0.18)_0%,rgba(244,242,238,0)_68%)]" />
          <div className="relative mx-auto max-w-[850px]">
            <div className="mb-6 inline-flex items-center gap-2 rounded-full border border-[#dcd5ea] bg-white/70 px-3.5 py-2 text-[11px] font-semibold text-[#5c4c84] shadow-sm backdrop-blur">
              <Sparkles aria-hidden="true" className="size-3.5" />
              A calmer way to run a music studio
            </div>
            <h1 className="text-balance text-[clamp(3rem,8vw,6.6rem)] font-semibold leading-[0.9] tracking-[-0.075em] text-[#211b33]">
              Run your studio.
              <span className="block text-[#7256cf]">Teach with presence.</span>
            </h1>
            <p className="mx-auto mt-7 max-w-[650px] text-balance text-base leading-7 text-[#6e6873] sm:text-lg sm:leading-8">
              Scheduling, people, billing, and learning finally move together—so your team spends less time managing the work and more time making music.
            </p>
            <div className="mt-9 flex flex-col items-center justify-center gap-3 sm:flex-row">
              <a href="#workspace" className="group inline-flex min-h-12 items-center justify-center gap-2 rounded-full bg-[#7457d4] px-6 text-sm font-semibold text-white shadow-[0_14px_32px_rgba(116,87,212,0.28)] transition-transform hover:-translate-y-0.5">
                Explore the workspace
                <ArrowRight aria-hidden="true" className="size-4 transition-transform group-hover:translate-x-0.5" />
              </a>
              <a href="#features" className="inline-flex min-h-12 items-center justify-center rounded-full border border-[#d7d1da] bg-white/70 px-6 text-sm font-semibold text-[#3a3440] transition-colors hover:bg-white">
                See what makes it different
              </a>
            </div>
            <div className="mt-5 flex flex-wrap items-center justify-center gap-x-5 gap-y-2 text-[11px] text-[#827b85]">
              {["30-day full experience", "No card needed", "Human migration help"].map((item) => (
                <span key={item} className="flex items-center gap-1.5"><Check aria-hidden="true" className="size-3.5 text-[#28836f]" />{item}</span>
              ))}
            </div>
          </div>
        </section>

        <WorkspacePreview />

        <section id="features" className="mx-auto max-w-[1180px] scroll-mt-24 px-5 py-28 sm:px-8 sm:py-36">
          <div className="grid gap-10 lg:grid-cols-[0.72fr_1.28fr] lg:gap-20">
            <div>
              <p className="text-xs font-bold uppercase tracking-[0.18em] text-[#7357cd]">Designed around the lesson</p>
              <h2 className="mt-4 max-w-[420px] text-balance text-4xl font-semibold leading-[1.02] tracking-[-0.055em] text-[#251f31] sm:text-5xl">Every detail in tune. Nothing in the way.</h2>
              <p className="mt-6 max-w-[430px] text-sm leading-7 text-[#736c77]">Maestro replaces disconnected admin screens with one clear operating rhythm—from the first inquiry to the final recital.</p>
            </div>
            <div className="space-y-3">
              {features.map((feature) => {
                const Icon = feature.icon;
                return (
                  <article key={feature.number} className="group grid gap-5 rounded-[22px] border border-[#e2dde4] bg-white/65 p-5 transition-all hover:-translate-y-0.5 hover:bg-white hover:shadow-[0_18px_45px_rgba(47,39,58,0.08)] sm:grid-cols-[48px_1fr_auto] sm:items-center sm:p-6">
                    <span className={`flex size-12 items-center justify-center rounded-2xl ${feature.accent}`}><Icon aria-hidden="true" className="size-5" /></span>
                    <div>
                      <h3 className="text-lg font-semibold tracking-[-0.035em] text-[#312a38]">{feature.title}</h3>
                      <p className="mt-1.5 max-w-[580px] text-sm leading-6 text-[#777079]">{feature.copy}</p>
                    </div>
                    <span className="hidden self-start pt-1 font-mono text-[10px] text-[#aaa3ad] sm:block">{feature.number}</span>
                  </article>
                );
              })}
            </div>
          </div>
        </section>

        <section id="principles" className="bg-[#211b34] px-5 py-24 text-white sm:px-8 sm:py-28">
          <div className="mx-auto grid max-w-[1180px] gap-12 lg:grid-cols-[1fr_1.1fr] lg:items-center">
            <div>
              <span className="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-2 text-[10px] font-semibold uppercase tracking-[0.15em] text-[#cfc4ef]"><ShieldCheck aria-hidden="true" className="size-3.5" />Built for trust</span>
              <h2 className="mt-5 max-w-[500px] text-balance text-4xl font-semibold leading-[1.02] tracking-[-0.055em] sm:text-5xl">One identity. Many studios. Strict boundaries.</h2>
              <p className="mt-6 max-w-[540px] text-sm leading-7 text-[#aaa2b8]">Families and teachers switch studios without logging out. Each studio keeps its own roles, policies, data, and brand—with tenant isolation treated as a system property, not a filter.</p>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
              {[
                [Clock3, "Offline-ready days", "Attendance and notes keep moving when the Wi-Fi does not."],
                [UsersRound, "Role-aware by design", "Owner, teacher, parent, and learner each see the right next action."],
                [CreditCard, "Exact financial history", "Every charge, payment, credit, and refund remains explainable."],
                [Sparkles, "Accessible from day one", "Keyboard-first workflows and WCAG 2.2 AA are release gates."],
              ].map(([Icon, title, copy]) => {
                const PrincipleIcon = Icon as typeof Clock3;
                return (
                  <article key={title as string} className="rounded-2xl border border-white/10 bg-white/[0.055] p-5">
                    <PrincipleIcon aria-hidden="true" className="size-5 text-[#a995ec]" />
                    <h3 className="mt-4 text-sm font-semibold">{title as string}</h3>
                    <p className="mt-2 text-xs leading-5 text-[#9f97ac]">{copy as string}</p>
                  </article>
                );
              })}
            </div>
          </div>
        </section>
      </main>

      <footer className="flex flex-col items-center justify-between gap-4 bg-[#191526] px-5 py-7 text-[#8d8698] sm:flex-row sm:px-8 lg:px-12">
        <div className="flex items-center gap-2"><Music2 aria-hidden="true" className="size-4 text-[#aa97e8]" /><span className="text-sm font-semibold tracking-[-0.03em] text-white">maestro</span></div>
        <p className="text-[10px]">Made for the people who make music happen.</p>
      </footer>
    </div>
  );
}
