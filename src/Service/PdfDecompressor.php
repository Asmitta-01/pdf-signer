<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Pure-PHP PDF 1.5+ decompressor for FPDI's free parser.
 *
 * Expands Cross-Reference Streams (/Type /XRef) and Object Streams (/Type /ObjStm)
 * into a standard uncompressed cross-reference table compatible with FPDI.
 */
final class PdfDecompressor
{
    public function __construct(
        private readonly Filesystem $filesystem,
    ) {}

    public function decompress(string $sourcePdfPath, string $outputPath): void
    {
        $data = @file_get_contents($sourcePdfPath);
        if ($data === false || $data === '') {
            throw new \RuntimeException(sprintf('Impossible de lire le fichier PDF "%s".', $sourcePdfPath));
        }

        $headerPos = strpos($data, '%PDF-');
        if ($headerPos === false) {
            throw new \RuntimeException('En-tête %PDF- introuvable.');
        }
        if ($headerPos > 0) {
            $data = substr($data, $headerPos);
        }

        $startXref = $this->findLastStartXref($data);
        $entries = [];
        $trailerInfo = [
            'Root' => null,
            'Info' => null,
            'Encrypt' => null,
        ];

        $this->collectXrefChain($data, $startXref, $entries, $trailerInfo);

        if ($trailerInfo['Encrypt'] !== null) {
            throw new \RuntimeException('Les fichiers PDF chiffrés ou protégés par mot de passe ne sont pas supportés.');
        }

        if ($trailerInfo['Root'] === null) {
            throw new \RuntimeException('Référence /Root introuvable dans le PDF.');
        }

        $rawObjects = [];
        $objStmIds = [];

        // Collect all uncompressed objects (type 1) and identify referenced Object Streams (type 2)
        foreach ($entries as $objNum => $entry) {
            if ($entry['type'] === 1) {
                $parsed = $this->extractIndirectObjectAt($data, $entry['field1'], $entries);
                if ($parsed === null) {
                    continue;
                }

                // Skip XRef and ObjStm objects from final output; their contents are expanded below
                if ($this->isDictionaryType($parsed['dict'], 'XRef') || $this->isDictionaryType($parsed['dict'], 'ObjStm')) {
                    continue;
                }

                $rawObjects[$objNum] = [
                    'gen' => $entry['field2'],
                    'raw' => $parsed['body'],
                ];
            } elseif ($entry['type'] === 2) {
                $objStmIds[$entry['field1']] = true;
            }
        }

        // Expand every Object Stream referenced by live type-2 entries
        foreach (array_keys($objStmIds) as $objStmNum) {
            if (!isset($entries[$objStmNum]) || $entries[$objStmNum]['type'] !== 1) {
                continue;
            }

            $parsed = $this->extractIndirectObjectAt($data, $entries[$objStmNum]['field1'], $entries);
            if ($parsed === null || $parsed['stream'] === null || $parsed['dict'] === null) {
                continue;
            }

            $decoded = $this->decodeStreamData($parsed['dict'], $parsed['stream'], $data, $entries);
            $n = $this->extractIntFromDict($parsed['dict'], 'N') ?? 0;
            $first = $this->extractIntFromDict($parsed['dict'], 'First') ?? 0;

            if ($n <= 0 || $first < 0 || $first > strlen($decoded)) {
                continue;
            }

            $headerPart = substr($decoded, 0, $first);
            if (!preg_match_all('/\d+/', $headerPart, $nums) || count($nums[0]) < $n * 2) {
                continue;
            }

            $pairs = [];
            for ($i = 0; $i < $n; ++$i) {
                $pairs[] = [
                    'objNum' => (int) $nums[0][$i * 2],
                    'offset' => (int) $nums[0][$i * 2 + 1],
                ];
            }

            $streamLen = strlen($decoded);
            for ($i = 0; $i < $n; ++$i) {
                $objNum = $pairs[$i]['objNum'];
                // Only emit if this object's active xref entry actually points to (objStmNum, i)
                if (
                    !isset($entries[$objNum])
                    || $entries[$objNum]['type'] !== 2
                    || $entries[$objNum]['field1'] !== $objStmNum
                    || $entries[$objNum]['field2'] !== $i
                ) {
                    continue;
                }

                $start = $first + $pairs[$i]['offset'];
                $end = ($i + 1 < $n) ? ($first + $pairs[$i + 1]['offset']) : $streamLen;
                if ($start < $first || $end < $start || $end > $streamLen) {
                    continue;
                }

                $rawObjects[$objNum] = [
                    'gen' => 0,
                    'raw' => trim(substr($decoded, $start, $end - $start)),
                ];
            }
        }

        if ($rawObjects === []) {
            throw new \RuntimeException('Aucun objet PDF valide extrait lors de la décompression.');
        }

        ksort($rawObjects);
        $maxObjNum = (int) array_key_last($rawObjects);
        $size = $maxObjNum + 1;

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($rawObjects as $objNum => $obj) {
            $offsets[$objNum] = strlen($out);
            $out .= sprintf("%d %d obj\n%s\nendobj\n", $objNum, $obj['gen'], $obj['raw']);
        }

        $xrefOffset = strlen($out);
        $out .= sprintf("xref\n0 %d\n", $size);
        $out .= "0000000000 65535 f \r\n";

        for ($objNum = 1; $objNum < $size; ++$objNum) {
            if (isset($offsets[$objNum])) {
                $out .= sprintf("%010d %05d n \r\n", $offsets[$objNum], $rawObjects[$objNum]['gen']);
            } else {
                $out .= "0000000000 65535 f \r\n";
            }
        }

        $out .= "trailer\n<<\n";
        $out .= sprintf("/Size %d\n", $size);
        $out .= sprintf("/Root %s\n", $trailerInfo['Root']);
        if ($trailerInfo['Info'] !== null) {
            $out .= sprintf("/Info %s\n", $trailerInfo['Info']);
        }
        $out .= ">>\n";
        $out .= sprintf("startxref\n%d\n%%%%EOF\n", $xrefOffset);

        $this->filesystem->dumpFile($outputPath, $out);
    }

