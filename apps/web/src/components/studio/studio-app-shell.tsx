"use client";

import {
  BarChart3,
  BookOpenText,
  CalendarDays,
  ChevronDown,
  CircleHelp,
  CreditCard,
  Globe2,
  Home,
  Menu,
  MessageCircleMore,
  Music2,
  Search,
  Settings,
  UsersRound,
  X,
  type LucideIcon,
} from "lucide-react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import {
  type ChangeEvent,
  type ReactNode,
  useEffect,
  useRef,
  useState,
} from "react";

import type {
  StudioNavigationIcon,
  StudioNavigationItemDto,
  StudioShellDto,
} from "@/lib/studio-fixtures";

const navigationIcons: Record<StudioNavigationIcon, LucideIcon> = {
  home: Home,
  calendar: CalendarDays,
  people: UsersRound,
  messages: MessageCircleMore,
  billing: CreditCard,
  learning: BookOpenText,
  reports: BarChart3,
  website: Globe2,
  settings: Settings,
};

function MaestroBrand({ compact = false }: { compact?: boolean }) {
  return (
    <Link
      href="/"
      aria-label="Maestro marketing home"
      className="inline-flex items-center gap-2.5 rounded-xl text-white"
    >
      <span className="flex size-9 items-center justify-center rounded-[12px] bg-white text-[#241d39] shadow-[0_10px_30px_rgba(0,0,0,0.18)]">
        <Music2 aria-hidden="true" className="size-[18px]" strokeWidth={2.3} />
      </span>
      {!compact && (
        <span className="text-[18px] font-bold tracking-[-0.055em]">maestro</span>
      )}
    </Link>
  );
}

function WorkspaceMark({
  initials,
  accent,
}: {
  initials: string;
  accent: StudioShellDto["currentStudio"]["accent"];
}) {
  return (
    <span
      aria-hidden="true"
      className={`flex size-9 shrink-0 items-center justify-center rounded-xl text-[11px] font-bold ${
        accent === "teal"
          ? "bg-[#cdece3] text-[#155f52]"
          : "bg-[#ded4ff] text-[#5137a3]"
      }`}
    >
      {initials}
    </span>
  );
}

function TenantSwitcher({
  shell,
  compact = false,
}: {
  shell: StudioShellDto;
  compact?: boolean;
}) {
  const router = useRouter();
  const initials = shell.currentStudio.shortName
    .split(" ")
    .slice(0, 2)
    .map((word) => word[0])
    .join("")
    .toUpperCase();

  function handleTenantChange(event: ChangeEvent<HTMLSelectElement>) {
    const nextStudio = event.target.value;
    if (nextStudio !== shell.currentStudio.slug) {
      router.push(`/studio/${nextStudio}/home`);
    }
  }

  return (
    <div className={`relative flex items-center ${compact ? "gap-1" : "gap-2.5"}`}>
      <WorkspaceMark initials={initials} accent={shell.currentStudio.accent} />
      <div className="min-w-0 flex-1">
        <label
          htmlFor={compact ? "mobile-workspace-switcher" : "workspace-switcher"}
          className="sr-only"
        >
          Switch studio workspace
        </label>
        <select
          id={compact ? "mobile-workspace-switcher" : "workspace-switcher"}
          value={shell.currentStudio.slug}
          onChange={handleTenantChange}
          className="w-full appearance-none rounded-lg bg-transparent py-1 pr-7 text-[13px] font-semibold tracking-[-0.015em] text-white outline-none"
          aria-label="Switch studio workspace"
        >
          {shell.workspaces.map((workspace) => (
            <option key={workspace.slug} value={workspace.slug}>
              {workspace.shortName}
            </option>
          ))}
        </select>
        {!compact && (
          <p className="truncate text-[10px] font-medium text-white/48">Owner workspace</p>
        )}
      </div>
      <ChevronDown
        aria-hidden="true"
        className="pointer-events-none absolute right-0 size-3.5 text-white/45"
      />
    </div>
  );
}

function NavigationLink({
  item,
  studioSlug,
  active,
  onNavigate,
}: {
  item: StudioNavigationItemDto;
  studioSlug: string;
  active: boolean;
  onNavigate?: () => void;
}) {
  const Icon = navigationIcons[item.icon];

  return (
    <Link
      href={`/studio/${studioSlug}/${item.segment}`}
      aria-current={active ? "page" : undefined}
      onClick={onNavigate}
      className={`group flex min-h-11 items-center gap-3 rounded-xl px-3 text-[13px] font-medium transition-colors ${
        active
          ? "bg-white/[0.11] text-white shadow-[inset_0_0_0_1px_rgba(255,255,255,0.06)]"
          : "text-white/62 hover:bg-white/[0.06] hover:text-white"
      }`}
    >
      <Icon
        aria-hidden="true"
        className={`size-[17px] ${active ? "text-[#cfc1ff]" : "text-white/44 group-hover:text-white/75"}`}
        strokeWidth={active ? 2.2 : 1.9}
      />
      <span>{item.label}</span>
      {item.badge && (
        <span className="ml-auto flex min-w-5 items-center justify-center rounded-full bg-[#df6d46] px-1.5 py-0.5 text-[9px] font-bold text-white">
          {item.badge}
        </span>
      )}
    </Link>
  );
}

