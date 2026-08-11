import type {
  WebAuthnCredentialDto,
  WebAuthnOptionsDto,
} from "@/lib/auth/auth-client";

export interface WebAuthnCeremony {
  isSupported(): boolean;
  create(options: WebAuthnOptionsDto): Promise<WebAuthnCredentialDto>;
  get(options: WebAuthnOptionsDto): Promise<WebAuthnCredentialDto>;
}

function base64UrlToBuffer(value: unknown): ArrayBuffer {
  if (typeof value !== "string" || !value) {
    throw new Error("The server returned invalid passkey options.");
  }
  const base64 = value.replace(/-/g, "+").replace(/_/g, "/");
  const padded = base64.padEnd(Math.ceil(base64.length / 4) * 4, "=");
  const binary = window.atob(padded);
  const bytes = Uint8Array.from(binary, (character) => character.charCodeAt(0));
  return bytes.buffer as ArrayBuffer;
}

function bufferToBase64Url(value: ArrayBuffer | null) {
  if (!value) return null;
  const bytes = new Uint8Array(value);
  let binary = "";
  for (const byte of bytes) binary += String.fromCharCode(byte);
  return window
    .btoa(binary)
    .replace(/\+/g, "-")
    .replace(/\//g, "_")
    .replace(/=+$/g, "");
}

function registrationOptions(
  value: WebAuthnOptionsDto,
): PublicKeyCredentialCreationOptions {
  const options = { ...value } as Record<string, unknown>;
  const user =
    options.user && typeof options.user === "object"
      ? { ...(options.user as Record<string, unknown>) }
      : null;
  if (!user) throw new Error("The server returned invalid passkey options.");
  user.id = base64UrlToBuffer(user.id);
  const excludeCredentials = Array.isArray(options.excludeCredentials)
    ? options.excludeCredentials.map((credential) => {
        const descriptor = { ...(credential as Record<string, unknown>) };
        descriptor.id = base64UrlToBuffer(descriptor.id);
        return descriptor;
      })
    : undefined;
  return {
    ...options,
    challenge: base64UrlToBuffer(options.challenge),
    user,
    ...(excludeCredentials ? { excludeCredentials } : {}),
  } as unknown as PublicKeyCredentialCreationOptions;
}

function requestOptions(
  value: WebAuthnOptionsDto,
): PublicKeyCredentialRequestOptions {
  const options = { ...value } as Record<string, unknown>;
  const allowCredentials = Array.isArray(options.allowCredentials)
    ? options.allowCredentials.map((credential) => {
        const descriptor = { ...(credential as Record<string, unknown>) };
        descriptor.id = base64UrlToBuffer(descriptor.id);
        return descriptor;
      })
    : undefined;
  return {
    ...options,
    challenge: base64UrlToBuffer(options.challenge),
    ...(allowCredentials ? { allowCredentials } : {}),
  } as PublicKeyCredentialRequestOptions;
}

function credentialToJson(credential: Credential | null): WebAuthnCredentialDto {
  if (!credential || !("rawId" in credential) || !("response" in credential)) {
    throw new Error("The passkey request did not return a credential.");
  }

  const publicKey = credential as PublicKeyCredential;
  const response = publicKey.response;
  const base = {
    id: publicKey.id,
    rawId: bufferToBase64Url(publicKey.rawId),
    type: publicKey.type,
    authenticatorAttachment: publicKey.authenticatorAttachment,
    clientExtensionResults: publicKey.getClientExtensionResults(),
  };

  if ("attestationObject" in response) {
    const attestation = response as AuthenticatorAttestationResponse;
    return {
      ...base,
      response: {
        clientDataJSON: bufferToBase64Url(attestation.clientDataJSON),
        attestationObject: bufferToBase64Url(attestation.attestationObject),
        transports:
          typeof attestation.getTransports === "function"
            ? attestation.getTransports()
            : undefined,
      },
    };
  }

  const assertion = response as AuthenticatorAssertionResponse;
  return {
    ...base,
    response: {
      clientDataJSON: bufferToBase64Url(assertion.clientDataJSON),
      authenticatorData: bufferToBase64Url(assertion.authenticatorData),
      signature: bufferToBase64Url(assertion.signature),
      userHandle: bufferToBase64Url(assertion.userHandle),
    },
  };
}

export const browserWebAuthnCeremony: WebAuthnCeremony = {
  isSupported() {
    return (
      typeof window !== "undefined" &&
      typeof window.PublicKeyCredential !== "undefined" &&
      Boolean(navigator.credentials)
    );
  },
  async create(options) {
    if (!this.isSupported()) {
      throw new Error("Passkeys are not supported in this browser.");
    }
    return credentialToJson(
      await navigator.credentials.create({ publicKey: registrationOptions(options) }),
    );
  },
  async get(options) {
    if (!this.isSupported()) {
      throw new Error("Passkeys are not supported in this browser.");
    }
    return credentialToJson(
      await navigator.credentials.get({ publicKey: requestOptions(options) }),
    );
  },
};

export function webAuthnErrorMessage(error: unknown) {
  if (error instanceof DOMException && error.name === "NotAllowedError") {
    return "The passkey request was canceled or timed out. Try again when you’re ready.";
  }
  return error instanceof Error
    ? error.message
    : "The passkey request could not be completed.";
}
