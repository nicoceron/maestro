import { afterEach, describe, expect, it, vi } from "vitest";

import { browserWebAuthnCeremony } from "@/lib/auth/webauthn";

function buffer(...bytes: number[]) {
  return new Uint8Array(bytes).buffer;
}

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("native WebAuthn ceremony", () => {
  it("decodes server options and serializes an attestation without token storage", async () => {
    const create = vi.fn(async (options: CredentialCreationOptions) => {
      void options;
      return {
      id: "credential-id",
      rawId: buffer(4, 5),
      type: "public-key",
      authenticatorAttachment: "platform",
      getClientExtensionResults: () => ({}),
      response: {
        clientDataJSON: buffer(6),
        attestationObject: buffer(7, 8),
        getTransports: () => ["internal"],
      },
      };
    });
    vi.stubGlobal("PublicKeyCredential", class PublicKeyCredential {});
    vi.stubGlobal("navigator", {
      credentials: { create, get: vi.fn() },
    });
    const storageWrite = vi.spyOn(Storage.prototype, "setItem");

    const credential = await browserWebAuthnCeremony.create({
      challenge: "AQI",
      rp: { name: "Maestro" },
      user: { id: "Aw", name: "maya@studio.test", displayName: "Maya" },
      pubKeyCredParams: [{ type: "public-key", alg: -7 }],
    });

    const options = create.mock.calls[0][0];
    expect(Array.from(new Uint8Array(options.publicKey!.challenge as ArrayBuffer))).toEqual([1, 2]);
    expect(Array.from(new Uint8Array(options.publicKey!.user.id as ArrayBuffer))).toEqual([3]);
    expect(credential).toMatchObject({
      id: "credential-id",
      rawId: "BAU",
      response: {
        clientDataJSON: "Bg",
        attestationObject: "Bwg",
        transports: ["internal"],
      },
    });
    expect(storageWrite).not.toHaveBeenCalled();
    storageWrite.mockRestore();
  });

  it("serializes an assertion including a nullable user handle", async () => {
    const get = vi.fn(async () => ({
      id: "assertion-id",
      rawId: buffer(1),
      type: "public-key",
      authenticatorAttachment: null,
      getClientExtensionResults: () => ({ credProps: true }),
      response: {
        clientDataJSON: buffer(2),
        authenticatorData: buffer(3),
        signature: buffer(4),
        userHandle: null,
      },
    }));
    vi.stubGlobal("PublicKeyCredential", class PublicKeyCredential {});
    vi.stubGlobal("navigator", {
      credentials: { create: vi.fn(), get },
    });

    const credential = await browserWebAuthnCeremony.get({ challenge: "AQ" });

    expect(credential).toMatchObject({
      rawId: "AQ",
      response: {
        clientDataJSON: "Ag",
        authenticatorData: "Aw",
        signature: "BA",
        userHandle: null,
      },
    });
  });
});