function StudioNavigation({
  shell,
  pathname,
  onNavigate,
}: {
  shell: StudioShellDto;
  pathname: string;
  onNavigate?: () => void;
}) {
  function isActive(item: StudioNavigationItemDto) {
    return pathname === `/studio/${shell.currentStudio.slug}/${item.segment}`;
  }

  return (
    <nav aria-label="Studio" className="flex min-h-0 flex-1 flex-col">
      <p className="mb-2 px-3 text-[9px] font-bold uppercase tracking-[0.18em] text-white/34">
        Workspace
      </p>
      <div className="space-y-1">
        {shell.primaryNavigation.map((item) => (
          <NavigationLink
            key={item.segment}
            item={item}
            studioSlug={shell.currentStudio.slug}
            active={isActive(item)}
            onNavigate={onNavigate}
          />
        ))}
      </div>

      <div className="my-5 border-t border-white/[0.07]" />
      <p className="mb-2 px-3 text-[9px] font-bold uppercase tracking-[0.18em] text-white/34">
        Manage
      </p>
      <div className="space-y-1">
        {shell.secondaryNavigation.map((item) => (
          <NavigationLink
            key={item.segment}
            item={item}
            studioSlug={shell.currentStudio.slug}
            active={isActive(item)}
            onNavigate={onNavigate}
          />
        ))}
      </div>
    </nav>
  );
}

