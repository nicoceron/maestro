import {
  ArrowRight,
  Banknote,
  BookOpenCheck,
  CalendarPlus,
  Check,
  CircleDollarSign,
  Clock3,
  CreditCard,
  Database,
  FileText,
  Guitar,
  Headphones,
  MessageCircleMore,
  Music2,
  Piano,
  Plus,
  ReceiptText,
  Sparkles,
  TrendingUp,
  UserPlus,
  UsersRound,
  type LucideIcon,
} from "lucide-react";
import Link from "next/link";

import type {
  StudioHomeActivityDto,
  StudioHomeDto,
  StudioHomeLessonDto,
  StudioHomeMetricDto,
  StudioHomeTaskDto,
  StudioHomeTeacherDto,
} from "@/lib/studio-fixtures";

const metricIcons: Record<StudioHomeMetricDto["id"], LucideIcon> = {
  lessons: Music2,
  revenue: CircleDollarSign,
  attendance: BookOpenCheck,
  practice: TrendingUp,
};

const instrumentIcons: Record<StudioHomeLessonDto["instrument"], LucideIcon> = {
  piano: Piano,
  voice: Headphones,
  strings: Music2,
  guitar: Guitar,
};

const metricStyles: Record<StudioHomeMetricDto["id"], string> = {
  lessons: "bg-[#eee9fc] text-[#5d45af]",
  revenue: "bg-[#ffe8da] text-[#a64a29]",
  attendance: "bg-[#e6f4ee] text-[#166b59]",
  practice: "bg-[#e7eefb] text-[#3a5f99]",
};

const lessonStyles: Record<StudioHomeLessonDto["status"], string> = {
  ready: "bg-[#e4f4ed] text-[#176756]",
  "in-progress": "bg-[#eee9fc] text-[#5d44ad]",
  "needs-notes": "bg-[#ffeadf] text-[#a64a29]",
  upcoming: "bg-[#eef0f3] text-[#625c68]",
};

function MetricCard({ metric }: { metric: StudioHomeMetricDto }) {
  const Icon = metricIcons[metric.id];
  const changeTone =
    metric.direction === "attention"
      ? "text-[#a74a29]"
      : metric.direction === "positive"
        ? "text-[#26715f]"
        : "text-[#79727d]";

  return (
    <article className="group rounded-[22px] border border-[#e6e1e7] bg-white p-4 shadow-[0_2px_12px_rgba(46,37,58,0.035)] transition-transform hover:-translate-y-0.5 sm:p-5">
      <div className="flex items-start justify-between gap-4">
        <div>
          <p className="text-[11px] font-medium text-[#85808a]">{metric.label}</p>
          <p className="mt-2 text-[26px] font-semibold tracking-[-0.055em] text-[#2b2532] sm:text-[29px]">
            {metric.value}
          </p>
        </div>
        <span
          className={`flex size-10 items-center justify-center rounded-[14px] ${metricStyles[metric.id]}`}
        >
          <Icon aria-hidden="true" className="size-[17px]" strokeWidth={2.15} />
        </span>
      </div>
      <p className={`mt-3 text-[10px] font-medium ${changeTone}`}>{metric.change}</p>
    </article>
  );
}

