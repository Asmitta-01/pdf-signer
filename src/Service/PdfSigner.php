<?php

declare(strict_types=1);

namespace App\Service;

use setasign\Fpdi\Fpdi;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

final class PdfSigner
{
    private const SIGNATURE_WIDTH_RATIO = 0.3;
    private const PAGE_MARGIN = 12.0;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly Filesystem $filesystem,
    ) {
    }

    public function sign(string $sourcePdfPath, string $signatureDataUri, string $position, string $pageTarget): string
    {
        $signaturePath = $this->createSignatureImageFromDataUri($signatureDataUri);
        $signedDirectory = $this->projectDir . '/var/storage/signed';
        $this->filesystem->mkdir($signedDirectory);

        $outputPath = sprintf('%s/%s.pdf', $signedDirectory, bin2hex(random_bytes(16)));

        $pdf = new Fpdi();
        $pageCount = $pdf->setSourceFile($sourcePdfPath);
        $targetPageNumber = $pageTarget === 'last' ? $pageCount : 1;

        [$signatureOriginalWidth, $signatureOriginalHeight] = getimagesize($signaturePath) ?: [500, 200];
        $signatureRatio = $signatureOriginalHeight / max($signatureOriginalWidth, 1);

        for ($pageNumber = 1; $pageNumber <= $pageCount; ++$pageNumber) {
            $templateId = $pdf->importPage($pageNumber);
            $templateSize = $pdf->getTemplateSize($templateId);

            $orientation = $templateSize['width'] > $templateSize['height'] ? 'L' : 'P';
            $pdf->AddPage($orientation, [$templateSize['width'], $templateSize['height']]);
            $pdf->useTemplate($templateId);

            if ($pageNumber !== $targetPageNumber) {
                continue;
            }

            $signatureWidth = $templateSize['width'] * self::SIGNATURE_WIDTH_RATIO;
            $signatureHeight = $signatureWidth * $signatureRatio;
            [$x, $y] = $this->resolvePosition($position, $templateSize['width'], $templateSize['height'], $signatureWidth, $signatureHeight);

            $pdf->Image($signaturePath, $x, $y, $signatureWidth, $signatureHeight);
        }

        $pdf->Output('F', $outputPath);
        $this->filesystem->remove($signaturePath);

        return $outputPath;
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

    /**
     * @return array{0: float, 1: float}
     */
    private function resolvePosition(
        string $position,
        float $pageWidth,
        float $pageHeight,
        float $signatureWidth,
        float $signatureHeight,
    ): array {
        $margin = self::PAGE_MARGIN;

        return match ($position) {
            'top-left' => [$margin, $margin],
            'top-right' => [$pageWidth - $signatureWidth - $margin, $margin],
            'bottom-left' => [$margin, $pageHeight - $signatureHeight - $margin],
            'center' => [($pageWidth - $signatureWidth) / 2, ($pageHeight - $signatureHeight) / 2],
            default => [$pageWidth - $signatureWidth - $margin, $pageHeight - $signatureHeight - $margin],
        };
    }
}
