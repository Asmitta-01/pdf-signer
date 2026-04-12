<?php

declare(strict_types=1);

namespace App\Service;

use setasign\Fpdi\Fpdi;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

final class PdfSigner
{
    private const PAGE_MARGIN = 12.0;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly Filesystem $filesystem,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $signatures
     */
    public function sign(string $sourcePdfPath, array $signatures): string
    {
        $signedDirectory = $this->projectDir . '/var/storage/signed';
        $this->filesystem->mkdir($signedDirectory);

        $outputPath = sprintf('%s/%s.pdf', $signedDirectory, bin2hex(random_bytes(16)));

        $pdf = new Fpdi();
        $pageCount = $pdf->setSourceFile($sourcePdfPath);

        [$normalizedSignatures, $tempSignaturePaths] = $this->normalizeSignatures($signatures, $pageCount);
        if ($normalizedSignatures === []) {
            throw new \InvalidArgumentException('Aucune signature valide a appliquer.');
        }

        try {
            for ($pageNumber = 1; $pageNumber <= $pageCount; ++$pageNumber) {
                $templateId = $pdf->importPage($pageNumber);
                $templateSize = $pdf->getTemplateSize($templateId);

                $orientation = $templateSize['width'] > $templateSize['height'] ? 'L' : 'P';
                $pdf->AddPage($orientation, [$templateSize['width'], $templateSize['height']]);
                $pdf->useTemplate($templateId);

                foreach ($normalizedSignatures as $signature) {
                    if ($signature['pageNumber'] !== $pageNumber) {
                        continue;
                    }

                    $this->renderSignature($pdf, $templateSize['width'], $templateSize['height'], $signature);
                }
            }

            $pdf->Output('F', $outputPath);
        } finally {
            if ($tempSignaturePaths !== []) {
                $this->filesystem->remove(array_values(array_unique($tempSignaturePaths)));
            }
        }

        return $outputPath;
    }

    /**
     * @param array<int, array<string, mixed>> $signatures
     *
     * @return array{0: array<int, array{pageNumber: int, xRatio: float, yRatio: float, widthRatio: float, path: string, aspectRatio: float}>, 1: array<int, string>}
     */
    private function normalizeSignatures(array $signatures, int $pageCount): array
    {
        $signatureAssets = [];
        $normalized = [];
        $tempPaths = [];

        foreach ($signatures as $signature) {
            if (!is_array($signature)) {
                continue;
            }

            $signatureData = trim((string) ($signature['signatureData'] ?? ''));
            if ($signatureData === '') {
                continue;
            }

            $assetKey = sha1($signatureData);
            if (!isset($signatureAssets[$assetKey])) {
                $path = $this->createSignatureImageFromDataUri($signatureData);
                [$width, $height] = getimagesize($path) ?: [500, 200];

                $signatureAssets[$assetKey] = [
                    'path' => $path,
                    'aspectRatio' => (float) ($height / max($width, 1)),
                ];

                $tempPaths[] = $path;
            }

            $normalized[] = [
                'pageNumber' => (int) $this->clamp((float) ($signature['pageNumber'] ?? 1), 1.0, (float) $pageCount),
                'xRatio' => $this->clamp((float) ($signature['xRatio'] ?? 0.65), 0.0, 1.0),
                'yRatio' => $this->clamp((float) ($signature['yRatio'] ?? 0.75), 0.0, 1.0),
                'widthRatio' => $this->clamp((float) ($signature['widthRatio'] ?? 0.3), 0.05, 0.9),
                'path' => $signatureAssets[$assetKey]['path'],
                'aspectRatio' => $signatureAssets[$assetKey]['aspectRatio'],
            ];
        }

        return [$normalized, $tempPaths];
    }

    /**
     * @param array{pageNumber: int, xRatio: float, yRatio: float, widthRatio: float, path: string, aspectRatio: float} $signature
     */
    private function renderSignature(Fpdi $pdf, float $pageWidth, float $pageHeight, array $signature): void
    {
        $signatureWidth = max(20.0, $pageWidth * $signature['widthRatio']);
        $signatureHeight = $signatureWidth * $signature['aspectRatio'];

        $maxWidth = max(20.0, $pageWidth - (2 * self::PAGE_MARGIN));
        if ($signatureWidth > $maxWidth) {
            $signatureWidth = $maxWidth;
            $signatureHeight = $signatureWidth * $signature['aspectRatio'];
        }

        $maxHeight = max(20.0, $pageHeight - (2 * self::PAGE_MARGIN));
        if ($signatureHeight > $maxHeight) {
            $signatureHeight = $maxHeight;
            $signatureWidth = $signatureHeight / max($signature['aspectRatio'], 0.01);
        }

        $x = $pageWidth * $signature['xRatio'];
        $y = $pageHeight * $signature['yRatio'];

        $xMax = max(self::PAGE_MARGIN, $pageWidth - $signatureWidth - self::PAGE_MARGIN);
        $yMax = max(self::PAGE_MARGIN, $pageHeight - $signatureHeight - self::PAGE_MARGIN);

        $x = $this->clamp($x, self::PAGE_MARGIN, $xMax);
        $y = $this->clamp($y, self::PAGE_MARGIN, $yMax);

        $pdf->Image($signature['path'], $x, $y, $signatureWidth, $signatureHeight);
    }

    private function createSignatureImageFromDataUri(string $signatureDataUri): string
    {
        if (!preg_match('#^data:image/(png|jpeg|jpg);base64,#', $signatureDataUri, $matches)) {
            throw new \InvalidArgumentException('Le format de signature est invalide.');
        }

        $base64Payload = preg_replace('#^data:image/(png|jpeg|jpg);base64,#', '', $signatureDataUri);
        $decodedBinary = base64_decode((string) $base64Payload, true);

        if ($decodedBinary === false) {
            throw new \InvalidArgumentException('Impossible de decoder la signature.');
        }

        $signatureDirectory = $this->projectDir . '/var/storage/signatures';
        $this->filesystem->mkdir($signatureDirectory);

        $extension = in_array($matches[1], ['jpeg', 'jpg'], true) ? 'jpg' : 'png';
        $signaturePath = sprintf('%s/%s.%s', $signatureDirectory, bin2hex(random_bytes(16)), $extension);
        file_put_contents($signaturePath, $decodedBinary);

        return $signaturePath;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
