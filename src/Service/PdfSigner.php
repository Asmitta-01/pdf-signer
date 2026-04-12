<?php

declare(strict_types=1);

namespace App\Service;

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\TcpdfFpdi;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

final class PdfSigner
{
    private const PAGE_MARGIN = 12.0;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly Filesystem $filesystem,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $signatures
     * @param array{page: int, xRatio: float, yRatio: float, widthRatio: float}|null $digitalPosition
     */
    public function sign(string $sourcePdfPath, array $signatures, ?string $signerName = null, ?array $digitalPosition = null): string
    {
        $signedDirectory = $this->projectDir . '/var/storage/signed';
        $this->filesystem->mkdir($signedDirectory);

        $outputPath = sprintf('%s/%s.pdf', $signedDirectory, bin2hex(random_bytes(16)));

        if ($signerName !== null) {
            $this->signWithDigital($sourcePdfPath, $signatures, $signerName, $outputPath, $digitalPosition);
        } else {
            $this->signWithFpdi($sourcePdfPath, $signatures, $outputPath);
        }

        return $outputPath;
    }

    private function signWithFpdi(string $sourcePdfPath, array $signatures, string $outputPath): void
    {
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
    }

    /**
     * @param array{page: int, xRatio: float, yRatio: float, widthRatio: float}|null $digitalPosition
     */
    private function signWithDigital(string $sourcePdfPath, array $signatures, string $signerName, string $outputPath, ?array $digitalPosition): void
    {
        $pdf = new TcpdfFpdi('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetTopMargin(0);
        $pdf->SetMargins(0, 0, 0, true);
        $pdf->setAutoPageBreak(false, 0);

        $pageCount = $pdf->setSourceFile($sourcePdfPath);

        [$normalizedSignatures, $tempSignaturePaths] = $this->normalizeSignatures($signatures, $pageCount);

        // Digital-only mode is valid (no visual signatures required)
        if ($normalizedSignatures === [] && $signatures !== []) {
            throw new \InvalidArgumentException('Aucune signature visuelle valide à appliquer.');
        }

        $lastPageWidth = 210.0;
        $lastPageHeight = 297.0;
        /** @var array<int, array{width: float, height: float}> $pageSizes */
        $pageSizes = [];

        try {
            for ($pageNumber = 1; $pageNumber <= $pageCount; ++$pageNumber) {
                $templateId = $pdf->importPage($pageNumber);
                $templateSize = $pdf->getTemplateSize($templateId);

                $w = (float) $templateSize['width'];
                $h = (float) $templateSize['height'];
                $orientation = $w > $h ? 'L' : 'P';

                $pdf->AddPage($orientation, [$w, $h]);
                $pdf->SetMargins(0, 0, 0, true);
                $pdf->setAutoPageBreak(false, 0);
                $pdf->useTemplate($templateId, 0, 0, $w, $h);

                $pageSizes[$pageNumber] = ['width' => $w, 'height' => $h];

                foreach ($normalizedSignatures as $signature) {
                    if ($signature['pageNumber'] !== $pageNumber) {
                        continue;
                    }

                    $this->renderSignature($pdf, $w, $h, $signature);
                }

                if ($pageNumber === $pageCount) {
                    $lastPageWidth = $w;
                    $lastPageHeight = $h;
                }
            }

            [$certPem, $privateKeyPem] = $this->generateSelfSignedCert($signerName);

            $pdf->setSignature($certPem, $privateKeyPem, '', '', 2, [
                'Name' => $signerName,
                'Location' => '',
                'Reason' => 'Signature numérique',
                'ContactInfo' => '',
            ]);

            // Widget position: use user-selected position if provided, else bottom-center of last page
            $widgetPage = isset($digitalPosition['page'])
                ? (int) $this->clamp((float) $digitalPosition['page'], 1.0, (float) $pageCount)
                : $pageCount;

            /** @var array{width: float, height: float} $widgetPageSize */
            $widgetPageSize = $pageSizes[$widgetPage] ?? ['width' => $lastPageWidth, 'height' => $lastPageHeight];
            $pw = $widgetPageSize['width'];
            $ph = $widgetPageSize['height'];

            $sigWidthRatio = isset($digitalPosition['widthRatio'])
                ? $this->clamp((float) $digitalPosition['widthRatio'], 0.05, 0.9)
                : 0.5;
            $sigW = max(20.0, $pw * $sigWidthRatio);
            $sigH = max(8.0, $sigW * 0.22);

            if (isset($digitalPosition['xRatio'], $digitalPosition['yRatio'])) {
                $sigX = $pw * $this->clamp((float) $digitalPosition['xRatio'], 0.0, 1.0);
                $sigY = $ph * $this->clamp((float) $digitalPosition['yRatio'], 0.0, 1.0);
                $sigX = $this->clamp($sigX, self::PAGE_MARGIN, max(self::PAGE_MARGIN, $pw - $sigW - self::PAGE_MARGIN));
                $sigY = $this->clamp($sigY, self::PAGE_MARGIN, max(self::PAGE_MARGIN, $ph - $sigH - self::PAGE_MARGIN));
            } else {
                $sigX = ($pw - $sigW) / 2.0;
                $sigY = $ph - $sigH - 4.0;
            }

            $pdf->setSignatureAppearance($sigX, $sigY, $sigW, $sigH, $widgetPage);

            // Draw the badge image directly on the page (always visible)
            $badgePath = $this->createSubtleSignatureBadge($signerName, $sigW, $sigH);
            if ($badgePath !== null) {
                try {
                    // Go back to the target page to draw the image
                    $savedPage = $pdf->getPage();
                    $pdf->setPage($widgetPage);
                    $pdf->Image($badgePath, $sigX, $sigY, $sigW, $sigH, 'PNG');
                    $pdf->setPage($savedPage);
                } finally {
                    $this->filesystem->remove($badgePath);
                }
            }

            $pdf->Output($outputPath, 'F');
        } finally {
            if ($tempSignaturePaths !== []) {
                $this->filesystem->remove(array_values(array_unique($tempSignaturePaths)));
            }
        }
    }

    private function createSubtleSignatureBadge(string $signerName, float $sigWMm, float $sigHMm): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }

