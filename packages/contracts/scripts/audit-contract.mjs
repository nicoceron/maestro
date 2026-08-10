import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

const packageRoot = fileURLToPath(new URL("../", import.meta.url));
const source = readFileSync(new URL("../openapi.yaml", import.meta.url), "utf8");
const generated = readFileSync(new URL("../generated/schema.d.ts", import.meta.url), "utf8");

const expectedPaths = [
  "/sanctum/csrf-cookie",
  "/api/v1/auth/login",
  "/api/v1/auth/register",
  "/api/v1/auth/logout",
  "/api/v1/auth/forgot-password",
  "/api/v1/auth/reset-password",
  "/api/v1/auth/email/verification-notification",
  "/api/v1/auth/email/verify/{id}/{hash}",
  "/api/v1/auth/user",
  "/api/v1/auth/user/confirm-password",
  "/api/v1/auth/user/confirmed-password-status",
  "/api/v1/auth/two-factor-challenge",
  "/api/v1/auth/user/two-factor-authentication",
  "/api/v1/auth/user/two-factor-qr-code",
  "/api/v1/auth/user/two-factor-secret-key",
  "/api/v1/auth/user/confirmed-two-factor-authentication",
  "/api/v1/auth/user/two-factor-recovery-codes",
  "/api/v1/auth/passkeys/login/options",
  "/api/v1/auth/passkeys/login",
  "/api/v1/auth/passkeys/confirm/options",
  "/api/v1/auth/passkeys/confirm",
  "/api/v1/auth/user/passkeys/options",
  "/api/v1/auth/user/passkeys",
  "/api/v1/auth/user/passkeys/{passkey}",
  "/api/v1/auth/passkeys",
  "/api/v1/auth/sessions",
  "/api/v1/auth/sessions/others",
  "/api/v1/auth/sessions/{userSession}",
  "/api/v1/invitations/preview",
  "/api/v1/invitations/accept",
  "/api/v1/onboarding",
  "/api/v1/studios/{studio}/invitations",
  "/api/v1/studios/{studio}/invitations/{invitation}",
];

const expectedOperations = [
  "initializeCsrf",
  "login",
  "register",
  "logout",
  "requestPasswordReset",
  "resetPassword",
  "resendEmailVerification",
  "verifyEmail",
  "getCurrentUser",
  "confirmPassword",
  "getPasswordConfirmationStatus",
  "completeTwoFactorChallenge",
  "enableTwoFactorAuthentication",
  "disableTwoFactorAuthentication",
  "getTwoFactorQrCode",
  "getTwoFactorSecretKey",
  "confirmTwoFactorAuthentication",
  "getTwoFactorRecoveryCodes",
  "regenerateTwoFactorRecoveryCodes",
  "getPasskeyLoginOptions",
  "loginWithPasskey",
  "getPasskeyConfirmationOptions",
  "confirmWithPasskey",
  "getPasskeyRegistrationOptions",
  "registerPasskey",
  "deletePasskey",
  "listPasskeys",
  "listSessions",
  "revokeOtherSessions",
  "revokeSession",
  "previewInvitation",
  "acceptInvitation",
  "completeOnboarding",
  "listStudioInvitations",
  "createStudioInvitation",
  "revokeStudioInvitation",
];

const expectedSchemas = [
  "CurrentUser",
  "TwoFactorChallengeInput",
  "TwoFactorRecoveryCodes",
  "WebAuthnCredential",
  "Passkey",
  "BrowserSession",
  "InvitationTokenInput",
  "InvitationPreview",
  "OnboardingInput",
  "StudioInvitation",
  "WorkspaceMode",
];

const expectedAcceptanceIds = {
  completeTwoFactorChallenge: ["IDA-MFA-002", "AUTH-E009"],
  enableTwoFactorAuthentication: ["IDA-MFA-001", "MFA-E001"],
  getTwoFactorQrCode: ["MFA-E006"],
  registerPasskey: ["IDA-PASSKEY-002", "PASS-E005"],
  listPasskeys: ["PASS-E008"],
  listSessions: ["IDA-SESSION-002", "AUTH-E017"],
  revokeOtherSessions: ["AUTH-E013"],
  revokeSession: ["AUTH-E012", "AUTH-E018"],
  createStudioInvitation: ["IDA-STEPUP-001", "INV-E001"],
};

const missing = [];

for (const path of expectedPaths) {
  if (!source.includes(`  ${path}:`)) missing.push(`source path ${path}`);
  if (!generated.includes(`\"${path}\":`)) missing.push(`generated path ${path}`);
}

