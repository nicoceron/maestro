import type {
  AuthClient,
  AuthErrorCode,
  AuthFailure,
  AuthFieldName,
  AuthResult,
  BrowserSessionDto,
  CurrentUserDto,
  LoginInput,
  PasskeyDto,
  RecoveryCodesDto,
  SessionResult,
  StudioDto,
  StudioMembershipRole,
  TwoFactorSetupDto,
  WebAuthnOptionsDto,
} from "@/lib/auth/auth-client";

type Operation =
  | "login"
  | "register"
  | "forgot"
  | "reset"
  | "resend"
  | "current-user"
  | "studios"
  | "onboarding"
  | "password-confirmation"
  | "two-factor-management"
  | "two-factor-challenge"
  | "passkey"
  | "sessions";

type LaravelErrors = Record<string, string[] | string>;
type LaravelPayload = {
  message?: string;
  errors?: LaravelErrors;
  data?: unknown;
  [key: string]: unknown;
};

type RequestOptions = {
  method?: "GET" | "POST" | "DELETE";
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
  code: "code",
  recovery_code: "recoveryCode",
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

function fieldErrors(errors?: LaravelErrors, operation?: Operation) {
  if (!errors) return undefined;
  const mapped: Partial<Record<AuthFieldName, string>> = {};
  for (const [laravelField, messages] of Object.entries(errors)) {
    const field =
      operation === "passkey" && laravelField === "name"
        ? "passkeyName"
        : fieldAliases[laravelField];
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
  if (response.status === 423) {
    return {
      code: "recent_password_required",
      message: "Confirm your password before changing account security.",
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
    if (operation === "password-confirmation") code = "invalid_credentials";
    if (operation === "two-factor-challenge") code = "challenge_invalid";
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
      fieldErrors: fieldErrors(payload.errors, operation),
    };
  }
  if (response.status === 404 || response.status === 405) {
    return {
      code: "feature_unavailable",
      message: "This security feature is not available on this server yet.",
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

function safeContinueTo(value?: string) {
  return value === "/account/security" ? value : undefined;
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
    twoFactorEnabled: user.two_factor_enabled === true,
    passkeysCount:
      typeof user.passkeys_count === "number" ? user.passkeys_count : 0,
  };
}

function dataObject(payload: LaravelPayload): Record<string, unknown> | null {
  return payload.data && typeof payload.data === "object"
    ? (payload.data as Record<string, unknown>)
    : null;
}

function stringArray(value: unknown) {
  return Array.isArray(value)
    ? value.filter((item): item is string => typeof item === "string")
    : [];
}

function recoveryCodes(payload: LaravelPayload): RecoveryCodesDto {
  if (Array.isArray(payload)) {
    return { recoveryCodes: stringArray(payload) };
  }
  const data = dataObject(payload);
  return {
    recoveryCodes: stringArray(
      payload.recoveryCodes ?? data?.recoveryCodes ?? data?.recovery_codes,
    ),
  };
}

function normalizePasskey(value: unknown): PasskeyDto | null {
  if (!value || typeof value !== "object") return null;
  const passkey = value as Record<string, unknown>;
  if (
    (typeof passkey.id !== "string" && typeof passkey.id !== "number") ||
    typeof passkey.name !== "string" ||
    typeof passkey.created_at !== "string"
  ) {
    return null;
  }
  return {
    id: String(passkey.id),
    name: passkey.name,
    authenticator:
      typeof passkey.authenticator === "string"
        ? passkey.authenticator
        : undefined,
    lastUsedAt:
      typeof passkey.last_used_at === "string" ? passkey.last_used_at : null,
    createdAt: passkey.created_at,
  };
}

function normalizeSession(value: unknown): BrowserSessionDto | null {
  if (!value || typeof value !== "object") return null;
  const session = value as Record<string, unknown>;
  if (
    typeof session.id !== "string" ||
    typeof session.current !== "boolean" ||
    typeof session.device !== "string" ||
    typeof session.created_at !== "string" ||
    typeof session.last_seen_at !== "string"
  ) {
    return null;
  }
  return {
    id: session.id,
    current: session.current,
    device: session.device,
    approximateLocation:
      typeof session.approximate_location === "string"
        ? session.approximate_location
        : null,
    createdAt: session.created_at,
    lastSeenAt: session.last_seen_at,
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
    continueTo?: string,
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
    const destination = safeContinueTo(continueTo);
    if (destination) {
      return {
        ok: true,
        data: { sessionEstablished: true, redirectTo: destination },
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
    const login = await request<LaravelPayload>("/api/v1/auth/login", {
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
    if (login.data.two_factor === true) {
      return {
        ok: true,
        data: {
          sessionEstablished: false,
          requiresTwoFactor: true,
          redirectTo: safeContinueTo(input.continueTo)
            ? "/two-factor-challenge?returnTo=%2Faccount%2Fsecurity"
            : "/two-factor-challenge",
        },
      };
    }
    return destinationAfterLogin(input.invitationToken, input.continueTo);
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
          sessionEstablished: false,
          message:
            "If those details can be used, check that email for next steps.",
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
    async getPasswordConfirmationStatus() {
      const result = await request<LaravelPayload>(
        "/api/v1/auth/user/confirmed-password-status",
        { operation: "password-confirmation" },
      );
      if (!result.ok) return result;
      return {
        ok: true,
        data: { confirmed: result.data.confirmed === true },
      };
    },
    async confirmPassword(password) {
      const result = await request("/api/v1/auth/user/confirm-password", {
        method: "POST",
        csrf: true,
        operation: "password-confirmation",
        body: { password },
      });
      return result.ok ? { ok: true, data: null } : result;
    },
    async getPasskeyConfirmationOptions() {
      const result = await request<LaravelPayload>(
        "/api/v1/auth/passkeys/confirm/options",
        { operation: "passkey" },
      );
      if (!result.ok) return result;
      const options = result.data.options;
      return options && typeof options === "object"
        ? { ok: true, data: options as WebAuthnOptionsDto }
        : { ok: false, error: networkFailure() };
    },
    async confirmWithPasskey(credential) {
      const result = await request("/api/v1/auth/passkeys/confirm", {
        method: "POST",
        csrf: true,
        operation: "passkey",
        body: { credential },
      });
      return result.ok ? { ok: true, data: null } : result;
    },
    async enableTwoFactor() {
      const result = await request(
        "/api/v1/auth/user/two-factor-authentication",
        {
          method: "POST",
          csrf: true,
          operation: "two-factor-management",
        },
      );
      return result.ok ? { ok: true, data: null } : result;
    },
    async getTwoFactorSetup() {
      const [qr, secret] = await Promise.all([
        request<LaravelPayload>("/api/v1/auth/user/two-factor-qr-code", {
          operation: "two-factor-management",
        }),
        request<LaravelPayload>("/api/v1/auth/user/two-factor-secret-key", {
          operation: "two-factor-management",
        }),
      ]);
      if (!qr.ok) return qr;
      if (!secret.ok) return secret;
      const svg = typeof qr.data.svg === "string" ? qr.data.svg : undefined;
      const secretKey =
        typeof secret.data.secretKey === "string"
          ? secret.data.secretKey
          : typeof secret.data.secret_key === "string"
            ? secret.data.secret_key
            : undefined;
      if (!svg || !secretKey) return { ok: false, error: networkFailure() };
      const setup: TwoFactorSetupDto = {
        svg,
        secretKey,
        ...(typeof qr.data.url === "string" ? { url: qr.data.url } : {}),
      };
      return { ok: true, data: setup };
    },
    async confirmTwoFactor(code) {
      const result = await request<LaravelPayload>(
        "/api/v1/auth/user/confirmed-two-factor-authentication",
        {
          method: "POST",
          csrf: true,
          operation: "two-factor-management",
          body: { code },
        },
      );
      if (!result.ok) return result;
      const codes = await request<LaravelPayload>(
        "/api/v1/auth/user/two-factor-recovery-codes",
        { operation: "two-factor-management" },
      );
      return codes.ok
        ? { ok: true, data: recoveryCodes(codes.data) }
        : codes;
    },
    async getRecoveryCodes() {
      const result = await request<LaravelPayload>(
        "/api/v1/auth/user/two-factor-recovery-codes",
        { operation: "two-factor-management" },
      );
      return result.ok
        ? { ok: true, data: recoveryCodes(result.data) }
        : result;
    },
    async regenerateRecoveryCodes() {
      const result = await request<LaravelPayload>(
        "/api/v1/auth/user/two-factor-recovery-codes",
        {
          method: "POST",
          csrf: true,
          operation: "two-factor-management",
        },
      );
      if (!result.ok) return result;
      const codes = await request<LaravelPayload>(
        "/api/v1/auth/user/two-factor-recovery-codes",
        { operation: "two-factor-management" },
      );
      return codes.ok
        ? { ok: true, data: recoveryCodes(codes.data) }
        : codes;
    },
    async disableTwoFactor() {
      const result = await request(
        "/api/v1/auth/user/two-factor-authentication",
        {
          method: "DELETE",
          csrf: true,
          operation: "two-factor-management",
        },
      );
      return result.ok ? { ok: true, data: null } : result;
    },
    async completeTwoFactorChallenge(input) {
      const result = await request("/api/v1/auth/two-factor-challenge", {
        method: "POST",
        csrf: true,
        operation: "two-factor-challenge",
        body: input.recoveryCode
          ? { recovery_code: input.recoveryCode }
          : { code: input.code },
      });
      if (!result.ok) return result;
      return destinationAfterLogin(input.invitationToken, input.continueTo);
    },
    async getPasskeys() {
      const result = await request<LaravelPayload>("/api/v1/auth/passkeys", {
        operation: "passkey",
      });
      if (!result.ok) return result;
      const values = Array.isArray(result.data.data) ? result.data.data : [];
      return {
        ok: true,
        data: values
          .map(normalizePasskey)
          .filter((passkey): passkey is PasskeyDto => Boolean(passkey)),
      };
    },
    async getPasskeyRegistrationOptions() {
      const result = await request<LaravelPayload>(
        "/api/v1/auth/user/passkeys/options",
        { operation: "passkey" },
      );
      if (!result.ok) return result;
      const options = result.data.options;
      return options && typeof options === "object"
        ? { ok: true, data: options as WebAuthnOptionsDto }
        : { ok: false, error: networkFailure() };
    },
    async registerPasskey(name, credential) {
      const result = await request("/api/v1/auth/user/passkeys", {
        method: "POST",
        csrf: true,
        operation: "passkey",
        body: { name, credential },
      });
      return result.ok ? { ok: true, data: null } : result;
    },
    async deletePasskey(id) {
      const result = await request(
        `/api/v1/auth/user/passkeys/${encodeURIComponent(id)}`,
        {
          method: "DELETE",
          csrf: true,
          operation: "passkey",
        },
      );
      return result.ok ? { ok: true, data: null } : result;
    },
    async getPasskeyLoginOptions() {
      const result = await request<LaravelPayload>(
        "/api/v1/auth/passkeys/login/options",
        { operation: "passkey" },
      );
      if (!result.ok) return result;
      const options = result.data.options;
      return options && typeof options === "object"
        ? { ok: true, data: options as WebAuthnOptionsDto }
        : { ok: false, error: networkFailure() };
    },
    async loginWithPasskey(credential, remember, invitationToken, continueTo) {
      const result = await request("/api/v1/auth/passkeys/login", {
        method: "POST",
        csrf: true,
        operation: "passkey",
        body: { credential, remember },
      });
      if (!result.ok) return result;
      return destinationAfterLogin(invitationToken, continueTo);
    },
    async getSessions() {
      const result = await request<LaravelPayload>("/api/v1/auth/sessions", {
        operation: "sessions",
      });
      if (!result.ok) return result;
      const values = Array.isArray(result.data.data) ? result.data.data : [];
      return {
        ok: true,
        data: values
          .map(normalizeSession)
          .filter((session): session is BrowserSessionDto => Boolean(session)),
      };
    },
    async revokeSession(id) {
      const sessions = await request(
        `/api/v1/auth/sessions/${encodeURIComponent(id)}`,
        {
          method: "DELETE",
          csrf: true,
          operation: "sessions",
        },
      );
      if (!sessions.ok) return sessions;
      return { ok: true, data: null };
    },
    async revokeOtherSessions() {
      const result = await request("/api/v1/auth/sessions/others", {
        method: "DELETE",
        csrf: true,
        operation: "sessions",
      });
      return result.ok ? { ok: true, data: null } : result;
    },
  };
}

export const sanctumAuthClient = createSanctumAuthClient();
