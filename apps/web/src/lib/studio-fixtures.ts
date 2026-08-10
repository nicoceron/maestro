import type {
  StudioNavigationItemDto,
  StudioShellDto,
  StudioWorkspaceDto,
} from "@/lib/studio";

export type {
  StudioNavigationIcon,
  StudioNavigationItemDto,
  StudioShellDto,
  StudioWorkspaceDto,
} from "@/lib/studio";

export interface StudioHomeMetricDto {
  id: "lessons" | "revenue" | "attendance" | "practice";
  label: string;
  value: string;
  change: string;
  direction: "positive" | "neutral" | "attention";
}

export interface StudioHomeLessonDto {
  id: string;
  time: string;
  endTime: string;
  title: string;
  student: string;
  teacher: string;
  location: string;
  status: "ready" | "in-progress" | "needs-notes" | "upcoming";
  statusLabel: string;
  instrument: "piano" | "voice" | "strings" | "guitar";
}

export interface StudioHomeTaskDto {
  id: string;
  title: string;
  detail: string;
  due: string;
  tone: "urgent" | "upcoming" | "finance";
  href: string;
  actionLabel: string;
}

export interface StudioHomeTeacherDto {
  id: string;
  name: string;
  initials: string;
  instrument: string;
  lessonCount: number;
  capacityLabel: string;
  capacityPercent: number;
  tone: "violet" | "peach" | "teal";
}

export interface StudioHomeActivityDto {
  id: string;
  actor: string;
  action: string;
  subject: string;
  time: string;
  tone: "violet" | "peach" | "teal" | "slate";
}

export interface StudioHomeDto {
  studioSlug: string;
  studioName: string;
  todayLabel: string;
  greeting: string;
  summary: string;
  metrics: StudioHomeMetricDto[];
  lessons: StudioHomeLessonDto[];
  tasks: StudioHomeTaskDto[];
  teachers: StudioHomeTeacherDto[];
  activity: StudioHomeActivityDto[];
  revenue: {
    collected: string;
    outstanding: string;
    percent: number;
    days: Array<{ label: string; value: number; amount: string }>;
  };
}

const workspaces: StudioWorkspaceDto[] = [
  {
    slug: "sonora-house",
    name: "Sonora House Music",
    shortName: "Sonora House",
    accent: "violet",
  },
  {
    slug: "northline-conservatory",
    name: "Northline Conservatory",
    shortName: "Northline",
    accent: "teal",
  },
];

const primaryNavigation: StudioNavigationItemDto[] = [
  { label: "Home", segment: "home", icon: "home" },
  { label: "Calendar", segment: "calendar", icon: "calendar" },
  { label: "People", segment: "people", icon: "people" },
  { label: "Messages", segment: "messages", icon: "messages", badge: "4" },
  { label: "Billing", segment: "billing", icon: "billing" },
  { label: "Learning", segment: "learning", icon: "learning" },
];

const secondaryNavigation: StudioNavigationItemDto[] = [
  { label: "Reports", segment: "reports", icon: "reports" },
  { label: "Website", segment: "website", icon: "website" },
  { label: "Settings", segment: "settings", icon: "settings" },
];

function titleFromSlug(slug: string) {
  return slug
    .split("-")
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(" ");
}

export function getStudioShellFixture(studioSlug: string): StudioShellDto {
  const currentStudio = workspaces.find(({ slug }) => slug === studioSlug) ?? {
    slug: studioSlug,
    name: `${titleFromSlug(studioSlug) || "Demo"} Music Studio`,
    shortName: titleFromSlug(studioSlug) || "Demo Studio",
    accent: "violet" as const,
  };

  return {
    currentStudio,
    workspaces: workspaces.some(({ slug }) => slug === currentStudio.slug)
      ? workspaces
      : [currentStudio, ...workspaces],
    primaryNavigation,
    secondaryNavigation,
    user: {
      name: "Maya Ortiz",
      initials: "MO",
      role: "Studio owner",
    },
  };
}

