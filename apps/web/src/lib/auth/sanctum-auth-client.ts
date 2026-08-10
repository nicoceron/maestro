import type {
  AuthClient,
  AuthErrorCode,
  AuthFailure,
  AuthFieldName,
  AuthResult,
  CurrentUserDto,
  LoginInput,
  SessionResult,
  StudioDto,
  StudioMembershipRole,
} from "@/lib/auth/auth-client";

type Operation =
  | "login"
  | "register"
  | "forgot"
  | "reset"
  | "resend"
  | "current-user"
  | "studios"
  | "onboarding";

type LaravelErrors = Record<string, string[] | string>;
type LaravelPayload = {
  message?: string;
  errors?: LaravelErrors;
  data?: unknown;
};

type RequestOptions = {
  method?: "GET" | "POST";
  body?: Record<string, unknown>;
  csrf?: boolean;
  operation: Operation;
};

const security = {
  session: "http-only-cookie",
  csrf: "sanctum-xsrf-cookie",
  credentials: "same-origin",
} as const;

const fieldAliases: Record<string, AuthFieldName | undefined> = {
  name: "name",
  email: "email",
  password: "password",
  password_confirmation: "passwordConfirmation",
  preferred_name: "name",
  "studio.name": "studioName",
  "studio.slug": "studioSlug",
  "studio.timezone": "timeZone",
  "studio.currency": "currency",
  workspace_mode: "workspaceMode",
  primary_goal: "primaryGoal",
};

function readCookie(name: string) {
  if (typeof document === "undefined") return undefined;
  const prefix = `${name}=`;
  const cookie = document.cookie
    .split(";")
    .map((part) => part.trim())
    .find((part) => part.startsWith(prefix));
  if (!cookie) return undefined;

  try {
    return decodeURIComponent(cookie.slice(prefix.length));
  } catch {
    return undefined;
  }
}

async function payloadFrom(response: Response): Promise<LaravelPayload> {
  const text = await response.text();
  if (!text) return {};
  try {
    return JSON.parse(text) as LaravelPayload;
  } catch {
    return {};
  }
}

function firstMessage(value: string[] | string | undefined) {
  return Array.isArray(value) ? value[0] : value;
}

function fieldErrors(errors?: LaravelErrors) {
  if (!errors) return undefined;
  const mapped: Partial<Record<AuthFieldName, string>> = {};
  for (const [laravelField, messages] of Object.entries(errors)) {
    const field = fieldAliases[laravelField];
    const message = firstMessage(messages);
    if (field && message) mapped[field] = message;
  }
  return Object.keys(mapped).length > 0 ? mapped : undefined;
}

function failureFor(
  response: Response,
  payload: LaravelPayload,
  operation: Operation,
): AuthFailure {
  const message = payload.message;

  if (response.status === 401) {
    return {
      code: "authentication_required",
      message: "Your secure session has ended. Sign in and try again.",
    };
  }
  if (response.status === 403 && /verified/i.test(message ?? "")) {
    return {
      code: "email_unverified",
      message: "Verify your email before entering a studio.",
    };
  }
  if (response.status === 419) {
    return {
      code: "csrf_expired",
      message: "Your secure form session expired. Refresh and try again.",
    };
  }
  if (response.status === 429) {
    const retryAfterHeader = response.headers.get("Retry-After");
    const retryAfter = retryAfterHeader ? Number(retryAfterHeader) : Number.NaN;
    return {
      code: "rate_limited",
      message: "Too many attempts. Take a moment, then try again.",
      retryAfterSeconds: Number.isFinite(retryAfter) ? retryAfter : undefined,
    };
  }
  if (response.status === 422) {
    let code: AuthErrorCode = "validation_failed";
    if (operation === "login") code = "invalid_credentials";
    if (operation === "reset" && (payload.errors?.email || payload.errors?.token)) {
      code = "token_expired";
    }
    if (
      (operation === "register" || operation === "onboarding") &&
      payload.errors?.invitation_token
    ) {
      code = "invite_invalid";
    }
    return {
      code,
      message:
        message ??
        (code === "invalid_credentials"
          ? "That email and password combination was not recognized."
          : "Review the highlighted fields and try again."),
      fieldErrors: fieldErrors(payload.errors),
    };
  }
  return {
    code: "service_unavailable",
    message:
      response.status >= 500
        ? "Maestro is having trouble right now. Try again in a moment."
        : message ?? "We could not complete that request. Try again.",
  };
}

function networkFailure(): AuthFailure {
  return {
    code: "service_unavailable",
    message: "We could not reach Maestro. Check your connection and try again.",
  };
}

