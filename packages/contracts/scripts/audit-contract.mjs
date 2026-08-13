import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

const packageRoot = fileURLToPath(new URL("../", import.meta.url));
const source = readFileSync(new URL("../openapi.yaml", import.meta.url), "utf8");
const generated = readFileSync(new URL("../generated/schema.d.ts", import.meta.url), "utf8");
const platformOperationsAcceptance = readFileSync(
  new URL("../../../docs/product/platform-operations-acceptance.md", import.meta.url),
  "utf8",
);

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
  "/api/v1/studios",
  "/api/v1/studios/{studio}",
  "/api/v1/studios/{studio}/invitations",
  "/api/v1/studios/{studio}/invitations/{invitation}",
  "/api/v1/studios/{studio}/invitations/{invitation}/resend",
  "/api/v1/studios/{studio}/people",
  "/api/v1/studios/{studio}/people/{person}",
  "/api/v1/studios/{studio}/people/{person}/student-status",
  "/api/v1/studios/{studio}/households",
  "/api/v1/studios/{studio}/households/{household}",
  "/api/v1/public/studios/{studio}/calendar",
  "/api/v1/studios/{studio}/scheduling/{resource}",
  "/api/v1/studios/{studio}/scheduling/{resource}/{record}",
  "/api/v1/studios/{studio}/scheduling/availability-overrides/{record}/approval",
  "/api/v1/studios/{studio}/calendar",
  "/api/v1/studios/{studio}/event-series/previews",
  "/api/v1/studios/{studio}/event-series/previews/{preview}/commit",
  "/api/v1/studios/{studio}/event-series/{series}",
  "/api/v1/studios/{studio}/event-series/{series}/clone/previews",
  "/api/v1/studios/{studio}/event-series/{series}/hold/convert",
  "/api/v1/studios/{studio}/event-series/{series}/hold",
  "/api/v1/studios/{studio}/event-series/{series}/enrollments",
  "/api/v1/studios/{studio}/event-series/{series}/enrollments/previews",
  "/api/v1/studios/{studio}/event-series/{series}/enrollments/{enrollment}/withdraw/previews",
  "/api/v1/studios/{studio}/schedule/enrollment-previews/{preview}/commit",
  "/api/v1/studios/{studio}/schedule/slot-search",
  "/api/v1/studios/{studio}/occurrences/{occurrence}/reschedule/previews",
  "/api/v1/studios/{studio}/occurrences/{occurrence}/cancel/previews",
  "/api/v1/studios/{studio}/occurrences/{occurrence}/restore/previews",
  "/api/v1/studios/{studio}/schedule/previews/{preview}/commit",
  "/api/v1/studios/{studio}/attendance/overdue",
  "/api/v1/studios/{studio}/occurrences/{occurrence}/attendance",
  "/api/v1/studios/{studio}/occurrences/{occurrence}/participants/{participant}/attendance",
  "/api/v1/studios/{studio}/occurrences/{occurrence}/attendance/bulk",
  "/api/v1/studios/{studio}/occurrences/{occurrence}/attendance/express-present",
  "/api/v1/studios/{studio}/occurrences/{occurrence}/notes",
  "/api/v1/studios/{studio}/notes/{note}",
  "/api/v1/studios/{studio}/notes/{note}/attachments",
  "/api/v1/studios/{studio}/notes/{note}/attachments/{attachment}/scan",
  "/api/v1/studios/{studio}/notes/{note}/attachments/{attachment}",
  "/api/v1/studios/{studio}/notes/{note}/attachments/{attachment}/download-url",
  "/api/v1/studios/{studio}/notes/{note}/attachments/{attachment}/download",
  "/api/v1/studios/{studio}/notes/{note}/delivery-previews",
  "/api/v1/studios/{studio}/note-delivery-previews/{preview}/commit",
  "/api/v1/studios/{studio}/note-templates",
  "/api/v1/studios/{studio}/note-templates/{template}",
  "/api/v1/studios/{studio}/data-exports",
  "/api/v1/studios/{studio}/data-exports/{export}",
  "/api/v1/studios/{studio}/data-exports/{export}/download-url",
  "/api/v1/studios/{studio}/data-exports/{export}/download",
  "/api/v1/studios/{studio}/data-exports/{export}/restore-drills",
  "/api/v1/studios/{studio}/restore-drills/{drill}",
  "/api/v1/studios/{studio}/retention-policy",
  "/api/v1/studios/{studio}/retention-policy/legal-hold",
  "/api/v1/studios/{studio}/deletion-requests",
  "/api/v1/studios/{studio}/deletion-requests/{deletion}",
  "/api/v1/studios/{studio}/deletion-requests/{deletion}/approve",
  "/api/v1/studios/{studio}/deletion-requests/{deletion}/cancel",
  "/api/v1/studios/{studio}/deletion-requests/{deletion}/restore",
  "/api/v1/studios/{studio}/audit-events",
  "/api/v1/studios/{studio}/support-access/grants",
  "/api/v1/studios/{studio}/support-access/grants/{grant}/approve",
  "/api/v1/studios/{studio}/support-access/grants/{grant}/reject",
  "/api/v1/studios/{studio}/support-access/grants/{grant}/revoke",
  "/api/v1/platform/support-access/grants",
  "/api/v1/platform/support-access/mfa-confirmation",
  "/api/v1/platform/support-access/requests",
  "/api/v1/platform/support-access/grants/{grant}/sessions",
  "/api/v1/platform/support-access/sessions/{session}",
  "/api/v1/platform/support-access/sessions/{session}/banner",
  "/api/v1/platform/support-access/sessions/{session}/audit-events",
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
  "listStudios",
  "createStudio",
  "getStudio",
  "listStudioInvitations",
  "createStudioInvitation",
  "resendStudioInvitation",
  "revokeStudioInvitation",
  "listPeople",
  "createPerson",
  "getPerson",
  "updatePerson",
  "transitionStudentStatus",
  "listHouseholds",
  "createHousehold",
  "getHousehold",
  "updateHousehold",
  "listPublicCalendarOccurrences",
  "listSchedulingRecords",
  "createSchedulingRecord",
  "getSchedulingRecord",
  "updateSchedulingRecord",
  "decideAvailabilityOverride",
  "listStudioCalendarOccurrences",
  "previewEventSeriesCreation",
  "commitEventSeriesCreation",
  "getEventSeries",
  "previewEventSeriesClone",
  "convertEventSeriesHold",
  "releaseEventSeriesHold",
  "listEventSeriesEnrollments",
  "previewEventEnrollment",
  "previewEventEnrollmentWithdrawal",
  "commitEventEnrollment",
  "searchSchedulingSlots",
  "previewOccurrenceReschedule",
  "previewOccurrenceCancellation",
  "previewOccurrenceRestoration",
  "commitScheduleChange",
  "listOverdueAttendanceOccurrences",
  "listOccurrenceAttendance",
  "recordParticipantAttendance",
  "recordOccurrenceAttendanceBulk",
  "expressPresentOccurrenceAttendance",
  "listOccurrenceLessonNotes",
  "createLessonNote",
  "reviseLessonNote",
  "uploadLessonNoteAttachment",
  "retryLessonNoteAttachmentScan",
  "retireLessonNoteAttachment",
  "createLessonNoteAttachmentDownloadUrl",
  "downloadLessonNoteAttachment",
  "previewLessonNoteDelivery",
  "commitLessonNoteDelivery",
  "listLessonNoteTemplates",
  "createLessonNoteTemplate",
  "updateLessonNoteTemplate",
  "listTenantDataExports",
  "requestTenantDataExport",
  "getTenantDataExport",
  "createTenantDataExportDownloadUrl",
  "downloadTenantDataExport",
  "requestTenantRestoreDrill",
  "getTenantRestoreDrill",
  "getTenantRetentionPolicy",
  "updateTenantRetentionPolicy",
  "placeTenantLegalHold",
  "releaseTenantLegalHold",
  "listTenantDeletionRequests",
  "requestTenantDeletion",
  "getTenantDeletionRequest",
  "approveTenantDeletion",
  "cancelTenantDeletion",
  "restoreTenantDeletion",
  "listTenantAuditEvents",
  "listTenantSupportAccessGrants",
  "approveSupportAccessGrant",
  "rejectSupportAccessGrant",
  "revokeSupportAccessGrant",
  "listPlatformSupportAccessGrants",
  "confirmSupportAccessMfa",
  "requestSupportAccess",
  "startSupportAccessSession",
  "endSupportAccessSession",
  "getSupportAccessSessionBanner",
  "listSupportSessionAuditEvents",
];

