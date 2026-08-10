export type AuthErrorCode =
  | "invalid_credentials"
  | "authentication_required"
  | "email_unverified"
  | "invite_invalid"
  | "token_expired"
  | "csrf_expired"
  | "recent_password_required"
  | "challenge_invalid"
  | "feature_unavailable"
  | "validation_failed"
  | "rate_limited"
  | "service_unavailable";

export type AuthFieldName =
  | "name"
  | "email"
  | "password"
  | "passwordConfirmation"
  | "code"
  | "recoveryCode"
  | "passkeyName"
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
  requiresTwoFactor?: boolean;
};

export type GenericDeliveryResult = {
  message: string;
};

export type RegistrationResult = GenericDeliveryResult & {
  sessionEstablished: false;
};

export type CurrentUserDto = {
  id: string | number;
  name: string;
  email: string;
  emailVerifiedAt: string | null;
  twoFactorEnabled: boolean;
  passkeysCount: number;
};

export type PasswordConfirmationStatusDto = {
  confirmed: boolean;
};

export type TwoFactorSetupDto = {
  svg: string;
  url?: string;
  secretKey: string;
};

export type RecoveryCodesDto = {
  recoveryCodes: string[];
};

export type PasskeyDto = {
  id: string;
  name: string;
  authenticator?: string;
  lastUsedAt: string | null;
  createdAt: string;
};

export type BrowserSessionDto = {
  id: string;
  current: boolean;
  device: string;
  approximateLocation: string | null;
  createdAt: string;
  lastSeenAt: string;
};

