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

    public function sign(
        string $sourcePdfPath,
        string $signatureDataUri,
        int $pageNumber,
        float $xRatio,
        float $yRatio,
        float $widthRatio,
    ): string
    {
        $signaturePath = $this->createSignatureImageFromDataUri($signatureDataUri);
        $signedDirectory = $this->projectDir . '/var/storage/signed';
        $this->filesystem->mkdir($signedDirectory);

        $outputPath = sprintf('%s/%s.pdf', $signedDirectory, bin2hex(random_bytes(16)));

        $pdf = new Fpdi();
        $pageCount = $pdf->setSourceFile($sourcePdfPath);
        $targetPageNumber = (int) $this->clamp((float) $pageNumber, 1.0, (float) $pageCount);
        $xRatio = $this->clamp($xRatio, 0.0, 1.0);
        $yRatio = $this->clamp($yRatio, 0.0, 1.0);
        $widthRatio = $this->clamp($widthRatio, 0.05, 0.9);

        [$signatureOriginalWidth, $signatureOriginalHeight] = getimagesize($signaturePath) ?: [500, 200];
        $signatureRatio = $signatureOriginalHeight / max($signatureOriginalWidth, 1.0);

        for ($pageNumber = 1; $pageNumber <= $pageCount; ++$pageNumber) {
            $templateId = $pdf->importPage($pageNumber);
            $templateSize = $pdf->getTemplateSize($templateId);

            $orientation = $templateSize['width'] > $templateSize['height'] ? 'L' : 'P';
            $pdf->AddPage($orientation, [$templateSize['width'], $templateSize['height']]);
            $pdf->useTemplate($templateId);

            if ($pageNumber !== $targetPageNumber) {
                continue;
            }

            $signatureWidth = max(20.0, $templateSize['width'] * $widthRatio);
            $signatureHeight = $signatureWidth * $signatureRatio;
            $maxHeight = $templateSize['height'] - (2 * self::PAGE_MARGIN);

            if ($signatureHeight > $maxHeight) {
                $signatureHeight = $maxHeight;
                $signatureWidth = $signatureHeight / max($signatureRatio, 0.01);
            }

            $x = $templateSize['width'] * $xRatio;
            $y = $templateSize['height'] * $yRatio;

            $x = $this->clamp($x, self::PAGE_MARGIN, $templateSize['width'] - $signatureWidth - self::PAGE_MARGIN);
            $y = $this->clamp($y, self::PAGE_MARGIN, $templateSize['height'] - $signatureHeight - self::PAGE_MARGIN);

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

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
