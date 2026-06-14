<?php

declare(strict_types=1);

namespace App\Services\Ksef;

use phpseclib3\Crypt\RSA;
use phpseclib3\File\X509;
use RuntimeException;

final class KsefCryptoService
{
    /**
     * @return array{cipher_key:string,cipher_iv:string,encrypted_symmetric_key:string,initialization_vector:string,public_key_id:string}
     */
    public function encryptionData(string $certificateBase64, string $publicKeyId): array
    {
        $cipherKey = random_bytes(32);
        $cipherIv = random_bytes(16);

        return [
            'cipher_key' => $cipherKey,
            'cipher_iv' => $cipherIv,
            'encrypted_symmetric_key' => base64_encode($this->encryptRsaOaepSha256($cipherKey, $certificateBase64)),
            'initialization_vector' => base64_encode($cipherIv),
            'public_key_id' => $publicKeyId,
        ];
    }

    public function encryptKsefToken(string $token, int|string $timestampMs, string $certificateBase64): string
    {
        $payload = $token.'|'.$timestampMs;

        return base64_encode($this->encryptRsaOaepSha256($payload, $certificateBase64));
    }

    /**
     * @param  array{cipher_key:string,cipher_iv:string}  $encryptionData
     * @return array{content:string,hash:string,size:int}
     */
    public function encryptInvoice(string $xml, array $encryptionData): array
    {
        $encrypted = openssl_encrypt(
            $xml,
            'aes-256-cbc',
            $encryptionData['cipher_key'],
            OPENSSL_RAW_DATA,
            $encryptionData['cipher_iv'],
        );

        if ($encrypted === false) {
            throw new RuntimeException('Nie udało się zaszyfrować XML faktury algorytmem AES-256-CBC.');
        }

        return [
            'content' => base64_encode($encrypted),
            'hash' => $this->sha256Base64($encrypted),
            'size' => strlen($encrypted),
        ];
    }

    /**
     * @return array{hash:string,size:int}
     */
    public function metadata(string $contents): array
    {
        return [
            'hash' => $this->sha256Base64($contents),
            'size' => strlen($contents),
        ];
    }

    public function sha256Base64(string $contents): string
    {
        return base64_encode(hash('sha256', $contents, true));
    }

    private function encryptRsaOaepSha256(string $payload, string $certificateBase64): string
    {
        $certificate = base64_decode($certificateBase64, true);

        if ($certificate === false || $certificate === '') {
            throw new RuntimeException('Nieprawidłowy certyfikat klucza publicznego KSeF.');
        }

        $pem = "-----BEGIN CERTIFICATE-----\n"
            .chunk_split(base64_encode($certificate), 64, "\n")
            ."-----END CERTIFICATE-----\n";

        $x509 = new X509;
        $x509->loadX509($pem);
        $publicKey = $x509->getPublicKey();

        if (! $publicKey instanceof RSA\PublicKey) {
            throw new RuntimeException('Certyfikat KSeF nie zawiera klucza publicznego RSA.');
        }

        return $publicKey
            ->withPadding(RSA::ENCRYPTION_OAEP)
            ->withHash('sha256')
            ->withMGFHash('sha256')
            ->encrypt($payload);
    }
}