    private function findLastStartXref(string $data): int
    {
        if (preg_match_all('/startxref\s+(\d+)\s+%%EOF/s', $data, $matches) < 1) {
            throw new \RuntimeException('Mot-clé startxref introuvable dans le PDF.');
        }

        return (int) end($matches[1]);
    }

    /**
     * @param array<int, array{type: int, field1: int, field2: int}> $entries
     * @param array{Root: ?string, Info: ?string, Encrypt: ?string} $trailerInfo
     */
    private function collectXrefChain(string $data, int $offset, array &$entries, array &$trailerInfo): void
    {
        $visited = [];
        $queue = [$offset];

        while ($queue !== []) {
            $currentOffset = array_shift($queue);
            if (isset($visited[$currentOffset]) || $currentOffset <= 0 || $currentOffset >= strlen($data)) {
                continue;
            }
            $visited[$currentOffset] = true;

            $pos = $currentOffset + strspn($data, " \t\r\n\0\x0c", $currentOffset);
            if (substr($data, $pos, 4) === 'xref') {
                $trailerDict = $this->parseClassicXrefSection($data, $pos, $entries);
                $this->captureTrailerInfo($trailerDict, $trailerInfo);

                $xrefStm = $this->extractIntFromDict($trailerDict, 'XRefStm');
                if ($xrefStm !== null) {
                    $queue[] = $xrefStm;
                }
                $prev = $this->extractIntFromDict($trailerDict, 'Prev');
                if ($prev !== null) {
                    $queue[] = $prev;
                }
            } else {
                $parsed = $this->extractIndirectObjectAt($data, $pos);
                if ($parsed === null || $parsed['dict'] === null || $parsed['stream'] === null) {
                    throw new \RuntimeException('Flux XRef invalide dans le PDF.');
                }

                $this->captureTrailerInfo($parsed['dict'], $trailerInfo);
                $this->parseXrefStreamSection($parsed['dict'], $parsed['stream'], $data, $entries);

                $prev = $this->extractIntFromDict($parsed['dict'], 'Prev');
                if ($prev !== null) {
                    $queue[] = $prev;
                }
            }
        }
    }

