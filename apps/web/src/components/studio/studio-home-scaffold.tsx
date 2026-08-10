"use client";

import { Database, ShieldCheck } from "lucide-react";

import { useStudioSession } from "@/components/studio/studio-route-gate";

const pendingModules = ["Schedule", "Teaching", "Billing", "Studio activity"];

export function StudioHomeScaffold() {
  const { studio } = useStudioSession();

  return (
    <div className="mx-auto w-full max-w-[1180px] px-4 pb-16 pt-8 sm:px-6 lg:px-8">
      <div className="flex flex-wrap items-center gap-2 text-xs font-semibold text-[#5f4aa5]">
        <ShieldCheck className="size-4" aria-hidden="true" />
        Authenticated workspace
      </div>
      <h1 className="mt-4 text-3xl font-semibold tracking-[-0.05em] text-[#292230] sm:text-4xl">
        {studio.name} is connected.
      </h1>
      <p className="mt-3 max-w-2xl text-sm leading-6 text-[#716a76] sm:text-base">
        Your membership and tenant boundary are live. Dashboard records will
        appear only after their domain endpoints are connected.
      </p>

      <section
        aria-labelledby="empty-dashboard-heading"
        className="mt-8 rounded-[24px] border border-[#e3dee5] bg-white p-6 shadow-[0_2px_12px_rgba(46,37,58,0.035)] sm:p-8"
      >
        <span className="grid size-11 place-items-center rounded-2xl bg-[#f0ecf7] text-[#6550af]">
          <Database className="size-5" aria-hidden="true" />
        </span>
        <h2
          id="empty-dashboard-heading"
          className="mt-5 text-xl font-semibold tracking-[-0.035em] text-[#332c3a]"
        >
          No live dashboard data yet
        </h2>
        <p className="mt-2 max-w-2xl text-sm leading-6 text-[#7c7580]">
          Maestro is not estimating lessons, revenue, attendance, or student
          activity. These areas remain clearly empty until real tenant-scoped
          records are available.
        </p>
        <ul className="mt-6 grid gap-3 sm:grid-cols-2" aria-label="Modules awaiting live data">
          {pendingModules.map((module) => (
            <li
              key={module}
              className="flex items-center justify-between rounded-xl border border-[#e8e3e9] bg-[#fbfaf8] px-4 py-3 text-sm font-medium text-[#514958]"
            >
              {module}
              <span className="text-[10px] font-semibold uppercase tracking-[0.1em] text-[#958e99]">
                Awaiting live data
              </span>
            </li>
          ))}
        </ul>
      </section>
    </div>
  );
}