const expectedSchemas = [
  "CurrentUser",
  "TwoFactorChallengeInput",
  "TwoFactorRecoveryCodes",
  "WebAuthnCredential",
  "Passkey",
  "BrowserSession",
  "RegistrationAcceptedResult",
  "InvitationTokenInput",
  "InvitationPreview",
  "OnboardingInput",
  "StudioInvitation",
  "InvitationDeliveryStatus",
  "StudioInvitationPermissions",
  "StudioInvitationCollectionCapabilities",
  "StudioInvitationResentEnvelope",
  "Person",
  "PersonStudentProfile",
  "PersonStaffProfile",
  "PersonInstrument",
  "PersonTag",
  "PersonCustomFieldValue",
  "StudentStatusTransition",
  "CreatePersonInput",
  "UpdatePersonInput",
  "TransitionStudentStatusInput",
  "PersonPaginatedCollectionEnvelope",
  "UpdateHouseholdInput",
  "UpdateHouseholdMemberInput",
  "WorkspaceMode",
  "SchedulingRecord",
  "CreateSchedulingRecordInput",
  "UpdateSchedulingRecordInput",
  "PreviewEventSeriesInput",
  "PreviewScheduleChangeInput",
  "ScheduleChangePreview",
  "ScheduleProjectionIntent",
  "EventSeries",
  "EventOccurrence",
  "EventEnrollment",
  "SlotSearchResult",
  "EventOccurrenceCursorCollectionEnvelope",
  "LaravelCursorPaginationMeta",
  "AttendanceRecord",
  "RecordAttendanceInput",
  "RecordAttendanceBulkInput",
  "LessonNote",
  "CreateLessonNoteInput",
  "LessonNoteTemplate",
  "LessonNoteDeliveryPreview",
  "LessonNoteDeliveryIntent",
  "LessonNoteAttachment",
  "UploadLessonNoteAttachmentInput",
  "LessonNoteAttachmentDownloadUrl",
  "TypedConflictProblem",
  "TenantAuditEvent",
  "TenantAuditEventCursorCollectionEnvelope",
  "SupportAccessGrant",
  "RequestSupportAccessInput",
  "SupportMfaConfirmationInput",
  "SupportMfaConfirmationResult",
  "SupportSessionBanner",
  "SupportSessionStarted",
  "SupportAccessProblem",
  "TenantDataExport",
  "TenantDataExportManifest",
  "TenantExportDatasetManifest",
  "TenantDataExportDownloadUrl",
  "TenantRestoreDrill",
  "TenantRestoreVerification",
  "TenantRetentionPolicy",
  "UpdateTenantRetentionPolicyInput",
  "TenantDeletionRequest",
  "RequestTenantDeletionInput",
  "TenantDataConflictProblem",
  "HighRiskConfirmationProblem",
];