function LessonRow({
  lesson,
  studioSlug,
  isLast,
}: {
  lesson: StudioHomeLessonDto;
  studioSlug: string;
  isLast: boolean;
}) {
  const Icon = instrumentIcons[lesson.instrument];

  return (
    <li className="grid grid-cols-[62px_22px_minmax(0,1fr)] gap-2 sm:grid-cols-[78px_28px_minmax(0,1fr)] sm:gap-3">
      <div className="pt-1 text-right">
        <p className="text-[11px] font-semibold text-[#403a45]">{lesson.time}</p>
        <p className="mt-1 text-[9px] text-[#aaa4ae]">{lesson.endTime}</p>
      </div>

      <div className="relative flex justify-center">
        <span className="relative z-10 mt-1 flex size-6 items-center justify-center rounded-full border-[5px] border-white bg-[#7055cc] shadow-[0_0_0_1px_#dcd4e5]">
          <span className="size-1.5 rounded-full bg-white" />
        </span>
        {!isLast && <span className="absolute bottom-[-4px] top-7 w-px bg-[#ded9e2]" />}
      </div>

      <article className="mb-3 min-w-0 rounded-[18px] border border-[#e8e3e9] bg-[#fcfbfa] p-3.5 transition-colors hover:border-[#d8cede] hover:bg-white sm:mb-4 sm:p-4">
        <div className="flex items-start gap-3">
          <span className="flex size-9 shrink-0 items-center justify-center rounded-[13px] bg-[#f0ecf6] text-[#5e4e72]">
            <Icon aria-hidden="true" className="size-4" strokeWidth={1.9} />
          </span>
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-2">
              <div className="min-w-0">
                <h3 className="truncate text-[13px] font-semibold tracking-[-0.02em] text-[#36303d]">
                  {lesson.title}
                </h3>
                <p className="mt-1 truncate text-[10px] text-[#7e7782]">{lesson.student}</p>
              </div>
              <span
                className={`rounded-full px-2.5 py-1 text-[9px] font-semibold ${lessonStyles[lesson.status]}`}
              >
                {lesson.statusLabel}
              </span>
            </div>
            <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-[9px] text-[#89828d]">
              <span className="inline-flex items-center gap-1.5">
                <UsersRound aria-hidden="true" className="size-3" />
                {lesson.teacher}
              </span>
              <span className="inline-flex items-center gap-1.5">
                <Clock3 aria-hidden="true" className="size-3" />
                {lesson.location}
              </span>
              <Link
                href={`/studio/${studioSlug}/calendar?lesson=${lesson.id}`}
                className="ml-auto inline-flex items-center gap-1 rounded-md font-semibold text-[#6750b7] hover:text-[#4f399e]"
                aria-label={`Open ${lesson.title} lesson`}
              >
                Open
                <ArrowRight aria-hidden="true" className="size-3" />
              </Link>
            </div>
          </div>
        </div>
      </article>
    </li>
  );
}

function TaskCard({ task }: { task: StudioHomeTaskDto }) {
  const toneStyles: Record<StudioHomeTaskDto["tone"], string> = {
    urgent: "bg-[#fff0e8] text-[#a64928]",
    upcoming: "bg-[#eee9fc] text-[#5a43a9]",
    finance: "bg-[#e6f4ee] text-[#176756]",
  };
  const icons: Record<StudioHomeTaskDto["tone"], LucideIcon> = {
    urgent: FileText,
    upcoming: CalendarPlus,
    finance: CreditCard,
  };
  const Icon = icons[task.tone];

  return (
    <article className="rounded-[18px] border border-[#e7e2e8] bg-[#fcfbfa] p-4 transition-colors hover:border-[#d7cddd] hover:bg-white">
      <div className="flex items-start gap-3">
        <span
          className={`flex size-9 shrink-0 items-center justify-center rounded-[13px] ${toneStyles[task.tone]}`}
        >
          <Icon aria-hidden="true" className="size-4" />
        </span>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-start justify-between gap-2">
            <h3 className="text-[12px] font-semibold tracking-[-0.02em] text-[#39333f]">
              {task.title}
            </h3>
            <span className="rounded-full bg-[#f0edf1] px-2 py-1 text-[8px] font-semibold text-[#736d76]">
              {task.due}
            </span>
          </div>
          <p className="mt-2 text-[10px] leading-[1.55] text-[#827b85]">{task.detail}</p>
          <Link
            href={task.href}
            className="mt-3 inline-flex min-h-8 items-center gap-1.5 rounded-lg text-[10px] font-semibold text-[#6550af] hover:text-[#4c3897]"
          >
            {task.actionLabel}
            <ArrowRight aria-hidden="true" className="size-3" />
          </Link>
        </div>
      </div>
    </article>
  );
}

function RevenueCard({ revenue }: Pick<StudioHomeDto, "revenue">) {
  return (
    <section
      aria-labelledby="revenue-heading"
      className="rounded-[24px] border border-[#e4dfe6] bg-white p-5 shadow-[0_2px_12px_rgba(46,37,58,0.035)] sm:p-6"
    >
      <div className="flex items-start justify-between gap-5">
        <div>
          <p className="text-[9px] font-bold uppercase tracking-[0.16em] text-[#8f8795]">
            August invoices
          </p>
          <h2
            id="revenue-heading"
            className="mt-1.5 text-[18px] font-semibold tracking-[-0.04em] text-[#312a38]"
          >
            Revenue flow
          </h2>
        </div>
        <span className="flex size-10 items-center justify-center rounded-[14px] bg-[#e7f4ef] text-[#176957]">
          <Banknote aria-hidden="true" className="size-[18px]" />
        </span>
      </div>

      <div className="mt-6 grid grid-cols-2 gap-3">
        <div>
          <p className="text-[9px] text-[#8d8690]">Collected</p>
          <p className="mt-1 text-[22px] font-semibold tracking-[-0.05em] text-[#302a36]">
            {revenue.collected}
          </p>
        </div>
        <div className="border-l border-[#ebe7ec] pl-4">
          <p className="text-[9px] text-[#8d8690]">Still open</p>
          <p className="mt-1 text-[22px] font-semibold tracking-[-0.05em] text-[#a64b2b]">
            {revenue.outstanding}
          </p>
        </div>
      </div>

      <div className="mt-5">
        <div className="mb-2 flex items-center justify-between text-[9px] font-medium">
          <span className="text-[#817a85]">Collection progress</span>
          <span className="text-[#246e5d]">{revenue.percent}%</span>
        </div>
        <div
          role="progressbar"
          aria-label="August invoice collection progress"
          aria-valuemin={0}
          aria-valuemax={100}
          aria-valuenow={revenue.percent}
          className="h-2 overflow-hidden rounded-full bg-[#eeebef]"
        >
          <div
            className="h-full rounded-full bg-[#299078]"
            style={{ width: `${revenue.percent}%` }}
          />
        </div>
      </div>

      <figure className="mt-6" aria-labelledby="revenue-chart-caption">
        <div className="flex h-[118px] items-end gap-2" aria-hidden="true">
          {revenue.days.map((day) => (
            <div key={day.label} className="flex h-full min-w-0 flex-1 flex-col justify-end gap-2">
              <div
                className="min-h-2 w-full rounded-t-md bg-[#d6caef] transition-colors hover:bg-[#7659cf]"
                style={{ height: `${day.value}%` }}
                title={`${day.label}: ${day.amount}`}
              />
              <span className="text-center text-[8px] font-medium text-[#99929d]">{day.label}</span>
            </div>
          ))}
        </div>
        <figcaption id="revenue-chart-caption" className="sr-only">
          Daily collected revenue: {revenue.days.map((day) => `${day.label} ${day.amount}`).join(", ")}.
        </figcaption>
      </figure>
    </section>
  );
}

function TeacherRow({ teacher }: { teacher: StudioHomeTeacherDto }) {
  const avatarStyles: Record<StudioHomeTeacherDto["tone"], string> = {
    violet: "bg-[#ded4ff] text-[#5137a3]",
    peach: "bg-[#f5ceb8] text-[#773a1c]",
    teal: "bg-[#cdece3] text-[#155f52]",
  };

  return (
    <li className="py-3.5 first:pt-0 last:pb-0">
      <div className="flex items-center gap-3">
        <span
          className={`flex size-9 shrink-0 items-center justify-center rounded-[13px] text-[9px] font-bold ${avatarStyles[teacher.tone]}`}
        >
          {teacher.initials}
        </span>
        <div className="min-w-0 flex-1">
          <div className="flex items-baseline justify-between gap-3">
            <div className="min-w-0">
              <p className="truncate text-[11px] font-semibold text-[#3a3440]">{teacher.name}</p>
              <p className="mt-0.5 text-[8px] text-[#938d97]">
                {teacher.instrument} · {teacher.lessonCount} lessons
              </p>
            </div>
            <span className="shrink-0 text-[8px] font-semibold text-[#77717c]">
              {teacher.capacityLabel}
            </span>
          </div>
          <div
            role="progressbar"
            aria-label={`${teacher.name} weekly capacity`}
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={teacher.capacityPercent}
            className="mt-2 h-1.5 overflow-hidden rounded-full bg-[#eeebef]"
          >
            <div
              className="h-full rounded-full bg-[#7560ba]"
              style={{ width: `${teacher.capacityPercent}%` }}
            />
          </div>
        </div>
      </div>
    </li>
  );
}

function ActivityRow({ activity }: { activity: StudioHomeActivityDto }) {
  const dotStyles: Record<StudioHomeActivityDto["tone"], string> = {
    violet: "bg-[#785ed0]",
    peach: "bg-[#e17b52]",
    teal: "bg-[#2a9179]",
    slate: "bg-[#7b7480]",
  };

  return (
    <li className="relative pl-5">
      <span
        aria-hidden="true"
        className={`absolute left-0 top-[5px] size-2 rounded-full ring-4 ring-[#f4f1f5] ${dotStyles[activity.tone]}`}
      />
      <p className="text-[10px] leading-[1.55] text-[#716a75]">
        <strong className="font-semibold text-[#3b3541]">{activity.actor}</strong> {activity.action}{" "}
        <span className="font-medium text-[#51465e]">{activity.subject}</span>
      </p>
      <p className="mt-1 text-[8px] text-[#a09aa4]">{activity.time}</p>
    </li>
  );
}

export function StudioHomeDashboard({ data }: { data: StudioHomeDto }) {
  const quickActions = [
    {
      label: "Add lesson",
      href: `/studio/${data.studioSlug}/calendar?new=lesson`,
      icon: CalendarPlus,
    },
    {
      label: "Add student",
      href: `/studio/${data.studioSlug}/people?new=student`,
      icon: UserPlus,
    },
    {
      label: "Record payment",
      href: `/studio/${data.studioSlug}/billing?new=payment`,
      icon: ReceiptText,
    },
  ];

  return (
    <div className="mx-auto w-full max-w-[1480px] px-4 pb-16 pt-6 sm:px-6 sm:pt-8 lg:px-8 lg:pb-20 lg:pt-9 xl:px-10">
      <div className="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
        <div className="max-w-[720px]">
          <div className="flex flex-wrap items-center gap-2">
            <p className="text-[10px] font-bold uppercase tracking-[0.17em] text-[#7b64bc]">
              {data.todayLabel}
            </p>
            <span className="h-3 w-px bg-[#d6d0d9]" />
            <span className="inline-flex items-center gap-1.5 rounded-full bg-[#ece8f2] px-2.5 py-1 text-[8px] font-bold uppercase tracking-[0.12em] text-[#6d627a]">
              <Database aria-hidden="true" className="size-2.5" />
              Fixture workspace
            </span>
          </div>
          <h1 className="mt-3 text-[clamp(2.2rem,5vw,4rem)] font-semibold leading-[0.98] tracking-[-0.065em] text-[#292232]">
            {data.greeting}
          </h1>
          <p className="mt-4 max-w-[660px] text-[13px] leading-6 text-[#716a75] sm:text-sm">
            {data.summary}
          </p>
        </div>

        <nav aria-label="Quick actions" className="flex flex-wrap gap-2">
          {quickActions.map(({ label, href, icon: Icon }, index) => (
            <Link
              key={label}
              href={href}
              className={`inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-3.5 text-[11px] font-semibold shadow-sm transition-transform hover:-translate-y-0.5 sm:px-4 ${
                index === 0
                  ? "bg-[#7055cc] text-white shadow-[0_10px_25px_rgba(112,85,204,0.22)]"
                  : "border border-[#ded9e1] bg-white text-[#524b58]"
              }`}
            >
              {index === 0 ? (
                <Plus aria-hidden="true" className="size-3.5" />
              ) : (
                <Icon aria-hidden="true" className="size-3.5" />
              )}
              {label}
            </Link>
          ))}
        </nav>
      </div>

      <aside className="mt-7 flex flex-col gap-4 overflow-hidden rounded-[20px] border border-[#dfd5f1] bg-[#eee9fa] p-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
        <div className="flex items-start gap-3">
          <span className="flex size-9 shrink-0 items-center justify-center rounded-[13px] bg-white text-[#6750b5] shadow-sm">
            <Sparkles aria-hidden="true" className="size-4" />
          </span>
          <div>
            <p className="text-[11px] font-semibold text-[#493c66]">A clear finish to the day</p>
            <p className="mt-1 text-[10px] leading-5 text-[#776c88]">
              Review two lesson notes now and every family update will be ready before the final lesson.
            </p>
          </div>
        </div>
        <Link
          href={`/studio/${data.studioSlug}/calendar?filter=needs-notes`}
          className="inline-flex min-h-9 shrink-0 items-center justify-center gap-1.5 self-start rounded-xl bg-white px-3.5 text-[9px] font-semibold text-[#5d47a7] shadow-sm ring-1 ring-[#ddd3ec] hover:bg-[#faf8fe] sm:self-auto"
        >
          Review notes
          <ArrowRight aria-hidden="true" className="size-3" />
        </Link>
      </aside>

      <section aria-label="Studio pulse" className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {data.metrics.map((metric) => (
          <MetricCard key={metric.id} metric={metric} />
        ))}
      </section>

      <div className="mt-5 grid items-start gap-5 xl:grid-cols-[minmax(0,1.45fr)_minmax(330px,0.8fr)]">
        <section
          aria-labelledby="schedule-heading"
          className="rounded-[24px] border border-[#e4dfe6] bg-white p-4 shadow-[0_2px_12px_rgba(46,37,58,0.035)] sm:p-6"
        >
          <div className="flex items-start justify-between gap-5">
            <div>
              <p className="text-[9px] font-bold uppercase tracking-[0.16em] text-[#8f8795]">
                Your day
              </p>
              <h2
                id="schedule-heading"
                className="mt-1.5 text-[20px] font-semibold tracking-[-0.045em] text-[#312a38]"
              >
                Next lessons
              </h2>
              <p className="mt-1 text-[10px] text-[#8d8690]">Everything you need before they arrive.</p>
            </div>
            <Link
              href={`/studio/${data.studioSlug}/calendar`}
              className="inline-flex min-h-9 shrink-0 items-center gap-1.5 rounded-xl border border-[#e1dce4] bg-[#fbfafb] px-3 text-[9px] font-semibold text-[#655d6d] hover:bg-white"
            >
              Full calendar
              <ArrowRight aria-hidden="true" className="size-3" />
            </Link>
          </div>

          <ol className="mt-6">
            {data.lessons.map((lesson, index) => (
              <LessonRow
                key={lesson.id}
                lesson={lesson}
                studioSlug={data.studioSlug}
                isLast={index === data.lessons.length - 1}
              />
            ))}
          </ol>

          <div className="mt-1 flex items-center justify-between rounded-[16px] bg-[#f4f1f6] px-4 py-3">
            <span className="inline-flex items-center gap-2 text-[9px] font-medium text-[#766f7b]">
              <Check aria-hidden="true" className="size-3.5 text-[#26806b]" />
              Travel and room buffers look good
            </span>
            <span className="text-[8px] font-semibold text-[#98919c]">2 open slots</span>
          </div>
        </section>

        <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-1">
          <section
            aria-labelledby="attention-heading"
            className="rounded-[24px] border border-[#e4dfe6] bg-white p-5 shadow-[0_2px_12px_rgba(46,37,58,0.035)] sm:p-6"
          >
            <div className="flex items-start justify-between gap-4">
              <div>
                <p className="text-[9px] font-bold uppercase tracking-[0.16em] text-[#8f8795]">
                  Stay ahead
                </p>
                <h2
                  id="attention-heading"
                  className="mt-1.5 text-[18px] font-semibold tracking-[-0.04em] text-[#312a38]"
                >
                  Attention needed
                </h2>
              </div>
              <span className="flex size-7 items-center justify-center rounded-full bg-[#f7e4da] text-[10px] font-bold text-[#9b4526]">
                {data.tasks.length}
              </span>
            </div>
            <div className="mt-5 space-y-2.5">
              {data.tasks.map((task) => (
                <TaskCard key={task.id} task={task} />
              ))}
            </div>
          </section>

          <RevenueCard revenue={data.revenue} />
        </div>
      </div>

      <div className="mt-5 grid gap-5 lg:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]">
        <section
          aria-labelledby="team-heading"
          className="rounded-[24px] border border-[#e4dfe6] bg-white p-5 shadow-[0_2px_12px_rgba(46,37,58,0.035)] sm:p-6"
        >
          <div className="flex items-start justify-between gap-4">
            <div>
              <p className="text-[9px] font-bold uppercase tracking-[0.16em] text-[#8f8795]">
                This week
              </p>
              <h2
                id="team-heading"
                className="mt-1.5 text-[18px] font-semibold tracking-[-0.04em] text-[#312a38]"
              >
                Teaching team
              </h2>
            </div>
            <Link
              href={`/studio/${data.studioSlug}/people?view=teachers`}
              className="inline-flex min-h-8 items-center gap-1 rounded-lg text-[9px] font-semibold text-[#6852b0] hover:text-[#4d3994]"
            >
              View team
              <ArrowRight aria-hidden="true" className="size-3" />
            </Link>
          </div>
          <ul className="mt-5 divide-y divide-[#eeeaf0]">
            {data.teachers.map((teacher) => (
              <TeacherRow key={teacher.id} teacher={teacher} />
            ))}
          </ul>
        </section>

        <section
          aria-labelledby="activity-heading"
          className="rounded-[24px] border border-[#e4dfe6] bg-white p-5 shadow-[0_2px_12px_rgba(46,37,58,0.035)] sm:p-6"
        >
          <div className="flex items-start justify-between gap-4">
            <div>
              <p className="text-[9px] font-bold uppercase tracking-[0.16em] text-[#8f8795]">
                Live from the studio
              </p>
              <h2
                id="activity-heading"
                className="mt-1.5 text-[18px] font-semibold tracking-[-0.04em] text-[#312a38]"
              >
                Recent activity
              </h2>
            </div>
            <span className="flex size-9 items-center justify-center rounded-[13px] bg-[#f0ecf6] text-[#675779]">
              <MessageCircleMore aria-hidden="true" className="size-4" />
            </span>
          </div>
          <ol className="mt-6 grid gap-5 sm:grid-cols-2">
            {data.activity.map((activity) => (
              <ActivityRow key={activity.id} activity={activity} />
            ))}
          </ol>
        </section>
      </div>

      <footer className="mt-8 flex flex-col gap-2 border-t border-[#ddd8df] pt-5 text-[8px] font-medium text-[#98919b] sm:flex-row sm:items-center sm:justify-between">
        <p>{data.studioName} · America/Bogota · USD</p>
        <p className="inline-flex items-center gap-1.5">
          <Database aria-hidden="true" className="size-2.5" />
          Demonstration DTOs only — no live studio records
        </p>
      </footer>
    </div>
  );
}