export function getStudioHomeFixture(studioSlug: string): StudioHomeDto {
  const shell = getStudioShellFixture(studioSlug);

  return {
    studioSlug,
    studioName: shell.currentStudio.name,
    todayLabel: "Monday, August 10",
    greeting: "Good morning, Maya",
    summary:
      "Your studio is in a steady rhythm. Two lesson notes need attention before the day wraps up.",
    metrics: [
      {
        id: "lessons",
        label: "Lessons today",
        value: "8",
        change: "2 open slots remain",
        direction: "neutral",
      },
      {
        id: "revenue",
        label: "Revenue collected",
        value: "$4,820",
        change: "92% of August invoices",
        direction: "positive",
      },
      {
        id: "attendance",
        label: "Attendance ready",
        value: "6 of 8",
        change: "2 notes need review",
        direction: "attention",
      },
      {
        id: "practice",
        label: "Practice pulse",
        value: "86%",
        change: "12% above last week",
        direction: "positive",
      },
    ],
    lessons: [
      {
        id: "les_1001",
        time: "9:00 AM",
        endTime: "9:45 AM",
        title: "Piano foundations",
        student: "Lena Ortiz",
        teacher: "Maya Ortiz",
        location: "Studio 2",
        status: "ready",
        statusLabel: "Ready",
        instrument: "piano",
      },
      {
        id: "les_1002",
        time: "10:15 AM",
        endTime: "11:15 AM",
        title: "Voice coaching",
        student: "Mateo Chen",
        teacher: "Ari Bennett",
        location: "Online",
        status: "in-progress",
        statusLabel: "In progress",
        instrument: "voice",
      },
      {
        id: "les_1003",
        time: "1:30 PM",
        endTime: "2:20 PM",
        title: "Junior strings",
        student: "Group · 6 students",
        teacher: "Noah Williams",
        location: "Hall A",
        status: "needs-notes",
        statusLabel: "Needs notes",
        instrument: "strings",
      },
      {
        id: "les_1004",
        time: "3:00 PM",
        endTime: "3:45 PM",
        title: "Guitar lab",
        student: "Inez Park",
        teacher: "Jon Bell",
        location: "Studio 1",
        status: "upcoming",
        statusLabel: "Upcoming",
        instrument: "guitar",
      },
    ],
    tasks: [
      {
        id: "task_1001",
        title: "Review two lesson notes",
        detail: "Junior strings and Guitar lab are waiting for a family-ready update.",
        due: "Before 6:00 PM",
        tone: "urgent",
        href: `/studio/${studioSlug}/calendar`,
        actionLabel: "Review notes",
      },
      {
        id: "task_1002",
        title: "Confirm Sofia's trial slot",
        detail: "Her recurring Tuesday time is held for another 36 hours.",
        due: "Tomorrow",
        tone: "upcoming",
        href: `/studio/${studioSlug}/people`,
        actionLabel: "Open trial",
      },
      {
        id: "task_1003",
        title: "One payment needs a nudge",
        detail: "The Kim family invoice is 4 days past due with Auto Pay off.",
        due: "$180 outstanding",
        tone: "finance",
        href: `/studio/${studioSlug}/billing`,
        actionLabel: "View invoice",
      },
    ],
    teachers: [
      {
        id: "tch_1001",
        name: "Maya Ortiz",
        initials: "MO",
        instrument: "Piano",
        lessonCount: 5,
        capacityLabel: "Comfortable",
        capacityPercent: 68,
        tone: "peach",
      },
      {
        id: "tch_1002",
        name: "Ari Bennett",
        initials: "AB",
        instrument: "Voice",
        lessonCount: 4,
        capacityLabel: "Nearly full",
        capacityPercent: 88,
        tone: "violet",
      },
      {
        id: "tch_1003",
        name: "Noah Williams",
        initials: "NW",
        instrument: "Strings",
        lessonCount: 3,
        capacityLabel: "Has room",
        capacityPercent: 54,
        tone: "teal",
      },
    ],
    activity: [
      {
        id: "act_1001",
        actor: "Lena Ortiz",
        action: "logged 28 minutes of practice for",
        subject: "Piano foundations",
        time: "12 minutes ago",
        tone: "violet",
      },
      {
        id: "act_1002",
        actor: "Noah Williams",
        action: "marked attendance for",
        subject: "Junior strings",
        time: "38 minutes ago",
        tone: "teal",
      },
      {
        id: "act_1003",
        actor: "Kim family",
        action: "opened invoice",
        subject: "INV-1048",
        time: "1 hour ago",
        tone: "peach",
      },
      {
        id: "act_1004",
        actor: "Sofia Rivera",
        action: "booked a trial with",
        subject: "Maya Ortiz",
        time: "2 hours ago",
        tone: "slate",
      },
    ],
    revenue: {
      collected: "$4,820",
      outstanding: "$420",
      percent: 92,
      days: [
        { label: "Mon", value: 42, amount: "$340" },
        { label: "Tue", value: 68, amount: "$560" },
        { label: "Wed", value: 52, amount: "$430" },
        { label: "Thu", value: 84, amount: "$690" },
        { label: "Fri", value: 72, amount: "$590" },
        { label: "Sat", value: 96, amount: "$780" },
        { label: "Sun", value: 34, amount: "$280" },
      ],
    },
  };
}