function normalizeUser(payload: LaravelPayload): CurrentUserDto | null {
  const data = payload.data;
  if (!data || typeof data !== "object") return null;
  const user = data as Record<string, unknown>;
  if (
    (typeof user.id !== "string" && typeof user.id !== "number") ||
    typeof user.name !== "string" ||
    typeof user.email !== "string"
  ) {
    return null;
  }
  return {
    id: user.id,
    name: user.name,
    email: user.email,
    emailVerifiedAt:
      typeof user.email_verified_at === "string" ? user.email_verified_at : null,
  };
}

function normalizeStudio(value: unknown): StudioDto | null {
  if (!value || typeof value !== "object") return null;
  const studio = value as Record<string, unknown>;
  const membership =
    studio.membership && typeof studio.membership === "object"
      ? (studio.membership as Record<string, unknown>)
      : null;
  const permissions =
    studio.permissions && typeof studio.permissions === "object"
      ? (studio.permissions as Record<string, unknown>)
      : null;
  const membershipRoles: StudioMembershipRole[] = [
    "owner",
    "administrator",
    "office",
    "billing",
    "teacher",
  ];
  if (
    (typeof studio.id !== "string" && typeof studio.id !== "number") ||
    typeof studio.name !== "string" ||
    typeof studio.slug !== "string" ||
    !membership ||
    typeof membership.role !== "string" ||
    !membershipRoles.includes(membership.role as StudioMembershipRole) ||
    membership.status !== "active" ||
    !permissions ||
    typeof permissions.manage !== "boolean"
  ) {
    return null;
  }
  return {
    id: studio.id,
    name: studio.name,
    slug: studio.slug,
    timezone: typeof studio.timezone === "string" ? studio.timezone : "UTC",
    currency: typeof studio.currency === "string" ? studio.currency : "USD",
    membership: {
      role: membership.role as StudioMembershipRole,
      status: "active",
    },
    permissions: { manage: permissions.manage },
  };
}