    /**
     * @param array<int, array{type: int, field1: int, field2: int}> $entries
     */
    private function parseClassicXrefSection(string $data, int $xrefPos, array &$entries): string
    {
        $pos = $xrefPos + 4;
        $len = strlen($data);

        while ($pos < $len) {
            $pos += strspn($data, " \t\r\n", $pos);
            if (substr($data, $pos, 7) === 'trailer') {
                break;
            }

            if (preg_match('/^(\d+)\s+(\d+)\s*/', substr($data, $pos, 40), $m) !== 1) {
                break;
            }

            $startObj = (int) $m[1];
            $count = (int) $m[2];
            $pos += strlen($m[0]);

            for ($i = 0; $i < $count; ++$i) {
                $line = substr($data, $pos, 25);
                if (preg_match('/^(\d{10})\s+(\d{5})\s+([nf])\s*/', $line, $em) !== 1) {
                    break 2;
                }

                $objNum = $startObj + $i;
                if (!isset($entries[$objNum])) {
                    $entries[$objNum] = [
                        'type' => $em[3] === 'n' ? 1 : 0,
                        'field1' => (int) $em[1],
                        'field2' => (int) $em[2],
                    ];
                }
                $pos += strlen($em[0]);
            }
        }

        $dictStart = strpos($data, '<<', $pos);
        if ($dictStart === false) {
            return '';
        }

        $dictEnd = $this->findDictEnd($data, $dictStart);

        return substr($data, $dictStart, $dictEnd - $dictStart);
    }

    /**
     * @param array<int, array{type: int, field1: int, field2: int}> $entries
     */
    private function parseXrefStreamSection(string $dict, string $rawStream, string $data, array &$entries): void
    {
        $decoded = $this->decodeStreamData($dict, $rawStream, $data, $entries);

        $size = $this->extractIntFromDict($dict, 'Size') ?? 0;
        $w = $this->extractIntArrayFromDict($dict, 'W');
        if ($w === null || count($w) < 3) {
            throw new \RuntimeException('Entrée /W manquante dans le flux XRef.');
        }

        $index = $this->extractIntArrayFromDict($dict, 'Index');
        if ($index === null || count($index) < 2) {
            $index = [0, $size];
        }

        $rowLen = $w[0] + $w[1] + $w[2];
        if ($rowLen <= 0) {
            return;
        }

        $streamPos = 0;
        $decodedLen = strlen($decoded);
        $indexCount = count($index);

        for ($p = 0; $p + 1 < $indexCount; $p += 2) {
            $startObj = $index[$p];
            $count = $index[$p + 1];

            for ($i = 0; $i < $count; ++$i) {
                if ($streamPos + $rowLen > $decodedLen) {
                    break 2;
                }

                $type = $w[0] === 0 ? 1 : $this->readBigEndianInt($decoded, $streamPos, $w[0]);
                $field1 = $w[1] === 0 ? 0 : $this->readBigEndianInt($decoded, $streamPos + $w[0], $w[1]);
                $field2 = $w[2] === 0 ? 0 : $this->readBigEndianInt($decoded, $streamPos + $w[0] + $w[1], $w[2]);
                $streamPos += $rowLen;

                $objNum = $startObj + $i;
                if (!isset($entries[$objNum])) {
                    $entries[$objNum] = [
                        'type' => $type,
                        'field1' => $field1,
                        'field2' => $field2,
                    ];
                }
            }
        }
    }

    private function readBigEndianInt(string $bytes, int $offset, int $length): int
    {
        $val = 0;
        for ($i = 0; $i < $length; ++$i) {
            $val = ($val << 8) | ord($bytes[$offset + $i]);
        }

        return $val;
    }

