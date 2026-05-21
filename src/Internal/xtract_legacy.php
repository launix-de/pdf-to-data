<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Smalot\PdfParser\Parser;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\XObject\Image as XObjectImage;
use Smalot\PdfParser\XObject\Form as XObjectForm;

/**
 * Legacy xtract engine.
 *
 * This is the phase-1 PDF normalizer that the public library API wraps.
 *
 * Its job is:
 * - read one PDF and decode text, raster images, and vector-derived graphics
 * - detect repeated headers and footers across pages
 * - remove those repeated regions together with their whitespace
 * - collapse the remaining page content into one continuous coordinate stream
 * - rebuild that stream as absolute-positioned HTML and as structured JSON elements
 *
 * Higher-level business interpretation must happen outside this file on top of the emitted elements.
 *
 * The code is still procedural because it was moved over from the prototype first. The public package
 * surface is object-oriented; this file stays the algorithm workbench until the extraction logic has
 * settled enough for a deeper internal refactor.
 */
const FONT_PX_SCALE = 1.0; // Global scale from PDF units to CSS px for text rendering

/**
 * Determine whether a PDF font name hints at a bold typeface.
 */
function isBoldFont(?string $fontName): bool
{
    $normalized = mb_strtolower((string)($fontName ?? ''));
    return (bool)preg_match('/\b(bold|black|heavy|semibold|semi-bold|demi|demibold|extra[- ]?bold)\b/u', $normalized);
}

/**
 * Resolve the expected channel count for a PDF ColorSpace value.
 *
 * @param mixed $colorSpace Raw ColorSpace entry from the PDF dictionary.
 */
function channelsFromColorSpace($colorSpace, int $fallback = 3): int
{
    $name = null;
    if (is_string($colorSpace)) {
        $name = $colorSpace;
    } elseif (is_array($colorSpace) && $colorSpace !== []) {
        $first = reset($colorSpace);
        $name = is_string($first) ? $first : null;
    }

    if (is_string($name)) {
        $lower = strtolower($name);
        if (strpos($lower, 'devicecmyk') !== false || strpos($lower, 'cmyk') !== false) {
            return 4;
        }
        if (strpos($lower, 'devicergb') !== false || strpos($lower, 'rgb') !== false) {
            return 3;
        }
        if (strpos($lower, 'devicegray') !== false || strpos($lower, 'devicegrey') !== false || strpos($lower, 'gray') !== false || strpos($lower, 'grey') !== false) {
            return 1;
        }
        if (strpos($lower, 'iccbased') !== false) {
            return 3; // Default to RGB; extracting ICC N would be nicer but overkill for now
        }
    }

    return $fallback;
}

/**
 * Convert interleaved 8-bit CMYK pixels into interleaved 8-bit RGB pixels.
 */
function cmyk8ToRgb8(string $raw, int $width, int $height): string
{
    $targetLength = $width * $height * 3;
    $output = str_repeat("\x00", $targetLength);
    $pixelCount = $width * $height;

    for ($i = 0, $src = 0, $dst = 0; $i < $pixelCount; $i++, $src += 4, $dst += 3) {
        $c = ord($raw[$src]);
        $m = ord($raw[$src + 1]);
        $y = ord($raw[$src + 2]);
        $k = ord($raw[$src + 3]);

        $r = 255 - min(255, $c + $k);
        $g = 255 - min(255, $m + $k);
        $b = 255 - min(255, $y + $k);

        $output[$dst] = chr($r);
        $output[$dst + 1] = chr($g);
        $output[$dst + 2] = chr($b);
    }

    return $output;
}

/**
 * Multiply two 2D affine matrices represented as [a b c d e f].
 */
function pdfMatrixMultiply(array $lhs, array $rhs): array
{
    return [
        $lhs[0] * $rhs[0] + $lhs[1] * $rhs[2],
        $lhs[0] * $rhs[1] + $lhs[1] * $rhs[3],
        $lhs[2] * $rhs[0] + $lhs[3] * $rhs[2],
        $lhs[2] * $rhs[1] + $lhs[3] * $rhs[3],
        $lhs[0] * $rhs[4] + $lhs[2] * $rhs[5] + $lhs[4],
        $lhs[1] * $rhs[4] + $lhs[3] * $rhs[5] + $lhs[5],
    ];
}

/**
 * Apply an affine matrix to a point.
 */
function pdfMatrixApply(array $matrix, float $x, float $y): array
{
    return [
        $matrix[0] * $x + $matrix[2] * $y + $matrix[4],
        $matrix[1] * $x + $matrix[3] * $y + $matrix[5],
    ];
}

/**
 * Compute the axis-aligned bounds of an affine-transformed rectangle.
 *
 * The matrix is [a b c d e f] and the local rectangle spans (0,0) to (width,height).
 *
 * @return array{0: float, 1: float, 2: float, 3: float}
 */
function pdfMatrixRectBounds(array $matrix, float $width = 1.0, float $height = 1.0, float $localX = 0.0, float $localY = 0.0): array
{
    [$x0, $y0] = pdfMatrixApply($matrix, $localX, $localY);
    [$x1, $y1] = pdfMatrixApply($matrix, $localX + $width, $localY);
    [$x2, $y2] = pdfMatrixApply($matrix, $localX, $localY + $height);
    [$x3, $y3] = pdfMatrixApply($matrix, $localX + $width, $localY + $height);

    return [
        min($x0, $x1, $x2, $x3),
        min($y0, $y1, $y2, $y3),
        max($x0, $x1, $x2, $x3),
        max($y0, $y1, $y2, $y3),
    ];
}

/**
 * Convert normalised RGB components (0..1) to a CSS hex colour string.
 */
