export type AuthErrorCode =
  | "invalid_credentials"
  | "authentication_required"
  | "email_unverified"
  | "invite_invalid"
  | "token_expired"
  | "csrf_expired"
  | "validation_failed"
  | "rate_limited"
  | "service_unavailable";

export type AuthFieldName =
  | "name"
  | "email"
  | "password"
  | "passwordConfirmation"
  | "studioName"
  | "studioSlug"
  | "workspaceMode"
  | "primaryGoal"
  | "timeZone"
  | "currency";

export type AuthFailure = {
  code: AuthErrorCode;
  message: string;
  fieldErrors?: Partial<Record<AuthFieldName, string>>;
  retryAfterSeconds?: number;
};

export type AuthResult<T> =
  | { ok: true; data: T }
  | { ok: false; error: AuthFailure };

export type SessionResult = {
  sessionEstablished: boolean;
  redirectTo: string;
};

export type GenericDeliveryResult = {
  message: string;
};

export type CurrentUserDto = {
  id: string | number;
  name: string;
  email: string;
  emailVerifiedAt: string | null;
};

export type StudioMembershipRole =
  | "owner"
  | "administrator"
  | "office"
  | "billing"
  | "teacher";

export type StudioDto = {
  id: string | number;
  name: string;
  slug: string;
  timezone: string;
  currency: string;
  membership: {
    role: StudioMembershipRole;
    status: "active";
  };
  permissions: {
    manage: boolean;
  };
};

export type LoginInput = {
  email: string;
  password: string;
  remember: boolean;
  invitationToken?: string;
};

export type RegisterInput = {
  name: string;
  email: string;
  password: string;
  passwordConfirmation: string;
  invitationToken?: string;
};

export type PasswordResetRequestInput = {
  email: string;
};

export type PasswordResetInput = {
  email: string;
  password: string;
  passwordConfirmation: string;
  token: string;
};

export type OnboardingInput = {
  firstName: string;
  workspaceMode: "owner" | "administrator" | "teacher";
  studioName?: string;
  studioSlug?: string;
  timeZone: string;
  currency: "USD" | "CAD" | "GBP" | "EUR" | "COP";
  primaryGoal: "schedule" | "billing" | "teaching" | "growth";
  invitationToken?: string;
};

export interface AuthClient {
  readonly security: {
    session: "http-only-cookie";
    csrf: "sanctum-xsrf-cookie";
    credentials: "same-origin";
  };
  login(input: LoginInput): Promise<AuthResult<SessionResult>>;
  register(input: RegisterInput): Promise<AuthResult<SessionResult>>;
  requestPasswordReset(
    input: PasswordResetRequestInput,
  ): Promise<AuthResult<GenericDeliveryResult>>;
  resetPassword(
    input: PasswordResetInput,
  ): Promise<AuthResult<SessionResult>>;
  resendVerification(): Promise<AuthResult<GenericDeliveryResult>>;
  getCurrentUser(): Promise<AuthResult<CurrentUserDto>>;
  getStudios(): Promise<AuthResult<StudioDto[]>>;
  completeOnboarding(
    input: OnboardingInput,
  ): Promise<AuthResult<SessionResult>>;
}

export type FixtureAuthClientOptions = {
  delayMs?: number;
};

const security = {
  session: "http-only-cookie",
  csrf: "sanctum-xsrf-cookie",
  credentials: "same-origin",
} as const;

function normalizeEmail(email: string) {
  return email.trim().toLowerCase();
}

function serviceFailure(email?: string): AuthResult<never> | null {
  if (email && normalizeEmail(email).endsWith("@error.maestro.test")) {
    return {
      ok: false,
      error: {
        code: "service_unavailable",
        message:
          "We could not reach Maestro. Check your connection and try again.",
      },
    };
  }
  return null;
}

/** Deterministic adapter for unit tests and isolated stories only. */
export function createFixtureAuthClient(
  options: FixtureAuthClientOptions = {},
): AuthClient {
  const delayMs = options.delayMs ?? 0;
  const wait = () =>
    delayMs > 0
      ? new Promise<void>((resolve) => window.setTimeout(resolve, delayMs))
      : Promise.resolve();

  return {
    security,
    async login(input) {
      await wait();
      const unavailable = serviceFailure(input.email);
      if (unavailable) return unavailable;
      if (input.password === "wrong-password") {
        return {
          ok: false,
          error: {
            code: "invalid_credentials",
            message: "That email and password combination was not recognized.",
          },
        };
      }
      if (input.invitationToken) {
        return {
          ok: true,
          data: {
            sessionEstablished: true,
            redirectTo: "/onboarding",
          },
        };
      }
      if (normalizeEmail(input.email).startsWith("unverified")) {
        return {
          ok: false,
          error: {
            code: "email_unverified",
            message: "Your session is secure. Verify your email to enter a studio.",
          },
        };
      }
      return {
        ok: true,
        data: {
          sessionEstablished: true,
          redirectTo: "/studio/sonora-house/home",
        },
      };
    },
    async register(input) {
      await wait();
      const unavailable = serviceFailure(input.email);
      if (unavailable) return unavailable;
      if (input.invitationToken === "expired-invite") {
        return {
          ok: false,
          error: {
            code: "invite_invalid",
            message:
              "This invitation can no longer be used. Ask the sender for a new one.",
          },
        };
      }
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
      await wait();
      const unavailable = serviceFailure(input.email);
      if (unavailable) return unavailable;
      return {
        ok: true,
        data: {
          message:
            "If an account matches that address, a reset link is on its way.",
        },
      };
    },
    async resetPassword(input) {
      await wait();
      const unavailable = serviceFailure(input.email);
      if (unavailable) return unavailable;
      if (input.token === "expired-token") {
        return {
          ok: false,
          error: {
            code: "token_expired",
            message: "That reset link has expired or has already been used.",
          },
        };
      }
      return {
        ok: true,
        data: { sessionEstablished: true, redirectTo: "/onboarding" },
      };
    },
    async resendVerification() {
      await wait();
      return {
        ok: true,
        data: { message: "A fresh verification link is on its way." },
      };
    },
    async getCurrentUser() {
      await wait();
      return {
        ok: true,
        data: {
          id: "fixture-user",
          name: "Maya Ortiz",
          email: "maya@studio.test",
          emailVerifiedAt: "2026-08-10T00:00:00Z",
        },
      };
    },
    async getStudios() {
      await wait();
      return {
        ok: true,
        data: [
          {
            id: "fixture-studio",
            name: "Sonora House Music",
            slug: "sonora-house",
            timezone: "America/Bogota",
            currency: "USD",
            membership: { role: "owner", status: "active" },
            permissions: { manage: true },
          },
        ],
      };
    },
    async completeOnboarding(input) {
      await wait();
      if (input.invitationToken === "expired-invite") {
        return {
          ok: false,
          error: {
            code: "invite_invalid",
            message:
              "This invitation can no longer be used. Ask the sender for a new one.",
          },
        };
      }
      return {
        ok: true,
        data: {
          sessionEstablished: true,
          redirectTo: `/studio/${input.studioSlug || "sonora-house"}/home`,
        },
      };
    },
  };
}

export const fixtureAuthClient = createFixtureAuthClient();