        // Scale mm → pixels at 150 dpi for a crisp but not huge image
        $dpi    = 150;
        $width  = (int) round($sigWMm / 25.4 * $dpi);
        $height = (int) round($sigHMm / 25.4 * $dpi);
        $width  = max(120, $width);
        $height = max(28, $height);

        $img = imagecreatetruecolor($width, $height);
        if ($img === false) {
            return null;
        }

        // White background
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));

        // Very light grey tint
        $bg = imagecolorallocate($img, 248, 250, 252);
        imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, $bg);

        // Left accent bar (cyan)
        $accentW = max(3, (int) round($width * 0.018));
        $accent  = imagecolorallocate($img, 34, 211, 238);
        imagefilledrectangle($img, 0, 0, $accentW, $height - 1, $accent);

        // Thin outer border
        $border = imagecolorallocate($img, 203, 213, 225);  // slate-300
        imagerectangle($img, 0, 0, $width - 1, $height - 1, $border);

        // Text colors
        $labelColor = imagecolorallocate($img, 71, 85, 105);   // slate-600
        $nameColor  = imagecolorallocate($img, 15, 23, 42);    // slate-900
        $dateColor  = imagecolorallocate($img, 148, 163, 184); // slate-400

        $textX = $accentW + max(4, (int) round($width * 0.04));

        $nameDisplay = mb_strlen($signerName) > 32 ? mb_substr($signerName, 0, 30) . '…' : $signerName;

        // Three lines: label / name / date — vertically distributed
        $lineH = (int) max(1, floor(($height - 6) / 3));
        $y1 = 3;
        $y2 = $y1 + $lineH;
        $y3 = $y2 + $lineH;

        imagestring($img, 2, $textX, $y1, '✓ Signature numérique', $labelColor);
        imagestring($img, 3, $textX, $y2, $nameDisplay, $nameColor);
        imagestring($img, 1, $textX, $y3, date('d/m/Y H:i'), $dateColor);

        $signaturesDir = $this->projectDir . '/var/storage/signatures';
        $this->filesystem->mkdir($signaturesDir);
        $badgePath = sprintf('%s/badge_%s.png', $signaturesDir, bin2hex(random_bytes(8)));

        imagepng($img, $badgePath);
        imagedestroy($img);

        return $badgePath;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function generateSelfSignedCert(string $signerName): array
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
     * @param \setasign\Fpdi\Fpdi|\setasign\Fpdi\TcpdfFpdi $pdf
     * @param array{pageNumber: int, xRatio: float, yRatio: float, widthRatio: float, path: string, aspectRatio: float} $signature
     */
    private function renderSignature(Fpdi|TcpdfFpdi $pdf, float $pageWidth, float $pageHeight, array $signature): void
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