for (const operation of expectedOperations) {
  if (!source.includes(`operationId: ${operation}`)) missing.push(`source operation ${operation}`);
  if (!generated.includes(`${operation}:`)) missing.push(`generated operation ${operation}`);
}

for (const schema of expectedSchemas) {
  if (!source.includes(`    ${schema}:`)) missing.push(`source schema ${schema}`);
  if (!generated.includes(`${schema}:`)) missing.push(`generated schema ${schema}`);
}

for (const [operation, ids] of Object.entries(expectedAcceptanceIds)) {
  const operationStart = source.indexOf(`operationId: ${operation}`);
  const operationBlock = operationStart === -1 ? "" : source.slice(operationStart, operationStart + 500);

  for (const id of ids) {
    if (!operationBlock.includes(id)) missing.push(`acceptance ID ${id} on ${operation}`);
  }
}

const invariants = [
  ["CSRF bootstrap is public", /\/sanctum\/csrf-cookie:[\s\S]*?operationId: initializeCsrf[\s\S]*?security: \[\]/],
  ["login requires the CSRF scheme", /operationId: login[\s\S]*?security:\n\s+- csrfToken: \[\]/],
  ["invitation preview is public", /operationId: previewInvitation[\s\S]*?security: \[\]/],
  ["invitation acceptance requires session and CSRF", /operationId: acceptInvitation[\s\S]*?sanctumSession: \[\][\s\S]*?csrfToken: \[\]/],
  ["invitation preview takes the bearer in JSON", /\/api\/v1\/invitations\/preview:[\s\S]*?requestBody:[\s\S]*?InvitationTokenInput/],
  ["invitation acceptance takes the bearer in JSON", /\/api\/v1\/invitations\/accept:[\s\S]*?requestBody:[\s\S]*?InvitationTokenInput/],
  ["workspace mode is documented as non-authorizing", /WorkspaceMode:[\s\S]*?never\s+selects or changes the authorization role/],
  ["TOTP challenge requires browser CSRF", /operationId: completeTwoFactorChallenge[\s\S]*?security:\n\s+- csrfToken: \[\]/],
  ["TOTP management requires recent confirmation", /operationId: enableTwoFactorAuthentication[\s\S]*?'423':[\s\S]*?RecentPasswordRequired/],
  ["passkey login options are public", /operationId: getPasskeyLoginOptions[\s\S]*?security: \[\]/],
  ["passkey registration requires session and CSRF", /operationId: registerPasskey[\s\S]*?sanctumSession: \[\][\s\S]*?csrfToken: \[\]/],
  ["session revocation requires session and CSRF", /operationId: revokeOtherSessions[\s\S]*?sanctumSession: \[\][\s\S]*?csrfToken: \[\]/],
  ["passkey labels are bounded at 80 characters", /PasskeyRegistrationInput:[\s\S]*?name:[\s\S]*?maxLength: 80/],
  ["session inventory exposes derived metadata", /BrowserSession:[\s\S]*?device:[\s\S]*?approximate_location:[\s\S]*?created_at:[\s\S]*?last_seen_at:[\s\S]*?current:/],
  ["TOTP recovery material is post-confirmation only", /operationId: getTwoFactorRecoveryCodes[\s\S]*?'404':[\s\S]*?NotFound/],
  ["sensitive identity responses are non-cacheable", /operationId: listSessions[\s\S]*?Cache-Control:[\s\S]*?NoStore[\s\S]*?PragmaNoCache[\s\S]*?ExpiresImmediately/],
];

for (const [label, pattern] of invariants) {
  if (!pattern.test(source)) missing.push(label);
}

const browserSessionBlock = source.slice(
  source.indexOf("    BrowserSession:"),
  source.indexOf("    SessionCollectionEnvelope:"),
);

for (const forbiddenField of ["ip_address", "user_agent", "session_id"]) {
  if (browserSessionBlock.includes(forbiddenField)) {
    missing.push(`BrowserSession leaks ${forbiddenField}`);
  }
}

for (const retiredPath of [
  "/api/v1/invitations/{token}",
  "/api/v1/invitations/{token}/accept",
  "/api/v1/studios/{studio}/invitations/{invitation}/resend",
]) {
  if (source.includes(`  ${retiredPath}:`)) missing.push(`retired source path ${retiredPath}`);
  if (generated.includes(`\"${retiredPath}\":`)) missing.push(`retired generated path ${retiredPath}`);
}

if (missing.length > 0) {
  throw new Error(`Contract audit failed in ${packageRoot}:\n- ${missing.join("\n- ")}`);
}

console.log(`Contract audit passed: ${expectedPaths.length} identity paths, ${expectedOperations.length} operations, and ${expectedSchemas.length} critical schemas.`);