function UserCard({ shell }: { shell: StudioShellDto }) {
  return (
    <div className="flex items-center gap-3 rounded-2xl border border-white/[0.07] bg-white/[0.045] p-2.5">
      <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-[#efbea1] text-[10px] font-bold text-[#713616]">
        {shell.user.initials}
      </span>
      <div className="min-w-0 flex-1">
        <p className="truncate text-[12px] font-semibold text-white">{shell.user.name}</p>
        <p className="truncate text-[9px] text-white/45">{shell.user.role}</p>
      </div>
      <Link
        href={`/studio/${shell.currentStudio.slug}/settings`}
        aria-label={`Open settings for ${shell.user.name}`}
        className="flex size-8 items-center justify-center rounded-lg text-white/42 transition-colors hover:bg-white/[0.07] hover:text-white"
      >
        <Settings aria-hidden="true" className="size-3.5" />
      </Link>
    </div>
  );
}

function Sidebar({
  shell,
  pathname,
  onNavigate,
}: {
  shell: StudioShellDto;
  pathname: string;
  onNavigate?: () => void;
}) {
  return (
    <div className="flex h-full flex-col bg-[#221b35] px-4 pb-4 pt-5 text-white">
      <div className="mb-6 flex items-center px-1">
        <MaestroBrand />
      </div>

      <div className="mb-6 rounded-2xl border border-white/[0.07] bg-white/[0.04] p-2.5">
        <TenantSwitcher shell={shell} compact={Boolean(onNavigate)} />
      </div>

      <StudioNavigation shell={shell} pathname={pathname} onNavigate={onNavigate} />

      <Link
        href="/help"
        className="mb-3 flex min-h-10 items-center gap-3 rounded-xl px-3 text-[12px] font-medium text-white/46 transition-colors hover:bg-white/[0.06] hover:text-white"
      >
        <CircleHelp aria-hidden="true" className="size-4" />
        Help & shortcuts
        <kbd className="ml-auto rounded border border-white/10 bg-white/[0.05] px-1.5 py-0.5 font-mono text-[8px] text-white/35">
          ?
        </kbd>
      </Link>
      <UserCard shell={shell} />
    </div>
  );
}

export function StudioAppShell({
  shell,
  children,
}: {
  shell: StudioShellDto;
  children: ReactNode;
}) {
  const pathname = usePathname();
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const openButtonRef = useRef<HTMLButtonElement>(null);
  const closeButtonRef = useRef<HTMLButtonElement>(null);
  const mobileDialogRef = useRef<HTMLElement>(null);

  function closeMobileMenu({ restoreFocus = true } = {}) {
    setMobileMenuOpen(false);
    if (restoreFocus) {
      window.setTimeout(() => openButtonRef.current?.focus(), 0);
    }
  }

  useEffect(() => {
    if (!mobileMenuOpen) return;

    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    closeButtonRef.current?.focus();

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === "Escape") {
        event.preventDefault();
        setMobileMenuOpen(false);
        window.setTimeout(() => openButtonRef.current?.focus(), 0);
        return;
      }

      if (event.key === "Tab") {
        const focusable = Array.from(
          mobileDialogRef.current?.querySelectorAll<HTMLElement>(
            'a[href], button:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])',
          ) ?? [],
        );
        const first = focusable.at(0);
        const last = focusable.at(-1);

        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last?.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first?.focus();
        }
      }
    }

    document.addEventListener("keydown", handleKeyDown);
    return () => {
      document.body.style.overflow = previousOverflow;
      document.removeEventListener("keydown", handleKeyDown);
    };
  }, [mobileMenuOpen]);

  return (
    <div className="min-h-screen bg-[#f4f2ee] text-[#272130]">
      <a
        href="#studio-main"
        className="fixed left-4 top-3 z-[80] -translate-y-24 rounded-xl bg-[#241d39] px-4 py-2.5 text-sm font-semibold text-white shadow-xl transition-transform focus:translate-y-0"
      >
        Skip to studio overview
      </a>

      <aside className="fixed inset-y-0 left-0 z-40 hidden w-[248px] lg:block">
        <Sidebar shell={shell} pathname={pathname} />
      </aside>

      <header className="sticky top-0 z-30 flex h-[68px] items-center justify-between border-b border-[#e5e0e7] bg-[#f8f7f4]/95 px-4 backdrop-blur-xl lg:ml-[248px] lg:px-7">
        <div className="flex min-w-0 items-center gap-3 lg:hidden">
          <button
            ref={openButtonRef}
            type="button"
            onClick={() => setMobileMenuOpen(true)}
            aria-label="Open studio navigation"
            aria-expanded={mobileMenuOpen}
            aria-controls="mobile-studio-navigation"
            className="flex size-11 shrink-0 items-center justify-center rounded-xl border border-[#ded9e2] bg-white text-[#332b43] shadow-sm transition-colors hover:bg-[#f4f1f8]"
          >
            <Menu aria-hidden="true" className="size-[19px]" />
          </button>
          <div className="min-w-0">
            <p className="truncate text-[13px] font-semibold tracking-[-0.02em] text-[#2b2437]">
              {shell.currentStudio.shortName}
            </p>
            <p className="text-[9px] font-medium uppercase tracking-[0.12em] text-[#8b8490]">
              Studio home
            </p>
          </div>
        </div>

        <div className="hidden items-center gap-2.5 lg:flex">
          <span className="flex size-8 items-center justify-center rounded-xl bg-white text-[#66557d] shadow-[0_1px_4px_rgba(45,35,58,0.08)] ring-1 ring-[#e6e1e9]">
            <Home aria-hidden="true" className="size-3.5" />
          </span>
          <div>
            <p className="text-[12px] font-semibold text-[#36303f]">Studio home</p>
            <p className="text-[9px] text-[#938d97]">Your daily operating view</p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <button
            type="button"
            aria-label="Search studio"
            className="flex size-10 items-center justify-center rounded-xl border border-[#e1dde4] bg-white text-[#6f6876] shadow-[0_1px_4px_rgba(45,35,58,0.05)] transition-colors hover:border-[#cfc5dc] hover:text-[#413551]"
          >
            <Search aria-hidden="true" className="size-4" />
          </button>
          <div className="hidden items-center gap-2 rounded-full border border-[#ded9e2] bg-white py-1.5 pl-1.5 pr-3 shadow-[0_1px_4px_rgba(45,35,58,0.05)] sm:flex">
            <span className="flex size-7 items-center justify-center rounded-full bg-[#efbea1] text-[9px] font-bold text-[#713616]">
              {shell.user.initials}
            </span>
            <span className="text-[10px] font-semibold text-[#4b4552]">{shell.user.name}</span>
          </div>
        </div>
      </header>

      {mobileMenuOpen && (
        <div className="fixed inset-0 z-50 lg:hidden">
          <button
            type="button"
            aria-label="Close studio navigation overlay"
            onClick={() => closeMobileMenu()}
            className="absolute inset-0 bg-[#191426]/60 backdrop-blur-[2px]"
          />
          <aside
            ref={mobileDialogRef}
            id="mobile-studio-navigation"
            role="dialog"
            aria-modal="true"
            aria-label="Studio navigation"
            className="relative h-full w-[min(88vw,320px)] shadow-[24px_0_70px_rgba(20,14,33,0.34)]"
          >
            <span className="sr-only" aria-live="polite">
              Studio navigation opened
            </span>
            <button
              ref={closeButtonRef}
              type="button"
              onClick={() => closeMobileMenu()}
              aria-label="Close studio navigation"
              className="absolute right-3 top-4 z-10 flex size-10 items-center justify-center rounded-xl text-white/60 transition-colors hover:bg-white/[0.08] hover:text-white"
            >
              <X aria-hidden="true" className="size-[18px]" />
            </button>
            <Sidebar
              shell={shell}
              pathname={pathname}
              onNavigate={() => closeMobileMenu({ restoreFocus: false })}
            />
          </aside>
        </div>
      )}

      <main id="studio-main" tabIndex={-1} className="lg:ml-[248px]">
        {children}
      </main>
    </div>
  );
}