    /**
     * @param array<int, array{type: int, field1: int, field2: int}> $entries
     */
    private function decodeStreamData(string $dict, string $rawStream, string $fullData, array $entries): string
    {
        $filters = $this->extractFiltersFromDict($dict);
        $stream = $rawStream;

        foreach ($filters as $filter) {
            if ($filter === 'FlateDecode' || $filter === 'Fl') {
                $uncompressed = @gzuncompress($stream);
                if ($uncompressed === false) {
                    $uncompressed = @gzinflate($stream);
                }
                if ($uncompressed === false) {
                    $uncompressed = @zlib_decode($stream);
                }
                if ($uncompressed === false) {
                    throw new \RuntimeException('Impossible de décompresser le flux FlateDecode.');
                }
                $stream = $uncompressed;
            } elseif ($filter === 'ASCIIHexDecode' || $filter === 'AHx') {
                $hex = preg_replace('/[^0-9a-fA-F]/', '', $stream) ?? '';
                if (strlen($hex) % 2 !== 0) {
                    $hex .= '0';
                }
                $stream = hex2bin($hex) ?: '';
            }
        }

        $predictor = $this->extractDecodeParmInt($dict, 'Predictor') ?? 1;
        if ($predictor > 1) {
            $columns = $this->extractDecodeParmInt($dict, 'Columns') ?? 1;
            $colors = $this->extractDecodeParmInt($dict, 'Colors') ?? 1;
            $bpc = $this->extractDecodeParmInt($dict, 'BitsPerComponent') ?? 8;
            $stream = $this->applyPredictor($stream, $predictor, $columns, $colors, $bpc);
        }

        return $stream;
    }

    private function applyPredictor(string $data, int $predictor, int $columns, int $colors, int $bpc): string
    {
        if ($predictor === 2) {
            $bpp = max(1, (int) ceil(($colors * $bpc) / 8));
            $rowLen = max(1, (int) ceil(($columns * $colors * $bpc) / 8));
            $len = strlen($data);
            $out = '';
            for ($pos = 0; $pos + $rowLen <= $len; $pos += $rowLen) {
                $row = substr($data, $pos, $rowLen);
                for ($i = $bpp; $i < $rowLen; ++$i) {
                    $row[$i] = chr((ord($row[$i]) + ord($row[$i - $bpp])) & 0xFF);
                }
                $out .= $row;
            }

            return $out;
        }

        if ($predictor >= 10 && $predictor <= 15) {
            $bpp = max(1, (int) ceil(($colors * $bpc) / 8));
            $rowBytes = max(1, (int) ceil(($columns * $colors * $bpc) / 8));
            $stride = $rowBytes + 1;
            $len = strlen($data);
            $prev = str_repeat("\x00", $rowBytes);
            $out = '';

            for ($pos = 0; $pos + $stride <= $len; $pos += $stride) {
                $filterType = ord($data[$pos]);
                $row = substr($data, $pos + 1, $rowBytes);

                if ($filterType === 1) {
                    for ($i = $bpp; $i < $rowBytes; ++$i) {
                        $row[$i] = chr((ord($row[$i]) + ord($row[$i - $bpp])) & 0xFF);
                    }
                } elseif ($filterType === 2) {
                    for ($i = 0; $i < $rowBytes; ++$i) {
                        $row[$i] = chr((ord($row[$i]) + ord($prev[$i])) & 0xFF);
                    }
                } elseif ($filterType === 3) {
                    for ($i = 0; $i < $rowBytes; ++$i) {
                        $left = $i >= $bpp ? ord($row[$i - $bpp]) : 0;
                        $up = ord($prev[$i]);
                        $row[$i] = chr((ord($row[$i]) + (($left + $up) >> 1)) & 0xFF);
                    }
                } elseif ($filterType === 4) {
                    for ($i = 0; $i < $rowBytes; ++$i) {
                        $a = $i >= $bpp ? ord($row[$i - $bpp]) : 0;
                        $b = ord($prev[$i]);
                        $c = $i >= $bpp ? ord($prev[$i - $bpp]) : 0;
                        $p = $a + $b - $c;
                        $pa = abs($p - $a);
                        $pb = abs($p - $b);
                        $pc = abs($p - $c);
                        $pr = ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
                        $row[$i] = chr((ord($row[$i]) + $pr) & 0xFF);
                    }
                }

                $out .= $row;
                $prev = $row;
            }

            return $out;
        }

        return $data;
    }

