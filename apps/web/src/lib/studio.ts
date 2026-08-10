import type {
  CurrentUserDto,
  StudioDto,
  StudioMembershipRole,
} from "@/lib/auth/auth-client";

export type StudioNavigationIcon =
  | "home"
  | "calendar"
  | "people"
  | "messages"
  | "billing"
  | "learning"
  | "reports"
  | "website"
  | "settings";

export interface StudioNavigationItemDto {
  label: string;
  segment: string;
  icon: StudioNavigationIcon;
  badge?: string;
}

export interface StudioWorkspaceDto {
  slug: string;
  name: string;
  shortName: string;
  accent: "violet" | "teal";
}

export interface StudioShellDto {
  currentStudio: StudioWorkspaceDto;
  workspaces: StudioWorkspaceDto[];
  primaryNavigation: StudioNavigationItemDto[];
  secondaryNavigation: StudioNavigationItemDto[];
  user: {
    name: string;
    initials: string;
    role: string;
  };
}

const primaryNavigation: StudioNavigationItemDto[] = [
  { label: "Home", segment: "home", icon: "home" },
  { label: "Calendar", segment: "calendar", icon: "calendar" },
  { label: "People", segment: "people", icon: "people" },
  { label: "Messages", segment: "messages", icon: "messages" },
  { label: "Billing", segment: "billing", icon: "billing" },
  { label: "Learning", segment: "learning", icon: "learning" },
];

const secondaryNavigation: StudioNavigationItemDto[] = [
  { label: "Reports", segment: "reports", icon: "reports" },
  { label: "Website", segment: "website", icon: "website" },
  { label: "Settings", segment: "settings", icon: "settings" },
];

const roleLabels: Record<StudioMembershipRole, string> = {
  owner: "Owner",
  administrator: "Administrator",
  office: "Office",
  billing: "Billing",
  teacher: "Teacher",
};

function initials(name: string) {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0])
    .join("")
    .toUpperCase();
}

function workspace(studio: StudioDto, index: number): StudioWorkspaceDto {
  return {
    slug: studio.slug,
    name: studio.name,
    shortName: studio.name,
    accent: index % 2 === 0 ? "violet" : "teal",
  };
}

export function createStudioShell(
  user: CurrentUserDto,
  studios: StudioDto[],
  currentStudio: StudioDto,
): StudioShellDto {
  const workspaces = studios.map(workspace);
  const currentIndex = studios.findIndex(
    ({ slug }) => slug === currentStudio.slug,
  );

  return {
    currentStudio: workspace(currentStudio, Math.max(currentIndex, 0)),
    workspaces,
    primaryNavigation,
    secondaryNavigation,
    user: {
      name: user.name,
      initials: initials(user.name),
      role: roleLabels[currentStudio.membership.role],
    },
  };
}