export type WebAuthnOptionsDto = Record<string, unknown>;
export type WebAuthnCredentialDto = Record<string, unknown>;

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
  continueTo?: string;
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
  register(input: RegisterInput): Promise<AuthResult<RegistrationResult>>;
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
  getPasswordConfirmationStatus(): Promise<
    AuthResult<PasswordConfirmationStatusDto>
  >;
  confirmPassword(password: string): Promise<AuthResult<null>>;
  getPasskeyConfirmationOptions(): Promise<AuthResult<WebAuthnOptionsDto>>;
  confirmWithPasskey(
    credential: WebAuthnCredentialDto,
  ): Promise<AuthResult<null>>;
  enableTwoFactor(): Promise<AuthResult<null>>;
  getTwoFactorSetup(): Promise<AuthResult<TwoFactorSetupDto>>;
  confirmTwoFactor(code: string): Promise<AuthResult<RecoveryCodesDto>>;
  getRecoveryCodes(): Promise<AuthResult<RecoveryCodesDto>>;
  regenerateRecoveryCodes(): Promise<AuthResult<RecoveryCodesDto>>;
  disableTwoFactor(): Promise<AuthResult<null>>;
  completeTwoFactorChallenge(input: {
    code?: string;
    recoveryCode?: string;
    invitationToken?: string;
    continueTo?: string;
  }): Promise<AuthResult<SessionResult>>;
  getPasskeys(): Promise<AuthResult<PasskeyDto[]>>;
  getPasskeyRegistrationOptions(): Promise<AuthResult<WebAuthnOptionsDto>>;
  registerPasskey(
    name: string,
    credential: WebAuthnCredentialDto,
  ): Promise<AuthResult<null>>;
  deletePasskey(id: string): Promise<AuthResult<null>>;
  getPasskeyLoginOptions(): Promise<AuthResult<WebAuthnOptionsDto>>;
  loginWithPasskey(
    credential: WebAuthnCredentialDto,
    remember: boolean,
    invitationToken?: string,
    continueTo?: string,
  ): Promise<AuthResult<SessionResult>>;
  getSessions(): Promise<AuthResult<BrowserSessionDto[]>>;
  revokeSession(id: string): Promise<AuthResult<null>>;
  revokeOtherSessions(): Promise<AuthResult<null>>;
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
          redirectTo: input.continueTo ?? "/studio/sonora-house/home",
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
          sessionEstablished: false,
          message:
            "If those details can be used, check that email for next steps.",
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
          twoFactorEnabled: false,
          passkeysCount: 0,
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
    async getPasswordConfirmationStatus() {
      await wait();
      return { ok: true, data: { confirmed: true } };
    },
    async confirmPassword(password) {
      await wait();
      return password === "wrong-password"
        ? {
            ok: false,
            error: {
              code: "invalid_credentials",
              message: "That password was not recognized.",
              fieldErrors: { password: "That password was not recognized." },
            },
          }
        : { ok: true, data: null };
    },
    async getPasskeyConfirmationOptions() {
      await wait();
      return { ok: true, data: {} };
    },
    async confirmWithPasskey() {
      await wait();
      return { ok: true, data: null };
    },
    async enableTwoFactor() {
      await wait();
      return { ok: true, data: null };
    },
    async getTwoFactorSetup() {
      await wait();
      return {
        ok: true,
        data: {
          svg: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 2 2"><path d="M0 0h1v1H0z"/></svg>',
          url: "otpauth://totp/Maestro:maya%40studio.test",
          secretKey: "MAESTROFIXTUREKEY",
        },
      };
    },
    async confirmTwoFactor(code) {
      await wait();
      return code === "000000"
        ? {
            ok: false,
            error: {
              code: "challenge_invalid",
              message: "That verification code was not accepted.",
              fieldErrors: { code: "Enter a current 6-digit code." },
            },
          }
        : {
            ok: true,
            data: { recoveryCodes: ["fixture-recovery-one", "fixture-recovery-two"] },
          };
    },
    async getRecoveryCodes() {
      await wait();
      return {
        ok: true,
        data: { recoveryCodes: ["fixture-recovery-one", "fixture-recovery-two"] },
      };
    },
    async regenerateRecoveryCodes() {
      await wait();
      return {
        ok: true,
        data: { recoveryCodes: ["new-fixture-one", "new-fixture-two"] },
      };
    },
    async disableTwoFactor() {
      await wait();
      return { ok: true, data: null };
    },
    async completeTwoFactorChallenge(input) {
      await wait();
      if (input.code === "000000" || input.recoveryCode === "wrong-code") {
        return {
          ok: false,
          error: {
            code: "challenge_invalid",
            message: "That verification code was not accepted.",
          },
        };
      }
      return {
        ok: true,
        data: {
          sessionEstablished: true,
          redirectTo: input.invitationToken
            ? "/onboarding"
            : input.continueTo ?? "/studio/sonora-house/home",
        },
      };
    },
    async getPasskeys() {
      await wait();
      return { ok: true, data: [] };
    },
    async getPasskeyRegistrationOptions() {
      await wait();
      return { ok: true, data: {} };
    },
    async registerPasskey(name) {
      await wait();
      void name;
      return { ok: true, data: null };
    },
    async deletePasskey() {
      await wait();
      return { ok: true, data: null };
    },
    async getPasskeyLoginOptions() {
      await wait();
      return { ok: true, data: {} };
    },
    async loginWithPasskey(_credential, _remember, invitationToken, continueTo) {
      await wait();
      return {
        ok: true,
        data: {
          sessionEstablished: true,
          redirectTo: invitationToken
            ? "/onboarding"
            : continueTo ?? "/studio/sonora-house/home",
        },
      };
    },
    async getSessions() {
      await wait();
      return {
        ok: true,
        data: [
          {
            id: "fixture-session",
            current: true,
            device: "Fixture browser",
            approximateLocation: "127.0.0.0/24 (approximate)",
            createdAt: "2026-08-10T00:00:00Z",
            lastSeenAt: "2026-08-10T00:00:00Z",
          },
        ],
      };
    },
    async revokeSession(id) {
      await wait();
      void id;
      return { ok: true, data: null };
    },
    async revokeOtherSessions() {
      await wait();
      return { ok: true, data: null };
    },
  };
}

export const fixtureAuthClient = createFixtureAuthClient();