    /**
     * @return array{body: string, dict: ?string, stream: ?string}|null
     */
    private function extractIndirectObjectAt(string $data, int $offset, ?array $entries = null): ?array
    {
        $len = strlen($data);
        if ($offset < 0 || $offset >= $len) {
            return null;
        }

        $pos = $offset + strspn($data, " \t\r\n\0\x0c", $offset);
        if (preg_match('/^\d+\s+\d+\s+obj\b/', substr($data, $pos, 40), $m) !== 1) {
            return null;
        }

        $bodyStart = $pos + strlen($m[0]);
        $valStart = $bodyStart + strspn($data, " \t\r\n", $bodyStart);

        $dict = null;
        $stream = null;
        $searchEndObjFrom = $valStart;

        if (substr($data, $valStart, 2) === '<<') {
            $dictEnd = $this->findDictEnd($data, $valStart);
            $dict = substr($data, $valStart, $dictEnd - $valStart);
            $afterDict = $dictEnd + strspn($data, " \t\r\n", $dictEnd);

            if (substr($data, $afterDict, 6) === 'stream') {
                $streamStart = $afterDict + 6;
                if (substr($data, $streamStart, 2) === "\r\n") {
                    $streamStart += 2;
                } elseif (isset($data[$streamStart]) && ($data[$streamStart] === "\n" || $data[$streamStart] === "\r")) {
                    ++$streamStart;
                }

                $declaredLen = $this->resolveStreamLength($dict, $data, $entries);
                if ($declaredLen !== null && $streamStart + $declaredLen <= $len) {
                    $stream = substr($data, $streamStart, $declaredLen);
                    $endStreamPos = strpos($data, 'endstream', $streamStart + $declaredLen);
                } else {
                    $endStreamPos = strpos($data, 'endstream', $streamStart);
                    if ($endStreamPos !== false) {
                        $rawSlice = substr($data, $streamStart, $endStreamPos - $streamStart);
                        $stream = rtrim($rawSlice, "\r\n");
                    }
                }

                $searchEndObjFrom = $endStreamPos !== false ? $endStreamPos + 9 : $streamStart;
            } else {
                $searchEndObjFrom = $afterDict;
            }
        }

        $endObjPos = $this->findKeywordOutsideStrings($data, 'endobj', $searchEndObjFrom);
        if ($endObjPos === false) {
            return null;
        }

        return [
            'body' => trim(substr($data, $bodyStart, $endObjPos - $bodyStart)),
            'dict' => $dict,
            'stream' => $stream,
        ];
    }

    /**
     * @param array<int, array{type: int, field1: int, field2: int}>|null $entries
     */
    private function resolveStreamLength(string $dict, string $data, ?array $entries): ?int
    {
        $direct = $this->extractIntFromDict($dict, 'Length');
        if ($direct !== null) {
            return $direct;
        }

        if ($entries !== null && preg_match('/\/Length\s+(\d+)\s+\d+\s+R\b/', $dict, $m) === 1) {
            $lenObjNum = (int) $m[1];
            if (isset($entries[$lenObjNum]) && $entries[$lenObjNum]['type'] === 1) {
                $pos = $entries[$lenObjNum]['field1'];
                if (preg_match('/^\d+\s+\d+\s+obj\s+(\d+)\s+endobj\b/', substr($data, $pos, 64), $lm) === 1) {
                    return (int) $lm[1];
                }
            }
        }

        return null;
    }