export function createSanctumAuthClient(): AuthClient {
  let csrfBootstrap: Promise<AuthResult<null>> | null = null;

  async function bootstrapCsrf(): Promise<AuthResult<null>> {
    if (!csrfBootstrap) {
      csrfBootstrap = (async () => {
        try {
          const response = await fetch("/sanctum/csrf-cookie", {
            method: "GET",
            credentials: "same-origin",
            headers: { Accept: "application/json" },
          });
          if (!response.ok) {
            return {
              ok: false,
              error: failureFor(response, await payloadFrom(response), "login"),
            };
          }
          return { ok: true, data: null };
        } catch {
          return { ok: false, error: networkFailure() };
        }
      })();
    }
    const result = await csrfBootstrap;
    if (!result.ok) csrfBootstrap = null;
    return result;
  }

  async function request<T = LaravelPayload>(
    path: string,
    options: RequestOptions,
    allowCsrfRetry = true,
  ): Promise<AuthResult<T>> {
    if (options.csrf) {
      const bootstrap = await bootstrapCsrf();
      if (!bootstrap.ok) return bootstrap;
    }

    const headers: Record<string, string> = { Accept: "application/json" };
    if (options.body) headers["Content-Type"] = "application/json";
    if (options.csrf) {
      const xsrfToken = readCookie("XSRF-TOKEN");
      if (!xsrfToken) {
        csrfBootstrap = null;
        if (allowCsrfRetry) {
          return request<T>(path, options, false);
        }
        return {
          ok: false,
          error: {
            code: "csrf_expired",
            message: "Your secure form session expired. Refresh and try again.",
          },
        };
      }
      headers["X-XSRF-TOKEN"] = xsrfToken;
    }

    try {
      const response = await fetch(path, {
        method: options.method ?? "GET",
        credentials: "same-origin",
        headers,
        body: options.body ? JSON.stringify(options.body) : undefined,
      });
      const payload = await payloadFrom(response);
      if (response.status === 419 && options.csrf && allowCsrfRetry) {
        csrfBootstrap = null;
        return request<T>(path, options, false);
      }
      if (!response.ok) {
        return {
          ok: false,
          error: failureFor(response, payload, options.operation),
        };
      }
      return { ok: true, data: payload as T };
    } catch {
      return { ok: false, error: networkFailure() };
    }
  }

  async function getCurrentUser(): Promise<AuthResult<CurrentUserDto>> {
    const result = await request<LaravelPayload>("/api/v1/auth/user", {
      operation: "current-user",
    });
    if (!result.ok) return result;
    const user = normalizeUser(result.data);
    return user
      ? { ok: true, data: user }
      : { ok: false, error: networkFailure() };
  }

  async function getStudios(): Promise<AuthResult<StudioDto[]>> {
    const result = await request<LaravelPayload>("/api/v1/studios", {
      operation: "studios",
    });
    if (!result.ok) return result;
    const values = Array.isArray(result.data.data) ? result.data.data : [];
    return {
      ok: true,
      data: values.map(normalizeStudio).filter((studio): studio is StudioDto => Boolean(studio)),
    };
  }

  async function destinationAfterLogin(
    invitationToken?: string,
  ): Promise<AuthResult<SessionResult>> {
    if (invitationToken) {
      return {
        ok: true,
        data: {
          sessionEstablished: true,
          redirectTo: "/onboarding",
        },
      };
    }

    const user = await getCurrentUser();
    if (!user.ok) return user;
    if (!user.data.emailVerifiedAt) {
      return {
        ok: false,
        error: {
          code: "email_unverified",
          message: "Your session is secure. Verify your email to enter a studio.",
        },
      };
    }
    const studios = await getStudios();
    if (!studios.ok) return studios;
    return {
      ok: true,
      data: {
        sessionEstablished: true,
        redirectTo: studios.data[0]
          ? `/studio/${studios.data[0].slug}/home`
          : "/onboarding",
      },
    };
  }

  async function postLogin(input: LoginInput): Promise<AuthResult<SessionResult>> {
    const login = await request("/api/v1/auth/login", {
      method: "POST",
      csrf: true,
      operation: "login",
      body: {
        email: input.email,
        password: input.password,
        remember: input.remember,
      },
    });
    if (!login.ok) return login;
    return destinationAfterLogin(input.invitationToken);
  }

  return {
    security,
    login: postLogin,
    async register(input) {
      const result = await request("/api/v1/auth/register", {
        method: "POST",
        csrf: true,
        operation: "register",
        body: {
          name: input.name,
          email: input.email,
          password: input.password,
          password_confirmation: input.passwordConfirmation,
          ...(input.invitationToken
            ? { invitation_token: input.invitationToken }
            : {}),
        },
      });
      if (!result.ok) return result;
      return {
        ok: true,
        data: {
          sessionEstablished: true,
          redirectTo: input.invitationToken
            ? "/onboarding"
            : "/verify-email",
        },
      };
    },
    async requestPasswordReset(input) {
      const result = await request<LaravelPayload>("/api/v1/auth/forgot-password", {
        method: "POST",
        csrf: true,
        operation: "forgot",
        body: { email: input.email },
      });
      if (!result.ok) return result;
      return {
        ok: true,
        data: {
          message:
            result.data.message ??
            "If an account matches that address, a reset link is on its way.",
        },
      };
    },
    async resetPassword(input) {
      const reset = await request("/api/v1/auth/reset-password", {
        method: "POST",
        csrf: true,
        operation: "reset",
        body: {
          email: input.email,
          token: input.token,
          password: input.password,
          password_confirmation: input.passwordConfirmation,
        },
      });
      if (!reset.ok) return reset;
      return postLogin({
        email: input.email,
        password: input.password,
        remember: false,
      });
    },
    async resendVerification() {
      const result = await request<LaravelPayload>(
        "/api/v1/auth/email/verification-notification",
        {
          method: "POST",
          csrf: true,
          operation: "resend",
        },
      );
      if (!result.ok) return result;
      return {
        ok: true,
        data: {
          message: result.data.message ?? "A fresh verification link is on its way.",
        },
      };
    },
    getCurrentUser,
    getStudios,
    async completeOnboarding(input) {
      const result = await request<LaravelPayload>("/api/v1/onboarding", {
        method: "POST",
        csrf: true,
        operation: "onboarding",
        body: {
          preferred_name: input.firstName,
          workspace_mode: input.workspaceMode,
          primary_goal: input.primaryGoal,
          ...(input.invitationToken
            ? { invitation_token: input.invitationToken }
            : {
                studio: {
                  name: input.studioName,
                  ...(input.studioSlug ? { slug: input.studioSlug } : {}),
                  timezone: input.timeZone,
                  currency: input.currency,
                },
              }),
        },
      });
      if (!result.ok) return result;

      const envelope = result.data.data;
      const candidate =
        envelope && typeof envelope === "object" && "studio" in envelope
          ? (envelope as { studio?: unknown }).studio
          : envelope;
      const studio = normalizeStudio(candidate);
      if (studio) {
        return {
          ok: true,
          data: {
            sessionEstablished: true,
            redirectTo: `/studio/${studio.slug}/home`,
          },
        };
      }
      return destinationAfterLogin();
    },
  };
}

export const sanctumAuthClient = createSanctumAuthClient();