const expectedAcceptanceIds = {
  register: ["IDA-REG-001", "REGISTER-E001", "REGISTER-E002", "REGISTER-E003", "REGISTER-E004", "REGISTER-E005"],
  completeTwoFactorChallenge: ["IDA-MFA-002", "AUTH-E009"],
  enableTwoFactorAuthentication: ["IDA-MFA-001", "MFA-E001"],
  getTwoFactorQrCode: ["MFA-E006"],
  confirmSupportAccessMfa: ["OPS-SUP-002", "OPS-SUP-007"],
  registerPasskey: ["IDA-PASSKEY-002", "PASS-E005"],
  listPasskeys: ["PASS-E008"],
  listSessions: ["IDA-SESSION-002", "AUTH-E017"],
  revokeOtherSessions: ["AUTH-E013"],
  revokeSession: ["AUTH-E012", "AUTH-E018"],
  createStudioInvitation: ["IDA-STEPUP-001", "INV-E001"],
  resendStudioInvitation: ["IDA-STEPUP-001", "IDA-INVITE-001", "INV-E005", "INV-E008", "INV-E018", "JOB-001", "JOB-002"],
  requestTenantDataExport: ["OPS-EXP-001", "OPS-EXP-002", "OPS-EXP-004", "OPS-EXP-005"],
  downloadTenantDataExport: ["OPS-EXP-005", "OPS-EXP-006"],
  requestTenantRestoreDrill: ["OPS-RST-001", "OPS-RST-002", "OPS-RST-004"],
  updateTenantRetentionPolicy: ["OPS-RET-001"],
  placeTenantLegalHold: ["OPS-RET-003", "OPS-RET-004"],
  requestTenantDeletion: ["OPS-DEL-001", "OPS-DEL-002", "OPS-RET-003"],
  approveTenantDeletion: ["OPS-DEL-001", "OPS-DEL-002", "OPS-DEL-003"],
  listTenantAuditEvents: ["OPS-AUD-001", "OPS-AUD-005", "OPS-AUD-006"],
  approveSupportAccessGrant: ["OPS-SUP-001", "OPS-SUP-002", "OPS-SUP-004", "OPS-SUP-005"],
  requestSupportAccess: ["OPS-SUP-001", "OPS-SUP-002", "OPS-SUP-004", "OPS-SUP-005", "OPS-SUP-007"],
  startSupportAccessSession: ["OPS-SUP-002", "OPS-SUP-003", "OPS-SUP-005", "OPS-SUP-007"],
  getSupportAccessSessionBanner: ["OPS-SUP-002", "OPS-SUP-003", "OPS-SUP-006", "OPS-SUP-007"],
};