    private function findDictEnd(string $data, int $startPos): int
    {
        $len = strlen($data);
        $depth = 0;
        $pos = $startPos;

        while ($pos < $len) {
            $ch = $data[$pos];

            if ($ch === '(') {
                $pos = $this->skipLiteralString($data, $pos);
                continue;
            }

            if ($ch === '%') {
                $nl = strpos($data, "\n", $pos);
                $pos = $nl === false ? $len : $nl + 1;
                continue;
            }

            if ($ch === '<' && ($data[$pos + 1] ?? '') === '<') {
                ++$depth;
                $pos += 2;
                continue;
            }

            if ($ch === '>' && ($data[$pos + 1] ?? '') === '>') {
                --$depth;
                $pos += 2;
                if ($depth <= 0) {
                    return $pos;
                }
                continue;
            }

            ++$pos;
        }

        return $len;
    }

    private function skipLiteralString(string $data, int $startPos): int
    {
        $len = strlen($data);
        $depth = 0;
        $pos = $startPos;

        while ($pos < $len) {
            $ch = $data[$pos];
            if ($ch === '\\') {
                $pos += 2;
                continue;
            }
            if ($ch === '(') {
                ++$depth;
            } elseif ($ch === ')') {
                --$depth;
                if ($depth <= 0) {
                    return $pos + 1;
                }
            }
            ++$pos;
        }

        return $len;
    }

    private function findKeywordOutsideStrings(string $data, string $keyword, int $startPos): int|false
    {
        $len = strlen($data);
        $kwLen = strlen($keyword);
        $pos = $startPos;

        while ($pos < $len) {
            $ch = $data[$pos];
            if ($ch === '(') {
                $pos = $this->skipLiteralString($data, $pos);
                continue;
            }
            if (substr($data, $pos, $kwLen) === $keyword) {
                return $pos;
            }
            ++$pos;
        }

        return false;
    }

    /**
     * @param array{Root: ?string, Info: ?string, Encrypt: ?string} $trailerInfo
     */
    private function captureTrailerInfo(string $dict, array &$trailerInfo): void
    {
        foreach (['Root', 'Info', 'Encrypt'] as $key) {
            if ($trailerInfo[$key] === null && preg_match('/\/' . $key . '\s+(\d+\s+\d+\s+R)\b/', $dict, $m) === 1) {
                $trailerInfo[$key] = $m[1];
            }
        }
    }

    private function isDictionaryType(?string $dict, string $type): bool
    {
        if ($dict === null) {
            return false;
        }

        return preg_match('/\/Type\s*\/' . preg_quote($type, '/') . '\b/', $dict) === 1;
    }

    private function extractIntFromDict(string $dict, string $key): ?int
    {
        if (preg_match('/\/' . preg_quote($key, '/') . '\s+(-?\d+)\b(?!\s+\d+\s+R)/', $dict, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * @return array<int, int>|null
     */
    private function extractIntArrayFromDict(string $dict, string $key): ?array
    {
        if (preg_match('/\/' . preg_quote($key, '/') . '\s*\[([^\]]*)\]/', $dict, $m) !== 1) {
            return null;
        }

        if (preg_match_all('/-?\d+/', $m[1], $nums) < 1) {
            return null;
        }

        return array_map(static fn(string $n): int => (int) $n, $nums[0]);
    }

    /**
     * @return array<int, string>
     */
    private function extractFiltersFromDict(string $dict): array
    {
        if (preg_match('/\/Filter\s*\[([^\]]*)\]/', $dict, $m) === 1) {
            preg_match_all('/\/([A-Za-z0-9]+)/', $m[1], $names);

            return $names[1];
        }

        if (preg_match('/\/Filter\s*\/([A-Za-z0-9]+)/', $dict, $m) === 1) {
            return [$m[1]];
        }

        return [];
    }

    private function extractDecodeParmInt(string $dict, string $key): ?int
    {
        if (preg_match('/\/DecodeParms\s*(?:\[\s*)?<<([^>]*)>>/', $dict, $m) !== 1) {
            return null;
        }

        return $this->extractIntFromDict($m[1], $key);
    }
}
