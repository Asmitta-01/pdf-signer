<?php

declare(strict_types=1);

namespace App\Service;

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\Tcpdf\Fpdi as TcpdfFpdi;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

final class PdfSigner
{
    public const ENGINE_PYHANKO = 'pyhanko';
    public const ENGINE_CLASSIC = 'classic';

    private const PAGE_MARGIN = 12.0;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly Filesystem $filesystem,
        private readonly PyHankoSigner $pyHankoSigner,
        private readonly SelfSignedCertificateGenerator $certificateGenerator,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $signatures
     * @param array{page: int, xRatio: float, yRatio: float, widthRatio: float}|null $digitalPosition
     */
    public function sign(string $sourcePdfPath, array $signatures, ?string $signerName = null, ?array $digitalPosition = null, string $engine = self::ENGINE_PYHANKO): string
    {
        if (!is_file($sourcePdfPath) || !is_readable($sourcePdfPath)) {
            throw new \InvalidArgumentException(sprintf('Le fichier PDF source "%s" est introuvable ou illisible.', $sourcePdfPath));
        }

        if (!in_array($engine, [self::ENGINE_PYHANKO, self::ENGINE_CLASSIC], true)) {
            throw new \InvalidArgumentException(sprintf('Moteur de signature inconnu : "%s".', $engine));
        }

        $signedDirectory = $this->projectDir . '/var/storage/signed';
        $this->filesystem->mkdir($signedDirectory);

        $outputPath = sprintf('%s/%s.pdf', $signedDirectory, bin2hex(random_bytes(16)));

        try {
            if ($engine === self::ENGINE_PYHANKO) {
                try {
                    $this->signWithPyHanko($sourcePdfPath, $signatures, $signerName, $outputPath, $digitalPosition);
                } catch (\RuntimeException $e) {
                    if (str_contains($e->getMessage(), 'pyHanko a échoué')) {
                        // Fallback automatically to classic engine if Python / pyHanko fails
                        if ($signerName !== null) {
                            $this->signWithDigital($sourcePdfPath, $signatures, $signerName, $outputPath, $digitalPosition);
                        } else {
                            $this->signWithFpdi($sourcePdfPath, $signatures, $outputPath);
                        }
                    } else {
                        throw $e;
                    }
                }
            } elseif ($signerName !== null) {
                $this->signWithDigital($sourcePdfPath, $signatures, $signerName, $outputPath, $digitalPosition);
            } else {
                $this->signWithFpdi($sourcePdfPath, $signatures, $outputPath);
            }
        } catch (\Throwable $e) {
            if (is_file($outputPath)) {
                $this->filesystem->remove($outputPath);
            }

            throw $e;
        }

        if (!is_file($outputPath) || filesize($outputPath) === 0) {
            if (is_file($outputPath)) {
                $this->filesystem->remove($outputPath);
            }

            throw new \RuntimeException('La signature du PDF a échoué : aucun fichier valide n\'a été produit.');
        }

        return $outputPath;
    }

    /**
     * @param array<int, array<string, mixed>> $signatures
     * @param array{page: int, xRatio: float, yRatio: float, widthRatio: float}|null $digitalPosition
     */
    private function signWithPyHanko(string $sourcePdfPath, array $signatures, ?string $signerName, string $outputPath, ?array $digitalPosition): void
    {
        $fpdi = new Fpdi();
        try {
            $pageCount = $fpdi->setSourceFile($sourcePdfPath);
        } finally {
            $fpdi->cleanUp();
        }

        if ($pageCount < 1) {
            throw new \InvalidArgumentException('Le fichier PDF source ne contient aucune page.');
        }

        [$normalizedSignatures, $tempSignaturePaths] = $this->normalizeSignatures($signatures, $pageCount);

        try {
            if ($normalizedSignatures === [] && ($signerName === null || $signatures !== [])) {
                throw new \InvalidArgumentException('Aucune signature valide a appliquer.');
            }

            $this->pyHankoSigner->sign($sourcePdfPath, $outputPath, $normalizedSignatures, $signerName, $digitalPosition, $pageCount);
        } finally {
            if ($tempSignaturePaths !== []) {
                $this->filesystem->remove(array_values(array_unique($tempSignaturePaths)));
            }
        }
    }