const expectedTypedConflictCodes = [
  "idempotency_key_reused",
  "idempotency_claim_incomplete",
  "preview_consumed",
  "preview_expired",
  "preview_command_mismatch",
  "preview_envelope_mismatch",
  "hard_scheduling_conflict",
  "soft_warning_unacknowledged",
  "soft_warnings_changed",
  "schedule_changed_after_preview",
  "participant_capacity",
  "note_changed_after_preview",
  "recipients_changed_after_preview",
  "attachments_changed_after_preview",
];

const expectedPlatformOperationsAcceptanceIds = [
  ...Array.from({ length: 6 }, (_, index) => `OPS-AUD-${String(index + 1).padStart(3, "0")}`),
  ...Array.from({ length: 8 }, (_, index) => `OPS-SUP-${String(index + 1).padStart(3, "0")}`),
  ...Array.from({ length: 9 }, (_, index) => `OPS-OUT-${String(index + 1).padStart(3, "0")}`),
  ...Array.from({ length: 7 }, (_, index) => `OPS-EXP-${String(index + 1).padStart(3, "0")}`),
  ...Array.from({ length: 4 }, (_, index) => `OPS-RET-${String(index + 1).padStart(3, "0")}`),
  ...Array.from({ length: 4 }, (_, index) => `OPS-DEL-${String(index + 1).padStart(3, "0")}`),
  ...Array.from({ length: 7 }, (_, index) => `OPS-RST-${String(index + 1).padStart(3, "0")}`),
];

const missing = [];

for (const id of expectedPlatformOperationsAcceptanceIds) {
  const occurrences = platformOperationsAcceptance.split(`\`${id}\``).length - 1;

  if (occurrences !== 1) {
    missing.push(`platform operations acceptance ID ${id} appears ${occurrences} times instead of once`);
  }
}

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

const typedConflictBlock = source.slice(
  source.indexOf("    TypedConflictProblem:"),
  source.indexOf("    Problem:"),
);

for (const code of expectedTypedConflictCodes) {
  if (!typedConflictBlock.includes(`- ${code}`)) missing.push(`typed conflict code ${code}`);
  if (!generated.includes(`"${code}"`)) missing.push(`generated typed conflict code ${code}`);
}

