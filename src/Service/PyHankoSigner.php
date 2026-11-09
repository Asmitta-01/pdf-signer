<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Signs PDFs with pyHanko (PAdES) using incremental updates, so the original
 * document bytes are preserved.
 */
final class PyHankoSigner
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(PDF_SIGN_PYTHON)%')]
        private readonly string $pythonBinary,
        #[Autowire('%env(PDF_SIGN_P12_PATH)%')]
        private readonly string $p12Path,
        #[Autowire('%env(PDF_SIGN_P12_PASSWORD)%')]
        private readonly string $p12Password,
        #[Autowire('%env(PDF_SIGN_TSA_URL)%')]
        private readonly string $tsaUrl,
        private readonly SelfSignedCertificateGenerator $certificateGenerator,
        private readonly Filesystem $filesystem,
    ) {}

    /**
     * @param array<int, array{pageNumber: int, xRatio: float, yRatio: float, widthRatio: float, path: string, aspectRatio: float}> $stamps
     * @param array{page: int, xRatio: float, yRatio: float, widthRatio: float}|null $digitalPosition
     */
    public function sign(string $sourcePdfPath, string $outputPath, array $stamps, ?string $signerName, ?array $digitalPosition, int $pageCount): void
    {
        $job = [
            'input' => $sourcePdfPath,
            'output' => $outputPath,
            'stamps' => array_map(static fn(array $stamp): array => [
                'page' => $stamp['pageNumber'],
                'xRatio' => $stamp['xRatio'],
                'yRatio' => $stamp['yRatio'],
                'widthRatio' => $stamp['widthRatio'],
                'aspectRatio' => $stamp['aspectRatio'],
                'path' => $stamp['path'],
            ], $stamps),
        ];

        $temporaryP12 = null;

        try {
            if ($signerName !== null) {
                if ($this->p12Path !== '') {
                    if (!is_file($this->p12Path)) {
                        throw new \RuntimeException('Le certificat PKCS#12 configuré est introuvable.');
                    }
                    $p12 = $this->p12Path;
                    $p12Password = $this->p12Password;
                } else {
                    $p12Password = '';
                    $p12 = $temporaryP12 = $this->createTemporaryP12($signerName);
                }

                $job['p12'] = $p12;
                $job['p12Password'] = $p12Password;
                $job['tsaUrl'] = $this->tsaUrl !== '' ? $this->tsaUrl : null;
                $job['digital'] = [
                    'name' => $signerName,
                    'reason' => 'Signature numérique',
                    'page' => $digitalPosition['page'] ?? $pageCount,
                    'xRatio' => $digitalPosition['xRatio'] ?? 0.25,
                    'yRatio' => $digitalPosition['yRatio'] ?? 0.9,
                    'widthRatio' => $digitalPosition['widthRatio'] ?? 0.5,
                ];
            }

            $pythonBinary = $this->pythonBinary;
            if ($pythonBinary === 'python3' && \PHP_OS_FAMILY === 'Windows') {
                $pythonBinary = 'python';
            }

            $process = new Process(
                [$pythonBinary, $this->projectDir . '/bin/pyhanko_sign.py'],
                null,
                null,
                json_encode($job, JSON_THROW_ON_ERROR),
                120,
            );
            $process->run();

            if (!$process->isSuccessful() || !is_file($outputPath)) {
                throw new \RuntimeException('pyHanko a échoué : ' . trim($process->getErrorOutput()));
            }
        } finally {
            if ($temporaryP12 !== null) {
                $this->filesystem->remove($temporaryP12);
            }
        }
    }

    private function createTemporaryP12(string $signerName): string
    {
        [$certPem, $privateKeyPem] = $this->certificateGenerator->generate($signerName);

        if (!openssl_pkcs12_export($certPem, $p12Binary, $privateKeyPem, '')) {
            throw new \RuntimeException('Impossible de créer le conteneur PKCS#12.');
        }

        $directory = $this->projectDir . '/var/storage/tmp';
        $this->filesystem->mkdir($directory);
        $path = sprintf('%s/%s.p12', $directory, bin2hex(random_bytes(16)));
        $this->filesystem->dumpFile($path, $p12Binary);
        $this->filesystem->chmod($path, 0600);

        return $path;
    }
}
