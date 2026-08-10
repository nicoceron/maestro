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
  "previewInvitation",
  "acceptInvitation",
  "completeOnboarding",
  "listStudioInvitations",
  "createStudioInvitation",
  "revokeStudioInvitation",
];

const expectedSchemas = [
  "CurrentUser",
  "InvitationTokenInput",
  "InvitationPreview",
  "OnboardingInput",
  "StudioInvitation",
  "WorkspaceMode",
];

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

const invariants = [
  ["CSRF bootstrap is public", /\/sanctum\/csrf-cookie:[\s\S]*?operationId: initializeCsrf[\s\S]*?security: \[\]/],
  ["login requires the CSRF scheme", /operationId: login[\s\S]*?security:\n\s+- csrfToken: \[\]/],
  ["invitation preview is public", /operationId: previewInvitation[\s\S]*?security: \[\]/],
  ["invitation acceptance requires session and CSRF", /operationId: acceptInvitation[\s\S]*?sanctumSession: \[\][\s\S]*?csrfToken: \[\]/],
  ["invitation preview takes the bearer in JSON", /\/api\/v1\/invitations\/preview:[\s\S]*?requestBody:[\s\S]*?InvitationTokenInput/],
  ["invitation acceptance takes the bearer in JSON", /\/api\/v1\/invitations\/accept:[\s\S]*?requestBody:[\s\S]*?InvitationTokenInput/],
  ["workspace mode is documented as non-authorizing", /WorkspaceMode:[\s\S]*?never\s+selects or changes the authorization role/],
];

for (const [label, pattern] of invariants) {
  if (!pattern.test(source)) missing.push(label);
}

for (const retiredPath of ["/api/v1/invitations/{token}", "/api/v1/invitations/{token}/accept"]) {
  if (source.includes(`  ${retiredPath}:`)) missing.push(`retired source path ${retiredPath}`);
  if (generated.includes(`\"${retiredPath}\":`)) missing.push(`retired generated path ${retiredPath}`);
}

if (missing.length > 0) {
  throw new Error(`Contract audit failed in ${packageRoot}:\n- ${missing.join("\n- ")}`);
}

console.log(`Contract audit passed: ${expectedPaths.length} identity paths, ${expectedOperations.length} operations, and ${expectedSchemas.length} critical schemas.`);