const invariants = [
  ["CSRF bootstrap is public", /\/sanctum\/csrf-cookie:[\s\S]*?operationId: initializeCsrf[\s\S]*?security: \[\]/],
  ["login requires the CSRF scheme", /operationId: login[\s\S]*?security:\n\s+- csrfToken: \[\]/],
  ["registration requires CSRF and stays unauthenticated", /operationId: register[\s\S]*?security:\n\s+- csrfToken: \[\][\s\S]*?'202':[\s\S]*?RegistrationAcceptedResult/],
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
  ["invitation resend requires session, CSRF, recent confirmation, and returns a replacement", /operationId: resendStudioInvitation[\s\S]*?sanctumSession: \[\][\s\S]*?csrfToken: \[\][\s\S]*?'202':[\s\S]*?StudioInvitationResentEnvelope[\s\S]*?'423':[\s\S]*?RecentPasswordRequired/],
  ["invitation list exposes server-derived capabilities", /StudioInvitationPaginatedCollectionEnvelope:[\s\S]*?capabilities:[\s\S]*?StudioInvitationCollectionCapabilities/],
  ["invitation resources expose server-derived actions", /StudioInvitation:[\s\S]*?resend_available_at:[\s\S]*?delivery_status:[\s\S]*?permissions:[\s\S]*?StudioInvitationPermissions/],
  ["people collection exposes server-derived create capability", /PersonPaginatedCollectionEnvelope:[\s\S]*?capabilities:[\s\S]*?PersonCollectionCapabilities/],
  ["person mutation requires Sanctum and CSRF", /operationId: updatePerson[\s\S]*?sanctumSession: \[\][\s\S]*?csrfToken: \[\]/],
  ["student transition requires Sanctum and CSRF", /operationId: transitionStudentStatus[\s\S]*?sanctumSession: \[\][\s\S]*?csrfToken: \[\]/],
  ["person update and transition both require optimistic versions", /UpdatePersonInput:[\s\S]*?required:[\s\S]*?- version[\s\S]*?TransitionStudentStatusInput:[\s\S]*?required:[\s\S]*?- version/],
  ["student left date is response-only", /PersonStudentProfile:[\s\S]*?left_on:[\s\S]*?PersonStudentInput:(?:(?!left_on)[\s\S])*?CreatePersonStudentInput:/],
  ["person resource keeps privacy-null fields present", /Person:[\s\S]*?required:[\s\S]*?- email[\s\S]*?- phone[\s\S]*?- birth_date[\s\S]*?- pronouns[\s\S]*?- source[\s\S]*?- external_reference[\s\S]*?- preferred_locale/],
  ["student history is typed and bounded", /student_status_history:[\s\S]*?maxItems: 100[\s\S]*?StudentStatusTransition/],
  ["initial student history has an explicit null predecessor", /StudentStatusTransition:[\s\S]*?previous_status:[\s\S]*?initial profile-created event[\s\S]*?type: 'null'/],
  ["billing-private people filters are explicitly forbidden", /Billing callers may use `q`, `status`, and pagination; a syntactically valid[\s\S]*?returns `403`/],
  ["person lookup is route-tenant scoped", /\/people\/\{person\}:[\s\S]*?cross-tenant ULID[\s\S]*?returns `404`[\s\S]*?operationId: getPerson/],
  ["household aggregate update requires optimistic versions and CSRF", /operationId: updateHousehold[\s\S]*?sanctumSession: \[\][\s\S]*?csrfToken: \[\][\s\S]*?UpdateHouseholdInput/],
  ["household members expose person versions for optimistic edits", /HouseholdPerson:[\s\S]*?required:[\s\S]*?- version[\s\S]*?Current person version required/],
  ["scheduling configuration mutation requires session and CSRF", /operationId: updateSchedulingRecord[\s\S]*?sanctumSession: \[\][\s\S]*?csrfToken: \[\]/],
  ["scheduling configuration retires instead of deleting", /\/scheduling\/\{resource\}\/\{record\}:[\s\S]*?no delete operation[\s\S]*?operationId: updateSchedulingRecord/],
  ["availability approval requires an optimistic version", /AvailabilityOverrideDecisionInput:[\s\S]*?required: \[version, approval_status\]/],
  ["calendar range is bounded", /\/studios\/\{studio\}\/calendar:[\s\S]*?range is limited to 93 days[\s\S]*?operationId: listStudioCalendarOccurrences/],
  ["calendar uses bounded cursor pagination without truncation", /operationId: listStudioCalendarOccurrences[\s\S]*?CalendarPageSize[\s\S]*?CalendarCursor[\s\S]*?EventOccurrenceCursorCollectionEnvelope/],
  ["teacher calendar filters are self-scoped", /\/studios\/\{studio\}\/calendar:[\s\S]*?filter only linked staff profiles[\s\S]*?operationId: listStudioCalendarOccurrences/],
  ["schedule commits require idempotency", /operationId: commitScheduleChange[\s\S]*?IdempotencyKey/],
  ["schedule commit bodies cannot replace preview commands", /EmptyCommitInput:[\s\S]*?cannot replace the command or warning acknowledgement/],
  ["schedule previews expose explicit projection intents", /SchedulePreviewImpact:[\s\S]*?projection_intents:[\s\S]*?ScheduleProjectionIntent/],
  ["future schedule edits carry recurrence shape", /PreviewScheduleChangeInput:[\s\S]*?dtstart_local:[\s\S]*?rrule:[\s\S]*?rdates:[\s\S]*?exdates:/],
  ["schedule preview hard conflicts are never overrideable", /SchedulePreviewCapabilities:[\s\S]*?hard_conflicts_overrideable:[\s\S]*?const: false/],
  ["schedule previews expose no actor tenant or raw command", /ScheduleChangePreview:[\s\S]*?required: \[id, command_type, scope, status, impact, conflicts, soft_warnings_acknowledged, expires_at, capabilities\]/],
  ["attendance dispositions are server-owned", /RecordAttendanceInput:[\s\S]*?additionalProperties: false(?:(?!billing_disposition|makeup_disposition)[\s\S])*?RecordAttendanceBulkItemInput:/],
  ["bulk attendance is bounded and atomic by contract", /\/attendance\/bulk:[\s\S]*?complete batch[\s\S]*?operationId: recordOccurrenceAttendanceBulk[\s\S]*?RecordAttendanceBulkInput/],
  ["billing attendance projection is redacted", /AttendanceRecord:[\s\S]*?person_id:[\s\S]*?Null in the billing projection[\s\S]*?makeup_disposition:[\s\S]*?Null in the billing projection/],
  ["note HTML is explicitly sanitized", /CreateLessonNoteInput:[\s\S]*?Sanitized to the server allowlist/],
  ["lesson note revisions are optimistic", /UpdateLessonNoteInput:[\s\S]*?required: \[version\]/],
  ["note delivery binds clean attachment metadata", /LessonNoteDeliveryPreview:[\s\S]*?attachments:[\s\S]*?LessonNoteDeliveryAttachment/],
  ["attachment upload is private quarantine", /\/notes\/\{note\}\/attachments:[\s\S]*?private quarantine[\s\S]*?operationId: uploadLessonNoteAttachment[\s\S]*?multipart\/form-data/],
  ["attachment upload is versioned idempotent and asynchronous", /operationId: uploadLessonNoteAttachment[\s\S]*?IdempotencyKey[\s\S]*?UploadLessonNoteAttachmentInput[\s\S]*?'202':/],
  ["attachment upload requires the note version and binary file", /UploadLessonNoteAttachmentInput:[\s\S]*?required: \[note_version, file\][\s\S]*?format: binary/],
  ["attachment downloads require signed recent-confirmation reauthorization", /operationId: downloadLessonNoteAttachment[\s\S]*?signature[\s\S]*?'423':[\s\S]*?RecentPasswordRequired/],
  ["attachment byte responses are length-bound no-store and sandboxed", /operationId: downloadLessonNoteAttachment[\s\S]*?Cache-Control:[\s\S]*?AttachmentNoStore[\s\S]*?Content-Disposition:[\s\S]*?AttachmentDisposition[\s\S]*?Content-Length:[\s\S]*?AttachmentContentLength[\s\S]*?Content-Security-Policy:[\s\S]*?sandbox/],
  ["unsafe attachment states are nondownloadable", /\/download-url:[\s\S]*?Pending, failed, infected, retired[\s\S]*?return `404`[\s\S]*?operationId: createLessonNoteAttachmentDownloadUrl/],
  ["tenant export creation requires session CSRF idempotency and high-risk confirmation", /operationId: requestTenantDataExport[\s\S]*?sanctumSession: \[\][\s\S]*?csrfToken: \[\][\s\S]*?IdempotencyKey[\s\S]*?'423':[\s\S]*?HighRiskConfirmationRequired/],
  ["tenant export download is signed reauthorized one-use and no-store", /\/data-exports\/\{export\}\/download:[\s\S]*?current-session MFA[\s\S]*?one-use[\s\S]*?operationId: downloadTenantDataExport[\s\S]*?signature[\s\S]*?Cache-Control:[\s\S]*?NoStore[\s\S]*?TenantExportDisposition/],
  ["tenant export manifest declares schema checksum and excluded secrets", /TenantDataExportManifest:[\s\S]*?snapshot_boundary[\s\S]*?excluded_secret_fields[\s\S]*?datasets[\s\S]*?manifest_sha256/],
  ["tenant export datasets declare dependencies counts bytes and checksums", /TenantExportDatasetManifest:[\s\S]*?dependencies[\s\S]*?count[\s\S]*?sha256[\s\S]*?bytes/],
  ["tenant deletion is explicitly cooling-off and non-immediate", /\/deletion-requests:[\s\S]*?14-day[\s\S]*?No tenant data is deleted by this endpoint[\s\S]*?operationId: requestTenantDeletion/],
  ["restore drill is explicitly dry-run and non-writing", /\/restore-drills:[\s\S]*?non-writing[\s\S]*?does not[\s\S]*?import, activate, merge, or overwrite[\s\S]*?operationId: requestTenantRestoreDrill/],
  ["support grants expose only read scopes", /SupportAccessScope:[\s\S]*?audit\.read[\s\S]*?configuration\.read[\s\S]*?people\.read[\s\S]*?schedule\.read[\s\S]*?diagnostics\.read[\s\S]*?Export, credential, billing-secret, legal-hold, deletion, restore, ownership, and arbitrary write scopes do not exist/],
  ["support MFA is current-session proof with recent identity and CSRF", /\/platform\/support-access\/mfa-confirmation:[\s\S]*?current browser session for ten minutes[\s\S]*?recent[\s\S]*?password or passkey[\s\S]*?operationId: confirmSupportAccessMfa[\s\S]*?sanctumSession: \[\][\s\S]*?csrfToken: \[\][\s\S]*?support_mfa_invalid/],
  ["support request is idempotent stepped-up and never enters tenant", /\/platform\/support-access\/requests:[\s\S]*?current-session MFA[\s\S]*?Idempotency-Key[\s\S]*?does not enter the tenant[\s\S]*?operationId: requestSupportAccess/],
  ["support session bearer is response-once and combined with operator session", /\/platform\/support-access\/grants\/\{grant\}\/sessions:[\s\S]*?returned exactly once[\s\S]*?X-Maestro-Support-Session[\s\S]*?operationId: startSupportAccessSession[\s\S]*?SupportSessionStartedEnvelope/],
  ["support audit requires both operator and support-session credentials", /operationId: listSupportSessionAuditEvents[\s\S]*?sanctumSession: \[\][\s\S]*?supportSession: \[\]/],
  ["support banner communicates visible delegated mode", /SupportSessionBanner:[\s\S]*?Support access is active\. Every view is scoped, visible, and audited\./],
];