    private function signWithFpdi(string $sourcePdfPath, array $signatures, string $outputPath): void
    {
        $pdf = new Fpdi();
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);

        $pageCount = $pdf->setSourceFile($sourcePdfPath);
        if ($pageCount < 1) {
            $pdf->cleanUp();
            throw new \InvalidArgumentException('Le fichier PDF source ne contient aucune page.');
        }

        [$normalizedSignatures, $tempSignaturePaths] = $this->normalizeSignatures($signatures, $pageCount);

        try {
            if ($normalizedSignatures === []) {
                throw new \InvalidArgumentException('Aucune signature valide a appliquer.');
            }

            for ($pageNumber = 1; $pageNumber <= $pageCount; ++$pageNumber) {
                $templateId = $pdf->importPage($pageNumber);
                $templateSize = $pdf->getTemplateSize($templateId);

                $w = (float) $templateSize['width'];
                $h = (float) $templateSize['height'];
                $orientation = $w > $h ? 'L' : 'P';

                $pdf->AddPage($orientation, [$w, $h]);
                $pdf->useTemplate($templateId, 0, 0, $w, $h);

                foreach ($normalizedSignatures as $signature) {
                    if ($signature['pageNumber'] !== $pageNumber) {
                        continue;
                    }

                    $this->renderSignature($pdf, $w, $h, $signature);
                }
            }

            $pdf->Output('F', $outputPath);
        } finally {
            $pdf->cleanUp();
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
        if (!class_exists(\TCPDF::class)) {
            throw new \RuntimeException('Le moteur classique avec signature numérique nécessite la bibliothèque "tecnickcom/tcpdf". Installez-la via Composer ("composer require tecnickcom/tcpdf:^6.8") ou utilisez le moteur pyHanko.');
        }

        $pdf = new TcpdfFpdi('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetTopMargin(0);
        $pdf->SetMargins(0, 0, 0, true);
        $pdf->setAutoPageBreak(false, 0);

        $pageCount = $pdf->setSourceFile($sourcePdfPath);
        if ($pageCount < 1) {
            $pdf->cleanUp();
            throw new \InvalidArgumentException('Le fichier PDF source ne contient aucune page.');
        }

        [$normalizedSignatures, $tempSignaturePaths] = $this->normalizeSignatures($signatures, $pageCount);

        $lastPageWidth = 210.0;
        $lastPageHeight = 297.0;
        /** @var array<int, array{width: float, height: float}> $pageSizes */
        $pageSizes = [];

        try {
            // Digital-only mode is valid (no visual signatures required)
            if ($normalizedSignatures === [] && $signatures !== []) {
                throw new \InvalidArgumentException('Aucune signature visuelle valide à appliquer.');
            }

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

            [$certPem, $privateKeyPem] = $this->certificateGenerator->generate($signerName);

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
            if ($badgePath !== null && is_file($badgePath)) {
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
        } catch (\Throwable $e) {
            $pdf->cleanUp();

            throw $e;
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
        $white = imagecolorallocate($img, 255, 255, 255);
        if ($white !== false) {
            imagefill($img, 0, 0, $white);
        }

        // Very light grey tint
        $bg = imagecolorallocate($img, 248, 250, 252);
        if ($bg !== false) {
            imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, $bg);
        }

        // Left accent bar (cyan)
        $accentW = max(3, (int) round($width * 0.018));
        $accent  = imagecolorallocate($img, 34, 211, 238);
        if ($accent !== false) {
            imagefilledrectangle($img, 0, 0, $accentW, $height - 1, $accent);
        }

        // Thin outer border
        $border = imagecolorallocate($img, 203, 213, 225);  // slate-300
        if ($border !== false) {
            imagerectangle($img, 0, 0, $width - 1, $height - 1, $border);
        }

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

        if ($labelColor !== false) {
            imagestring($img, 2, $textX, $y1, '>> Signature numerique', $labelColor);
        }
        if ($nameColor !== false) {
            imagestring($img, 3, $textX, $y2, $this->toAscii($nameDisplay), $nameColor);
        }
        if ($dateColor !== false) {
            imagestring($img, 1, $textX, $y3, date('d/m/Y H:i'), $dateColor);
        }

        $signaturesDir = $this->projectDir . '/var/storage/signatures';
        $this->filesystem->mkdir($signaturesDir);
        $badgePath = sprintf('%s/badge_%s.png', $signaturesDir, bin2hex(random_bytes(8)));

        $success = imagepng($img, $badgePath);
        imagedestroy($img);

        return $success && is_file($badgePath) ? $badgePath : null;
    }

    private function toAscii(string $text): string
    {
        $map = [
            'à' => 'a',
            'â' => 'a',
            'ä' => 'a',
            'á' => 'a',
            'ã' => 'a',
            'è' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'é' => 'e',
            'î' => 'i',
            'ï' => 'i',
            'í' => 'i',
            'ì' => 'i',
            'ô' => 'o',
            'ö' => 'o',
            'ó' => 'o',
            'ò' => 'o',
            'õ' => 'o',
            'ù' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'ú' => 'u',
            'ç' => 'c',
            'ñ' => 'n',
            'À' => 'A',
            'Â' => 'A',
            'Ä' => 'A',
            'Á' => 'A',
            'È' => 'E',
            'Ê' => 'E',
            'Ë' => 'E',
            'É' => 'E',
            'Î' => 'I',
            'Ï' => 'I',
            'Í' => 'I',
            'Ô' => 'O',
            'Ö' => 'O',
            'Ó' => 'O',
            'Ù' => 'U',
            'Û' => 'U',
            'Ü' => 'U',
            'Ú' => 'U',
            'Ç' => 'C',
            'Ñ' => 'N',
        ];

        $converted = strtr($text, $map);
        if (function_exists('iconv')) {
            $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $converted);
            if ($transliterated !== false) {
                $converted = $transliterated;
            }
        }

        return preg_replace('/[^\x20-\x7E]/', '', $converted) ?? $converted;
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

        try {
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
                    $tempPaths[] = $path;

                    $imageInfo = @getimagesize($path);
                    if ($imageInfo === false || $imageInfo[0] <= 0 || $imageInfo[1] <= 0) {
                        throw new \InvalidArgumentException('Le fichier de signature ne correspond pas à une image valide.');
                    }

                    $width = (float) $imageInfo[0];
                    $height = (float) $imageInfo[1];

                    $signatureAssets[$assetKey] = [
                        'path' => $path,
                        'aspectRatio' => $height / $width,
                    ];
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
        } catch (\Throwable $e) {
            if ($tempPaths !== []) {
                $this->filesystem->remove(array_values(array_unique($tempPaths)));
            }

            throw $e;
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
        if (!preg_match('#^data:image/(png|jpeg|jpg);base64,#i', $signatureDataUri, $matches)) {
            throw new \InvalidArgumentException('Le format de signature est invalide.');
        }

        $base64Payload = preg_replace('#^data:image/(png|jpeg|jpg);base64,#i', '', $signatureDataUri);
        $cleanedPayload = preg_replace('/\s+/', '', (string) $base64Payload);
        $decodedBinary = base64_decode((string) $cleanedPayload, true);

        if ($decodedBinary === false || $decodedBinary === '') {
            throw new \InvalidArgumentException('Impossible de decoder la signature.');
        }

        $signatureDirectory = $this->projectDir . '/var/storage/signatures';
        $this->filesystem->mkdir($signatureDirectory);

        $extension = in_array(strtolower($matches[1]), ['jpeg', 'jpg'], true) ? 'jpg' : 'png';
        $signaturePath = sprintf('%s/%s.%s', $signatureDirectory, bin2hex(random_bytes(16)), $extension);

        $this->filesystem->dumpFile($signaturePath, $decodedBinary);

        return $signaturePath;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