function pdfRgbArrayToHex(array $rgb): string
{
    $r = max(0, min(255, (int)round(($rgb[0] ?? 0) * 255)));
    $g = max(0, min(255, (int)round(($rgb[1] ?? 0) * 255)));
    $b = max(0, min(255, (int)round(($rgb[2] ?? 0) * 255)));

    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

/**
 * Convert normalised CMYK components (0..1) to a CSS hex colour string.
 */
function pdfCmykArrayToHex(array $cmyk): string
{
    $c = max(0.0, min(1.0, (float)($cmyk[0] ?? 0.0)));
    $m = max(0.0, min(1.0, (float)($cmyk[1] ?? 0.0)));
    $y = max(0.0, min(1.0, (float)($cmyk[2] ?? 0.0)));
    $k = max(0.0, min(1.0, (float)($cmyk[3] ?? 0.0)));

    $r = (int)round(255.0 * (1.0 - $c) * (1.0 - $k));
    $g = (int)round(255.0 * (1.0 - $m) * (1.0 - $k));
    $b = (int)round(255.0 * (1.0 - $y) * (1.0 - $k));

    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

/**
 * Build a PNG stream from raw pixel data (8bpc, DeviceGray or DeviceRGB).
 */
function makePng(int $width, int $height, int $channels, int $bitsPerComponent, string $rawPixelData): string
{
    $rowBytes = (int)($width * $channels * ($bitsPerComponent / 8));
    $scanlines = '';
    for ($y = 0; $y < $height; $y++) {
        $offset = $y * $rowBytes;
        $scanlines .= "\x00" . substr($rawPixelData, $offset, $rowBytes);
    }

    $compressed = gzcompress($scanlines, 9);

    $chunk = function (string $type, string $data): string {
        $length = strlen($data);
        $crc = crc32($type . $data);
        return pack('N', $length) . $type . $data . pack('N', $crc & 0xffffffff);
    };

    $signature = "\x89PNG\x0D\x0A\x1A\x0A";
    $ihdr = pack('NNCCCCC', $width, $height, $bitsPerComponent, $channels === 1 ? 0 : 2, 0, 0, 0);

    return $signature . $chunk('IHDR', $ihdr) . $chunk('IDAT', $compressed) . $chunk('IEND', '');
}

/**
 * Convert an embedded PDF image into a browser-friendly data URI.
 *
 * Browsers are unreliable for CMYK JPEGs. We normalize those to RGB PNG so the generated HTML
 * matches the PDF visually instead of depending on viewer-specific colour handling.
 *
 * @param array<string,mixed> $details
 */
function makeDisplayImageDataUri(string $mime, string $content, array $details = []): ?string
{
    if ($content === '') {
        return null;
    }

    $channels = channelsFromColorSpace($details['ColorSpace'] ?? '', 3);
    $isCmykLike = $channels === 4;

    if ($mime === 'image/jpeg' && $isCmykLike) {
        try {
            if (class_exists('Imagick')) {
                $image = new \Imagick();
                $image->readImageBlob($content);
                $image->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
                $image->setImageFormat('png');
                return 'data:image/png;base64,' . base64_encode((string)$image->getImagesBlob());
            }
        } catch (\Throwable $e) {
            // fall through to raw data URI below
        }
    }

    return 'data:' . $mime . ';base64,' . base64_encode($content);
}

/**
 * Decode PNG/TIFF predictor bytes used by Flate/LZW-compressed PDF image streams.
 *
 * @param mixed $decodeParams Raw DecodeParms entry from the PDF dictionary.
 */
function decodePredictorData(string $raw, $decodeParams, int $width, int $channels, int $bitsPerComponent = 8): string
{
    if ($raw === '' || $width <= 0 || $channels <= 0 || $bitsPerComponent <= 0) {
        return $raw;
    }

    $params = [];
    if (is_array($decodeParams)) {
        $params = $decodeParams;
    } elseif (is_object($decodeParams)) {
        $params = (array)$decodeParams;
    }

    $predictor = (int)($params['Predictor'] ?? $params['P'] ?? 1);
    if ($predictor <= 1) {
        return $raw;
    }

    $colors = max(1, (int)($params['Colors'] ?? $params['C'] ?? $channels));
    $bits = max(1, (int)($params['BitsPerComponent'] ?? $params['BPC'] ?? $bitsPerComponent));
    $columns = max(1, (int)($params['Columns'] ?? $params['Cols'] ?? $width));
    $bytesPerPixel = max(1, (int)ceil(($colors * $bits) / 8));
    $rowBytes = max(1, (int)ceil(($columns * $colors * $bits) / 8));

    if ($predictor === 2) {
        if ($bits !== 8) {
            return $raw;
        }
        $decoded = '';
        $length = strlen($raw);
        for ($offset = 0; $offset + $rowBytes <= $length; $offset += $rowBytes) {
            $row = substr($raw, $offset, $rowBytes);
            $out = $row;
            for ($i = $bytesPerPixel; $i < $rowBytes; $i++) {
                $out[$i] = chr((ord($out[$i]) + ord($out[$i - $bytesPerPixel])) & 0xff);
            }
            $decoded .= $out;
        }
        return $decoded;
    }

    if ($predictor < 10 || $predictor > 15 || $bits !== 8) {
        return $raw;
    }

    $decoded = '';
    $prev = str_repeat("\x00", $rowBytes);
    $length = strlen($raw);

    for ($offset = 0; $offset < $length;) {
        $filter = ord($raw[$offset]);
        $offset++;
        if ($offset + $rowBytes > $length) {
            break;
        }

        $row = substr($raw, $offset, $rowBytes);
        $offset += $rowBytes;
        $out = $row;

        switch ($filter) {
            case 0:
                break;

            case 1:
                for ($i = $bytesPerPixel; $i < $rowBytes; $i++) {
                    $out[$i] = chr((ord($row[$i]) + ord($out[$i - $bytesPerPixel])) & 0xff);
                }
                break;

            case 2:
                for ($i = 0; $i < $rowBytes; $i++) {
                    $out[$i] = chr((ord($row[$i]) + ord($prev[$i])) & 0xff);
                }
                break;

            case 3:
                for ($i = 0; $i < $rowBytes; $i++) {
                    $left = $i >= $bytesPerPixel ? ord($out[$i - $bytesPerPixel]) : 0;
                    $up = ord($prev[$i]);
                    $out[$i] = chr((ord($row[$i]) + intdiv($left + $up, 2)) & 0xff);
                }
                break;

            case 4:
                for ($i = 0; $i < $rowBytes; $i++) {
                    $a = $i >= $bytesPerPixel ? ord($out[$i - $bytesPerPixel]) : 0;
                    $b = ord($prev[$i]);
                    $c = $i >= $bytesPerPixel ? ord($prev[$i - $bytesPerPixel]) : 0;
                    $p = $a + $b - $c;
                    $pa = abs($p - $a);
                    $pb = abs($p - $b);
                    $pc = abs($p - $c);
                    $predict = ($pa <= $pb && $pa <= $pc) ? $a : (($pb <= $pc) ? $b : $c);
                    $out[$i] = chr((ord($row[$i]) + $predict) & 0xff);
                }
                break;

            default:
                break;
        }

        $decoded .= $out;
        $prev = $out;
    }

    return $decoded !== '' ? $decoded : $raw;
}

/**
 * @return array{0:int,1:int,2:int,3:int}|null
 */
function softMaskBounds(string $bytes, int $width, int $height, int $threshold = 8): ?array
{
    if ($width <= 0 || $height <= 0) {
        return null;
    }

    $expected = $width * $height;
    if (strlen($bytes) < $expected) {
        return null;
    }

    $minX = $width;
    $minY = $height;
    $maxX = -1;
    $maxY = -1;

    for ($y = 0; $y < $height; $y++) {
        $rowOffset = $y * $width;
        for ($x = 0; $x < $width; $x++) {
            if (ord($bytes[$rowOffset + $x]) <= $threshold) {
                continue;
            }
            if ($x < $minX) $minX = $x;
            if ($y < $minY) $minY = $y;
            if ($x > $maxX) $maxX = $x;
            if ($y > $maxY) $maxY = $y;
        }
    }

    if ($maxX < $minX || $maxY < $minY) {
        return null;
    }

    return [$minX, $minY, $maxX + 1, $maxY + 1];
}

function invertMaskBytes(string $bytes): string
{
    $out = '';
    $len = strlen($bytes);
    for ($i = 0; $i < $len; $i++) {
        $out .= chr(255 - ord($bytes[$i]));
    }
    return $out;
}

/**
 * @return array{bytes:string,crop:array{0:int,1:int,2:int,3:int}}
 */
function normalizeSoftMaskBytes(string $bytes, int $width, int $height, bool $invert = false): array
{
    $bytes = substr($bytes, 0, max(0, $width * $height));
    $fullCrop = [0, 0, max(1, $width), max(1, $height)];
    if ($width <= 0 || $height <= 0 || $bytes === '') {
        return ['bytes' => $bytes, 'crop' => $fullCrop];
    }

    if ($invert) {
        $bytes = invertMaskBytes($bytes);
        return ['bytes' => $bytes, 'crop' => softMaskBounds($bytes, $width, $height) ?? $fullCrop];
    }

    $crop = softMaskBounds($bytes, $width, $height);
    $normalArea = $crop ? max(1, ($crop[2] - $crop[0]) * ($crop[3] - $crop[1])) : ($width * $height);

    $edgeOpaque = 0;
    $edgeCount = 0;
    for ($x = 0; $x < $width; $x++) {
        $edgeCount += 2;
        if (ord($bytes[$x]) > 245) $edgeOpaque++;
        if (ord($bytes[(($height - 1) * $width) + $x]) > 245) $edgeOpaque++;
    }
    for ($y = 1; $y < ($height - 1); $y++) {
        $rowOffset = $y * $width;
        $edgeCount += 2;
        if (ord($bytes[$rowOffset]) > 245) $edgeOpaque++;
        if (ord($bytes[$rowOffset + $width - 1]) > 245) $edgeOpaque++;
    }
    $edgeOpaqueRatio = $edgeCount > 0 ? ($edgeOpaque / $edgeCount) : 0.0;

    if ($edgeOpaqueRatio <= 0.8 || $normalArea < (int)round($width * $height * 0.9)) {
        return ['bytes' => $bytes, 'crop' => $crop ?? $fullCrop];
    }

    $inverted = invertMaskBytes($bytes);
    $invertedCrop = softMaskBounds($inverted, $width, $height);
    if ($invertedCrop === null) {
        return ['bytes' => $bytes, 'crop' => $crop ?? $fullCrop];
    }

    $invertedArea = max(1, ($invertedCrop[2] - $invertedCrop[0]) * ($invertedCrop[3] - $invertedCrop[1]));
    if ($invertedArea < ($normalArea * 0.85)) {
        return ['bytes' => $inverted, 'crop' => $invertedCrop];
    }

    return ['bytes' => $bytes, 'crop' => $crop ?? $fullCrop];
}

/**
 * Compose an SVG data URI that applies a grayscale mask onto a colour image URI.
 */
function makeMaskedSvgDataUri(string $colorDataUri, string $maskDataUri, float $width, float $height, ?array $crop = null): string
{
    $width = max(1.0, $width);
    $height = max(1.0, $height);
    $cropX = 0.0;
    $cropY = 0.0;
    $cropW = $width;
    $cropH = $height;
    if (is_array($crop) && count($crop) >= 4) {
        $cropX = max(0.0, (float)$crop[0]);
        $cropY = max(0.0, (float)$crop[1]);
        $cropW = max(1.0, min($width - $cropX, (float)$crop[2] - (float)$crop[0]));
        $cropH = max(1.0, min($height - $cropY, (float)$crop[3] - (float)$crop[1]));
    }

    $svg = [];
    $svg[] = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $cropW . '" height="' . $cropH . '" viewBox="0 0 ' . $cropW . ' ' . $cropH . '">';
    $svg[] = '<defs><mask id="m" maskUnits="userSpaceOnUse" maskContentUnits="userSpaceOnUse" x="0" y="0" width="' . $cropW . '" height="' . $cropH . '">';
    $svg[] = '<image x="' . (-$cropX) . '" y="' . (-$cropY) . '" width="' . $width . '" height="' . $height . '" href="' . $maskDataUri . '" />';
    $svg[] = '</mask></defs>';
    $svg[] = '<image x="' . (-$cropX) . '" y="' . (-$cropY) . '" width="' . $width . '" height="' . $height . '" href="' . $colorDataUri . '" mask="url(#m)" />';
    $svg[] = '</svg>';

    return 'data:image/svg+xml;base64,' . base64_encode(implode('', $svg));
}

/**
 * Overlay PDF text snippets on top of a background image inside an SVG.
 */
function makeImageWithTextsSvg(string $backgroundUri, float $width, float $height, array $texts, float $fontScale = FONT_PX_SCALE): string
{
    $width = max(1.0, $width);
    $height = max(1.0, $height);
    $escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $svg = [];
    $svg[] = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">';
    $svg[] = '<image x="0" y="0" width="' . $width . '" height="' . $height . '" href="' . $backgroundUri . '" />';

    foreach ($texts as $text) {
        $x = (float)($text['x'] ?? 0.0);
        $y = (float)($text['y'] ?? 0.0);
        $fontSize = (float)($text['font_size'] ?? 10.0) * $fontScale;
        $isBold = !empty($text['bold']);
        $content = $escape($text['text'] ?? '');
        $fontWeight = $isBold ? '700' : '400';
        $ySvg = max(0.0, $height - $y - $fontSize);

        $svg[] = '<text x="' . $x . '" y="' . $ySvg . '" style="font-family:Arial,Helvetica,sans-serif;font-size:' . $fontSize . 'px;font-weight:' . $fontWeight . ';fill:#000;dominant-baseline:text-before-edge">' . $content . '</text>';
    }

    $svg[] = '</svg>';

    return 'data:image/svg+xml;base64,' . base64_encode(implode('', $svg));
}

function decodeSvgDataUri(string $dataUri): ?string
{
    if (!str_starts_with($dataUri, 'data:image/svg+xml')) {
        return null;
    }
    $comma = strpos($dataUri, ',');
    if ($comma === false) {
        return null;
    }
    if (str_contains(substr($dataUri, 0, $comma), ';base64')) {
        $decoded = base64_decode(substr($dataUri, $comma + 1), true);
        return is_string($decoded) ? $decoded : null;
    }
    return rawurldecode(substr($dataUri, $comma + 1));
}

/**
 * @return array{0:float,1:float,2:float,3:float}|null
 */
function rawItemBounds(array $item): ?array
{
    if (($item['type'] ?? '') !== 'image') {
        return null;
    }

    if (isset($item['tm_a'])) {
        $matrix = [
            (float)($item['tm_a'] ?? 0.0),
            (float)($item['tm_b'] ?? 0.0),
            (float)($item['tm_c'] ?? 0.0),
            (float)($item['tm_d'] ?? 0.0),
            (float)($item['tm_e'] ?? $item['x'] ?? 0.0),
            (float)($item['tm_f'] ?? $item['y'] ?? 0.0),
        ];
        $objW = max(1.0, (float)($item['object_width'] ?? 1.0));
        $objH = max(1.0, (float)($item['object_height'] ?? 1.0));
        $objX = (float)($item['object_min_x'] ?? 0.0);
        $objY = (float)($item['object_min_y'] ?? 0.0);
        return pdfMatrixRectBounds($matrix, $objW, $objH, $objX, $objY);
    }

    $minX = (float)($item['x'] ?? 0.0);
    $minY = (float)($item['y'] ?? 0.0);
    return [
        $minX,
        $minY,
        $minX + max(0.0, (float)($item['render_w'] ?? 0.0)),
        $minY + max(0.0, (float)($item['render_h'] ?? 0.0)),
    ];
}

function makeCompositeImageDataUri(array $layers, float $minX, float $minY, float $maxX, float $maxY): string
{
    $width = max(1.0, $maxX - $minX);
    $height = max(1.0, $maxY - $minY);
    $svg = [];
    $svg[] = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">';
    foreach ($layers as $layer) {
        [$lx0, $ly0, $lx1, $ly1] = $layer['bounds'];
        $layerWidth = max(1.0, $lx1 - $lx0);
        $layerHeight = max(1.0, $ly1 - $ly0);
        $x = $lx0 - $minX;
        $y = $height - ($ly1 - $minY);
        $svg[] = '<image x="' . $x . '" y="' . $y . '" width="' . $layerWidth . '" height="' . $layerHeight . '" href="' . htmlspecialchars((string)$layer['href'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" />';
    }
    $svg[] = '</svg>';
    return 'data:image/svg+xml;base64,' . base64_encode(implode('', $svg));
}

function mergeOverlappingImageLayers(array $items): array
{
    $byPage = [];
    foreach ($items as $index => $item) {
        if (($item['type'] ?? '') !== 'image') {
            continue;
        }
        $pageIndex = (int)($item['page_index'] ?? 0);
        $bounds = rawItemBounds($item);
        if ($bounds === null) {
            continue;
        }
        $svg = decodeSvgDataUri((string)($item['dataUri'] ?? ''));
        $byPage[$pageIndex][] = [
            'index' => $index,
            'item' => $item,
            'bounds' => $bounds,
            'svg' => $svg,
        ];
    }

    if ($byPage === []) {
        return $items;
    }

    $remove = [];
    $add = [];

    foreach ($byPage as $pageItems) {
        foreach ($pageItems as $candidate) {
            if (isset($remove[$candidate['index']])) {
                continue;
            }
            $svg = $candidate['svg'];
            if (!is_string($svg) || !str_contains($svg, '<mask') || !str_contains($svg, '<image')) {
                continue;
            }

            [$baseMinX, $baseMinY, $baseMaxX, $baseMaxY] = $candidate['bounds'];
            $layers = [
                [
                    'href' => (string)$candidate['item']['dataUri'],
                    'bounds' => $candidate['bounds'],
                ],
            ];
            $matched = [];
            $unionMinX = $baseMinX;
            $unionMinY = $baseMinY;
            $unionMaxX = $baseMaxX;
            $unionMaxY = $baseMaxY;

            foreach ($pageItems as $overlay) {
                if ($overlay['index'] === $candidate['index'] || isset($remove[$overlay['index']])) {
                    continue;
                }
                $overlaySvg = $overlay['svg'];
                if (!is_string($overlaySvg) || !str_contains($overlaySvg, '<path') || str_contains($overlaySvg, '<mask')) {
                    continue;
                }
                [$ox0, $oy0, $ox1, $oy1] = $overlay['bounds'];
                if ($ox0 < $baseMinX - 6.0 || $oy0 < $baseMinY - 6.0 || $ox1 > $baseMaxX + 6.0 || $oy1 > $baseMaxY + 6.0) {
                    continue;
                }
                $matched[] = $overlay['index'];
                $layers[] = [
                    'href' => (string)$overlay['item']['dataUri'],
                    'bounds' => $overlay['bounds'],
                ];
                $unionMinX = min($unionMinX, $ox0);
                $unionMinY = min($unionMinY, $oy0);
                $unionMaxX = max($unionMaxX, $ox1);
                $unionMaxY = max($unionMaxY, $oy1);
            }

            if ($matched === []) {
                continue;
            }

            $remove[$candidate['index']] = true;
            foreach ($matched as $index) {
                $remove[$index] = true;
            }

            $composite = $candidate['item'];
            unset(
                $composite['tm_a'],
                $composite['tm_b'],
                $composite['tm_c'],
                $composite['tm_d'],
                $composite['tm_e'],
                $composite['tm_f'],
                $composite['object_min_x'],
                $composite['object_min_y'],
                $composite['object_width'],
                $composite['object_height']
            );
            $composite['x'] = $unionMinX;
            $composite['y'] = $unionMinY;
            $composite['render_w'] = $unionMaxX - $unionMinX;
            $composite['render_h'] = $unionMaxY - $unionMinY;
            $composite['dataUri'] = makeCompositeImageDataUri($layers, $unionMinX, $unionMinY, $unionMaxX, $unionMaxY);
            $add[] = $composite;
        }
    }

    if ($remove === [] || $add === []) {
        return $items;
    }

    $merged = [];
    foreach ($items as $index => $item) {
        if (!isset($remove[$index])) {
            $merged[] = $item;
        }
    }
    foreach ($add as $item) {
        $merged[] = $item;
    }

    usort($merged, static function (array $left, array $right): int {
        $pageCompare = ((int)($left['page_index'] ?? 0)) <=> ((int)($right['page_index'] ?? 0));
        if ($pageCompare !== 0) {
            return $pageCompare;
        }
        $yCompare = ((float)($right['y'] ?? 0.0)) <=> ((float)($left['y'] ?? 0.0));
        if ($yCompare !== 0) {
            return $yCompare;
        }
        return ((float)($left['x'] ?? 0.0)) <=> ((float)($right['x'] ?? 0.0));
    });

    return $merged;
}

/**
 * Run an OCR fallback by producing a searchable PDF and recursively invoking this script on it.
 */
function runOcrFallbackExtraction(string $sourcePdf, string $scriptPath): bool
{
    $ocrmypdf = trim((string)@shell_exec('command -v ocrmypdf 2>/dev/null'));
    if ($ocrmypdf === '') {
        return false;
    }

    $tmpDir = sys_get_temp_dir() . '/versco-ocr-' . bin2hex(random_bytes(6));
    if (!@mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
        return false;
    }

    $ocrPdf = $tmpDir . '/ocr.pdf';
    $ocrCmd = sprintf(
        '%s --force-ocr --output-type pdf %s %s >/dev/null 2>&1',
        escapeshellarg($ocrmypdf),
        escapeshellarg($sourcePdf),
        escapeshellarg($ocrPdf)
    );
    exec($ocrCmd, $_, $ocrCode);
    if ($ocrCode !== 0 || !is_file($ocrPdf)) {
        return false;
    }

    $phpBin = PHP_BINARY ?: 'php';
    $childCmd = sprintf(
        'XTRACT_OCR_FALLBACK_ACTIVE=1 %s %s %s >/dev/null 2>&1',
        escapeshellarg($phpBin),
        escapeshellarg($scriptPath),
        escapeshellarg($ocrPdf)
    );
    exec('cd ' . escapeshellarg($tmpDir) . ' && ' . $childCmd, $_, $childCode);
    if ($childCode !== 0) {
        return false;
    }

    $childJson = $tmpDir . '/out2.json';
    $childHtml = $tmpDir . '/out2.html';
    if (!is_file($childJson)) {
        return false;
    }

    @copy($childJson, 'out2.json');
    if (is_file($childHtml)) {
        @copy($childHtml, 'out2.html');
    }

    return true;
}

/**
 * Extract text lines with coordinates via pdftotext -bbox-layout.
 *
 * @return array<int,array<string,mixed>>
 */
function extractBboxLayoutTextItems(string $pdfFile, array $pageHeights): array
{
    $pdftotext = trim((string)@shell_exec('command -v pdftotext 2>/dev/null'));
    if ($pdftotext === '') {
        return [];
    }

    $cmd = sprintf(
        '%s -bbox-layout %s - 2>/dev/null',
        escapeshellarg($pdftotext),
        escapeshellarg($pdfFile)
    );
    $html = @shell_exec($cmd);
    if (!is_string($html) || trim($html) === '') {
        return [];
    }

    $items = [];
    $dom = new DOMDocument();
    $prevUseErrors = libxml_use_internal_errors(true);
    $loaded = $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($prevUseErrors);
    if (!$loaded) {
        return [];
    }

    $pages = $dom->getElementsByTagName('page');
    foreach ($pages as $pageIndex0 => $pageNode) {
        $pageHeight = (float)($pageHeights[$pageIndex0] ?? 0.0);
        if ($pageHeight <= 0.0) {
            continue;
        }
        $lines = $pageNode->getElementsByTagName('line');
        foreach ($lines as $lineNode) {
            if (!$lineNode instanceof DOMElement) {
                continue;
            }
            $words = $lineNode->getElementsByTagName('word');
            $parts = [];
            foreach ($words as $wordNode) {
                $text = trim((string)$wordNode->textContent);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
            if ($parts === []) {
                continue;
            }
            $attr = static function (DOMElement $node, string $name): string {
                $direct = $node->getAttribute($name);
                if ($direct !== '') {
                    return $direct;
                }
                return $node->getAttribute(mb_strtolower($name, 'UTF-8'));
            };
            $xMin = (float)$attr($lineNode, 'xMin');
            $yMin = (float)$attr($lineNode, 'yMin');
            $yMax = (float)$attr($lineNode, 'yMax');
            $lineHeight = max(6.0, $yMax - $yMin);
            $items[] = [
                'type' => 'text',
                'page_index' => $pageIndex0,
                'x' => $xMin,
                'y' => max(0.0, $pageHeight - $yMax),
                'page_height' => $pageHeight,
                'text' => implode(' ', $parts),
                'bold' => false,
                'font_size' => $lineHeight,
            ];
        }
    }

    return $items;
}

/**
 * Light helper for mapping PDF Filter values to a MIME type we can embed.
 */
function detectMimeFromFilter($filterValue): ?string
{
    $filters = [];
    if (is_array($filterValue)) {
        foreach ($filterValue as $entry) {
            $filters[] = (string)$entry;
        }
    } elseif ($filterValue !== null) {
        $filters[] = (string)$filterValue;
    }

    foreach ($filters as $filter) {
        if (stripos($filter, 'DCTDecode') !== false) {
            return 'image/jpeg';
        }
        if (stripos($filter, 'JPXDecode') !== false) {
            return 'image/jp2';
        }
    }

    return null;
}

// Usage: php xtract.php <file.pdf>
if ($argc < 2) {
    fwrite(STDERR, "Usage: php xtract.php <file.pdf>\n");
    exit(1);
}

$pdfFile = $argv[1];
if (!is_file($pdfFile)) {
    fwrite(STDERR, "File not found: {$pdfFile}\n");
    exit(1);
}

$parser = new Parser();
// Ensure DataTm includes font id/size for style detection
$parser->getConfig()->setDataTmFontInfoHasToBeIncluded(true);

// Global scale from PDF font units to CSS px
// Keep at 1.0 unless you need to uniformly upscale/downscale text.
// NOTE: helper functions (isBoldFont, channelsFromColorSpace, ...) live above for easier reuse.

/**
 * Render a Form XObject (vector content + text) into an SVG string.
 *
 * @return array{svg_xml:string,width:float,height:float}
 */
function renderFormToSvg(XObjectForm $form): array
{
    // Figure out the intrinsic bounding box so that the caller can position the SVG correctly.
    $minX = 0.0;
    $minY = 0.0;
    $maxX = 200.0;
    $maxY = 200.0;
    try {
        $details = $form->getDetails(true);
        if (isset($details['BBox']) && is_array($details['BBox']) && count($details['BBox']) >= 4) {
            $minX = (float)$details['BBox'][0];
            $minY = (float)$details['BBox'][1];
            $maxX = (float)$details['BBox'][2];
            $maxY = (float)$details['BBox'][3];
        }
    } catch (\Throwable $exception) {
        // Leave defaults in place if the form does not expose a bounding box.
    }

    $width = max(1.0, $maxX - $minX);
    $height = max(1.0, $maxY - $minY);

    // Minimal graphics state stack for the drawing commands inside the form.
    $ctm = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
    $stack = [];
    $stroke = '#000000';
    $fill = 'none';
    $lineWidth = 1.0;
    $fillRule = 'nonzero';
    $path = [];
    $elements = [];

    $flushPath = function (string $paintOperator) use (&$path, &$elements, &$stroke, &$fill, &$lineWidth, &$fillRule): void {
        if ($path === []) {
            return;
        }

        $d = '';
        foreach ($path as $segment) {
            switch ($segment['op']) {
                case 'M':
                    $d .= 'M ' . $segment['x'] . ' ' . $segment['y'] . ' ';
                    break;
                case 'L':
                    $d .= 'L ' . $segment['x'] . ' ' . $segment['y'] . ' ';
                    break;
                case 'C':
                    $d .= 'C ' . $segment['x1'] . ' ' . $segment['y1'] . ' ' . $segment['x2'] . ' ' . $segment['y2'] . ' ' . $segment['x'] . ' ' . $segment['y'] . ' ';
                    break;
                case 'Z':
                    $d .= 'Z ';
                    break;
            }
        }

        $useFill = in_array($paintOperator, ['f', 'F', 'f*', 'B', 'B*', 'b', 'b*'], true);
        $useStroke = in_array($paintOperator, ['S', 's', 'B', 'B*', 'b', 'b*'], true);
        $shouldClose = in_array($paintOperator, ['s', 'b', 'b*'], true);
        if ($shouldClose && end($path)['op'] !== 'Z') {
            $d .= 'Z ';
        }

        $elements[] = [
            'type' => 'path',
            'd' => trim($d),
            'stroke' => $useStroke ? $stroke : 'none',
            'fill' => $useFill ? $fill : 'none',
            'lineWidth' => $lineWidth,
            'fillRule' => ($paintOperator === 'f*' || $paintOperator === 'B*' || $paintOperator === 'b*') ? 'evenodd' : $fillRule,
        ];

        $path = [];
    };

    $resolveXObject = static function ($container, string $name) {
        try {
            $resources = $container->get('Resources');
            if (method_exists($resources, 'has') && $resources->has('XObject')) {
                $xObjects = $resources->get('XObject');
                $elements = $xObjects instanceof \Smalot\PdfParser\Header ? $xObjects->getElements() : $xObjects->getHeader()->getElements();
                if (isset($elements[$name])) {
                    return $elements[$name];
                }
            }
        } catch (\Throwable $exception) {
            // Ignore lookup errors; the caller will simply skip the asset.
        }

        return null;
    };

    try {
        $sections = $form->getSectionsText($form->getContent());
    } catch (\Throwable $exception) {
        $sections = [];
    }

    foreach ($sections as $section) {
        $commands = $form->getCommandsText($section);
        foreach ($commands as $command) {
            $operator = $command['o'] ?? null;
            if (!$operator) {
                continue;
            }

            switch ($operator) {
                case 'q':
                    $stack[] = [$ctm, $stroke, $fill, $lineWidth, $fillRule];
                    break;

                case 'Q':
                    if ($stack !== []) {
                        [$ctm, $stroke, $fill, $lineWidth, $fillRule] = array_pop($stack);
                    }
                    break;

                case 'cm':
                    $values = preg_split('/\s+/', trim((string)($command['c'] ?? '')));
                    if (count($values) >= 6) {
                        $newMatrix = [
                            (float)$values[0],
                            (float)$values[1],
                            (float)$values[2],
                            (float)$values[3],
                            (float)$values[4],
                            (float)$values[5],
                        ];
                        $ctm = pdfMatrixMultiply($ctm, $newMatrix);
                    }
                    break;

                case 'w':
                    $lineWidth = (float)trim((string)($command['c'] ?? ''));
                    break;

                case 'RG':
                    $values = preg_split('/\s+/', trim((string)($command['c'] ?? '')));
                    if (count($values) >= 3) {
                        $stroke = pdfRgbArrayToHex([(float)$values[0], (float)$values[1], (float)$values[2]]);
                    }
                    break;

                case 'rg':
                    $values = preg_split('/\s+/', trim((string)($command['c'] ?? '')));
                    if (count($values) >= 3) {
                        $fill = pdfRgbArrayToHex([(float)$values[0], (float)$values[1], (float)$values[2]]);
                    }
                    break;

                case 'K':
                    $values = preg_split('/\s+/', trim((string)($command['c'] ?? '')));
                    if (count($values) >= 4) {
                        $stroke = pdfCmykArrayToHex([(float)$values[0], (float)$values[1], (float)$values[2], (float)$values[3]]);
                    }
                    break;

                case 'k':
                    $values = preg_split('/\s+/', trim((string)($command['c'] ?? '')));
                    if (count($values) >= 4) {
                        $fill = pdfCmykArrayToHex([(float)$values[0], (float)$values[1], (float)$values[2], (float)$values[3]]);
                    }
                    break;

                case 'G':
                    $gray = (float)trim((string)($command['c'] ?? ''));
                    $stroke = pdfRgbArrayToHex([$gray, $gray, $gray]);
                    break;

                case 'g':
                    $gray = (float)trim((string)($command['c'] ?? ''));
                    $fill = pdfRgbArrayToHex([$gray, $gray, $gray]);
                    break;

                case 'm':
                    $values = preg_split('/\s+/', trim((string)($command['c'] ?? '')));
                    if (count($values) >= 2) {
                        [$x, $y] = pdfMatrixApply($ctm, (float)$values[0], (float)$values[1]);
                        $path[] = ['op' => 'M', 'x' => $x, 'y' => $y];
                    }
                    break;

                case 'l':
                    $values = preg_split('/\s+/', trim((string)($command['c'] ?? '')));
                    if (count($values) >= 2) {
                        [$x, $y] = pdfMatrixApply($ctm, (float)$values[0], (float)$values[1]);
                        $path[] = ['op' => 'L', 'x' => $x, 'y' => $y];
                    }
                    break;

                case 'c':
                    $values = preg_split('/\s+/', trim((string)($command['c'] ?? '')));
                    if (count($values) >= 6) {
                        [$x1, $y1] = pdfMatrixApply($ctm, (float)$values[0], (float)$values[1]);
                        [$x2, $y2] = pdfMatrixApply($ctm, (float)$values[2], (float)$values[3]);
                        [$x3, $y3] = pdfMatrixApply($ctm, (float)$values[4], (float)$values[5]);
                        $path[] = ['op' => 'C', 'x1' => $x1, 'y1' => $y1, 'x2' => $x2, 'y2' => $y2, 'x' => $x3, 'y' => $y3];
                    }
                    break;

                case 'h':
                    $path[] = ['op' => 'Z'];
                    break;

                case 're':
                    $values = preg_split('/\s+/', trim((string)($command['c'] ?? '')));
                    if (count($values) >= 4) {
                        $x = (float)$values[0];
                        $y = (float)$values[1];
                        $w = (float)$values[2];
                        $h = (float)$values[3];
                        [$x0, $y0] = pdfMatrixApply($ctm, $x, $y);
                        [$x1, $y1] = pdfMatrixApply($ctm, $x + $w, $y);
                        [$x2, $y2] = pdfMatrixApply($ctm, $x + $w, $y + $h);
                        [$x3, $y3] = pdfMatrixApply($ctm, $x, $y + $h);
                        $path[] = ['op' => 'M', 'x' => $x0, 'y' => $y0];
                        $path[] = ['op' => 'L', 'x' => $x1, 'y' => $y1];
                        $path[] = ['op' => 'L', 'x' => $x2, 'y' => $y2];
                        $path[] = ['op' => 'L', 'x' => $x3, 'y' => $y3];
                        $path[] = ['op' => 'Z'];
                    }
                    break;

                case 'S':
                case 's':
                case 'f':
                case 'F':
                case 'f*':
                case 'B':
                case 'B*':
                case 'b':
                case 'b*':
                    $flushPath($operator);
                    break;

                case 'n':
                    $path = [];
                    break;

                case 'Do':
                    $name = ltrim(trim((string)($command['c'] ?? '')), '/');
                    if ($name === '') {
                        break;
                    }

                    $xObject = $resolveXObject($form, $name);
                    if ($xObject instanceof XObjectImage) {
                        $dataUri = null;
                        $widthImage = null;
                        $heightImage = null;

                        try {
                            $detailsImage = $xObject->getDetails(true);
                            $filterValue = $detailsImage['Filter'] ?? '';
                            $colorSpace = $detailsImage['ColorSpace'] ?? '';
                            $widthImage = (int)($detailsImage['Width'] ?? 0);
                            $heightImage = (int)($detailsImage['Height'] ?? 0);
                            $bits = (int)($detailsImage['BitsPerComponent'] ?? 8);

                            if (stripos((string)$filterValue, 'DCTDecode') !== false) {
                                $content = $xObject->getContent();
                                if (is_string($content) && $content !== '') {
                                    $dataUri = makeDisplayImageDataUri('image/jpeg', $content, $detailsImage);
                                }
                            } elseif (stripos((string)$filterValue, 'JPXDecode') !== false) {
                                $dataUri = 'data:image/jp2;base64,' . base64_encode($xObject->getContent());
                            } elseif (stripos((string)$filterValue, 'FlateDecode') !== false && $widthImage > 0 && $heightImage > 0 && $bits === 8) {
                                $channels = channelsFromColorSpace($colorSpace, 0);
                                if ($channels > 0) {
                                    $raw = $xObject->getContent();
                                    if (is_string($raw)) {
                                        $raw = decodePredictorData($raw, $detailsImage['DecodeParms'] ?? null, $widthImage, $channels, $bits);
                                        $expected = $widthImage * $heightImage * $channels;
                                        if (strlen($raw) >= $expected) {
                                            $pixels = substr($raw, 0, $expected);
                                            if ($channels === 4) {
                                                $pixels = cmyk8ToRgb8($pixels, $widthImage, $heightImage);
                                                $channels = 3;
                                            }
                                            $png = makePng($widthImage, $heightImage, $channels, $bits, $pixels);
                                            $dataUri = 'data:image/png;base64,' . base64_encode($png);
                                        }
                                    }
                                }
                            }
                        } catch (\Throwable $exception) {
                            // Ignore decoding issues and fall back to skipping the image.
                        }

                        if ($dataUri) {
                            $elements[] = [
                                'type' => 'image',
                                'href' => $dataUri,
                                'transform' => $ctm,
                                // PDF image XObjects are painted into the unit square and then scaled by the CTM.
                                // Using pixel dimensions here would apply the geometry twice and displace nested
                                // graphics relative to vector paths inside the same form.
                                'width' => 1.0,
                                'height' => 1.0,
                            ];
                        }
                    } elseif ($xObject instanceof XObjectForm) {
                        try {
                            $child = renderFormToSvg($xObject);
                            $svgXml = $child['svg_xml'] ?? '';
                            if ($svgXml !== '') {
                                $elements[] = [
                                    'type' => 'image',
                                    'href' => 'data:image/svg+xml;base64,' . base64_encode($svgXml),
                                    'transform' => $ctm,
                                    'width' => (float)($child['width'] ?? 0.0),
                                    'height' => (float)($child['height'] ?? 0.0),
                                ];
                            }
                        } catch (\Throwable $exception) {
                            // Ignore nested form rendering errors.
                        }
                    }
                    break;

                default:
                    // Unsupported operator -> ignore.
                    break;
            }
        }
    }

    $texts = [];
    try {
        $dataTm = $form->getDataTm();
        foreach ($dataTm as $entry) {
            if (!is_array($entry) || count($entry) < 2) {
                continue;
            }

            $tm = $entry[0];
            $text = (string)$entry[1];
            if ($text === '') {
                continue;
            }

            $fontId = $entry[2] ?? null;
            $fontSize = isset($entry[3]) ? (float)$entry[3] : null;
            $bold = false;
            if ($fontId !== null) {
                try {
                    $font = $form->getFont((string)$fontId);
                    $bold = isBoldFont($font ? $font->getName() : null);
                } catch (\Throwable $exception) {
                    $bold = false;
                }
            }

            $texts[] = [
                'x' => (float)$tm[4],
                'y' => (float)$tm[5],
                'text' => $text,
                'bold' => $bold,
                'font_size' => $fontSize,
            ];
        }
    } catch (\Throwable $exception) {
        // Text extraction is best-effort; ignore decoding failures.
    }

    $escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $svg = [];
    $svg[] = '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int)ceil($width) . '" height="' . (int)ceil($height) . '" viewBox="0 0 ' . $width . ' ' . $height . '">';
    $svg[] = '<g transform="translate(0,' . $height . ') scale(1,-1) translate(' . (-$minX) . ',' . (-$minY) . ')">';

    foreach ($elements as $element) {
        if ($element['type'] === 'path' && $element['d'] !== '') {
            $style = 'fill:' . $element['fill'] . ';stroke:' . $element['stroke'] . ';stroke-width:' . $element['lineWidth'] . ';fill-rule:' . $element['fillRule'] . ';';
            $svg[] = '<path d="' . $element['d'] . '" style="' . $style . '" />';
        } elseif ($element['type'] === 'image' && !empty($element['href'])) {
            $matrix = $element['transform'];
            $widthImg = (float)($element['width'] ?? 0.0);
            $heightImg = (float)($element['height'] ?? 0.0);
            $transform = 'matrix(' . $matrix[0] . ' ' . $matrix[1] . ' ' . $matrix[2] . ' ' . $matrix[3] . ' ' . $matrix[4] . ' ' . $matrix[5] . ')';
            $svg[] = '<g transform="' . $transform . '"><image x="0" y="0" width="' . $widthImg . '" height="' . $heightImg . '" href="' . $element['href'] . '" /></g>';
        }
    }

    foreach ($texts as $text) {
        $fontWeight = !empty($text['bold']) ? '700' : '400';
        $fontSize = ($text['font_size'] !== null) ? (float)$text['font_size'] : 12.0;
        $svg[] = '<text x="' . $text['x'] . '" y="' . $text['y'] . '" style="font-family:Arial,Helvetica,sans-serif;font-size:' . $fontSize . 'px;font-weight:' . $fontWeight . ';fill:#000">' . $escape($text['text']) . '</text>';
    }

    $svg[] = '</g></svg>';

    return [
        'svg_xml' => implode('', $svg),
        'width' => $width,
        'height' => $height,
        'min_x' => $minX,
        'min_y' => $minY,
    ];
}

/**
 * Render page vector graphics (paths only) to an SVG string sized to the page MediaBox.
 *
 * Not currently invoked in the main pipeline, but kept around as a debugging aid when you need to
 * inspect the raw drawing commands for a specific page.
 */
function renderPageVectorsToSvg(Page $page): ?string
{

    // Page size
    $pageWidth = 595.0; $pageHeight = 842.0; $x0 = 0.0; $y0 = 0.0;
    try {
        $pd = $page->getDetails(true);
        if (isset($pd['MediaBox'][2])) $pageWidth = (float)$pd['MediaBox'][2];
        if (isset($pd['MediaBox'][3])) $pageHeight = (float)$pd['MediaBox'][3];
    } catch (\Throwable $e) {}

    $ctm = [1.0,0.0,0.0,1.0,0.0,0.0];
    $stack = [];
    $stroke = '#000000';
    $fill = 'none';
    $lineWidth = 1.0;
    $fillRule = 'nonzero';
    $elements = [];
    $path = [];

    $flush_path = function (string $paintOp) use (&$path, &$elements, &$stroke, &$fill, &$lineWidth, &$fillRule) {
        if (empty($path)) return;
        $d = '';
        foreach ($path as $seg) {
            switch ($seg['op']) {
                case 'M': $d .= 'M '.$seg['x'].' '.$seg['y'].' '; break;
                case 'L': $d .= 'L '.$seg['x'].' '.$seg['y'].' '; break;
                case 'C': $d .= 'C '.$seg['x1'].' '.$seg['y1'].' '.$seg['x2'].' '.$seg['y2'].' '.$seg['x'].' '.$seg['y'].' '; break;
                case 'Z': $d .= 'Z '; break;
            }
        }
        $useFill = in_array($paintOp, ['f','F','f*','B','B*','b','b*'], true);
        $useStroke = in_array($paintOp, ['S','s','B','B*','b','b*'], true);
        $doClose = in_array($paintOp, ['s','b','b*'], true);
        if ($doClose && (!empty($path) && end($path)['op'] !== 'Z')) { $d .= 'Z '; }
        $elements[] = [
            'type' => 'path',
            'd' => trim($d),
            'stroke' => $useStroke ? $stroke : 'none',
            'fill' => $useFill ? $fill : 'none',
            'lineWidth' => $lineWidth,
            'fillRule' => ($paintOp === 'f*' || $paintOp === 'B*' || $paintOp === 'b*') ? 'evenodd' : $fillRule,
        ];
        $path = [];
    };

    // Get raw page content and perform a very light-weight token parse
    $content = '';
    try {
        $obj = $page->get('Contents');
        if ($obj) {
            $raw = $obj->getContent();
            if (is_string($raw)) {
                $content = $raw;
            } elseif (is_array($raw)) {
                foreach ($raw as $part) {
                    try {
                        if (is_string($part)) { $content .= $part; }
                        elseif (is_object($part) && method_exists($part, 'getContent')) { $content .= (string)$part->getContent(); }
                        elseif (is_object($part) && method_exists($part, 'getObject') && method_exists($part->getObject(), 'getContent')) { $content .= (string)$part->getObject()->getContent(); }
                    } catch (\Throwable $e) { /* skip */ }
                }
            } else {
                // Fallback: try to read elements
                try {
                    $elements = $obj->getHeader()->getElements();
                    foreach ($elements as $el) {
                        if (is_object($el) && method_exists($el, 'getContent')) { $content .= (string)$el->getContent(); }
                    }
                } catch (\Throwable $e) {}
            }
        }
    } catch (\Throwable $e) { $content=''; }
    if ($content === '') return null;

    // Remove text strings in parentheses to avoid confusion
    $content = preg_replace('/\((?:\\.|[^\\()])*\)/s', ' ', $content);
    // Tokenize
    $tokens = preg_split('/\s+/', $content);
    $nums = [];
    foreach ($tokens as $tok) {
        if ($tok === '' || $tok === null) continue;
        if (preg_match('/^[-+]?(?:\d+\.?\d*|\.\d+)$/', $tok)) {
            $nums[] = (float)$tok; continue;
        }
        switch ($tok) {
            case 'q': $stack[] = [$ctm,$stroke,$fill,$lineWidth,$fillRule]; $nums=[]; break;
            case 'Q': if (!empty($stack)) { [$ctm,$stroke,$fill,$lineWidth,$fillRule] = array_pop($stack); } $nums=[]; break;
            case 'cm':
                if (count($nums) >= 6) {
                    $vals = array_slice($nums, -6);
                    $nums = [];
                    $new = [(float)$vals[0],(float)$vals[1],(float)$vals[2],(float)$vals[3],(float)$vals[4],(float)$vals[5]];
                    $ctm = pdfMatrixMultiply($ctm, $new);
                } else { $nums=[]; }
                break;
            case 'w': if (count($nums)>=1){ $lineWidth=(float)array_pop($nums);} $nums=[]; break;
            case 'RG': if(count($nums)>=3){ $v=array_slice($nums,-3); $stroke=pdfRgbArrayToHex([$v[0],$v[1],$v[2]]);} $nums=[]; break;
            case 'rg': if(count($nums)>=3){ $v=array_slice($nums,-3); $fill=pdfRgbArrayToHex([$v[0],$v[1],$v[2]]);} $nums=[]; break;
            case 'K': if(count($nums)>=4){ $v=array_slice($nums,-4); $stroke=pdfCmykArrayToHex([$v[0],$v[1],$v[2],$v[3]]);} $nums=[]; break;
            case 'k': if(count($nums)>=4){ $v=array_slice($nums,-4); $fill=pdfCmykArrayToHex([$v[0],$v[1],$v[2],$v[3]]);} $nums=[]; break;
            case 'G': if(count($nums)>=1){ $g=(float)array_pop($nums); $stroke=pdfRgbArrayToHex([$g,$g,$g]);} $nums=[]; break;
            case 'g': if(count($nums)>=1){ $g=(float)array_pop($nums); $fill=pdfRgbArrayToHex([$g,$g,$g]);} $nums=[]; break;
            case 'm': if(count($nums)>=2){ $v=array_slice($nums,-2); $nums=[]; [$X,$Y]=pdfMatrixApply($ctm,(float)$v[0],(float)$v[1]); $path[]=['op'=>'M','x'=>$X,'y'=>$Y]; } else { $nums=[]; } break;
            case 'l': if(count($nums)>=2){ $v=array_slice($nums,-2); $nums=[]; [$X,$Y]=pdfMatrixApply($ctm,(float)$v[0],(float)$v[1]); $path[]=['op'=>'L','x'=>$X,'y'=>$Y]; } else { $nums=[]; } break;
            case 'c': if(count($nums)>=6){ $v=array_slice($nums,-6); $nums=[]; [$x1,$y1]=pdfMatrixApply($ctm,(float)$v[0],(float)$v[1]); [$x2,$y2]=pdfMatrixApply($ctm,(float)$v[2],(float)$v[3]); [$x3,$y3]=pdfMatrixApply($ctm,(float)$v[4],(float)$v[5]); $path[]=['op'=>'C','x1'=>$x1,'y1'=>$y1,'x2'=>$x2,'y2'=>$y2,'x'=>$x3,'y'=>$y3]; } else { $nums=[]; } break;
            case 'h': $path[]=['op'=>'Z']; $nums=[]; break;
            case 're': if(count($nums)>=4){ $v=array_slice($nums,-4); $nums=[]; $x=(float)$v[0]; $y=(float)$v[1]; $w=(float)$v[2]; $h=(float)$v[3]; [$x0t,$y0t]=pdfMatrixApply($ctm,$x,$y); [$x1t,$y1t]=pdfMatrixApply($ctm,$x+$w,$y); [$x2t,$y2t]=pdfMatrixApply($ctm,$x+$w,$y+$h); [$x3t,$y3t]=pdfMatrixApply($ctm,$x,$y+$h); $path[]=['op'=>'M','x'=>$x0t,'y'=>$y0t]; $path[]=['op'=>'L','x'=>$x1t,'y'=>$y1t]; $path[]=['op'=>'L','x'=>$x2t,'y'=>$y2t]; $path[]=['op'=>'L','x'=>$x3t,'y'=>$y3t]; $path[]=['op'=>'Z']; } else { $nums=[]; } break;
            case 'S': case 's': case 'f': case 'F': case 'f*': case 'B': case 'B*': case 'b': case 'b*': $flush_path($tok); $nums=[]; break;
            case 'n': $path = []; $nums=[]; break;
            case 'W': case 'W*': $nums=[]; break; // ignore clipping
            default:
                // Unknown token: reset numeric stack to avoid cross-operator leakage
                $nums = [];
                break;
        }
    }

    if (empty($elements)) return null; // nothing to draw
    $svg = [];
    $svg[] = '<svg xmlns="http://www.w3.org/2000/svg" width="'.(int)ceil($pageWidth).'" height="'.(int)ceil($pageHeight).'" viewBox="0 0 '.($pageWidth).' '.($pageHeight).'">';
    $svg[] = '<g transform="translate(0,'.($pageHeight).') scale(1,-1)">';
    foreach ($elements as $el) {
        if ($el['type'] === 'path' && $el['d'] !== '') {
            $style = 'fill:'.$el['fill'].';stroke:'.$el['stroke'].';stroke-width:'.$el['lineWidth'].';fill-rule:'.$el['fillRule'].';';
            $svg[] = '<path d="'.$el['d'].'" style="'.$style.'" />';
        }
    }
    $svg[] = '</g></svg>';

    return implode('', $svg);
}

/**
 * Extract page vector graphics into multiple small SVG clusters, ignoring thin lines (e.g. table borders).
 */
function extractPageVectorClusters(Page $page): array
{

    // Page size
    $pageWidth = 595.0; $pageHeight = 842.0;
    try { $pd = $page->getDetails(true); if (isset($pd['MediaBox'][2])) $pageWidth=(float)$pd['MediaBox'][2]; if (isset($pd['MediaBox'][3])) $pageHeight=(float)$pd['MediaBox'][3]; } catch (\Throwable $e) {}

    $ctm = [1.0,0.0,0.0,1.0,0.0,0.0];
    $stack = [];
    $stroke = '#000'; $fill = 'none'; $lineWidth = 1.0; $fillRule='nonzero';
    $path = [];
    $pts = [];
    $segments = [];
    $lineItems = [];

    $add_point = function (float $x,float $y) use (&$pts) { $pts[] = [$x,$y]; };
    $flush_path = function (string $paintOp) use (&$path,&$pts,&$segments,&$lineItems,&$stroke,&$fill,&$lineWidth,&$fillRule) {
        if (empty($path) || empty($pts)) { $path = []; $pts = []; return; }
        $useFill = in_array($paintOp, ['f','F','f*','B','B*','b','b*'], true);
        $useStroke = in_array($paintOp, ['S','s','B','B*','b','b*'], true);
        $doClose = in_array($paintOp, ['s','b','b*'], true);
        $d = '';
        foreach ($path as $seg) {
            switch ($seg['op']) {
                case 'M': $d.='M '.$seg['x'].' '.$seg['y'].' '; break;
                case 'L': $d.='L '.$seg['x'].' '.$seg['y'].' '; break;
                case 'C': $d.='C '.$seg['x1'].' '.$seg['y1'].' '.$seg['x2'].' '.$seg['y2'].' '.$seg['x'].' '.$seg['y'].' '; break;
                case 'Z': $d.='Z '; break;
            }
        }
        if ($doClose && end($path)['op'] !== 'Z') $d.='Z ';
        // bbox
        $minX = INF; $minY = INF; $maxX = -INF; $maxY = -INF;
        foreach ($pts as [$x,$y]) { if ($x<$minX)$minX=$x; if($y<$minY)$minY=$y; if($x>$maxX)$maxX=$x; if($y>$maxY)$maxY=$y; }
        $w = max(0.0,$maxX-$minX); $h = max(0.0,$maxY-$minY);
        // ignore thin lines: stroke only, very thin in one axis and small linewidth
        $aspect = ($w>0 && $h>0) ? (max($w,$h) / max(0.1,min($w,$h))) : INF;
        $isThinDim = (min($w,$h) <= max(1.5*$lineWidth, 1.2));
        // Treat very thin filled rectangles and thin strokes as lines; avoid clustering them as images
        $isLineish = (($isThinDim && ($useStroke || $useFill)) || ($useStroke && $aspect >= 50));
        if ($isLineish) {
            $lineItems[] = [
                'x' => $minX,
                'y' => $minY,
                'w' => max(0.0,$w),
                'h' => max(0.0,$h),
                'stroke' => $stroke,
                'lineWidth' => $lineWidth,
            ];
        } else {
            $segments[] = [
                'd' => trim($d), 'stroke'=>$useStroke?$stroke:'none', 'fill'=>$useFill?$fill:'none', 'lineWidth'=>$lineWidth,
                'fillRule'=>($paintOp==='f*'||$paintOp==='B*'||$paintOp==='b*')?'evenodd':$fillRule,
                'minX'=>$minX,'minY'=>$minY,'maxX'=>$maxX,'maxY'=>$maxY
            ];
        }
        $path = []; $pts = [];
    };

    // Tokenize page content and build segments
    $content='';
    try { $obj=$page->get('Contents'); if ($obj) { $raw=$obj->getContent(); if (is_string($raw)) $content=$raw; elseif (is_array($raw)) { foreach ($raw as $part) { if (is_string($part)) $content.=$part; elseif (is_object($part) && method_exists($part,'getContent')) $content.=(string)$part->getContent(); } } } } catch (\Throwable $e) {}
    if ($content==='') return [];
    $content=preg_replace('/\((?:\\.|[^\\()])*\)/s',' ',$content);
    $tokens=preg_split('/\s+/',$content);
    $nums=[];
    foreach($tokens as $tok){
        if($tok===''||$tok===null) continue;
        if(preg_match('/^[-+]?(?:\d+\.?\d*|\.\d+)$/',$tok)){ $nums[]=(float)$tok; continue; }
        switch($tok){
            case 'q': $stack[] = [$ctm,$stroke,$fill,$lineWidth,$fillRule]; $nums=[]; break;
            case 'Q': if(!empty($stack)){ [$ctm,$stroke,$fill,$lineWidth,$fillRule]=array_pop($stack);} $nums=[]; break;
            case 'cm': if(count($nums)>=6){ $v=array_slice($nums,-6); $nums=[]; $ctm=pdfMatrixMultiply($ctm,[(float)$v[0],(float)$v[1],(float)$v[2],(float)$v[3],(float)$v[4],(float)$v[5]]);} else {$nums=[];} break;
            case 'w': if(count($nums)>=1){ $lineWidth=(float)array_pop($nums);} $nums=[]; break;
            case 'RG': if(count($nums)>=3){ $v=array_slice($nums,-3); $stroke=pdfRgbArrayToHex([$v[0],$v[1],$v[2]]);} $nums=[]; break;
            case 'rg': if(count($nums)>=3){ $v=array_slice($nums,-3); $fill=pdfRgbArrayToHex([$v[0],$v[1],$v[2]]);} $nums=[]; break;
            case 'K': if(count($nums)>=4){ $v=array_slice($nums,-4); $stroke=pdfCmykArrayToHex([$v[0],$v[1],$v[2],$v[3]]);} $nums=[]; break;
            case 'k': if(count($nums)>=4){ $v=array_slice($nums,-4); $fill=pdfCmykArrayToHex([$v[0],$v[1],$v[2],$v[3]]);} $nums=[]; break;
            case 'G': if(count($nums)>=1){ $g=(float)array_pop($nums); $stroke=pdfRgbArrayToHex([$g,$g,$g]);} $nums=[]; break;
            case 'g': if(count($nums)>=1){ $g=(float)array_pop($nums); $fill=pdfRgbArrayToHex([$g,$g,$g]);} $nums=[]; break;
            case 'm': if(count($nums)>=2){ $v=array_slice($nums,-2); $nums=[]; [$X,$Y]=pdfMatrixApply($ctm,$v[0],$v[1]); $path[]=['op'=>'M','x'=>$X,'y'=>$Y]; $add_point($X,$Y);} else {$nums=[];} break;
            case 'l': if(count($nums)>=2){ $v=array_slice($nums,-2); $nums=[]; [$X,$Y]=pdfMatrixApply($ctm,$v[0],$v[1]); $path[]=['op'=>'L','x'=>$X,'y'=>$Y]; $add_point($X,$Y);} else {$nums=[];} break;
            case 'c': if(count($nums)>=6){ $v=array_slice($nums,-6); $nums=[]; [$x1,$y1]=pdfMatrixApply($ctm,$v[0],$v[1]); [$x2,$y2]=pdfMatrixApply($ctm,$v[2],$v[3]); [$x3,$y3]=pdfMatrixApply($ctm,$v[4],$v[5]); $path[]=['op'=>'C','x1'=>$x1,'y1'=>$y1,'x2'=>$x2,'y2'=>$y2,'x'=>$x3,'y'=>$y3]; $add_point($x1,$y1); $add_point($x2,$y2); $add_point($x3,$y3);} else {$nums=[];} break;
            case 'h': $path[]=['op'=>'Z']; $nums=[]; break;
            case 're': if(count($nums)>=4){ $v=array_slice($nums,-4); $nums=[]; $x=$v[0];$y=$v[1];$w=$v[2];$h=$v[3]; [$x0t,$y0t]=pdfMatrixApply($ctm,$x,$y); [$x1t,$y1t]=pdfMatrixApply($ctm,$x+$w,$y); [$x2t,$y2t]=pdfMatrixApply($ctm,$x+$w,$y+$h); [$x3t,$y3t]=pdfMatrixApply($ctm,$x,$y+$h); $path[]=['op'=>'M','x'=>$x0t,'y'=>$y0t]; $path[]=['op'=>'L','x'=>$x1t,'y'=>$y1t]; $path[]=['op'=>'L','x'=>$x2t,'y'=>$y2t]; $path[]=['op'=>'L','x'=>$x3t,'y'=>$y3t]; $path[]=['op'=>'Z']; $add_point($x0t,$y0t);$add_point($x1t,$y1t);$add_point($x2t,$y2t);$add_point($x3t,$y3t);} else {$nums=[];} break;
            case 'S': case 's': case 'f': case 'F': case 'f*': case 'B': case 'B*': case 'b': case 'b*': $flush_path($tok); $nums=[]; break;
            case 'n': $path=[]; $pts=[]; $nums=[]; break;
            default: $nums=[]; break;
        }
    }

    if (empty($segments)) return ['clusters'=>[], 'lines'=>$lineItems];
    // Cluster segments with bbox overlap, ignoring line-only segments which we filtered
    $clusters = [];
    // Allow a small gap to connect adjacent vector pieces belonging to the same drawing
    // Too small (0.5) was splitting window drawings into separate clusters; 6.0 keeps nearby parts together
    $eps = 6.0;
    foreach ($segments as $seg) {
        $attached = false;
        foreach ($clusters as &$cl) {
            // overlap test
            $minX = min($cl['minX'],$seg['minX']); $minY=min($cl['minY'],$seg['minY']);
            $maxX = max($cl['maxX'],$seg['maxX']); $maxY=max($cl['maxY'],$seg['maxY']);
            $overlap = !($seg['maxX'] + $eps < $cl['minX'] || $seg['minX'] - $eps > $cl['maxX'] || $seg['maxY'] + $eps < $cl['minY'] || $seg['minY'] - $eps > $cl['maxY']);
            if ($overlap) {
                $cl['paths'][] = $seg;
                $cl['minX'] = min($cl['minX'],$seg['minX']); $cl['minY'] = min($cl['minY'],$seg['minY']);
                $cl['maxX'] = max($cl['maxX'],$seg['maxX']); $cl['maxY'] = max($cl['maxY'],$seg['maxY']);
                $attached = true; break;
            }
        }
        unset($cl);
        if (!$attached) {
            $clusters[] = ['paths'=>[$seg], 'minX'=>$seg['minX'], 'minY'=>$seg['minY'], 'maxX'=>$seg['maxX'], 'maxY'=>$seg['maxY']];
        }
    }

    // Second pass: merge clusters whose bboxes are very close (within join gap)
    if (!empty($clusters)) {
        $join_gap = 6.0; // match eps above; close-but-not-overlapping clusters should join
        $changed = true;
        while ($changed) {
            $changed = false;
            $n = count($clusters);
            for ($i = 0; $i < $n; $i++) {
                if (!isset($clusters[$i])) continue;
                for ($j = $i + 1; $j < $n; $j++) {
                    if (!isset($clusters[$j])) continue;
                    $ci = $clusters[$i]; $cj = $clusters[$j];
                    $overlap = !(
                        $cj['maxX'] + $join_gap < $ci['minX'] ||
                        $cj['minX'] - $join_gap > $ci['maxX'] ||
                        $cj['maxY'] + $join_gap < $ci['minY'] ||
                        $cj['minY'] - $join_gap > $ci['maxY']
                    );
                    if ($overlap) {
                        // merge j into i
                        $clusters[$i]['paths'] = array_merge($clusters[$i]['paths'], $clusters[$j]['paths']);
                        $clusters[$i]['minX'] = min($clusters[$i]['minX'], $clusters[$j]['minX']);
                        $clusters[$i]['minY'] = min($clusters[$i]['minY'], $clusters[$j]['minY']);
                        $clusters[$i]['maxX'] = max($clusters[$i]['maxX'], $clusters[$j]['maxX']);
                        $clusters[$i]['maxY'] = max($clusters[$i]['maxY'], $clusters[$j]['maxY']);
                        unset($clusters[$j]);
                        $changed = true;
                    }
                }
            }
            if ($changed) { $clusters = array_values($clusters); }
        }
    }

    // Build small SVG for each cluster
    $out = [];
    foreach ($clusters as $cl) {
        $x0 = $cl['minX']; $y0 = $cl['minY']; $w = max(0.0,$cl['maxX']-$cl['minX']); $h = max(0.0,$cl['maxY']-$cl['minY']);
        if ($w <= 0 || $h <= 0) continue;
        $svg = [];
        $svg[] = '<svg xmlns="http://www.w3.org/2000/svg" width="'.(float)$w.'" height="'.(float)$h.'" viewBox="0 0 '.(float)$w.' '.(float)$h.'">';
        $svg[] = '<g transform="translate(0,'.(float)$h.') scale(1,-1) translate('.(-$x0).','.(-$y0).')">';
        foreach ($cl['paths'] as $pseg) {
            $style = 'fill:'.$pseg['fill'].';stroke:'.$pseg['stroke'].';stroke-width:'.$pseg['lineWidth'].';fill-rule:'.$pseg['fillRule'].';';
            $svg[] = '<path d="'.$pseg['d'].'" style="'.$style.'" />';
        }
        $svg[] = '</g></svg>';
        $out[] = ['x'=>$x0,'y'=>$y0,'w'=>$w,'h'=>$h,'svg'=>'data:image/svg+xml;base64,'.base64_encode(implode('', $svg))];
    }
    return ['clusters' => $out, 'lines' => $lineItems];
}

try {
    $pdf = $parser->parseFile($pdfFile);
    $pages = $pdf->getPages();

    $items = [];
    $totalHeight = 0.0;
    $maxWidth = 0.0;
    $pageHeights = [];
    $pageWidths = [];

    foreach ($pages as $pageIndex0 => $page) {
        if (!$page instanceof Page) continue;

        // Page size
        $pageWidth = null; $pageHeight = null;
        try {
            $pd = $page->getDetails(true);
            if (isset($pd['MediaBox'][2])) $pageWidth = (float)$pd['MediaBox'][2];
            if (isset($pd['MediaBox'][3])) $pageHeight = (float)$pd['MediaBox'][3];
        } catch (\Throwable $e) {}
        $pageWidth = $pageWidth ?? 595.0;  // A4 default
        $pageHeight = $pageHeight ?? 842.0; // A4 default
        $pageHeights[$pageIndex0] = $pageHeight;
        $pageWidths[$pageIndex0] = $pageWidth;
        $totalHeight += $pageHeight;
        $maxWidth = max($maxWidth, $pageWidth);

        // --- Page vectors: lines and clusters ---
        try {
            $res = extractPageVectorClusters($page);
            $clusters = (array)($res['clusters'] ?? []);
            $lines = (array)($res['lines'] ?? []);

            foreach ($lines as $ln) {
                $items[] = [
                    'type' => 'line',
                    'page_index' => $pageIndex0,
                    'x' => (float)($ln['x'] ?? 0.0),
                    'y' => (float)($ln['y'] ?? 0.0),
                    'page_height' => $pageHeight,
                    'render_w' => (float)($ln['w'] ?? 0.0),
                    'render_h' => (float)($ln['h'] ?? 0.0),
                    'color' => (string)($ln['stroke'] ?? '#000'),
                ];
            }

            $filtered = [];
            foreach ($clusters as $cl) {
                $w = (float)($cl['w'] ?? 0.0);
                $h = (float)($cl['h'] ?? 0.0);
                if ($w < 12.0 || $h < 12.0) {
                    continue; // drop tiny fragments that usually belong to glyph outlines
                }
                $filtered[] = $cl;
            }

            if (count($filtered) > 250) {
                usort($filtered, function ($a, $b) {
                    $areaA = (float)($a['w'] ?? 0.0) * (float)($a['h'] ?? 0.0);
                    $areaB = (float)($b['w'] ?? 0.0) * (float)($b['h'] ?? 0.0);
                    return $areaB <=> $areaA;
                });
                $filtered = array_slice($filtered, 0, 250);
            }

            foreach ($filtered as $cl) {
                $items[] = [
                    'type' => 'image',
                    'page_index' => $pageIndex0,
                    'x' => (float)$cl['x'],
                    'y' => (float)$cl['y'],
                    'page_height' => $pageHeight,
                    'dataUri' => (string)$cl['svg'],
                    'render_w' => (float)$cl['w'],
                    'render_h' => (float)$cl['h'],
                ];
            }
        } catch (\Throwable $e) {
            // ignore vector clustering errors
        }

        // --- Text items ---
        $dataTm = $page->getDataTm();
        foreach ($dataTm as $entry) {
            if (!is_array($entry) || count($entry) < 2) continue;
            $tm = $entry[0];
            $text = (string)$entry[1];
            if (!is_array($tm) || count($tm) < 6) continue;
            if ($text === '') continue;

            $x = (float)$tm[4];
            $y = (float)$tm[5];

            $bold = false; $fontSize = isset($entry[3]) ? (float)$entry[3] : null;
            $fontId = $entry[2] ?? null;
            if ($fontId !== null) {
                try { $font = $page->getFont((string)$fontId); $bold = isBoldFont($font ? $font->getName() : null); } catch (\Throwable $e) {}
            }

            $items[] = [
                'type' => 'text',
                'page_index' => $pageIndex0,
                'x' => $x,
                'y' => $y,
                'page_height' => $pageHeight,
                'a' => (float)$tm[0],
                'b' => (float)$tm[1],
                'c' => (float)$tm[2],
                'd' => (float)$tm[3],
                'text' => $text,
                'bold' => $bold,
                'font_size' => $fontSize,
            ];
        }

        // --- Image items (XObject + inline) ---
        $commands = $page->extractRawData();
        $concatTm = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $gsStack = [];
        $mul = function (array $a, array $b): array {
            return [
                $a[0] * $b[0] + $a[1] * $b[2],
                $a[0] * $b[1] + $a[1] * $b[3],
                $a[2] * $b[0] + $a[3] * $b[2],
                $a[2] * $b[1] + $a[3] * $b[3],
                $a[4] * $b[0] + $a[5] * $b[2] + $b[4],
                $a[4] * $b[1] + $a[5] * $b[3] + $b[5],
            ];
        };

        $resolveXObject = function ($container, string $name) {
            if ($container instanceof Page) return $container->getXObject($name);
            try {
                $resources = $container->get('Resources');
                if (method_exists($resources, 'has') && $resources->has('XObject')) {
                    $xobjs = $resources->get('XObject');
                    $elements = $xobjs instanceof \Smalot\PdfParser\Header ? $xobjs->getElements() : $xobjs->getHeader()->getElements();
                    if (isset($elements[$name])) return $elements[$name];
                }
            } catch (\Throwable $e) {}
            return null;
        };

        $isImageXObject = function ($obj): bool {
            if ($obj instanceof XObjectImage) return true;
            try {
                $det = $obj->getDetails(true);
                return (isset($det['Type']) && false !== stripos((string)$det['Type'], 'XObject'))
                    && (isset($det['Subtype']) && false !== stripos((string)$det['Subtype'], 'Image'));
            } catch (\Throwable $e) { return false; }
        };

        $scanXObject = function ($container, array $baseTm) use (&$resolveXObject, &$scanXObject, $isImageXObject, $page, $pageIndex0, $pageHeight, &$items) {
            try { $sections = $container->getSectionsText($container->getContent()); }
            catch (\Throwable $e) { return; }
            $tm = $baseTm; $stack = [];
            // Text state for Tm/Td handling
            $textTm = [1.0,0.0,0.0,1.0,0.0,0.0];
            $Tx = 0.0; $Ty = 0.0; $Tl = 0.0; $fontId = null; $fontSize = 12.0;
            foreach ($sections as $section) {
                $cmds = $container->getCommandsText($section);
                foreach ($cmds as $cmd) {
                    $op = $cmd['o'] ?? null; if (!$op) continue;
                    switch ($op) {
                        case 'q': $stack[] = $tm; break;
                        case 'Q': $tm = count($stack) ? array_pop($stack) : $baseTm; break;
                        case 'cm':
                            $vals = preg_split('/\s+/', trim((string)($cmd['c'] ?? '')));
                            if (count($vals) >= 6) {
                                $new = [(float)$vals[0],(float)$vals[1],(float)$vals[2],(float)$vals[3],(float)$vals[4],(float)$vals[5]];
                                $tm = pdfMatrixMultiply($tm, $new);
                            }
                            break;
                        case 'BT':
                            $textTm = [1.0,0.0,0.0,1.0,0.0,0.0]; $Tx=0.0; $Ty=0.0; $Tl=0.0; break;
                        case 'TL':
                            $Tl = (float)($cmd['c'] ?? 0); break;
                        case 'Td':
                            $vals = preg_split('/\s+/', trim((string)($cmd['c'] ?? '')));
                            if (count($vals) >= 2) { $Tx += (float)$vals[0]; $Ty += (float)$vals[1]; $textTm[4]=(string)$Tx; $textTm[5]=(string)$Ty; }
                            break;
                        case 'TD':
                            $vals = preg_split('/\s+/', trim((string)($cmd['c'] ?? '')));
                            if (count($vals) >= 2) { $Tl = - (float)$vals[1]; $Tx += (float)$vals[0]; $Ty += (float)$vals[1]; $textTm[4]=(string)$Tx; $textTm[5]=(string)$Ty; }
                            break;
                        case 'T*':
                            $Ty -= $Tl; $textTm[5]=(string)$Ty; break;
                        case 'Tm':
                            $vals = preg_split('/\s+/', trim((string)($cmd['c'] ?? '')));
                            if (count($vals) >= 6) {
                                $textTm = [(float)$vals[0],(float)$vals[1],(float)$vals[2],(float)$vals[3],(float)$vals[4],(float)$vals[5]];
                                $Tx = (float)$textTm[4]; $Ty = (float)$textTm[5];
                            }
                            break;
                        case 'Tf':
                            $vals = preg_split('/\s+/', trim((string)($cmd['c'] ?? '')));
                            if (count($vals) >= 2) { $fontId = (string)$vals[0]; $fontSize = (float)$vals[1]; }
                            break;
                        case 'Tj':
                        case "'":
                        case '"':
                            $text = (string)($cmd['c'] ?? '');
                            $T = pdfMatrixMultiply($tm, $textTm);
                            $bold = false;
                            if ($fontId !== null) { try { $font = $container->getFont((string)$fontId); $bold = isBoldFont($font ? $font->getName() : null); } catch (\Throwable $e) {} }
                            $items[] = [
                                'type' => 'text',
                                'page_index' => $pageIndex0,
                                'x' => (float)$T[4],
                                'y' => (float)$T[5],
                                'page_height' => $pageHeight,
                                'a' => (float)$T[0], 'b' => (float)$T[1], 'c' => (float)$T[2], 'd' => (float)$T[3],
                                'text' => $text,
                                'bold' => $bold,
                                'font_size' => $fontSize,
                            ];
                            if ($op === "'") { $Ty -= $Tl; $textTm[5]=(string)$Ty; }
                            break;
                        case 'Do':
                            $name = ltrim(trim((string)($cmd['c'] ?? '')), '/'); if ($name==='') break;
                            $xo = $resolveXObject($container, $name);
                            if ($xo && $isImageXObject($xo)) {
                                // Nested raster/image XObjects are already embedded into the parent form SVG by
                                // renderFormToSvg(). Emitting them again here produces duplicate HTML items in the
                                // parent page coordinate system and displaces mixed vector+raster graphics.
                                break;
                            } elseif ($xo instanceof XObjectForm) {
                                $scanXObject($xo, $tm);
                            }
                            break;
                    }
                }
            }
        };

        $inlineCounter = 0; $pendingInline = null;
        foreach ($commands as $cmd) {
            $op = $cmd['o'] ?? null; if (!$op) continue;
            switch ($op) {
                case 'q': $gsStack[] = $concatTm; break;
                case 'Q': $concatTm = count($gsStack) ? array_pop($gsStack) : [1.0,0.0,0.0,1.0,0.0,0.0]; break;
                case 'cm':
                    $vals = preg_split('/\s+/', trim((string)($cmd['c'] ?? '')));
                    if (count($vals) >= 6) {
                        $new = [(float)$vals[0],(float)$vals[1],(float)$vals[2],(float)$vals[3],(float)$vals[4],(float)$vals[5]];
                        $concatTm = pdfMatrixMultiply($concatTm, $new);
                    }
                    break;
                case 'Do':
                    $name = ltrim(trim((string)($cmd['c'] ?? '')), '/'); if ($name==='') break;
                    $xobj = $page->getXObject($name);
                    if ($xobj) {
                        $det = $xobj->getDetails(true);
                        $isImg = (isset($det['Subtype']) && false !== stripos((string)$det['Subtype'], 'Image')) || ($xobj instanceof XObjectImage);
                        if ($isImg) {
                            $dataUri = null; $filterVal = null; $w = null; $h = null;
                            try { if ($xobj->has('Filter')) { $filterVal = (string)$xobj->get('Filter'); } } catch (\Throwable $e) {}
                            $mime = detectMimeFromFilter($filterVal);
                            if ($mime) {
                                try {
                                    $content = $xobj->getContent();
                                    if (is_string($content) && $content !== '') {
                                        $dataUri = makeDisplayImageDataUri($mime, $content, $det);
                                    }
                                } catch (\Throwable $e) {}
                            } elseif (is_string($filterVal) && stripos($filterVal, 'FlateDecode') !== false) {
                                // Attempt to build PNG from flate-compressed pixel data (DeviceGray/DeviceRGB 8bpc)
                                try {
                                    $det = $xobj->getDetails(true);
                                    $csVal = $det['ColorSpace'] ?? '';
                                    $channels = channelsFromColorSpace($csVal, 3);
                                    $w = (int)($det['Width'] ?? 0); $h = (int)($det['Height'] ?? 0);
                                    $bpc = (int)($det['BitsPerComponent'] ?? 8);
                                    if ($w > 0 && $h > 0 && $bpc === 8 && $channels > 0) {
                                        $raw = $xobj->getContent();
                                        if (is_string($raw)) {
                                            $raw = decodePredictorData($raw, $det['DecodeParms'] ?? null, $w, $channels, $bpc);
                                        }
                                        $expected = $w * $h * $channels;
                                        if (is_string($raw) && strlen($raw) >= $expected) {
                                            $pix = substr($raw, 0, $expected);
                                            if ($channels === 4) { $pix = cmyk8ToRgb8($pix, $w, $h); $channels = 3; }
                                            $png = makePng($w, $h, $channels, $bpc, $pix);
                                            $dataUri = 'data:image/png;base64,' . base64_encode($png);
                                        }
                                    }
                                } catch (\Throwable $e) { /* ignore */ }
                            }
                            // Apply soft mask (SMask) if present
                            try {
                                if ($dataUri && $xobj->has('SMask')) {
                                    $sm = $xobj->get('SMask');
                                    if ($sm) {
                                        $detm = $sm->getDetails(true);
                                        $mw = (int)($detm['Width'] ?? ($w ?? 0));
                                        $mh = (int)($detm['Height'] ?? ($h ?? 0));
                                        $mbpc = (int)($detm['BitsPerComponent'] ?? 8);
                                        $fval = $detm['Filter'] ?? '';
                                        if (is_array($fval)) { $mfilters = implode(',', array_map('strval', $fval)); }
                                        else { $mfilters = (string)$fval; }
                                        $decode = $detm['Decode'] ?? null; $invert = false;
                                        if (is_array($decode) && count($decode) >= 2) {
                                            $d0 = is_numeric($decode[0] ?? null) ? (float)$decode[0] : null;
                                            $d1 = is_numeric($decode[1] ?? null) ? (float)$decode[1] : null;
                                            if ($d0 !== null && $d1 !== null && $d0 > $d1) { $invert = true; }
                                        } elseif (is_string($decode) && preg_match('/([\-\d\.]+)\s+([\-\d\.]+)/', $decode, $mm)) {
                                            $invert = ((float)$mm[1] > (float)$mm[2]);
                                        }
                                        if ($mbpc === 8 && $mw > 0 && $mh > 0) {
                                            $mraw = $sm->getContent();
                                            // If mask is Flate-encoded raw gray, build PNG
                                            $channelsM = 1; // SMask is gray
                                            if (is_string($mraw)) {
                                                $mraw = decodePredictorData($mraw, $detm['DecodeParms'] ?? null, $mw, $channelsM, $mbpc);
                                                $expectedM = $mw * $mh * $channelsM;
                                                if (strlen($mraw) >= $expectedM) {
                                                    $maskInfo = normalizeSoftMaskBytes(substr($mraw, 0, $expectedM), $mw, $mh, $invert);
                                                    $maskPng = makePng($mw, $mh, 1, 8, $maskInfo['bytes']);
                                                    $maskUri = 'data:image/png;base64,' . base64_encode($maskPng);
                                                    $dataUri = makeMaskedSvgDataUri($dataUri, $maskUri, (float)($w ?? $mw), (float)($h ?? $mh), $maskInfo['crop']);
                                                }
                                            }
                                        }
                                    }
                                }
                            } catch (\Throwable $e) { /* ignore SMask errors */ }
                            $items[] = [
                                'type' => 'image',
                                'page_index' => $pageIndex0,
                                'x' => (float)$concatTm[4],
                                'y' => (float)$concatTm[5],
                                'page_height' => $pageHeight,
                                'dataUri' => $dataUri,
                                'render_w' => abs((float)$concatTm[0]),
                                'render_h' => abs((float)$concatTm[3]),
                                'tm_a' => (float)$concatTm[0],
                                'tm_b' => (float)$concatTm[1],
                                'tm_c' => (float)$concatTm[2],
                                'tm_d' => (float)$concatTm[3],
                                'tm_e' => (float)$concatTm[4],
                                'tm_f' => (float)$concatTm[5],
                            ];
                        } elseif ($xobj instanceof XObjectForm) {
                            // 1) Render form as SVG image for vector graphics
                            try {
                                $child = renderFormToSvg($xobj);
                                $svgXml = $child['svg_xml'] ?? '';
                                if ($svgXml !== '') {
                                    $cw = (float)($child['width'] ?? 0.0);
                                    $ch = (float)($child['height'] ?? 0.0);
                                    $cminX = (float)($child['min_x'] ?? 0.0);
                                    $cminY = (float)($child['min_y'] ?? 0.0);
                                    $sa = abs((float)$concatTm[0]);
                                    $sd = abs((float)$concatTm[3]);
                                    $rw = ($cw > 0.0 ? $cw * ($sa > 0.0 ? $sa : 1.0) : $sa);
                                    $rh = ($ch > 0.0 ? $ch * ($sd > 0.0 ? $sd : 1.0) : $sd);
                                    $items[] = [
                                    'type' => 'image',
                                    'page_index' => $pageIndex0,
                                    'x' => (float)$concatTm[4],
                                    'y' => (float)$concatTm[5],
                                    'page_height' => $pageHeight,
                                        'dataUri' => 'data:image/svg+xml;base64,'.base64_encode($svgXml),
                                        'render_w' => $rw,
                                        'render_h' => $rh,
                                        'object_min_x' => $cminX,
                                        'object_min_y' => $cminY,
                                        'object_width' => $cw,
                                        'object_height' => $ch,
                                        'tm_a' => (float)$concatTm[0],
                                    'tm_b' => (float)$concatTm[1],
                                    'tm_c' => (float)$concatTm[2],
                                    'tm_d' => (float)$concatTm[3],
                                    'tm_e' => (float)$concatTm[4],
                                        'tm_f' => (float)$concatTm[5],
                                    ];
                                }
                            } catch (\Throwable $e) {}
                            // 2) Also extract text inside the form to keep text searchable
                            try {
                                $dt = $xobj->getDataTm();
                                foreach ($dt as $entryF) {
                                    if (!is_array($entryF) || count($entryF) < 2) continue;
                                    $tmF = $entryF[0]; $textF = (string)$entryF[1];
                                    if ($textF === '' || !is_array($tmF) || count($tmF) < 6) continue;
                                    $tma = [(float)$tmF[0],(float)$tmF[1],(float)$tmF[2],(float)$tmF[3],(float)$tmF[4],(float)$tmF[5]];
                                    $TM = pdfMatrixMultiply($concatTm, $tma);
                                    $boldF = false; $fsF = isset($entryF[3]) ? (float)$entryF[3] : null; $fidF = $entryF[2] ?? null;
                                    if ($fidF !== null) { try { $fontF = $xobj->getFont((string)$fidF); $boldF = isBoldFont($fontF ? $fontF->getName() : null); } catch (\Throwable $e) {} }
                                    $items[] = [
                                        'type' => 'text',
                                        'page_index' => $pageIndex0,
                                        'x' => (float)$TM[4],
                                        'y' => (float)$TM[5],
                                        'page_height' => $pageHeight,
                                        'a' => (float)$TM[0],
                                        'b' => (float)$TM[1],
                                        'c' => (float)$TM[2],
                                        'd' => (float)$TM[3],
                                        'text' => $textF,
                                        'bold' => $boldF,
                                        'font_size' => $fsF,
                                    ];
                                }
                            } catch (\Throwable $e) {}
                        }
                    }
                    break;
                case 'BI':
                    $dictRaw = (string)($cmd['c'] ?? '');
                    $dict = [];
                    if ($dictRaw !== '') {
                        try { $dict = $page->parseDictionary('<<' . $dictRaw . '>>'); } catch (\Throwable $e) { $dict = []; }
                    }
                    $pendingInline = [
                        'x' => (float)$concatTm[4],
                        'y' => (float)$concatTm[5],
                        'dict' => $dict,
                        'tm_a' => (float)$concatTm[0],
                        'tm_d' => (float)$concatTm[3],
                    ];
                    break;
                case 'EI':
                    if ($pendingInline) {
                        $dict = $pendingInline['dict'] ?? [];
                        $mime = null;
                        try { if (isset($dict['Filter'])) { $mime = detectMimeFromFilter($dict['Filter']); } } catch (\Throwable $e) {}
                        $data = (string)($cmd['c'] ?? '');
                        $dataUri = null;
                        if ($data !== '') {
                            if ($mime) {
                                $dataUri = 'data:'.$mime.';base64,'.base64_encode($data);
                            } else {
                                // Try FlateDecode inline images to PNG
                                $filterVal = $dict['Filter'] ?? '';
                                if (is_string($filterVal) && stripos($filterVal, 'FlateDecode') !== false) {
                                    try {
                                        $w = (int)($dict['W'] ?? $dict['Width'] ?? 0);
                                        $h = (int)($dict['H'] ?? $dict['Height'] ?? 0);
                                        $csVal = $dict['ColorSpace'] ?? '';
                                        $channels = channelsFromColorSpace($csVal, 3);
                                        $bpc = (int)($dict['BitsPerComponent'] ?? 8);
                                        $raw = @zlib_decode($data);
                                        if ($raw === false || $raw === null) { $raw = @gzuncompress($data); }
                                        if ($w > 0 && $h > 0 && $channels > 0 && $bpc === 8 && $raw) {
                                            $raw = decodePredictorData($raw, $dict['DecodeParms'] ?? null, $w, $channels, $bpc);
                                            $expected = $w * $h * $channels;
                                            if (strlen($raw) >= $expected) {
                                                $pix = substr($raw, 0, $expected);
                                                if ($channels === 4) { $pix = cmyk8ToRgb8($pix, $w, $h); $channels = 3; }
                                                $png = makePng($w, $h, $channels, $bpc, $pix);
                                                $dataUri = 'data:image/png;base64,' . base64_encode($png);
                                            }
                                        }
                                    } catch (\Throwable $e) {}
                                }
                            }
                        }
                        $items[] = [
                            'type' => 'image',
                            'page_index' => $pageIndex0,
                            'x' => (float)$pendingInline['x'],
                            'y' => (float)$pendingInline['y'],
                            'page_height' => $pageHeight,
                            'dataUri' => $dataUri,
                            'render_w' => abs((float)($pendingInline['tm_a'] ?? 0.0)),
                            'render_h' => abs((float)($pendingInline['tm_d'] ?? 0.0)),
                            'tm_a' => (float)($pendingInline['tm_a'] ?? 0.0),
                            'tm_b' => 0.0,
                            'tm_c' => 0.0,
                            'tm_d' => (float)($pendingInline['tm_d'] ?? 0.0),
                            'tm_e' => (float)$pendingInline['x'],
                            'tm_f' => (float)$pendingInline['y'],
                        ];
                        $pendingInline = null;
                    }
                    break;
                default: break;
            }
        }
    }

    $hasSearchableText = false;
    $parserAnchorCount = 0;
    $anchorPattern = '/\b(?:pos|poz)\.?(?:\s*nr\.?)?\s*\d{1,3}\b/ui';
    $codeAnchorPattern = '/^0\d{3}\b/u';
    $parserPosAnchorCount = 0;
    $parserCodeAnchorCount = 0;
    $textItemHashes = [];
    $parserTextLengths = [];
    $parserFontSizes = [];
    foreach ($items as $probeItem) {
        if (($probeItem['type'] ?? '') !== 'text') {
            continue;
        }
        $text = trim((string)($probeItem['text'] ?? ''));
        if ($text === '') {
            continue;
        }
        $hasSearchableText = true;
        $parserTextLengths[] = mb_strlen($text, 'UTF-8');
        $parserFontSizes[] = (float)($probeItem['font_size'] ?? 0.0);
        if (preg_match($anchorPattern, $text) === 1 || preg_match($codeAnchorPattern, $text) === 1) {
            $parserAnchorCount++;
        }
        if (preg_match($anchorPattern, $text) === 1) {
            $parserPosAnchorCount++;
        }
        if (preg_match($codeAnchorPattern, $text) === 1) {
            $parserCodeAnchorCount++;
        }
        $hash = round((float)($probeItem['x'] ?? 0.0), 1) . '|' . round((float)($probeItem['y'] ?? 0.0), 1) . '|' . mb_strtolower(preg_replace('/\s+/u', ' ', $text), 'UTF-8');
        $textItemHashes[$hash] = true;
    }

    $bboxTextItems = extractBboxLayoutTextItems($pdfFile, $pageHeights);
    if ($bboxTextItems !== []) {
        $singleCharTexts = 0;
        foreach ($parserTextLengths as $len) {
            if ($len <= 2) {
                $singleCharTexts++;
            }
        }
        sort($parserFontSizes, SORT_NUMERIC);
        $parserMedianFontSize = $parserFontSizes !== []
            ? (float)$parserFontSizes[intdiv(count($parserFontSizes), 2)]
            : 0.0;
        $singleCharShare = $parserTextLengths !== []
            ? ((float)$singleCharTexts / (float)count($parserTextLengths))
            : 0.0;
        $shouldPreferBboxText = $hasSearchableText
            && $singleCharShare >= 0.45
            && $parserMedianFontSize >= 80.0;
        if ($shouldPreferBboxText) {
            $nonTextItems = array_values(array_filter($items, static function (array $item): bool {
                return ($item['type'] ?? '') !== 'text';
            }));
            $items = array_merge($nonTextItems, $bboxTextItems);
            $hasSearchableText = $bboxTextItems !== [];
            $textItemHashes = [];
            foreach ($items as $probeItem) {
                if (($probeItem['type'] ?? '') !== 'text') {
                    continue;
                }
                $text = trim((string)($probeItem['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $hash = round((float)($probeItem['x'] ?? 0.0), 1) . '|' . round((float)($probeItem['y'] ?? 0.0), 1) . '|' . mb_strtolower(preg_replace('/\s+/u', ' ', $text), 'UTF-8');
                $textItemHashes[$hash] = true;
            }
        }
        $bboxAnchorCount = 0;
        $bboxPosAnchorCount = 0;
        $bboxCodeAnchorCount = 0;
        foreach ($bboxTextItems as $bboxItem) {
            $text = trim((string)($bboxItem['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            if (preg_match($anchorPattern, $text) === 1 || preg_match($codeAnchorPattern, $text) === 1) {
                $bboxAnchorCount++;
            }
            if (preg_match($anchorPattern, $text) === 1) {
                $bboxPosAnchorCount++;
            }
            if (preg_match($codeAnchorPattern, $text) === 1) {
                $bboxCodeAnchorCount++;
            }
        }
        $shouldMergeAllBboxText = !$hasSearchableText;
        $shouldMergeAnchorBboxText = $hasSearchableText && $bboxPosAnchorCount > $parserPosAnchorCount;
        $shouldMergeCodeAnchorBboxText = $hasSearchableText
            && $parserPosAnchorCount === 0
            && $bboxCodeAnchorCount > $parserCodeAnchorCount;
        if ($shouldMergeAllBboxText || $shouldMergeAnchorBboxText || $shouldMergeCodeAnchorBboxText) {
            foreach ($bboxTextItems as $bboxItem) {
                $text = trim((string)($bboxItem['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $isPosAnchorText = preg_match($anchorPattern, $text) === 1;
                $isCodeAnchorText = preg_match($codeAnchorPattern, $text) === 1;
                if (!$shouldMergeAllBboxText) {
                    if (!$shouldMergeAnchorBboxText || !$isPosAnchorText) {
                        if (!$shouldMergeCodeAnchorBboxText || !$isCodeAnchorText) {
                            continue;
                        }
                    }
                }
                $hash = round((float)($bboxItem['x'] ?? 0.0), 1) . '|' . round((float)($bboxItem['y'] ?? 0.0), 1) . '|' . mb_strtolower(preg_replace('/\s+/u', ' ', $text), 'UTF-8');
                if (isset($textItemHashes[$hash])) {
                    continue;
                }
                if ($isCodeAnchorText && !$isPosAnchorText) {
                    $bboxItem['_preserve_as_text'] = true;
                }
                $items[] = $bboxItem;
                $textItemHashes[$hash] = true;
                $hasSearchableText = true;
            }
        }
    }

    if (!$hasSearchableText && getenv('XTRACT_OCR_FALLBACK_ACTIVE') !== '1') {
        if (runOcrFallbackExtraction($pdfFile, __FILE__)) {
            fwrite(STDOUT, "OCR fallback extracted searchable text.\n");
            exit(0);
        }
    }

    // Build absolute-positioned HTML
    $esc = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };

    $html = [];
    $html[] = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    $html[] = '<meta name="viewport" content="width=device-width, initial-scale=1">';
    $html[] = '<title>out2</title>';
    $html[] = '<style>';
    $html[] = 'body{margin:0;background:#f5f5f5}';
    $html[] = '#canvas{position:relative;background:#fff;margin:0 auto;box-shadow:0 0 0 1px #e0e0e0;width:' . (int)ceil($maxWidth) . 'px;height:' . (int)ceil($totalHeight) . 'px;}';
    $html[] = '.item{position:absolute;left:0;top:0;transform:translateZ(0);}';
    $html[] = '.txt{white-space:pre; font-family: system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; color:#000; z-index:2;}';
    $html[] = '.img{display:block; z-index:1;}';
    $html[] = '.line{display:block; z-index:1;}';
    $html[] = '</style></head><body>';
    $html[] = '<div id="canvas">';

    // We'll compute pageOffsets after header/footer detection and cuts.

    // Feature toggle: bake overlapping text into images (SVG or raster)
    // This ensures text that visually sits on top of a drawing (e.g., window/door)
    // becomes part of that image's SVG, not a separate text element.
    $enableBakeTextIntoRaster = getenv('XTRACT_OCR_FALLBACK_ACTIVE') === '1' ? false : true;

    // Bake text into overlapping images (per page)
    $idxByPageText = [];
    $idxByPageImg = [];
    foreach ($items as $idx => $it) {
        $pi = (int)($it['page_index'] ?? 0);
        if (($it['type'] ?? '') === 'text') { $idxByPageText[$pi][] = $idx; }
        elseif (($it['type'] ?? '') === 'image') { $idxByPageImg[$pi][] = $idx; }
    }
    if ($enableBakeTextIntoRaster) {
        $bakedText = [];
        // Requirement: text overlapping images must live inside the image, not separate
        $keepBakedTextAsText = false;
        $shouldPreserveBakedText = static function (array $item): bool {
            $text = trim((string)($item['text'] ?? ''));
            if ($text === '') {
                return false;
            }
            if (!empty($item['_preserve_as_text'])) {
                return true;
            }
            if (preg_match('/^0\d{3}\s+[A-Z0-9]{8,}\s+\d{1,3}\b/u', $text) === 1) {
                return true;
            }
            return preg_match('/\b(?:pos|poz)\.?(?:\s*nr\.?)?\s*\d{1,3}\b/ui', $text) === 1;
        };
        foreach ($idxByPageImg as $pi => $imgIdxs) {
            foreach ($imgIdxs as $ii) {
                $im = $items[$ii];
                $bg = $im['dataUri'] ?? null; if (!$bg) continue;
                // Only bake into real XObject images/forms placed via TM.
                // Skip vector-cluster pseudo images (they lack TM and can be very large),
                // which would incorrectly swallow unrelated page text.
                if (!isset($im['tm_a'])) { continue; }
                // Compute image bounds in PDF coordinates (bottom-left origin)
                $hasTm = isset($im['tm_a']);
                if ($hasTm) {
                    $matrix = [
                        (float)($im['tm_a'] ?? 0.0),
                        (float)($im['tm_b'] ?? 0.0),
                        (float)($im['tm_c'] ?? 0.0),
                        (float)($im['tm_d'] ?? 0.0),
                        (float)($im['tm_e'] ?? $im['x'] ?? 0.0),
                        (float)($im['tm_f'] ?? $im['y'] ?? 0.0),
                    ];
                    $objW = max(1.0, (float)($im['object_width'] ?? 1.0));
                    $objH = max(1.0, (float)($im['object_height'] ?? 1.0));
                    $objX = (float)($im['object_min_x'] ?? 0.0);
                    $objY = (float)($im['object_min_y'] ?? 0.0);
                    [$ix0, $iy0, $ix1, $iy1] = pdfMatrixRectBounds($matrix, $objW, $objH, $objX, $objY);
                } else {
                    $w0 = (float)($im['render_w'] ?? 0.0); $h0 = (float)($im['render_h'] ?? 0.0);
                    $ix0 = (float)($im['x'] ?? 0.0); $iy0 = (float)($im['y'] ?? 0.0);
                    $ix1 = $ix0 + $w0; $iy1 = $iy0 + $h0;
                }
                // Discard degenerate or excessively large AABBs which likely represent backgrounds
                $wA = max(0.0, $ix1 - $ix0); $hA = max(0.0, $iy1 - $iy0);
                if (!is_finite($wA) || !is_finite($hA) || $wA < 4.0 || $hA < 4.0) continue;
                // Collect text items whose baseline point falls inside the image AABB
                $textsFor = [];
                foreach ($idxByPageText[$pi] ?? [] as $ti) {
                    if (!empty($bakedText[$ti])) continue;
                    $t = $items[$ti]; $tx = (float)($t['x'] ?? 0.0); $ty = (float)($t['y'] ?? 0.0);
                    if ($tx >= $ix0 - 0.5 && $tx <= $ix1 + 0.5 && $ty >= $iy0 - 0.5 && $ty <= $iy1 + 0.5) {
                        $textsFor[] = [
                            'x' => $tx - $ix0,
                            'y' => $ty - $iy0,
                            'font_size' => (float)($t['font_size'] ?? 10.0),
                            'bold' => (bool)($t['bold'] ?? false),
                            'text' => (string)($t['text'] ?? ''),
                        ];
                        $bakedText[$ti] = true;
                    }
                }
                if (!empty($textsFor)) {
                    $items[$ii]['dataUri'] = makeImageWithTextsSvg($bg, $wA, $hA, $textsFor);
                    // Ensure placement uses this bounding box consistently
                    $items[$ii]['x'] = $ix0; $items[$ii]['y'] = $iy0; $items[$ii]['render_w'] = $wA; $items[$ii]['render_h'] = $hA;
                    // Keep TM values if present; JSON/HTML emitters compute AABB and will match ix0..iy1
                }
            }
        }
        // Remove separately emitted text that was baked into images
        if (!empty($bakedText) && !$keepBakedTextAsText) {
            $items = array_values(array_filter(
                $items,
                function ($it, $idx) use ($bakedText, $shouldPreserveBakedText) {
                    if (($it['type'] ?? '') !== 'text' || empty($bakedText[$idx])) {
                        return true;
                    }
                    return $shouldPreserveBakedText($it);
                },
                ARRAY_FILTER_USE_BOTH
            ));
        }
    }

    // Image repeat filter disabled: header/footer cuts below will handle images in those zones.

    // Detect headers and footers across pages, remove them (except first-page header and last-page footer),
    // and subtract their heights from page height to avoid gaps.
    $pageCount = count($pageHeights);
    $pageHeightsRaw = array_map(static fn($v): float => (float)$v, $pageHeights);
    $perPageText = [];
    foreach ($items as $idx => $it) {
        if (($it['type'] ?? '') === 'text') {
            $pi = (int)($it['page_index'] ?? 0);
            $perPageText[$pi][] = $idx;
        }
    }
    $normalize_line = function (string $s): string {
        $s = mb_strtolower(trim(preg_replace('/\s+/u',' ', $s)));
        // normalize dates and numbers
        $s = preg_replace('/\b\d{1,2}[\.\/-]\d{1,2}[\.\/-]\d{2,4}\b/u', '{n}', $s);
        $s = preg_replace('/\b\d+(?:[\.,]\d+)*\b/u', '{n}', $s);
        return (string)$s;
    };
    $headerNormCounts = [];
    $footerNormCounts = [];
    $headerLinesByPage = [];
    $footerLinesByPage = [];
    $headerImageCounts = [];
    $footerImageCounts = [];
    $headerImagesByPage = [];
    $footerImagesByPage = [];
    $topAnchorLinesByPage = [];
    for ($pi=0; $pi<$pageCount; $pi++) {
        $ph = (float)($pageHeights[$pi] ?? 0.0);
        if ($ph <= 0) continue;
        $yTol = max(1.0, $ph * 0.006);
        $topZone = $ph * 0.20;     // top 20%
        $bottomZone = $ph * 0.20;  // bottom 20%
        $idxList = $perPageText[$pi] ?? [];
        // Build lines (cluster by Y proximity)
        $lines = [];
        usort($idxList, function ($a, $b) use ($items) {
            $ya = (float)$items[$a]['y'];
            $yb = (float)$items[$b]['y'];
            if ($ya === $yb) return (float)$items[$a]['x'] <=> (float)$items[$b]['x'];
            return $yb <=> $ya; // desc by y
        });
        foreach ($idxList as $idx) {
            $y = (float)$items[$idx]['y'];
            $x = (float)$items[$idx]['x'];
            $t = (string)$items[$idx]['text'];
            $assigned = false;
            for ($i=0; $i<count($lines); $i++) {
                if (abs($lines[$i]['y'] - $y) <= $yTol) {
                    $lines[$i]['items'][] = ['idx'=>$idx,'x'=>$x,'y'=>$y,'text'=>$t];
                    $lines[$i]['y'] = ($lines[$i]['y'] * $lines[$i]['n'] + $y) / ($lines[$i]['n'] + 1);
                    $lines[$i]['n'] += 1;
                    $assigned = true; break;
                }
            }
            if (!$assigned) { $lines[] = ['y'=>$y,'n'=>1,'items'=>[['idx'=>$idx,'x'=>$x,'y'=>$y,'text'=>$t]]]; }
        }
        // Process lines in top/bottom zones
        foreach ($lines as $ln) {
            $yLine = (float)$ln['y'];
            $itemsLine = $ln['items'];
            usort($itemsLine, function ($a,$b){ return $a['x'] <=> $b['x']; });
            $joined = '';
            $fsMax = 0.0;
            foreach ($itemsLine as $itx) {
                $joined .= ($joined === '' ? '' : ' ') . trim((string)$itx['text']);
                $ti = $itx['idx'];
                $fs0 = (float)($items[$ti]['font_size'] ?? 12.0);
                $b = isset($items[$ti]['b']) ? (float)$items[$ti]['b'] : 0.0;
                $d = isset($items[$ti]['d']) ? (float)$items[$ti]['d'] : 1.0;
                $yScale = hypot($b,$d); if (!is_finite($yScale) || $yScale<=0.0) $yScale = abs($d) > 0.0 ? abs($d) : 1.0;
                $fsCss = $fs0 * $yScale * FONT_PX_SCALE;
                if ($fsCss > $fsMax) $fsMax = $fsCss;
            }
            $norm = $normalize_line($joined);
            if ($yLine >= $ph - $topZone) {
                $headerLinesByPage[$pi][] = ['norm'=>$norm,'y'=>$yLine,'fs'=>$fsMax];
                if ($norm !== '') { $headerNormCounts[$norm] = ($headerNormCounts[$norm] ?? 0) + 1; }
                if (
                    preg_match('/\b(?:pos|poz)\.?(?:\s*nr\.?)?\s*\d{1,3}\b/ui', $joined) === 1
                    || preg_match('/^\s*0\d{3}\s+[A-Z0-9]{8,}\s+\d{1,3}\b/u', $joined) === 1
                ) {
                    $topAnchorLinesByPage[$pi][] = ['y' => $yLine, 'fs' => $fsMax];
                }
            } elseif ($yLine <= $bottomZone) {
                $footerLinesByPage[$pi][] = ['norm'=>$norm,'y'=>$yLine,'fs'=>$fsMax];
                if ($norm !== '') { $footerNormCounts[$norm] = ($footerNormCounts[$norm] ?? 0) + 1; }
            }
        }
        foreach ($items as $item) {
            if ((int)($item['page_index'] ?? -1) !== $pi) continue;
            if (($item['type'] ?? '') !== 'image') continue;
            $src = (string)($item['dataUri'] ?? '');
            if ($src === '') continue;
            $a = $item['tm_a'] ?? null; $b = $item['tm_b'] ?? null; $c = $item['tm_c'] ?? null; $d = $item['tm_d'] ?? null; $e = (float)($item['tm_e'] ?? $item['x'] ?? 0.0); $f = (float)($item['tm_f'] ?? $item['y'] ?? 0.0);
            if ($a !== null && $d !== null) {
                $a=(float)$a; $b=(float)($b ?? 0.0); $c=(float)($c ?? 0.0); $d=(float)$d;
                $minX = $e + min(0.0, $a, $c, $a + $c);
                $maxX = $e + max(0.0, $a, $c, $a + $c);
                $minY = $f + min(0.0, $b, $d, $b + $d);
                $maxY = $f + max(0.0, $b, $d, $b + $d);
            } else {
                $w0 = (float)($item['render_w'] ?? 0.0);
                $h0 = (float)($item['render_h'] ?? 0.0);
                $minX = (float)($item['x'] ?? 0.0);
                $maxX = $minX + $w0;
                $minY = (float)($item['y'] ?? 0.0);
                $maxY = $minY + $h0;
            }
            $bboxW = max(0.0, $maxX - $minX);
            $bboxH = max(0.0, $maxY - $minY);
            $sig = substr(sha1($src), 0, 16) . ':' . round($bboxW, 1) . ':' . round($bboxH, 1) . ':' . round($minX, 1);
            if ($maxY >= $ph - $topZone) {
                $headerImagesByPage[$pi][] = ['sig' => $sig, 'minY' => $minY, 'maxY' => $maxY];
                $headerImageCounts[$sig] = ($headerImageCounts[$sig] ?? 0) + 1;
            } elseif ($minY <= $bottomZone) {
                $footerImagesByPage[$pi][] = ['sig' => $sig, 'minY' => $minY, 'maxY' => $maxY];
                $footerImageCounts[$sig] = ($footerImageCounts[$sig] ?? 0) + 1;
            }
        }
    }
    $repeatThreshold = max(2, (int)ceil($pageCount * 0.6));
    $headerRepeated = [];
    foreach ($headerNormCounts as $norm=>$cnt) { if ($cnt >= $repeatThreshold) $headerRepeated[$norm] = true; }
    // Also treat common header tokens (e.g., datum:, seite:) as repeated by pattern
    $isLikelyHeader = function (string $norm): bool {
        return (bool)preg_match('/\b(datum|date|auftrags|angebot|rechnung|best[aä]tigung|auftragsbestaetigung|kunde|customer)\b/u', $norm);
    };
    foreach ($headerNormCounts as $norm=>$cnt) { if ($isLikelyHeader($norm)) $headerRepeated[$norm] = true; }
    $footerRepeated = [];
    foreach ($footerNormCounts as $norm=>$cnt) { if ($cnt >= $repeatThreshold) $footerRepeated[$norm] = true; }
    // Also include page number lines like "seite: {n}" or "page {n}"
    foreach ($footerNormCounts as $norm=>$cnt) { if (preg_match('/\b(seite|page)\s*[:]?\s*\{n\}\b/u', $norm)) $footerRepeated[$norm] = true; }
    $headerRepeatedImages = [];
    foreach ($headerImageCounts as $sig => $cnt) { if ($cnt >= $repeatThreshold) $headerRepeatedImages[$sig] = true; }
    $footerRepeatedImages = [];
    foreach ($footerImageCounts as $sig => $cnt) { if ($cnt >= $repeatThreshold) $footerRepeatedImages[$sig] = true; }

    // Compute per-page header/footer cut heights
    $pageHeaderCut = array_fill(0, $pageCount, 0.0);
    $pageFooterCut = array_fill(0, $pageCount, 0.0);
    for ($pi=0; $pi<$pageCount; $pi++) {
        $ph = (float)($pageHeights[$pi] ?? 0.0); if ($ph <= 0) continue;
        $hr = 0.0;
        foreach ($headerLinesByPage[$pi] ?? [] as $ln) {
            if (!empty($headerRepeated[$ln['norm']])) {
                $hr = max($hr, ($ph - (float)$ln['y']) + (float)$ln['fs']);
            }
        }
        foreach ($headerImagesByPage[$pi] ?? [] as $imgInfo) {
            if (!empty($headerRepeatedImages[$imgInfo['sig']])) {
                $hr = max($hr, $ph - (float)$imgInfo['minY']);
            }
        }
        foreach ($topAnchorLinesByPage[$pi] ?? [] as $anchor) {
            $anchorLimit = max(0.0, ($ph - (float)$anchor['y']) - (float)$anchor['fs'] - 2.0);
            if ($hr > 0.0) {
                $hr = min($hr, $anchorLimit);
            }
        }
        $hr = min($hr, $ph * 0.25);
        $pageHeaderCut[$pi] = $hr;

        $fr = 0.0;
        foreach ($footerLinesByPage[$pi] ?? [] as $ln) {
            if (!empty($footerRepeated[$ln['norm']])) {
                $fr = max($fr, (float)$ln['y'] + (float)$ln['fs']);
            }
        }
        foreach ($footerImagesByPage[$pi] ?? [] as $imgInfo) {
            if (!empty($footerRepeatedImages[$imgInfo['sig']])) {
                $fr = max($fr, (float)$imgInfo['maxY']);
            }
        }
        $fr = min($fr, $ph * 0.25);
        $pageFooterCut[$pi] = $fr;
    }

    // Remove items inside header/footer cuts and adjust page heights
    if ($pageCount > 0) {
        $items = array_values(array_filter($items, function ($it) use ($pageHeights, $pageWidths, $pageHeaderCut, $pageFooterCut, $perPageText) {
            $pi = (int)($it['page_index'] ?? 0);
            $ph = (float)($pageHeights[$pi] ?? 0.0);
            $pw = (float)($pageWidths[$pi] ?? 0.0);
            $hr = (float)($pageHeaderCut[$pi] ?? 0.0);
            $fr = (float)($pageFooterCut[$pi] ?? 0.0);
            $type = (string)($it['type'] ?? '');
            if ($type === 'text') {
                $y = (float)($it['y'] ?? 0.0);
                if ($hr > 0.0 && $y >= $ph - $hr - 0.5) return false; // in header zone
                if ($fr > 0.0 && $y <= $fr + 0.5) return false;       // in footer zone
                return true;
            } elseif ($type === 'image' || $type === 'line') {
                // Compute image AABB (PDF coords)
                $a = $it['tm_a'] ?? null; $b = $it['tm_b'] ?? null; $c = $it['tm_c'] ?? null; $d = $it['tm_d'] ?? null; $e = (float)($it['tm_e'] ?? $it['x'] ?? 0.0); $f = (float)($it['tm_f'] ?? $it['y'] ?? 0.0);
                if ($a !== null && $d !== null) {
                    $a=(float)$a;$b=(float)($b??0);$c=(float)($c??0);$d=(float)$d;
                    $minX = $e + min(0.0, $a, $c, $a + $c);
                    $maxX = $e + max(0.0, $a, $c, $a + $c);
                    $minY = $f + min(0.0, $b, $d, $b + $d);
                    $maxY = $f + max(0.0, $b, $d, $b + $d);
                } else {
                    $w0 = (float)($it['render_w'] ?? 0.0);
                    $h0 = (float)($it['render_h'] ?? 0.0);
                    $minX = (float)($it['x'] ?? 0.0);
                    $maxX = $minX + $w0;
                    $minY = (float)($it['y'] ?? 0.0);
                    $maxY = $minY + $h0;
                }
                if ($type === 'image') {
                    $src = (string)($it['dataUri'] ?? '');
                    $bboxW = max(0.0, $maxX - $minX);
                    $bboxH = max(0.0, $maxY - $minY);
                    $textCount = count($perPageText[$pi] ?? []);
                    if (
                        str_starts_with($src, 'data:image/svg+xml')
                        && $pw > 0.0
                        && $ph > 0.0
                        && $textCount >= 10
                        && $bboxW >= $pw * 0.95
                        && $bboxH >= $ph * 0.95
                    ) {
                        return false;
                    }
                }
                $centerY = 0.5 * ($minY + $maxY);
                if ($hr > 0.0 && $centerY >= $ph - $hr) return false;
                if ($fr > 0.0 && $centerY <= $fr) return false;
                return true;
            }
            return true;
        }));
        // Adjust page heights (effective) by subtracting cuts
        for ($pi=0; $pi<$pageCount; $pi++) {
            $pageHeights[$pi] = max(0.0, (float)$pageHeights[$pi] - (float)$pageHeaderCut[$pi] - (float)$pageFooterCut[$pi]);
        }
    }

    // Normalize item coordinates into content-space (subtract footer height per page)
    // and shrink per-item page_height to the effective content height. This avoids
    // gaps between pages and makes top-mapping simpler (no need to subtract header).
    foreach ($items as &$itRef) {
        $piN = (int)($itRef['page_index'] ?? 0);
        $ph0 = (float)($itRef['page_height'] ?? ($pageHeights[$piN] ?? 0.0));
        $hr0 = (float)($pageHeaderCut[$piN] ?? 0.0);
        $fr0 = (float)($pageFooterCut[$piN] ?? 0.0);
        $phEff = max(0.0, $ph0 - $hr0 - $fr0);
        $itRef['page_height'] = $phEff;
        $typeN = (string)($itRef['type'] ?? '');
        $isMatrixedImage = ($typeN === 'image' && isset($itRef['tm_a']));
        if ($typeN === 'text' || $typeN === 'line' || !$isMatrixedImage) {
            if (isset($itRef['y'])) { $itRef['y'] = (float)$itRef['y'] - $fr0; }
        }
        if ($typeN === 'image' && !$isMatrixedImage) {
            // Inline images occasionally carry wildly offset coordinates from nested forms.
            // Normalise them back into the page content box so downstream consumers get
            // sensible values (matching the rendered HTML position).
            $phUse = max(0.0, (float)($itRef['page_height'] ?? 0.0));
            if ($phUse <= 0.0) {
                $phUse = max(0.0, (float)($pageHeights[$piN] ?? 0.0));
                if ($phUse > 0.0) { $itRef['page_height'] = $phUse; }
            }
            if ($phUse > 0.0 && isset($itRef['y'])) {
                $yVal = (float)$itRef['y'];
                if (!is_finite($yVal)) { $yVal = 0.0; }
                $hRaw = max(0.0, (float)($itRef['render_h'] ?? 0.0));
                $topLocal = max(0.0, $phUse - $yVal - $hRaw);
                $yNorm = $phUse - $topLocal - $hRaw;
                if (!is_finite($yNorm)) { $yNorm = 0.0; }
                $itRef['y'] = max(0.0, min($phUse, $yNorm));
            } elseif (isset($itRef['y'])) {
                $itRef['y'] = max(0.0, (float)$itRef['y']);
            }
        }
        // Shift TM translation for TM-based images/forms
        if ($typeN === 'image' && isset($itRef['tm_f'])) {
            $itRef['tm_f'] = (float)$itRef['tm_f'] - $fr0;
        }
    }
    unset($itRef);

    $normalizedHeaderImageCounts = [];
    $normalizedFooterImageCounts = [];
    $normalizedHeaderImagesByPage = [];
    $normalizedFooterImagesByPage = [];
    foreach ($items as $item) {
        if (($item['type'] ?? '') !== 'image') continue;
        $pi = (int)($item['page_index'] ?? -1);
        $ph = (float)($item['page_height'] ?? ($pageHeights[$pi] ?? 0.0));
        if ($pi < 0 || $ph <= 0.0) continue;
        $src = (string)($item['dataUri'] ?? '');
        if ($src === '') continue;
        if (isset($item['tm_a'])) {
            $matrix = [
                (float)($item['tm_a'] ?? 0.0),
                (float)($item['tm_b'] ?? 0.0),
                (float)($item['tm_c'] ?? 0.0),
                (float)($item['tm_d'] ?? 0.0),
                (float)($item['tm_e'] ?? $item['x'] ?? 0.0),
                (float)($item['tm_f'] ?? $item['y'] ?? 0.0),
            ];
            $objW = max(1.0, (float)($item['object_width'] ?? 1.0));
            $objH = max(1.0, (float)($item['object_height'] ?? 1.0));
            $objX = (float)($item['object_min_x'] ?? 0.0);
            $objY = (float)($item['object_min_y'] ?? 0.0);
            [$minX, $minY, $maxX, $maxY] = pdfMatrixRectBounds($matrix, $objW, $objH, $objX, $objY);
            $localTop = max(0.0, $ph - $maxY);
            $headerExtent = max(0.0, $ph - $minY);
            $footerExtent = max(0.0, $maxY);
        } else {
            $w = max(0.0, (float)($item['render_w'] ?? 0.0));
            $h = max(0.0, (float)($item['render_h'] ?? 0.0));
            $minX = (float)($item['x'] ?? 0.0);
            $maxX = $minX + $w;
            $y = (float)($item['y'] ?? 0.0);
            $localTop = max(0.0, $ph - $y - $h);
            $headerExtent = $localTop + $h;
            $footerExtent = $y + $h;
        }
        $bboxW = max(0.0, $maxX - $minX);
        $bboxH = max(0.0, isset($maxY) ? ($maxY - $minY) : 0.0);
        if (!isset($maxY)) $bboxH = max(0.0, (float)($item['render_h'] ?? 0.0));
        $sig = substr(sha1($src), 0, 16) . ':' . round($bboxW, 1) . ':' . round($bboxH, 1) . ':' . round($minX, 1);
        if ($localTop <= $ph * 0.20) {
            $normalizedHeaderImagesByPage[$pi][] = ['sig' => $sig, 'extent' => $headerExtent];
            $normalizedHeaderImageCounts[$sig] = ($normalizedHeaderImageCounts[$sig] ?? 0) + 1;
        }
        if ($footerExtent <= $ph * 0.20) {
            $normalizedFooterImagesByPage[$pi][] = ['sig' => $sig, 'extent' => $footerExtent];
            $normalizedFooterImageCounts[$sig] = ($normalizedFooterImageCounts[$sig] ?? 0) + 1;
        }
    }
    foreach ($normalizedHeaderImageCounts as $sig => $cnt) {
        if ($cnt >= $repeatThreshold) $headerRepeatedImages[$sig] = true;
    }
    foreach ($normalizedFooterImageCounts as $sig => $cnt) {
        if ($cnt >= $repeatThreshold) $footerRepeatedImages[$sig] = true;
    }
    $pageHeaderCut2 = array_fill(0, $pageCount, 0.0);
    $pageFooterCut2 = array_fill(0, $pageCount, 0.0);
    for ($pi = 0; $pi < $pageCount; $pi++) {
        $ph = (float)($pageHeights[$pi] ?? 0.0);
        if ($ph <= 0.0) continue;
        foreach ($normalizedHeaderImagesByPage[$pi] ?? [] as $imgInfo) {
            if (!empty($headerRepeatedImages[$imgInfo['sig']])) {
                $pageHeaderCut2[$pi] = max($pageHeaderCut2[$pi], min($ph * 0.25, (float)$imgInfo['extent']));
            }
        }
        foreach ($normalizedFooterImagesByPage[$pi] ?? [] as $imgInfo) {
            if (!empty($footerRepeatedImages[$imgInfo['sig']])) {
                $pageFooterCut2[$pi] = max($pageFooterCut2[$pi], min($ph * 0.25, (float)$imgInfo['extent']));
            }
        }
    }
    $needsSecondaryCut = false;
    for ($pi = 0; $pi < $pageCount; $pi++) {
        if ($pageHeaderCut2[$pi] > 0.5 || $pageFooterCut2[$pi] > 0.5) { $needsSecondaryCut = true; break; }
    }
    if ($needsSecondaryCut) {
        $items = array_values(array_filter($items, function ($it) use ($pageHeights, $pageHeaderCut2, $pageFooterCut2) {
            $pi = (int)($it['page_index'] ?? 0);
            $ph = (float)($pageHeights[$pi] ?? 0.0);
            $hr = (float)($pageHeaderCut2[$pi] ?? 0.0);
            $fr = (float)($pageFooterCut2[$pi] ?? 0.0);
            $type = (string)($it['type'] ?? '');
            if ($type === 'text') {
                $y = (float)($it['y'] ?? 0.0);
                $fs = (float)($it['font_size'] ?? 0.0);
                $localTop = max(0.0, $ph - $y - $fs);
                if ($hr > 0.0 && $localTop <= $hr + 0.5) return false;
                if ($fr > 0.0 && $y <= $fr + 0.5) return false;
                return true;
            }
            if ($type === 'image' || $type === 'line') {
                if (isset($it['tm_a'])) {
                    $matrix = [
                        (float)($it['tm_a'] ?? 0.0),
                        (float)($it['tm_b'] ?? 0.0),
                        (float)($it['tm_c'] ?? 0.0),
                        (float)($it['tm_d'] ?? 0.0),
                        (float)($it['tm_e'] ?? $it['x'] ?? 0.0),
                        (float)($it['tm_f'] ?? $it['y'] ?? 0.0),
                    ];
                    $objW = max(1.0, (float)($it['object_width'] ?? 1.0));
                    $objH = max(1.0, (float)($it['object_height'] ?? 1.0));
                    $objX = (float)($it['object_min_x'] ?? 0.0);
                    $objY = (float)($it['object_min_y'] ?? 0.0);
                    [, $minY, , $maxY] = pdfMatrixRectBounds($matrix, $objW, $objH, $objX, $objY);
                    $localTop = max(0.0, $ph - $maxY);
                    $footerExtent = max(0.0, $maxY);
                } else {
                    $h = max(0.0, (float)($it['render_h'] ?? 0.0));
                    $y = (float)($it['y'] ?? 0.0);
                    $localTop = max(0.0, $ph - $y - $h);
                    $footerExtent = $y + $h;
                }
                if ($hr > 0.0 && $localTop <= $hr + 0.5) return false;
                if ($fr > 0.0 && $footerExtent <= $fr + 0.5) return false;
                return true;
            }
            return true;
        }));
        foreach ($items as &$itRef) {
            $piN = (int)($itRef['page_index'] ?? 0);
            $hr2 = (float)($pageHeaderCut2[$piN] ?? 0.0);
            $fr2 = (float)($pageFooterCut2[$piN] ?? 0.0);
            $itRef['page_height'] = max(0.0, (float)($itRef['page_height'] ?? ($pageHeights[$piN] ?? 0.0)) - $hr2 - $fr2);
            $typeN = (string)($itRef['type'] ?? '');
            $isMatrixedImage = ($typeN === 'image' && isset($itRef['tm_a']));
            if ($typeN === 'text' || $typeN === 'line' || !$isMatrixedImage) {
                if (isset($itRef['y'])) { $itRef['y'] = (float)$itRef['y'] - $fr2; }
            }
            if ($typeN === 'image' && isset($itRef['tm_f'])) {
                $itRef['tm_f'] = (float)$itRef['tm_f'] - $fr2;
            }
        }
        unset($itRef);
        for ($pi = 0; $pi < $pageCount; $pi++) {
            $pageHeaderCut[$pi] = max((float)$pageHeaderCut[$pi], (float)$pageHeaderCut2[$pi]);
            $pageFooterCut[$pi] = max((float)$pageFooterCut[$pi], (float)$pageFooterCut2[$pi]);
            $pageHeights[$pi] = max(0.0, (float)$pageHeights[$pi] - (float)$pageHeaderCut2[$pi] - (float)$pageFooterCut2[$pi]);
        }
    }

    $items = mergeOverlappingImageLayers($items);

    if ($bboxTextItems !== []) {
        $finalAnchorHashes = [];
        foreach ($items as $probeItem) {
            if (($probeItem['type'] ?? '') !== 'text') {
                continue;
            }
            $text = trim((string)($probeItem['text'] ?? ''));
            if (
                preg_match('/\b(?:pos|poz)\.?(?:\s*nr\.?)?\s*\d{1,3}\b/ui', $text) !== 1
                && preg_match('/^0\d{3}\s+[A-Z0-9]{8,}\s+\d{1,3}\b/u', $text) !== 1
            ) {
                continue;
            }
            $hash = (int)($probeItem['page_index'] ?? 0)
                . '|' . round((float)($probeItem['x'] ?? 0.0), 1)
                . '|' . round((float)($probeItem['y'] ?? 0.0), 1)
                . '|' . mb_strtolower(preg_replace('/\s+/u', ' ', $text), 'UTF-8');
            $finalAnchorHashes[$hash] = true;
        }
        foreach ($bboxTextItems as $bboxItem) {
            $text = trim((string)($bboxItem['text'] ?? ''));
            if (
                preg_match('/\b(?:pos|poz)\.?(?:\s*nr\.?)?\s*\d{1,3}\b/ui', $text) !== 1
                && preg_match('/^0\d{3}\s+[A-Z0-9]{8,}\s+\d{1,3}\b/u', $text) !== 1
            ) {
                continue;
            }
            $pi = (int)($bboxItem['page_index'] ?? 0);
            $bboxItem['page_height'] = (float)($pageHeights[$pi] ?? ($bboxItem['page_height'] ?? 0.0));
            $bboxItem['y'] = (float)($bboxItem['y'] ?? 0.0) - (float)($pageFooterCut[$pi] ?? 0.0);
            $bboxItem['_preserve_as_text'] = true;
            $hash = $pi
                . '|' . round((float)($bboxItem['x'] ?? 0.0), 1)
                . '|' . round((float)($bboxItem['y'] ?? 0.0), 1)
                . '|' . mb_strtolower(preg_replace('/\s+/u', ' ', $text), 'UTF-8');
            if (isset($finalAnchorHashes[$hash])) {
                continue;
            }
            $items[] = $bboxItem;
            $finalAnchorHashes[$hash] = true;
        }
    }

    // Recompute page offsets after cuts
    $pageOffsets = [];
    $acc = 0.0;
    for ($i=0; $i<$pageCount; $i++) { $pageOffsets[$i] = $acc; $acc += (float)$pageHeights[$i]; }
    // Override canvas size to reflect cuts (avoid inter-page gaps)
    $html[] = '<style>#canvas{height:' . (int)ceil($acc) . 'px;width:' . (int)ceil($maxWidth) . 'px;}</style>';

    // Emit a single flat list of absolutely positioned items (no per-page wrappers)
    $maxBottomCss = 0.0; // compute actual used height to avoid oversized canvas
    foreach ($items as $it) {
        $x = (float)$it['x'];
        $yPdf = (float)$it['y'];
        $ph = (float)$it['page_height'];
        $piCur = (int)($it['page_index'] ?? 0);
        $off = (float)($pageOffsets[$piCur] ?? 0.0);
        // Header/footer already normalized out of coordinates and page_height

        if (($it['type'] ?? '') === 'text') {
            $fs = isset($it['font_size']) && is_numeric($it['font_size']) ? (float)$it['font_size'] : 12.0;
            $b = isset($it['b']) ? (float)$it['b'] : 0.0; $d = isset($it['d']) ? (float)$it['d'] : 1.0;
            $yScale = hypot($b, $d);
            $useScaledFs = is_finite($yScale) && ($yScale > 1.35 || $yScale < 0.74);
            $fsCss = ($useScaledFs ? $fs * $yScale : $fs) * FONT_PX_SCALE;
            $top = $off + max(0.0, $ph - $yPdf - $fsCss);
            $weight = !empty($it['bold']) ? '700' : '400';
            $style = 'left:' . $x . 'px;top:' . $top . 'px;font-weight:' . $weight . ';font-size:' . $fsCss . 'px;';
            $text = $esc($it['text']);
            $html[] = '<div class="item txt" style="' . $style . '">' . $text . '</div>';
            $bottom = $top + $fsCss;
            if ($bottom > $maxBottomCss) $maxBottomCss = $bottom;
        } elseif (($it['type'] ?? '') === 'image') {
            $src = $it['dataUri'] ?? null;
            if ($src) {
                if (!isset($it['tm_a'])) {
                    $w = max(0.0, isset($it['render_w']) ? (float)$it['render_w'] : 0.0);
                    $h = max(0.0, isset($it['render_h']) ? (float)$it['render_h'] : 0.0);
                    // Fallback for embedded SVGs with missing render size (avoid 1px output)
                    if (($w <= 1.0 || $h <= 1.0) && is_string($src) && strpos($src, 'data:image/svg+xml') === 0) {
                        $comma = strpos($src, ',');
                        if ($comma !== false) {
                            $svg = base64_decode(substr($src, $comma+1), true);
                            if (is_string($svg) && $svg !== '') {
                                if (preg_match('/\bwidth\s*=\s*"([\d\.]+)"/i', $svg, $mW)) { $w = max($w, (float)$mW[1]); }
                                if (preg_match('/\bheight\s*=\s*"([\d\.]+)"/i', $svg, $mH)) { $h = max($h, (float)$mH[1]); }
                            }
                        }
                    }
                    // For images without TM (e.g., vector clusters), y is bottom-left.
                    // Convert to CSS top-left by subtracting height.
                    // Some sources (nested forms) may yield pathological y values (far beyond page height).
                    // Normalize such values to the current page to avoid pinning at top:0.
                    $yForTop = $yPdf;
                    if (is_finite($yForTop) && $ph > 0.0 && $yForTop > $ph * 10.0) {
                        // Keep relative position within page – map into [0, $ph)
                        $yForTop = fmod($yForTop, $ph);
                        if ($yForTop < 0.0) $yForTop += $ph;
                    }
                    $pwUse = (float)($pageWidths[$piCur] ?? $maxWidth);
                    $leftCss = (float)$x;
                    if (!is_finite($leftCss)) { $leftCss = 0.0; }
                    if ($pwUse > 0.0) {
                        $rightCss = $leftCss + $w;
                        $leftCss = max(0.0, min($leftCss, $pwUse));
                        $rightCss = max($leftCss, min($pwUse, $rightCss));
                        $w = max(0.0, $rightCss - $leftCss);
                    }
                    $top = $off + max(0.0, $ph - $yForTop - $h);
                    $size = '';
                    if ($w > 0.0) $size .= 'width:' . $w . 'px;';
                    if ($h > 0.0) $size .= 'height:' . $h . 'px;';
                    $style = 'left:' . $leftCss . 'px;top:' . $top . 'px;' . $size;
                    $html[] = '<img class="item img" style="' . $style . '" src="' . $src . '" alt="image" />';
                    $bottom = $top + $h;
                    if ($bottom > $maxBottomCss) $maxBottomCss = $bottom;
                } else {
                    $matrix = [
                        (float)$it['tm_a'],
                        (float)$it['tm_b'],
                        (float)$it['tm_c'],
                        (float)$it['tm_d'],
                        (float)$it['tm_e'],
                        (float)$it['tm_f'],
                    ];
                    $objW = max(1.0, (float)($it['object_width'] ?? 1.0));
                    $objH = max(1.0, (float)($it['object_height'] ?? 1.0));
                    $objX = (float)($it['object_min_x'] ?? 0.0);
                    $objY = (float)($it['object_min_y'] ?? 0.0);
                    [$minX, $minY, $maxX, $maxY] = pdfMatrixRectBounds($matrix, $objW, $objH, $objX, $objY);
                    $left = $minX;
                    $phUse = $ph > 0.0 ? $ph : (float)($pageHeights[$piCur] ?? 0.0);
                    if ($phUse <= 0.0) { $phUse = max(0.0, $maxY); }
                    $w = max(0.0, $maxX - $minX);
                    $h = max(0.0, $maxY - $minY);
                    $topLocalRaw = max(0.0, $phUse - $maxY);
                    $yNorm = $phUse > 0.0 ? max(0.0, min($phUse, $phUse - $topLocalRaw - $h)) : max(0.0, $maxY);
                    $top = $off + max(0.0, $phUse - $yNorm - $h);
                    $pwUse = (float)($pageWidths[$piCur] ?? $maxWidth);
                    if ($pwUse > 0.0) {
                        $right = $left + $w;
                        $left = max(0.0, min($left, $pwUse));
                        $right = max($left, min($pwUse, $right));
                        $w = max(0.0, $right - $left);
                    }
                    $size = '';
                    if ($w > 0.0) $size .= 'width:' . $w . 'px;';
                    if ($h > 0.0) $size .= 'height:' . $h . 'px;';
                    $style = 'left:' . $left . 'px;top:' . $top . 'px;' . $size;
                    $html[] = '<img class="item img" style="' . $style . '" src="' . $src . '" alt="image" />';
                    $bottom = $top + $h;
                    if ($bottom > $maxBottomCss) $maxBottomCss = $bottom;
                }
            }
        } elseif (($it['type'] ?? '') === 'line') {
            $w = isset($it['render_w']) ? (float)$it['render_w'] : 0.0;
            $h = isset($it['render_h']) ? (float)$it['render_h'] : 0.0;
            $minX = $x; $minY = $yPdf; $maxY = $minY + $h;
            $left = $minX;
            $top = $off + max(0.0, $ph - $maxY);
            $size = '';
            if ($w > 0.0) $size .= 'width:' . $w . 'px;';
            if ($h > 0.0) $size .= 'height:' . $h . 'px;';
            $color = (string)($it['color'] ?? '#000');
            $style = 'left:' . $left . 'px;top:' . $top . 'px;background:' . $color . ';' . $size;
            $html[] = '<div class="item line" style="' . $style . '"></div>';
            $bottom = $top + $h;
            if ($bottom > $maxBottomCss) $maxBottomCss = $bottom;
        }
    }
    // Override canvas height to actual used bottom to keep it compact
    $html[] = '<style>#canvas{height:' . (int)ceil($maxBottomCss) . 'px;}</style>';

    $html[] = '</div></body></html>';

    // Write HTML
    file_put_contents('out2.html', implode('', $html));

    // Build JSON snapshot of all items
    $jsonItems = [];
    foreach ($items as $it) {
        $pi = (int)($it['page_index'] ?? 0);
        // Match HTML placement branch: use original page height from item
        $ph = (float)($it['page_height'] ?? (float)($pageHeights[$pi] ?? 0.0));
        $off = (float)($pageOffsets[$pi] ?? 0.0);
        if (($it['type'] ?? '') === 'text') {
            $fs = isset($it['font_size']) && is_numeric($it['font_size']) ? (float)$it['font_size'] : 12.0;
            $b = isset($it['b']) ? (float)$it['b'] : 0.0;
            $d = isset($it['d']) ? (float)$it['d'] : 1.0;
            $yScale = hypot($b, $d);
            $useScaledFs = is_finite($yScale) && ($yScale > 1.35 || $yScale < 0.74);
            $fsCss = ($useScaledFs ? $fs * $yScale : $fs) * FONT_PX_SCALE;
            $xCss = (float)$it['x'];
            $yCss = (float)$it['y'];
            $jsonItems[] = [
                'type' => 'text',
                'page' => $pi + 1,
                'page_index' => $pi,
                'x' => $xCss,
                'y' => $yCss,
                'left' => $xCss,
                'top' => $off + max(0.0, $ph - $yCss - $fsCss),
                'font_size' => $fsCss,
                'text' => (string)($it['text'] ?? ''),
                'bold' => (bool)($it['bold'] ?? false),
            ];
        } elseif (($it['type'] ?? '') === 'image') {
            $src = (string)($it['dataUri'] ?? '');
            if (!isset($it['tm_a'])) {
                // Legacy placement used in HTML: baseline mapping without subtracting height
                $w = (float)($it['render_w'] ?? 0.0);
                $h = (float)($it['render_h'] ?? 0.0);
                // If the source is an SVG and sizes are implausibly small, read intrinsic size
                if (($w <= 1.0 || $h <= 1.0) && strpos($src, 'data:image/svg+xml') === 0) {
                    $comma = strpos($src, ',');
                    if ($comma !== false) {
                        $svg = base64_decode(substr($src, $comma+1), true);
                        if (is_string($svg) && $svg !== '') {
                            if (preg_match('/\bwidth\s*=\s*"([\d\.]+)"/i', $svg, $mW)) { $w = max($w, (float)$mW[1]); }
                            if (preg_match('/\bheight\s*=\s*"([\d\.]+)"/i', $svg, $mH)) { $h = max($h, (float)$mH[1]); }
                        }
                    }
                }
                // Normalize pathological y values into the current page height range (see HTML emitter above)
                $yRaw = (float)($it['y'] ?? 0.0);
                $phUse = $ph > 0.0 ? $ph : (float)($pageHeights[$pi] ?? 0.0);
                if ($phUse <= 0.0) { $phUse = max(0.0, $yRaw + $h); }
                $yNorm = $phUse > 0.0 ? max(0.0, min($phUse, $phUse - max(0.0, $phUse - $yRaw - $h) - $h)) : max(0.0, $yRaw);
                $topLocal = max(0.0, $phUse - $yNorm - $h);
                $pwUse = (float)($pageWidths[$pi] ?? $maxWidth);
                $leftCss = (float)$it['x'];
                if (!is_finite($leftCss)) { $leftCss = 0.0; }
                if ($pwUse > 0.0) {
                    $rightCss = $leftCss + $w;
                    $leftCss = max(0.0, min($leftCss, $pwUse));
                    $rightCss = max($leftCss, min($pwUse, $rightCss));
                    $w = max(0.0, $rightCss - $leftCss);
                }
                $jsonItems[] = [
                    'type' => 'image',
                    'page' => $pi + 1,
                    'page_index' => $pi,
                    'x' => $leftCss,
                    'y' => $yNorm,
                    'left' => $leftCss,
                    // Conversion: y is bottom-left (content space); subtract height for top-left.
                    'top' => $off + $topLocal,
                    'width' => $w,
                    'height' => $h,
                    'url' => $src,
                ];
            } else {
                // TM-based placement like HTML (use AABB)
                $matrix = [
                    (float)$it['tm_a'],
                    (float)$it['tm_b'],
                    (float)$it['tm_c'],
                    (float)$it['tm_d'],
                    (float)$it['tm_e'],
                    (float)$it['tm_f'],
                ];
                $objW = max(1.0, (float)($it['object_width'] ?? 1.0));
                $objH = max(1.0, (float)($it['object_height'] ?? 1.0));
                $objX = (float)($it['object_min_x'] ?? 0.0);
                $objY = (float)($it['object_min_y'] ?? 0.0);
                [$minX, $minY, $maxX, $maxY] = pdfMatrixRectBounds($matrix, $objW, $objH, $objX, $objY);
                $left = $minX;
                $phUse = $ph > 0.0 ? $ph : (float)($pageHeights[$pi] ?? 0.0);
                if ($phUse <= 0.0) { $phUse = max(0.0, $maxY); }
                $topLocalRaw = max(0.0, $phUse - $maxY);
                $w = max(0.0, $maxX - $minX);
                $h = max(0.0, $maxY - $minY);
                $yNorm = $phUse > 0.0 ? max(0.0, min($phUse, $phUse - $topLocalRaw - $h)) : max(0.0, $maxY);
                $topLocal = max(0.0, $phUse - $yNorm - $h);
                $pwUse = (float)($pageWidths[$pi] ?? $maxWidth);
                if ($pwUse > 0.0) {
                    $right = $left + $w;
                    $left = max(0.0, min($left, $pwUse));
                    $right = max($left, min($pwUse, $right));
                    $w = max(0.0, $right - $left);
                }
                $topCss = $off + $topLocal;
                $jsonItems[] = [
                    'type' => 'image',
                    'page' => $pi + 1,
                    'page_index' => $pi,
                    'x' => $left,
                    'y' => $yNorm,
                    'left' => $left,
                    'top' => $topCss,
                    'width' => $w,
                    'height' => $h,
                    'url' => $src,
                ];
            }
        } elseif (($it['type'] ?? '') === 'line') {
            $w = (float)($it['render_w'] ?? 0.0);
            $h = (float)($it['render_h'] ?? 0.0);
            $jsonItems[] = [
                'type' => 'line',
                'page' => $pi + 1,
                'page_index' => $pi,
                'x' => (float)$it['x'],
                'y' => (float)$it['y'],
                'left' => (float)$it['x'],
                'top' => $off + max(0.0, $ph - ((float)$it['y'] + $h)),
                'width' => $w,
                'height' => $h,
                'color' => (string)($it['color'] ?? '#000'),
            ];
        }
    }
    usort($jsonItems, static function (array $a, array $b): int {
        $pa = (int)($a['page_index'] ?? 0);
        $pb = (int)($b['page_index'] ?? 0);
        if ($pa !== $pb) return $pa <=> $pb;
        $ta = (float)($a['top'] ?? 0.0);
        $tb = (float)($b['top'] ?? 0.0);
        if (abs($ta - $tb) > 0.5) return $ta <=> $tb;
        $la = (float)($a['left'] ?? $a['x'] ?? 0.0);
        $lb = (float)($b['left'] ?? $b['x'] ?? 0.0);
        if (abs($la - $lb) > 0.01) return $la <=> $lb;
        return strcmp((string)($a['type'] ?? ''), (string)($b['type'] ?? ''));
    });
    foreach ($jsonItems as $idx => &$jsonItem) {
        $jsonItem['stream_index'] = $idx;
    }
    unset($jsonItem);

    $pageMeta = [];
    for ($pi = 0; $pi < $pageCount; $pi++) {
        $pageMeta[] = [
            'page' => $pi + 1,
            'page_index' => $pi,
            'raw_height' => (float)($pageHeightsRaw[$pi] ?? 0.0),
            'header_cut' => (float)($pageHeaderCut[$pi] ?? 0.0),
            'footer_cut' => (float)($pageFooterCut[$pi] ?? 0.0),
            'image_header_cut' => (float)($pageHeaderCut2[$pi] ?? 0.0),
            'image_footer_cut' => (float)($pageFooterCut2[$pi] ?? 0.0),
            'content_height' => (float)($pageHeights[$pi] ?? 0.0),
            'offset_top' => (float)($pageOffsets[$pi] ?? 0.0),
            'keep_header' => false,
            'keep_footer' => false,
        ];
    }

    $xtractMeta = [
        'page_count' => $pageCount,
        'repeat_threshold' => $repeatThreshold,
        'stream_height' => (float)$maxBottomCss,
        'header_repeated' => array_values(array_keys($headerRepeated)),
        'footer_repeated' => array_values(array_keys($footerRepeated)),
        'header_repeated_images' => array_values(array_keys($headerRepeatedImages)),
        'footer_repeated_images' => array_values(array_keys($footerRepeatedImages)),
    ];

    $postHeaderImageCounts = [];
    $postFooterImageCounts = [];
    $postHeaderImagesByPage = [];
    $postFooterImagesByPage = [];
    foreach ($jsonItems as $item) {
        if (($item['type'] ?? '') !== 'image') continue;
        $pi = (int)($item['page_index'] ?? -1);
        if ($pi < 0 || !isset($pageMeta[$pi])) continue;
        $contentHeight = (float)($pageMeta[$pi]['content_height'] ?? 0.0);
        $offsetTop = (float)($pageMeta[$pi]['offset_top'] ?? 0.0);
        if ($contentHeight <= 0.0) continue;
        $localTop = (float)($item['top'] ?? 0.0) - $offsetTop;
        $height = max(0.0, (float)($item['height'] ?? 0.0));
        $width = max(0.0, (float)($item['width'] ?? 0.0));
        $left = (float)($item['left'] ?? $item['x'] ?? 0.0);
        $src = (string)($item['url'] ?? '');
        if ($src === '') continue;
        $sig = substr(sha1($src), 0, 16) . ':' . round($width, 1) . ':' . round($height, 1) . ':' . round($left, 1);
        $headerExtent = $localTop + $height;
        $footerExtent = $contentHeight - $localTop;
        if ($localTop <= $contentHeight * 0.20) {
            $postHeaderImagesByPage[$pi][] = ['sig' => $sig, 'extent' => $headerExtent];
            $postHeaderImageCounts[$sig] = ($postHeaderImageCounts[$sig] ?? 0) + 1;
        }
        if ($footerExtent <= $contentHeight * 0.20) {
            $postFooterImagesByPage[$pi][] = ['sig' => $sig, 'extent' => $footerExtent];
            $postFooterImageCounts[$sig] = ($postFooterImageCounts[$sig] ?? 0) + 1;
        }
    }
    $postHeaderRepeatedImages = [];
    foreach ($postHeaderImageCounts as $sig => $cnt) { if ($cnt >= $repeatThreshold) $postHeaderRepeatedImages[$sig] = true; }
    $postFooterRepeatedImages = [];
    foreach ($postFooterImageCounts as $sig => $cnt) { if ($cnt >= $repeatThreshold) $postFooterRepeatedImages[$sig] = true; }
    $postHeaderCuts = array_fill(0, $pageCount, 0.0);
    $postFooterCuts = array_fill(0, $pageCount, 0.0);
    for ($pi = 0; $pi < $pageCount; $pi++) {
        $contentHeight = (float)($pageMeta[$pi]['content_height'] ?? 0.0);
        foreach ($postHeaderImagesByPage[$pi] ?? [] as $imgInfo) {
            if (!empty($postHeaderRepeatedImages[$imgInfo['sig']])) {
                $postHeaderCuts[$pi] = max($postHeaderCuts[$pi], min($contentHeight * 0.25, (float)$imgInfo['extent']));
            }
        }
        foreach ($postFooterImagesByPage[$pi] ?? [] as $imgInfo) {
            if (!empty($postFooterRepeatedImages[$imgInfo['sig']])) {
                $postFooterCuts[$pi] = max($postFooterCuts[$pi], min($contentHeight * 0.25, (float)$imgInfo['extent']));
            }
        }
    }
    $needsJsonCut = false;
    for ($pi = 0; $pi < $pageCount; $pi++) {
        if ($postHeaderCuts[$pi] > 0.5 || $postFooterCuts[$pi] > 0.5) { $needsJsonCut = true; break; }
    }
    if ($needsJsonCut) {
        $newOffsets = [];
        $runningOffset = 0.0;
        for ($pi = 0; $pi < $pageCount; $pi++) {
            $pageMeta[$pi]['header_cut'] = (float)($pageMeta[$pi]['header_cut'] ?? 0.0) + (float)$postHeaderCuts[$pi];
            $pageMeta[$pi]['footer_cut'] = (float)($pageMeta[$pi]['footer_cut'] ?? 0.0) + (float)$postFooterCuts[$pi];
            $pageMeta[$pi]['image_header_cut'] = (float)($pageMeta[$pi]['image_header_cut'] ?? 0.0) + (float)$postHeaderCuts[$pi];
            $pageMeta[$pi]['image_footer_cut'] = (float)($pageMeta[$pi]['image_footer_cut'] ?? 0.0) + (float)$postFooterCuts[$pi];
            $pageMeta[$pi]['content_height'] = max(0.0, (float)($pageMeta[$pi]['content_height'] ?? 0.0) - (float)$postHeaderCuts[$pi] - (float)$postFooterCuts[$pi]);
            $pageMeta[$pi]['offset_top'] = $runningOffset;
            $newOffsets[$pi] = $runningOffset;
            $runningOffset += (float)$pageMeta[$pi]['content_height'];
        }
        $adjustedItems = [];
        foreach ($jsonItems as $item) {
            $pi = (int)($item['page_index'] ?? -1);
            if ($pi < 0 || !isset($pageMeta[$pi])) continue;
            $oldOffset = (float)($pageOffsets[$pi] ?? 0.0);
            $oldContentHeight = (float)($pageMeta[$pi]['content_height'] ?? 0.0) + (float)$postHeaderCuts[$pi] + (float)$postFooterCuts[$pi];
            $newContentHeight = (float)($pageMeta[$pi]['content_height'] ?? 0.0);
            $localTop = (float)($item['top'] ?? 0.0) - $oldOffset;
            $boxHeight = ($item['type'] ?? '') === 'text'
                ? max(0.0, (float)($item['font_size'] ?? 0.0))
                : max(0.0, (float)($item['height'] ?? 0.0));
            $footerExtent = $oldContentHeight - $localTop;
            if ($postHeaderCuts[$pi] > 0.0 && $localTop <= $postHeaderCuts[$pi] + 0.5) continue;
            if ($postFooterCuts[$pi] > 0.0 && $footerExtent <= $postFooterCuts[$pi] + 0.5) continue;
            $localTop = max(0.0, $localTop - (float)$postHeaderCuts[$pi]);
            $item['top'] = $newOffsets[$pi] + $localTop;
            $item['page_height'] = $newContentHeight;
            if (($item['type'] ?? '') === 'text') {
                $item['y'] = max(0.0, $newContentHeight - $localTop - $boxHeight);
            } else {
                $item['y'] = max(0.0, $newContentHeight - $localTop - $boxHeight);
            }
            $adjustedItems[] = $item;
        }
        $jsonItems = $adjustedItems;
        usort($jsonItems, static function (array $a, array $b): int {
            $pa = (int)($a['page_index'] ?? 0);
            $pb = (int)($b['page_index'] ?? 0);
            if ($pa !== $pb) return $pa <=> $pb;
            $ta = (float)($a['top'] ?? 0.0);
            $tb = (float)($b['top'] ?? 0.0);
            if (abs($ta - $tb) > 0.5) return $ta <=> $tb;
            $la = (float)($a['left'] ?? $a['x'] ?? 0.0);
            $lb = (float)($b['left'] ?? $b['x'] ?? 0.0);
            if (abs($la - $lb) > 0.01) return $la <=> $lb;
            return strcmp((string)($a['type'] ?? ''), (string)($b['type'] ?? ''));
        });
        foreach ($jsonItems as $idx => &$jsonItem) {
            $jsonItem['stream_index'] = $idx;
        }
        unset($jsonItem);
    }

    $contentTrimTop = array_fill(0, $pageCount, 0.0);
    $contentTrimBottom = array_fill(0, $pageCount, 0.0);
    $pageHasContent = array_fill(0, $pageCount, false);
    $pageContentTop = array_fill(0, $pageCount, INF);
    $pageContentBottom = array_fill(0, $pageCount, 0.0);

    foreach ($jsonItems as $item) {
        $pi = (int)($item['page_index'] ?? -1);
        if ($pi < 0 || !isset($pageMeta[$pi])) {
            continue;
        }
        if (($item['type'] ?? '') === 'line') {
            continue;
        }
        if (($item['type'] ?? '') === 'text' && trim((string)($item['text'] ?? '')) === '') {
            continue;
        }
        $offsetTop = (float)($pageMeta[$pi]['offset_top'] ?? 0.0);
        $localTop = (float)($item['top'] ?? 0.0) - $offsetTop;
        $boxHeight = ($item['type'] ?? '') === 'text'
            ? max(0.0, (float)($item['font_size'] ?? 0.0))
            : max(0.0, (float)($item['height'] ?? 0.0));
        $boxWidth = ($item['type'] ?? '') === 'text'
            ? max(0.0, (float)mb_strlen((string)($item['text'] ?? ''), 'UTF-8'))
            : max(0.0, (float)($item['width'] ?? 0.0));
        if (($item['type'] ?? '') === 'image' && ($boxWidth <= 2.0 || $boxHeight <= 2.0)) {
            continue;
        }
        $localBottom = $localTop + $boxHeight;
        $pageHasContent[$pi] = true;
        $pageContentTop[$pi] = min($pageContentTop[$pi], $localTop);
        $pageContentBottom[$pi] = max($pageContentBottom[$pi], $localBottom);
    }

    $needsContentCompaction = false;
    for ($pi = 0; $pi < $pageCount; $pi++) {
        if (!$pageHasContent[$pi]) {
            continue;
        }
        $contentHeight = (float)($pageMeta[$pi]['content_height'] ?? 0.0);
        $topTrim = max(0.0, min($contentHeight, (float)$pageContentTop[$pi]));
        $bottomTrim = max(0.0, $contentHeight - max(0.0, min($contentHeight, (float)$pageContentBottom[$pi])));
        $contentTrimTop[$pi] = $topTrim;
        $contentTrimBottom[$pi] = $bottomTrim;
        if ($topTrim > 0.5 || $bottomTrim > 0.5) {
            $needsContentCompaction = true;
        }
    }

    if ($needsContentCompaction) {
        $oldContentHeights = [];
        for ($pi = 0; $pi < $pageCount; $pi++) {
            $oldContentHeights[$pi] = (float)($pageMeta[$pi]['content_height'] ?? 0.0);
        }
        $newOffsets = [];
        $runningOffset = 0.0;
        for ($pi = 0; $pi < $pageCount; $pi++) {
            $pageMeta[$pi]['content_trim_top'] = (float)$contentTrimTop[$pi];
            $pageMeta[$pi]['content_trim_bottom'] = (float)$contentTrimBottom[$pi];
            $pageMeta[$pi]['content_height'] = max(
                0.0,
                (float)($pageMeta[$pi]['content_height'] ?? 0.0) - (float)$contentTrimTop[$pi] - (float)$contentTrimBottom[$pi]
            );
            $pageMeta[$pi]['offset_top'] = $runningOffset;
            $newOffsets[$pi] = $runningOffset;
            $runningOffset += (float)$pageMeta[$pi]['content_height'];
        }

        $compactedItems = [];
        foreach ($jsonItems as $item) {
            $pi = (int)($item['page_index'] ?? -1);
            if ($pi < 0 || !isset($pageMeta[$pi])) {
                $compactedItems[] = $item;
                continue;
            }
            $oldOffset = (float)($pageOffsets[$pi] ?? 0.0);
            $oldLocalTop = (float)($item['top'] ?? 0.0) - $oldOffset;
            $boxHeight = ($item['type'] ?? '') === 'text'
                ? max(0.0, (float)($item['font_size'] ?? 0.0))
                : max(0.0, (float)($item['height'] ?? 0.0));
            $oldLocalBottom = $oldLocalTop + $boxHeight;
            $oldContentHeight = (float)($oldContentHeights[$pi] ?? 0.0);
            $keptBandTop = (float)$contentTrimTop[$pi];
            $keptBandBottom = max($keptBandTop, $oldContentHeight - (float)$contentTrimBottom[$pi]);
            if ($oldLocalBottom <= $keptBandTop + 0.01 || $oldLocalTop >= $keptBandBottom - 0.01) {
                continue;
            }
            $newLocalTop = max(0.0, $oldLocalTop - (float)$contentTrimTop[$pi]);
            $newContentHeight = (float)($pageMeta[$pi]['content_height'] ?? 0.0);
            if ($boxHeight > 0.0 && $newLocalTop + $boxHeight > $newContentHeight) {
                $newLocalTop = max(0.0, $newContentHeight - $boxHeight);
            }
            $item['top'] = $newOffsets[$pi] + $newLocalTop;
            $item['page_height'] = $newContentHeight;
            $item['y'] = max(0.0, $newContentHeight - $newLocalTop - $boxHeight);
            $compactedItems[] = $item;
        }
        $jsonItems = $compactedItems;

        usort($jsonItems, static function (array $a, array $b): int {
            $pa = (int)($a['page_index'] ?? 0);
            $pb = (int)($b['page_index'] ?? 0);
            if ($pa !== $pb) return $pa <=> $pb;
            $ta = (float)($a['top'] ?? 0.0);
            $tb = (float)($b['top'] ?? 0.0);
            if (abs($ta - $tb) > 0.5) return $ta <=> $tb;
            $la = (float)($a['left'] ?? $a['x'] ?? 0.0);
            $lb = (float)($b['left'] ?? $b['x'] ?? 0.0);
            if (abs($la - $lb) > 0.01) return $la <=> $lb;
            return strcmp((string)($a['type'] ?? ''), (string)($b['type'] ?? ''));
        });
        foreach ($jsonItems as $idx => &$jsonItem) {
            $jsonItem['stream_index'] = $idx;
        }
        unset($jsonItem);
    }

    $renderedBottom = 0.0;
    $htmlOut = [
        '<!doctype html><html><head><meta charset="utf-8"><style>',
        'body{margin:0;background:#f3f3f3;font-family:Arial,sans-serif;}',
        '#canvas{position:relative;margin:0 auto;background:#fff;}',
        '.item{position:absolute;box-sizing:border-box;}',
        '.txt{white-space:pre;line-height:1;}',
        '.img{display:block;}',
        '.line{display:block;}',
        '</style></head><body><div id="canvas">'
    ];
    foreach ($jsonItems as $item) {
        $left = (float)($item['left'] ?? $item['x'] ?? 0.0);
        $top = (float)($item['top'] ?? 0.0);
        if (($item['type'] ?? '') === 'text') {
            $fs = (float)($item['font_size'] ?? 12.0);
            $weight = !empty($item['bold']) ? '700' : '400';
            $htmlOut[] = '<div class="item txt" style="left:' . $left . 'px;top:' . $top . 'px;font-size:' . $fs . 'px;font-weight:' . $weight . ';">' . htmlspecialchars((string)($item['text'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
            $renderedBottom = max($renderedBottom, $top + $fs);
        } elseif (($item['type'] ?? '') === 'image') {
            $w = max(0.0, (float)($item['width'] ?? 0.0));
            $h = max(0.0, (float)($item['height'] ?? 0.0));
            $style = 'left:' . $left . 'px;top:' . $top . 'px;';
            if ($w > 0.0) $style .= 'width:' . $w . 'px;';
            if ($h > 0.0) $style .= 'height:' . $h . 'px;';
            $htmlOut[] = '<img class="item img" style="' . $style . '" src="' . htmlspecialchars((string)($item['url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="image" />';
            $renderedBottom = max($renderedBottom, $top + $h);
        } elseif (($item['type'] ?? '') === 'line') {
            $w = max(0.0, (float)($item['width'] ?? 0.0));
            $h = max(0.0, (float)($item['height'] ?? 0.0));
            $color = htmlspecialchars((string)($item['color'] ?? '#000'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $htmlOut[] = '<div class="item line" style="left:' . $left . 'px;top:' . $top . 'px;width:' . $w . 'px;height:' . $h . 'px;background:' . $color . ';"></div>';
            $renderedBottom = max($renderedBottom, $top + $h);
        }
    }
    $xtractMeta['stream_height'] = $renderedBottom;
    $htmlOut[] = '</div><style>#canvas{width:' . (int)ceil($maxWidth) . 'px;height:' . (int)ceil($renderedBottom) . 'px;}</style></body></html>';
    file_put_contents('out2.html', implode('', $htmlOut));

    file_put_contents('out2.json', json_encode([
        'meta' => $xtractMeta,
        'pages' => $pageMeta,
        'items' => $jsonItems,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