for (const [label, pattern] of invariants) {
  if (!pattern.test(source)) missing.push(label);
}

const registrationBlock = source.slice(
  source.indexOf("  /api/v1/auth/register:"),
  source.indexOf("  /api/v1/auth/logout:"),
);

if (registrationBlock.includes("'201':")) {
  missing.push("registration still advertises authenticated 201");
}

for (const requiredText of [
  "same generic `202`",
  "never authenticates the browser",
  "without mutation or notification",
]) {
  if (!registrationBlock.includes(requiredText)) {
    missing.push(`registration privacy rule: ${requiredText}`);
  }
}

const passwordBlock = source.slice(
  source.indexOf("    StrongPassword:"),
  source.indexOf("    LoginInput:"),
);

if (passwordBlock.includes("pattern:")) {
  missing.push("StrongPassword encodes a character-composition rule");
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

const invitationResourceBlock = source.slice(
  source.indexOf("    StudioInvitation:"),
  source.indexOf("    StudioInvitationPermissions:"),
);

for (const forbiddenField of [
  "token_hash",
  "pending_key",
  "plaintext_token",
  "outbox_id",
  "global_user_id",
  "lineage_id",
  "delivery_version",
  "previous_invitation_id",
  "superseded_by_id",
]) {
  if (invitationResourceBlock.includes(forbiddenField)) {
    missing.push(`StudioInvitation leaks ${forbiddenField}`);
  }
}

const personResourceBlock = source.slice(
  source.indexOf("    Person:"),
  source.indexOf("    PersonCollectionCapabilities:"),
);

for (const forbiddenField of [
  "studio_id",
  "user_id",
  "actor_id",
  "student_profile_id",
  "person_id",
  "deleted_at",
  "normalized_name",
  "sort_order",
  "definition_options",
]) {
  if (personResourceBlock.includes(forbiddenField)) {
    missing.push(`Person leaks internal field ${forbiddenField}`);
  }
}

for (const forbiddenField of ["studio_id", "actor_id", "person_id", "student_profile_id"]) {
  const historyBlock = source.slice(
    source.indexOf("    StudentStatusTransition:"),
    source.indexOf("    PersonPermissions:"),
  );
  if (historyBlock.includes(forbiddenField)) {
    missing.push(`StudentStatusTransition leaks internal field ${forbiddenField}`);
  }
}

const householdResourceBlock = source.slice(
  source.indexOf("    Household:"),
  source.indexOf("    CreateStudentProfileInput:"),
);

for (const forbiddenField of ["studio_id", "user_id", "actor_id", "deleted_at"]) {
  if (householdResourceBlock.includes(forbiddenField)) {
    missing.push(`Household leaks internal field ${forbiddenField}`);
  }
}

const schedulePreviewBlock = source.slice(
  source.indexOf("    ScheduleChangePreview:"),
  source.indexOf("    ScheduleChangePreviewEnvelope:"),
);

for (const forbiddenField of ["studio_id", "actor_id", "command", "command_hash", "aggregate_versions", "consumed_at"]) {
  if (new RegExp(`\\n\\s+${forbiddenField}:`).test(schedulePreviewBlock)) {
    missing.push(`ScheduleChangePreview leaks internal field ${forbiddenField}`);
  }
}

const attendanceResourceBlock = source.slice(
  source.indexOf("    AttendanceRecord:"),
  source.indexOf("    AttendanceRecordEnvelope:"),
);

for (const forbiddenField of ["studio_id", "actor_id", "recorded_by", "corrected_by", "idempotency_key"]) {
  if (attendanceResourceBlock.includes(forbiddenField)) {
    missing.push(`AttendanceRecord leaks internal field ${forbiddenField}`);
  }
}

const attachmentResourceBlock = source.slice(
  source.indexOf("    LessonNoteAttachment:"),
  source.indexOf("    LessonNoteAttachmentEnvelope:"),
);

for (const forbiddenField of ["studio_id", "actor_id", "disk", "key", "quarantine", "clean_key", "engine", "retired_by"]) {
  if (attachmentResourceBlock.includes(forbiddenField)) {
    missing.push(`LessonNoteAttachment leaks internal field ${forbiddenField}`);
  }
}

const tenantDataExportResourceBlock = source.slice(
  source.indexOf("    TenantDataExport:"),
  source.indexOf("    TenantDataExportEnvelope:"),
);

for (const forbiddenField of [
  "studio_id", "requested_by_id", "idempotency_key", "request_fingerprint",
  "archive_path", "archive_sha256", "archive_ciphertext_sha256", "failure_digest", "storage_key", "queue_id",
]) {
  if (new RegExp(`\\n\\s+${forbiddenField}:`).test(tenantDataExportResourceBlock)) {
    missing.push(`TenantDataExport leaks internal field ${forbiddenField}`);
  }
}

const tenantAuditResourceBlock = source.slice(
  source.indexOf("    TenantAuditEvent:"),
  source.indexOf("    TenantAuditEventCursorCollectionEnvelope:"),
);

for (const forbiddenField of [
  "studio_id", "actor_user_id", "support_session_id", "request_id", "request_method",
  "request_ip_hash", "user_agent_hash", "previous_hash", "integrity_hash", "stream_sequence",
]) {
  if (new RegExp(`\\n\\s+${forbiddenField}:`).test(tenantAuditResourceBlock)) {
    missing.push(`TenantAuditEvent leaks internal field ${forbiddenField}`);
  }
}

const supportGrantResourceBlock = source.slice(
  source.indexOf("    SupportAccessGrant:"),
  source.indexOf("    SupportAccessGrantEnvelope:"),
);

for (const forbiddenField of [
  "requested_by_user_id", "approved_by_user_id", "revoked_by_user_id", "idempotency_key",
  "request_id", "correlation_id", "token", "token_hash",
]) {
  if (new RegExp(`\\n\\s+${forbiddenField}:`).test(supportGrantResourceBlock)) {
    missing.push(`SupportAccessGrant leaks internal field ${forbiddenField}`);
  }
}

const supportBannerResourceBlock = source.slice(
  source.indexOf("    SupportSessionBanner:"),
  source.indexOf("    SupportSessionBannerEnvelope:"),
);

for (const forbiddenField of ["access_token", "token_hash", "support_user_id", "approved_by_user_id", "recent_auth_at", "mfa_verified_at"]) {
  if (new RegExp(`\\n\\s+${forbiddenField}:`).test(supportBannerResourceBlock)) {
    missing.push(`SupportSessionBanner leaks internal field ${forbiddenField}`);
  }
}

const publicOccurrenceBlock = source.slice(
  source.indexOf("    PublicEventOccurrence:"),
  source.indexOf("    PublicEventOccurrenceCursorCollectionEnvelope:"),
);

for (const forbiddenField of ["studio_id", "series_id", "person_id", "staff_profile_id", "price_minor", "internal_description", "online_join_url"]) {
  if (new RegExp(`\\n\\s+${forbiddenField}:`).test(publicOccurrenceBlock)) {
    missing.push(`PublicEventOccurrence leaks private field ${forbiddenField}`);
  }
}

const personPathBlock = source.slice(
  source.indexOf("  /api/v1/studios/{studio}/people/{person}:"),
  source.indexOf("  /api/v1/studios/{studio}/people/{person}/student-status:"),
);

if (personPathBlock.includes("    put:")) {
  missing.push("person update advertises PUT despite PATCH-only partial semantics");
}

for (const retiredPath of [
  "/api/v1/invitations/{token}",
  "/api/v1/invitations/{token}/accept",
]) {
  if (source.includes(`  ${retiredPath}:`)) missing.push(`retired source path ${retiredPath}`);
  if (generated.includes(`\"${retiredPath}\":`)) missing.push(`retired generated path ${retiredPath}`);
}

if (missing.length > 0) {
  throw new Error(`Contract audit failed in ${packageRoot}:\n- ${missing.join("\n- ")}`);
}

console.log(`Contract audit passed: ${expectedPaths.length} audited paths, ${expectedOperations.length} operations, and ${expectedSchemas.length} critical schemas.`);
