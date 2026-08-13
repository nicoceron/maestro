<?php

namespace App\TenantData\Support;

use Illuminate\Contracts\Encryption\Encrypter;
use RuntimeException;

final class TenantArchiveCipher
{
    private const MAGIC = "MAESTRO-TENANT-EXPORT\0";

    public function __construct(private readonly Encrypter $encrypter) {}

    public function encrypt(string $plaintext): string
    {
        return self::MAGIC.$this->encrypter->encryptString($plaintext);
    }

    public function decrypt(string $ciphertext): string
    {
        if (! str_starts_with($ciphertext, self::MAGIC)) {
            throw new RuntimeException('The archive envelope is invalid.');
        }

        return $this->encrypter->decryptString(substr($ciphertext, strlen(self::MAGIC)));
    }
}
