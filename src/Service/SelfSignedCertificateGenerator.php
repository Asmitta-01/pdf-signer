<?php

declare(strict_types=1);

namespace App\Service;

final class SelfSignedCertificateGenerator
{
    /**
     * @return array{0: string, 1: string} Certificate PEM and private key PEM
     */
    public function generate(string $signerName): array
    {
        $config = [
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => \OPENSSL_KEYTYPE_RSA,
        ];

        $dn = [
            'countryName' => 'FR',
            'organizationName' => 'PDF Signer',
            'commonName' => mb_substr($signerName, 0, 64),
        ];

        $privateKey = openssl_pkey_new($config);
        if ($privateKey === false) {
            throw new \RuntimeException('Impossible de générer la clé privée.');
        }

        $csr = openssl_csr_new($dn, $privateKey, $config);
        if ($csr === false) {
            throw new \RuntimeException('Impossible de créer la CSR.');
        }

        $cert = openssl_csr_sign($csr, null, $privateKey, 365, $config);
        if ($cert === false) {
            throw new \RuntimeException('Impossible de signer le certificat auto-signé.');
        }

        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($privateKey, $privateKeyPem);

        return [$certPem, $privateKeyPem];
    }
}
