<?php

declare(strict_types=1);

namespace PdfToolkit\Image;

use PdfToolkit\Core\PdfException;
use PdfToolkit\Parser\PdfLiteralString;

final class ImageReader
{
    private const DEFAULT_MAX_DECODED_IMAGE_BYTES = 67108864;
    private const DEFAULT_MAX_RASTER_PIXELS = 40000000;
    private const SVG_MAGICK_ENV = 'PDFTOOLKIT_ENABLE_SVG_MAGICK';
    private const LEGACY_SVG_MAGICK_ENV = 'PDFTOOLBOX_ENABLE_SVG_MAGICK';

    public function readPlacement(ImagePlacement $placement): ImageXObject
    {
        if (!$placement->hasInlineData()) {
            return $this->read($placement->path);
        }

        return match ($placement->format) {
            'svg' => $this->readSvgDataViaMagick($placement->path, $placement->data),
            default => throw new PdfException(sprintf(
                'Unsupported inline image format%s.',
                $placement->format === null ? '' : ': ' . $placement->format
            )),
        };
    }

    public function read(string $path): ImageXObject
    {
        $resolvedPath = realpath($path);

        if ($resolvedPath === false || !is_file($resolvedPath)) {
            throw new PdfException(sprintf('Image file not found: %s', $path));
        }

        $bytes = file_get_contents($resolvedPath);

        if ($bytes === false) {
            throw new PdfException(sprintf('Unable to read image file: %s', $path));
        }

        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return $this->readPng($resolvedPath, $bytes);
        }

        if (str_starts_with($bytes, "\xFF\xD8")) {
            return $this->readJpeg($resolvedPath, $bytes);
        }

        if ($this->looksLikeSvg($bytes)) {
            return $this->readSvgViaMagick($resolvedPath);
        }

        if (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return $this->readWebpViaGd($resolvedPath, $bytes);
        }

        throw new PdfException(sprintf('Unsupported image format for %s. Supported formats: JPEG, PNG, WebP, SVG.', $path));
    }

    private function readJpeg(string $path, string $bytes): ImageXObject
    {
        $info = getimagesizefromstring($bytes);

        if ($info === false) {
            throw new PdfException(sprintf('Unable to inspect JPEG image: %s', $path));
        }

        $this->assertRasterDimensions($path, (int) $info[0], (int) $info[1]);

        $channels = $info['channels'] ?? 3;
        $colorSpace = match ($channels) {
            1 => 'DeviceGray',
            3 => 'DeviceRGB',
            4 => 'DeviceCMYK',
            default => throw new PdfException(sprintf('Unsupported JPEG channel count %d for %s.', $channels, $path)),
        };

        $dictionary = [
            'Type' => 'XObject',
            'Subtype' => 'Image',
            'Width' => (int) $info[0],
            'Height' => (int) $info[1],
            'ColorSpace' => $colorSpace,
            'BitsPerComponent' => 8,
            'Filter' => 'DCTDecode',
        ];

        if (($decode = $this->jpegDecodeArray($bytes, $channels)) !== null) {
            $dictionary['Decode'] = $decode;
        }

        $iccProfile = null;
        $iccBytes = $this->jpegIccProfile($bytes);

        if ($iccBytes !== null) {
            $iccProfile = [
                'dictionary' => [
                    'N' => $channels,
                    'Alternate' => $colorSpace,
                ],
                'data' => $iccBytes,
            ];
        }

        return new ImageXObject(
            key: $this->key($path),
            path: $path,
            width: (int) $info[0],
            height: (int) $info[1],
            dictionary: $dictionary,
            data: $bytes,
            iccProfile: $iccProfile,
        );
    }

    /**
     * @return list<int>|null
     */
    private function jpegDecodeArray(string $bytes, int $channels): ?array
    {
        if ($channels !== 4 || !$this->hasAdobeApp14Marker($bytes)) {
            return null;
        }

        return [1, 0, 1, 0, 1, 0, 1, 0];
    }

    private function hasAdobeApp14Marker(string $bytes): bool
    {
        if (!str_starts_with($bytes, "\xFF\xD8")) {
            return false;
        }

        $offset = 2;
        $length = strlen($bytes);

        while ($offset + 4 <= $length) {
            while ($offset < $length && $bytes[$offset] !== "\xFF") {
                $offset++;
            }

            if ($offset + 1 >= $length) {
                return false;
            }

            while ($offset < $length && $bytes[$offset] === "\xFF") {
                $offset++;
            }

            if ($offset >= $length) {
                return false;
            }

            $marker = ord($bytes[$offset++]);

            if (in_array($marker, [0xD8, 0xD9], true)) {
                continue;
            }

            if ($marker === 0xDA) {
                return false;
            }

            if (($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
                continue;
            }

            if ($offset + 2 > $length) {
                return false;
            }

            $segmentLength = unpack('n', substr($bytes, $offset, 2))[1];
            $offset += 2;

            if ($segmentLength < 2 || $offset + $segmentLength - 2 > $length) {
                return false;
            }

            $segment = substr($bytes, $offset, $segmentLength - 2);

            if ($marker === 0xEE && str_starts_with($segment, 'Adobe')) {
                return true;
            }

            $offset += $segmentLength - 2;
        }

        return false;
    }

    private function jpegIccProfile(string $bytes): ?string
    {
        $segments = $this->jpegAppSegments($bytes, 0xE2);

        if ($segments === []) {
            return null;
        }

        $chunks = [];
        $expectedCount = null;

        foreach ($segments as $segment) {
            if (!str_starts_with($segment, "ICC_PROFILE\0") || strlen($segment) < 14) {
                continue;
            }

            $sequence = ord($segment[12]);
            $count = ord($segment[13]);

            if ($sequence < 1 || $count < 1) {
                continue;
            }

            $expectedCount ??= $count;

            if ($expectedCount !== $count) {
                return null;
            }

            $chunks[$sequence] = substr($segment, 14);
        }

        if ($chunks === [] || $expectedCount === null) {
            return null;
        }

        for ($sequence = 1; $sequence <= $expectedCount; $sequence++) {
            if (!array_key_exists($sequence, $chunks)) {
                return null;
            }
        }

        ksort($chunks);

        return implode('', $chunks);
    }

    /**
     * @return list<string>
     */
    private function jpegAppSegments(string $bytes, int $targetMarker): array
    {
        if (!str_starts_with($bytes, "\xFF\xD8")) {
            return [];
        }

        $offset = 2;
        $length = strlen($bytes);
        $segments = [];

        while ($offset + 4 <= $length) {
            while ($offset < $length && $bytes[$offset] !== "\xFF") {
                $offset++;
            }

            if ($offset + 1 >= $length) {
                break;
            }

            while ($offset < $length && $bytes[$offset] === "\xFF") {
                $offset++;
            }

            if ($offset >= $length) {
                break;
            }

            $marker = ord($bytes[$offset++]);

            if (in_array($marker, [0xD8, 0xD9], true)) {
                continue;
            }

            if ($marker === 0xDA) {
                break;
            }

            if (($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
                continue;
            }

            if ($offset + 2 > $length) {
                break;
            }

            $segmentLength = unpack('n', substr($bytes, $offset, 2))[1];
            $offset += 2;

            if ($segmentLength < 2 || $offset + $segmentLength - 2 > $length) {
                break;
            }

            $segment = substr($bytes, $offset, $segmentLength - 2);

            if ($marker === $targetMarker) {
                $segments[] = $segment;
            }

            $offset += $segmentLength - 2;
        }

        return $segments;
    }

    private function readPng(string $path, string $bytes): ImageXObject
    {
        $offset = 8;
        $width = null;
        $height = null;
        $bitDepth = null;
        $colorType = null;
        $interlace = 0;
        $idat = '';
        $palette = null;
        $transparency = null;

        while ($offset + 8 <= strlen($bytes)) {
            $length = unpack('N', substr($bytes, $offset, 4))[1];
            $type = substr($bytes, $offset + 4, 4);
            $data = substr($bytes, $offset + 8, $length);
            $offset += 12 + $length;

            if ($type === 'IHDR') {
                $header = unpack('Nwidth/Nheight/CbitDepth/CcolorType/Ccompression/Cfilter/Cinterlace', $data);
                $width = (int) $header['width'];
                $height = (int) $header['height'];
                $bitDepth = (int) $header['bitDepth'];
                $colorType = (int) $header['colorType'];
                $interlace = (int) $header['interlace'];
                $this->assertRasterDimensions($path, $width, $height);
            }

            if ($type === 'IDAT') {
                $idat .= $data;
            }

            if ($type === 'PLTE') {
                $palette = $data;
            }

            if ($type === 'tRNS') {
                $transparency = $data;
            }

            if ($type === 'IEND') {
                break;
            }
        }

        if ($width === null || $height === null || $bitDepth === null || $colorType === null || $idat === '') {
            throw new PdfException(sprintf('Invalid PNG image: %s', $path));
        }

        if (
            $interlace !== 0
            || !in_array($colorType, [0, 2, 3, 4, 6], true)
            || !$this->supportsNativePngBitDepth($colorType, $bitDepth)
        ) {
            return $this->readPngViaGd($path, $bytes);
        }

        if ($colorType === 4) {
            return $this->readGrayAlphaPng($path, $width, $height, $idat);
        }

        if ($colorType === 6) {
            return $this->readRgbaPng($path, $width, $height, $idat);
        }

        if ($colorType === 3) {
            return $this->readIndexedPng($path, $width, $height, $bitDepth, $idat, $palette, $transparency);
        }

        [$colorSpace, $colors] = match ($colorType) {
            0 => ['DeviceGray', 1],
            2 => ['DeviceRGB', 3],
            default => throw new PdfException(sprintf('Unsupported PNG color type %d for %s.', $colorType, $path)),
        };

        $dictionary = [
            'Type' => 'XObject',
            'Subtype' => 'Image',
            'Width' => $width,
            'Height' => $height,
            'ColorSpace' => $colorSpace,
            'BitsPerComponent' => $bitDepth,
            'Filter' => 'FlateDecode',
            'DecodeParms' => [
                'Predictor' => 15,
                'Colors' => $colors,
                'BitsPerComponent' => $bitDepth,
                'Columns' => $width,
            ],
        ];

        if (($mask = $this->pngTransparencyMask($colorType, $transparency)) !== null) {
            $dictionary['Mask'] = $mask;
        }

        return new ImageXObject(
            key: $this->key($path),
            path: $path,
            width: $width,
            height: $height,
            dictionary: $dictionary,
            data: $idat,
        );
    }

    private function supportsNativePngBitDepth(int $colorType, int $bitDepth): bool
    {
        return match ($colorType) {
            0, 3 => in_array($bitDepth, [1, 2, 4, 8], true),
            2, 4, 6 => $bitDepth === 8,
            default => false,
        };
    }

    /**
     * @return list<int>|null
     */
    private function pngTransparencyMask(int $colorType, ?string $transparency): ?array
    {
        if ($transparency === null || $transparency === '') {
            return null;
        }

        if ($colorType === 0 && strlen($transparency) >= 2) {
            $value = unpack('n', substr($transparency, 0, 2))[1];

            return [$value, $value];
        }

        if ($colorType === 2 && strlen($transparency) >= 6) {
            $values = unpack('nred/ngreen/nblue', substr($transparency, 0, 6));

            return [
                $values['red'] & 0xFF,
                $values['red'] & 0xFF,
                $values['green'] & 0xFF,
                $values['green'] & 0xFF,
                $values['blue'] & 0xFF,
                $values['blue'] & 0xFF,
            ];
        }

        return null;
    }

    private function readIndexedPng(
        string $path,
        int $width,
        int $height,
        int $bitDepth,
        string $idat,
        ?string $palette,
        ?string $transparency,
    ): ImageXObject {
        if ($palette === null || $palette === '' || strlen($palette) % 3 !== 0) {
            throw new PdfException(sprintf('Indexed PNG image is missing a valid palette: %s', $path));
        }

        $paletteEntries = intdiv(strlen($palette), 3);
        $softMask = null;

        if ($transparency !== null && $transparency !== '') {
            $decoded = $this->inflateImageData($idat);

            if ($decoded === false) {
                throw new PdfException(sprintf('Unable to decode indexed PNG alpha data for %s.', $path));
            }

            $indexes = $bitDepth === 8
                ? $this->decodePngScanlines($decoded, $width, $height, 1)
                : $this->decodePackedPngSamples($decoded, $width, $height, $bitDepth);
            $alphaScanlines = '';
            $cursor = 0;

            for ($y = 0; $y < $height; $y++) {
                $alphaScanlines .= "\x00";

                for ($x = 0; $x < $width; $x++) {
                    $index = ord($indexes[$cursor++]);
                    $alphaScanlines .= $transparency[$index] ?? "\xFF";
                }
            }

            $softMask = new ImageXObject(
                key: $this->key($path) . ':alpha',
                path: $path,
                width: $width,
                height: $height,
                dictionary: [
                    'Type' => 'XObject',
                    'Subtype' => 'Image',
                    'Width' => $width,
                    'Height' => $height,
                    'ColorSpace' => 'DeviceGray',
                    'BitsPerComponent' => 8,
                    'Filter' => 'FlateDecode',
                    'DecodeParms' => [
                        'Predictor' => 15,
                        'Colors' => 1,
                        'BitsPerComponent' => 8,
                        'Columns' => $width,
                    ],
                ],
                data: gzcompress($alphaScanlines),
            );
        }

        return new ImageXObject(
            key: $this->key($path),
            path: $path,
            width: $width,
            height: $height,
            dictionary: [
                'Type' => 'XObject',
                'Subtype' => 'Image',
                'Width' => $width,
                'Height' => $height,
                'ColorSpace' => ['Indexed', 'DeviceRGB', $paletteEntries - 1, new PdfLiteralString($palette)],
                'BitsPerComponent' => $bitDepth,
                'Filter' => 'FlateDecode',
                'DecodeParms' => [
                    'Predictor' => 15,
                    'Colors' => 1,
                    'BitsPerComponent' => $bitDepth,
                    'Columns' => $width,
                ],
            ],
            data: $idat,
            softMask: $softMask,
        );
    }

    private function decodePackedPngSamples(string $decoded, int $width, int $height, int $bitDepth): string
    {
        if (!in_array($bitDepth, [1, 2, 4], true)) {
            throw new PdfException(sprintf('Unsupported packed PNG bit depth %d.', $bitDepth));
        }

        $bytesPerRow = (int) ceil(($width * $bitDepth) / 8);
        $cursor = 0;
        $previous = str_repeat("\x00", $bytesPerRow);
        $samples = '';
        $mask = (1 << $bitDepth) - 1;
        $samplesPerByte = intdiv(8, $bitDepth);

        for ($y = 0; $y < $height; $y++) {
            if ($cursor >= strlen($decoded)) {
                throw new PdfException('Unexpected end of PNG scanline data.');
            }

            $filter = ord($decoded[$cursor++]);
            $scanline = substr($decoded, $cursor, $bytesPerRow);
            $cursor += $bytesPerRow;

            if (strlen($scanline) !== $bytesPerRow) {
                throw new PdfException('Incomplete PNG scanline encountered.');
            }

            $reconstructed = '';

            for ($i = 0; $i < $bytesPerRow; $i++) {
                $raw = ord($scanline[$i]);
                $left = $i > 0 ? ord($reconstructed[$i - 1]) : 0;
                $up = ord($previous[$i]);
                $upperLeft = $i > 0 ? ord($previous[$i - 1]) : 0;

                $value = match ($filter) {
                    0 => $raw,
                    1 => $raw + $left,
                    2 => $raw + $up,
                    3 => $raw + intdiv($left + $up, 2),
                    4 => $raw + $this->paeth($left, $up, $upperLeft),
                    default => throw new PdfException(sprintf('Unsupported PNG filter type %d.', $filter)),
                };

                $reconstructed .= chr($value & 0xFF);
            }

            for ($x = 0; $x < $width; $x++) {
                $byte = ord($reconstructed[intdiv($x, $samplesPerByte)]);
                $shift = 8 - $bitDepth - (($x % $samplesPerByte) * $bitDepth);
                $samples .= chr(($byte >> $shift) & $mask);
            }

            $previous = $reconstructed;
        }

        return $samples;
    }

    private function readPngViaGd(string $path, string $bytes): ImageXObject
    {
        $image = $this->decodeRasterImageViaGd($bytes, sprintf('This PNG variant requires the GD extension to decode: %s', $path), sprintf('Unable to decode PNG image via GD: %s', $path));

        return $this->rasterImageToXObject($path, $image);
    }

    private function readWebpViaGd(string $path, string $bytes): ImageXObject
    {
        $image = $this->decodeRasterImageViaGd($bytes, sprintf('WebP decoding requires the GD extension: %s', $path), sprintf('Unable to decode WebP image via GD: %s', $path));

        return $this->rasterImageToXObject($path, $image);
    }

    private function readSvgViaMagick(string $path): ImageXObject
    {
        $bytes = file_get_contents($path);

        if ($bytes === false) {
            throw new PdfException(sprintf('Unable to read SVG image file: %s', $path));
        }

        return $this->readSvgDataViaMagick($path, $bytes);
    }

    private function readSvgDataViaMagick(string $label, string $bytes): ImageXObject
    {
        if (!$this->svgMagickEnabled()) {
            throw new PdfException(sprintf(
                'SVG decoding via ImageMagick is disabled by default for security. Set %s=1 to enable it for trusted SVG input: %s',
                self::SVG_MAGICK_ENV,
                $label
            ));
        }

        $magick = $this->findExecutable('magick');

        if ($magick === null) {
            return $this->readSvgDataViaGdFallback($label, $bytes);
        }

        $inputPath = tempnam(sys_get_temp_dir(), 'pdftoolkit-svg-src-');

        if ($inputPath === false) {
            throw new PdfException(sprintf('Unable to allocate temporary file for SVG decoding: %s', $label));
        }

        $outputPath = tempnam(sys_get_temp_dir(), 'pdftoolbox-svg-');

        if ($outputPath === false) {
            @unlink($inputPath);
            throw new PdfException(sprintf('Unable to allocate temporary file for SVG decoding: %s', $label));
        }

        $pngPath = $outputPath . '.png';
        @unlink($outputPath);
        $svgPath = $inputPath . '.svg';
        @unlink($inputPath);

        if (file_put_contents($svgPath, $bytes) === false) {
            @unlink($svgPath);
            throw new PdfException(sprintf('Unable to write temporary SVG image for decoding: %s', $label));
        }

        $command = sprintf(
            '%s -background none %s PNG32:%s 2>&1',
            escapeshellarg($magick),
            escapeshellarg($svgPath),
            escapeshellarg($pngPath)
        );

        exec($command, $output, $exitCode);

        if ($exitCode !== 0 || !is_file($pngPath)) {
            @unlink($svgPath);
            @unlink($pngPath);

            return $this->readSvgDataViaGdFallback($label, $bytes);
        }

        try {
            $pngBytes = file_get_contents($pngPath);

            if ($pngBytes === false) {
                throw new PdfException(sprintf('Unable to read rendered SVG image: %s', $label));
            }

            $image = $this->readPngViaGd($label, $pngBytes);

            if ($this->imageXObjectHasVisiblePixels($image)) {
                return $image;
            }

            return $this->readSvgDataViaGdFallback($label, $bytes);
        } finally {
            @unlink($svgPath);
            @unlink($pngPath);
        }
    }

    private function readSvgDataViaGdFallback(string $label, string $bytes): ImageXObject
    {
        if (!class_exists(\DOMDocument::class)) {
            throw new PdfException(sprintf('Unable to decode SVG image: DOM extension is required for fallback rendering: %s', $label));
        }

        if (!function_exists('imagecreatetruecolor')) {
            throw new PdfException(sprintf('Unable to decode SVG image: GD extension is required for fallback rendering: %s', $label));
        }

        $document = new \DOMDocument();

        if (@$document->loadXML($bytes, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) !== true) {
            throw new PdfException(sprintf('Unable to parse SVG image: %s', $label));
        }

        $root = $document->documentElement;

        if (!$root instanceof \DOMElement || strtolower($root->tagName) !== 'svg') {
            throw new PdfException(sprintf('Unable to parse SVG image: missing root <svg> element: %s', $label));
        }

        [$width, $height, $viewBox] = $this->svgViewport($root, $label);
        $this->assertRasterDimensions($label, $width, $height);

        $image = imagecreatetruecolor($width, $height);

        if (!$image instanceof \GdImage) {
            throw new PdfException(sprintf('Unable to allocate fallback SVG raster image: %s', $label));
        }

        imagealphablending($image, true);
        imagesavealpha($image, false);
        $background = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $background);

        $this->renderSvgElementChildren($image, $root, $viewBox);

        return $this->rasterImageToMaskedColorXObject($label, $image, [255, 255, 255]);
    }

    private function decodeRasterImageViaGd(string $bytes, string $missingExtensionMessage, string $decodeFailureMessage): \GdImage
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new PdfException($missingExtensionMessage);
        }

        $info = getimagesizefromstring($bytes);

        if ($info !== false) {
            $this->assertRasterDimensions('embedded raster image', (int) $info[0], (int) $info[1]);
        }

        $image = imagecreatefromstring($bytes);

        if (!$image instanceof \GdImage) {
            throw new PdfException($decodeFailureMessage);
        }

        return $image;
    }

    private function rasterImageToXObject(string $path, \GdImage $image): ImageXObject
    {
        imagesavealpha($image, true);

        $width = imagesx($image);
        $height = imagesy($image);
        $rgbScanlines = '';
        $alphaScanlines = '';
        $hasTransparency = false;

        for ($y = 0; $y < $height; $y++) {
            $rgbScanlines .= "\x00";
            $alphaScanlines .= "\x00";

            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($image, $x, $y);
                $components = imageistruecolor($image)
                    ? $this->trueColorComponents($color)
                    : imagecolorsforindex($image, $color);

                $red = (int) ($components['red'] ?? 0);
                $green = (int) ($components['green'] ?? 0);
                $blue = (int) ($components['blue'] ?? 0);
                $alpha = (int) ($components['alpha'] ?? 0);
                $alphaByte = (int) round((127 - max(0, min(127, $alpha))) * 255 / 127);

                if ($alphaByte < 255) {
                    $hasTransparency = true;
                }

                $rgbScanlines .= chr($red) . chr($green) . chr($blue);
                $alphaScanlines .= chr($alphaByte);
            }
        }

        imagedestroy($image);

        $softMask = null;

        if ($hasTransparency) {
            $softMask = new ImageXObject(
                key: $this->key($path) . ':alpha',
                path: $path,
                width: $width,
                height: $height,
                dictionary: [
                    'Type' => 'XObject',
                    'Subtype' => 'Image',
                    'Width' => $width,
                    'Height' => $height,
                    'ColorSpace' => 'DeviceGray',
                    'BitsPerComponent' => 8,
                    'Filter' => 'FlateDecode',
                    'DecodeParms' => [
                        'Predictor' => 15,
                        'Colors' => 1,
                        'BitsPerComponent' => 8,
                        'Columns' => $width,
                    ],
                ],
                data: gzcompress($alphaScanlines),
            );
        }

        return new ImageXObject(
            key: $this->key($path),
            path: $path,
            width: $width,
            height: $height,
            dictionary: [
                'Type' => 'XObject',
                'Subtype' => 'Image',
                'Width' => $width,
                'Height' => $height,
                'ColorSpace' => 'DeviceRGB',
                'BitsPerComponent' => 8,
                'Filter' => 'FlateDecode',
                'DecodeParms' => [
                    'Predictor' => 15,
                    'Colors' => 3,
                    'BitsPerComponent' => 8,
                    'Columns' => $width,
                ],
            ],
            data: gzcompress($rgbScanlines),
            softMask: $softMask,
        );
    }

    /**
     * @param array{0: int, 1: int, 2: int} $transparentRgb
     */
    private function rasterImageToMaskedColorXObject(string $path, \GdImage $image, array $transparentRgb): ImageXObject
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $rgbScanlines = '';

        for ($y = 0; $y < $height; $y++) {
            $rgbScanlines .= "\x00";

            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($image, $x, $y);
                $components = imageistruecolor($image)
                    ? $this->trueColorComponents($color)
                    : imagecolorsforindex($image, $color);

                $rgbScanlines .= chr((int) ($components['red'] ?? 0))
                    . chr((int) ($components['green'] ?? 0))
                    . chr((int) ($components['blue'] ?? 0));
            }
        }

        imagedestroy($image);

        return new ImageXObject(
            key: $this->key($path),
            path: $path,
            width: $width,
            height: $height,
            dictionary: [
                'Type' => 'XObject',
                'Subtype' => 'Image',
                'Width' => $width,
                'Height' => $height,
                'ColorSpace' => 'DeviceRGB',
                'BitsPerComponent' => 8,
                'Filter' => 'FlateDecode',
                'DecodeParms' => [
                    'Predictor' => 15,
                    'Colors' => 3,
                    'BitsPerComponent' => 8,
                    'Columns' => $width,
                ],
                'Mask' => [
                    $transparentRgb[0], $transparentRgb[0],
                    $transparentRgb[1], $transparentRgb[1],
                    $transparentRgb[2], $transparentRgb[2],
                ],
            ],
            data: gzcompress($rgbScanlines),
        );
    }

    private function readRgbaPng(string $path, int $width, int $height, string $idat): ImageXObject
    {
        $decoded = $this->inflateImageData($idat);

        if ($decoded === false) {
            throw new PdfException(sprintf('Unable to decode PNG alpha data for %s.', $path));
        }

        $rgba = $this->decodePngScanlines($decoded, $width, $height, 4);
        $rgbScanlines = '';
        $alphaScanlines = '';
        $cursor = 0;

        for ($y = 0; $y < $height; $y++) {
            $rgbScanlines .= "\x00";
            $alphaScanlines .= "\x00";

            for ($x = 0; $x < $width; $x++) {
                $rgbScanlines .= $rgba[$cursor] . $rgba[$cursor + 1] . $rgba[$cursor + 2];
                $alphaScanlines .= $rgba[$cursor + 3];
                $cursor += 4;
            }
        }

        $softMask = new ImageXObject(
            key: $this->key($path) . ':alpha',
            path: $path,
            width: $width,
            height: $height,
            dictionary: [
                'Type' => 'XObject',
                'Subtype' => 'Image',
                'Width' => $width,
                'Height' => $height,
                'ColorSpace' => 'DeviceGray',
                'BitsPerComponent' => 8,
                'Filter' => 'FlateDecode',
                'DecodeParms' => [
                    'Predictor' => 15,
                    'Colors' => 1,
                    'BitsPerComponent' => 8,
                    'Columns' => $width,
                ],
            ],
            data: gzcompress($alphaScanlines),
        );

        return new ImageXObject(
            key: $this->key($path),
            path: $path,
            width: $width,
            height: $height,
            dictionary: [
                'Type' => 'XObject',
                'Subtype' => 'Image',
                'Width' => $width,
                'Height' => $height,
                'ColorSpace' => 'DeviceRGB',
                'BitsPerComponent' => 8,
                'Filter' => 'FlateDecode',
                'DecodeParms' => [
                    'Predictor' => 15,
                    'Colors' => 3,
                    'BitsPerComponent' => 8,
                    'Columns' => $width,
                ],
            ],
            data: gzcompress($rgbScanlines),
            softMask: $softMask,
        );
    }

    private function readGrayAlphaPng(string $path, int $width, int $height, string $idat): ImageXObject
    {
        $decoded = $this->inflateImageData($idat);

        if ($decoded === false) {
            throw new PdfException(sprintf('Unable to decode PNG alpha data for %s.', $path));
        }

        $grayAlpha = $this->decodePngScanlines($decoded, $width, $height, 2);
        $grayScanlines = '';
        $alphaScanlines = '';
        $cursor = 0;

        for ($y = 0; $y < $height; $y++) {
            $grayScanlines .= "\x00";
            $alphaScanlines .= "\x00";

            for ($x = 0; $x < $width; $x++) {
                $grayScanlines .= $grayAlpha[$cursor];
                $alphaScanlines .= $grayAlpha[$cursor + 1];
                $cursor += 2;
            }
        }

        $softMask = new ImageXObject(
            key: $this->key($path) . ':alpha',
            path: $path,
            width: $width,
            height: $height,
            dictionary: [
                'Type' => 'XObject',
                'Subtype' => 'Image',
                'Width' => $width,
                'Height' => $height,
                'ColorSpace' => 'DeviceGray',
                'BitsPerComponent' => 8,
                'Filter' => 'FlateDecode',
                'DecodeParms' => [
                    'Predictor' => 15,
                    'Colors' => 1,
                    'BitsPerComponent' => 8,
                    'Columns' => $width,
                ],
            ],
            data: gzcompress($alphaScanlines),
        );

        return new ImageXObject(
            key: $this->key($path),
            path: $path,
            width: $width,
            height: $height,
            dictionary: [
                'Type' => 'XObject',
                'Subtype' => 'Image',
                'Width' => $width,
                'Height' => $height,
                'ColorSpace' => 'DeviceGray',
                'BitsPerComponent' => 8,
                'Filter' => 'FlateDecode',
                'DecodeParms' => [
                    'Predictor' => 15,
                    'Colors' => 1,
                    'BitsPerComponent' => 8,
                    'Columns' => $width,
                ],
            ],
            data: gzcompress($grayScanlines),
            softMask: $softMask,
        );
    }

    private function decodePngScanlines(string $decoded, int $width, int $height, int $bytesPerPixel): string
    {
        $stride = $width * $bytesPerPixel;
        $cursor = 0;
        $previous = str_repeat("\x00", $stride);
        $output = '';

        for ($y = 0; $y < $height; $y++) {
            if ($cursor >= strlen($decoded)) {
                throw new PdfException('Unexpected end of PNG scanline data.');
            }

            $filter = ord($decoded[$cursor++]);
            $scanline = substr($decoded, $cursor, $stride);
            $cursor += $stride;

            if (strlen($scanline) !== $stride) {
                throw new PdfException('Incomplete PNG scanline encountered.');
            }

            $reconstructed = '';

            for ($i = 0; $i < $stride; $i++) {
                $raw = ord($scanline[$i]);
                $left = $i >= $bytesPerPixel ? ord($reconstructed[$i - $bytesPerPixel]) : 0;
                $up = ord($previous[$i]);
                $upperLeft = $i >= $bytesPerPixel ? ord($previous[$i - $bytesPerPixel]) : 0;

                $value = match ($filter) {
                    0 => $raw,
                    1 => $raw + $left,
                    2 => $raw + $up,
                    3 => $raw + intdiv($left + $up, 2),
                    4 => $raw + $this->paeth($left, $up, $upperLeft),
                    default => throw new PdfException(sprintf('Unsupported PNG filter type %d.', $filter)),
                };

                $reconstructed .= chr($value & 0xFF);
            }

            $output .= $reconstructed;
            $previous = $reconstructed;
        }

        return $output;
    }

    private function paeth(int $left, int $up, int $upperLeft): int
    {
        $estimate = $left + $up - $upperLeft;
        $leftDistance = abs($estimate - $left);
        $upDistance = abs($estimate - $up);
        $upperLeftDistance = abs($estimate - $upperLeft);

        if ($leftDistance <= $upDistance && $leftDistance <= $upperLeftDistance) {
            return $left;
        }

        if ($upDistance <= $upperLeftDistance) {
            return $up;
        }

        return $upperLeft;
    }

    /**
     * @return array{red: int, green: int, blue: int, alpha: int}
     */
    private function trueColorComponents(int $color): array
    {
        return [
            'red' => ($color >> 16) & 0xFF,
            'green' => ($color >> 8) & 0xFF,
            'blue' => $color & 0xFF,
            'alpha' => ($color & 0x7F000000) >> 24,
        ];
    }

    private function looksLikeSvg(string $bytes): bool
    {
        $trimmed = ltrim($bytes);

        return str_starts_with($trimmed, '<svg')
            || (str_starts_with($trimmed, '<?xml') && str_contains($trimmed, '<svg'));
    }

    private function imageXObjectHasVisiblePixels(ImageXObject $image): bool
    {
        $rgb = gzuncompress($image->data);

        if (is_string($rgb) && preg_match('/[^\x00]/', $rgb) === 1) {
            return true;
        }

        if ($image->softMask === null) {
            return false;
        }

        $alpha = gzuncompress($image->softMask->data);

        return is_string($alpha) && preg_match('/[^\x00]/', $alpha) === 1;
    }

    /**
     * @return array{0: int, 1: int, 2: array{x: float, y: float, width: float, height: float}}
     */
    private function svgViewport(\DOMElement $root, string $label): array
    {
        $viewBox = $root->getAttribute('viewBox');
        $viewBoxValues = preg_split('/[\s,]+/', trim($viewBox));

        if (is_array($viewBoxValues) && count($viewBoxValues) === 4) {
            $view = [
                'x' => (float) $viewBoxValues[0],
                'y' => (float) $viewBoxValues[1],
                'width' => (float) $viewBoxValues[2],
                'height' => (float) $viewBoxValues[3],
            ];
        } else {
            $width = $this->svgLength($root->getAttribute('width'));
            $height = $this->svgLength($root->getAttribute('height'));

            if ($width === null || $height === null || $width <= 0 || $height <= 0) {
                throw new PdfException(sprintf('Unable to determine SVG viewport: %s', $label));
            }

            $view = ['x' => 0.0, 'y' => 0.0, 'width' => $width, 'height' => $height];
        }

        $width = (int) max(1, round($this->svgLength($root->getAttribute('width')) ?? $view['width']));
        $height = (int) max(1, round($this->svgLength($root->getAttribute('height')) ?? $view['height']));

        return [$width, $height, $view];
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $viewBox
     */
    private function renderSvgElementChildren(\GdImage $image, \DOMElement $root, array $viewBox): void
    {
        foreach ($root->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }

            $this->renderSvgElement($image, $child, $viewBox);
        }
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $viewBox
     */
    private function renderSvgElement(\GdImage $image, \DOMElement $element, array $viewBox): void
    {
        $name = strtolower($element->tagName);

        if ($name === 'g' || $name === 'svg') {
            $this->renderSvgElementChildren($image, $element, $viewBox);

            return;
        }

        match ($name) {
            'path' => $this->renderSvgPathElement($image, $element, $viewBox),
            'rect' => $this->renderSvgRectElement($image, $element, $viewBox),
            'line' => $this->renderSvgLineElement($image, $element, $viewBox),
            'polyline', 'polygon' => $this->renderSvgPolylineElement($image, $element, $viewBox, $name === 'polygon'),
            default => null,
        };
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $viewBox
     */
    private function renderSvgRectElement(\GdImage $image, \DOMElement $element, array $viewBox): void
    {
        $fill = $this->svgColor($element->getAttribute('fill'));

        if ($fill === null) {
            return;
        }

        $x = $this->svgLength($element->getAttribute('x')) ?? 0.0;
        $y = $this->svgLength($element->getAttribute('y')) ?? 0.0;
        $width = $this->svgLength($element->getAttribute('width')) ?? 0.0;
        $height = $this->svgLength($element->getAttribute('height')) ?? 0.0;

        if ($width <= 0 || $height <= 0) {
            return;
        }

        [$x1, $y1] = $this->svgPointToPixel($x, $y, $viewBox, imagesx($image), imagesy($image));
        [$x2, $y2] = $this->svgPointToPixel($x + $width, $y + $height, $viewBox, imagesx($image), imagesy($image));
        $color = imagecolorallocatealpha($image, $fill['r'], $fill['g'], $fill['b'], 0);
        imagefilledrectangle($image, $x1, $y1, $x2, $y2, $color);
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $viewBox
     */
    private function renderSvgLineElement(\GdImage $image, \DOMElement $element, array $viewBox): void
    {
        $points = [[
            'x' => $this->svgLength($element->getAttribute('x1')) ?? 0.0,
            'y' => $this->svgLength($element->getAttribute('y1')) ?? 0.0,
        ], [
            'x' => $this->svgLength($element->getAttribute('x2')) ?? 0.0,
            'y' => $this->svgLength($element->getAttribute('y2')) ?? 0.0,
        ]];

        $this->strokeSvgPolyline($image, $points, $element, $viewBox, false);
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $viewBox
     */
    private function renderSvgPolylineElement(\GdImage $image, \DOMElement $element, array $viewBox, bool $closed): void
    {
        $pointsAttribute = trim($element->getAttribute('points'));

        if ($pointsAttribute === '') {
            return;
        }

        $values = preg_split('/[\s,]+/', $pointsAttribute);

        if (!is_array($values) || count($values) < 4) {
            return;
        }

        $points = [];

        for ($index = 0; $index + 1 < count($values); $index += 2) {
            $points[] = [
                'x' => (float) $values[$index],
                'y' => (float) $values[$index + 1],
            ];
        }

        $this->strokeSvgPolyline($image, $points, $element, $viewBox, $closed);
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $viewBox
     */
    private function renderSvgPathElement(\GdImage $image, \DOMElement $element, array $viewBox): void
    {
        $stroke = $this->svgColor($element->getAttribute('stroke'));

        if ($stroke === null) {
            return;
        }

        $d = trim($element->getAttribute('d'));

        if ($d === '') {
            return;
        }

        $paths = $this->svgPathToPolylines($d);
        $this->strokeSvgPaths($image, $paths, $element, $viewBox);
    }

    /**
     * @param list<array{x: float, y: float}> $points
     * @param array{x: float, y: float, width: float, height: float} $viewBox
     */
    private function strokeSvgPolyline(\GdImage $image, array $points, \DOMElement $element, array $viewBox, bool $closed): void
    {
        $stroke = $this->svgColor($element->getAttribute('stroke'));

        if ($stroke === null || count($points) < 2) {
            return;
        }

        $this->strokeSvgPaths($image, [[
            'points' => $points,
            'closed' => $closed,
        ]], $element, $viewBox);
    }

    /**
     * @param list<array{points: list<array{x: float, y: float}>, closed: bool}> $paths
     * @param array{x: float, y: float, width: float, height: float} $viewBox
     */
    private function strokeSvgPaths(\GdImage $image, array $paths, \DOMElement $element, array $viewBox): void
    {
        $stroke = $this->svgColor($element->getAttribute('stroke'));

        if ($stroke === null) {
            return;
        }

        $color = imagecolorallocatealpha($image, $stroke['r'], $stroke['g'], $stroke['b'], 0);
        $strokeWidth = max(1, (int) round($this->svgLength($element->getAttribute('stroke-width')) ?? 1.0));
        $lineCap = strtolower(trim($element->getAttribute('stroke-linecap')));

        imagesetthickness($image, $strokeWidth);

        foreach ($paths as $path) {
            $pixelPoints = array_map(
                fn (array $point): array => $this->svgPointToPixel(
                    $point['x'],
                    $point['y'],
                    $viewBox,
                    imagesx($image),
                    imagesy($image)
                ),
                $path['points']
            );

            for ($index = 0; $index + 1 < count($pixelPoints); $index++) {
                [$x1, $y1] = $pixelPoints[$index];
                [$x2, $y2] = $pixelPoints[$index + 1];
                imageline($image, $x1, $y1, $x2, $y2, $color);
            }

            if ($path['closed'] && count($pixelPoints) > 2) {
                [$x1, $y1] = $pixelPoints[array_key_last($pixelPoints)];
                [$x2, $y2] = $pixelPoints[0];
                imageline($image, $x1, $y1, $x2, $y2, $color);
            }

            if ($lineCap === 'round' && $pixelPoints !== []) {
                [$startX, $startY] = $pixelPoints[0];
                [$endX, $endY] = $pixelPoints[array_key_last($pixelPoints)];
                imagefilledellipse($image, $startX, $startY, $strokeWidth, $strokeWidth, $color);
                imagefilledellipse($image, $endX, $endY, $strokeWidth, $strokeWidth, $color);
            }
        }

        imagesetthickness($image, 1);
    }

    /**
     * @return list<array{points: list<array{x: float, y: float}>, closed: bool}>
     */
    private function svgPathToPolylines(string $d): array
    {
        preg_match_all('/[A-Za-z]|[-+]?(?:\d*\.\d+|\d+)(?:[eE][-+]?\d+)?/', $d, $matches);
        $tokens = $matches[0] ?? [];
        $index = 0;
        $command = null;
        $current = ['x' => 0.0, 'y' => 0.0];
        $start = ['x' => 0.0, 'y' => 0.0];
        $lastCubicControl = null;
        $lastQuadraticControl = null;
        $paths = [];
        $activePath = null;

        while ($index < count($tokens)) {
            if (preg_match('/^[A-Za-z]$/', $tokens[$index]) === 1) {
                $command = $tokens[$index++];
            }

            if ($command === null) {
                break;
            }

            $absolute = strtoupper($command) === $command;

            switch (strtoupper($command)) {
                case 'M':
                    $x = (float) $tokens[$index++];
                    $y = (float) $tokens[$index++];
                    $current = $absolute ? ['x' => $x, 'y' => $y] : ['x' => $current['x'] + $x, 'y' => $current['y'] + $y];
                    $start = $current;
                    $activePath = ['points' => [$current], 'closed' => false];
                    $paths[] = &$activePath;
                    $command = $absolute ? 'L' : 'l';
                    $lastCubicControl = null;
                    $lastQuadraticControl = null;
                    break;

                case 'L':
                    if ($activePath === null) {
                        break 2;
                    }
                    $x = (float) $tokens[$index++];
                    $y = (float) $tokens[$index++];
                    $current = $absolute ? ['x' => $x, 'y' => $y] : ['x' => $current['x'] + $x, 'y' => $current['y'] + $y];
                    $activePath['points'][] = $current;
                    $lastCubicControl = null;
                    $lastQuadraticControl = null;
                    break;

                case 'H':
                    if ($activePath === null) {
                        break 2;
                    }
                    $x = (float) $tokens[$index++];
                    $current = $absolute ? ['x' => $x, 'y' => $current['y']] : ['x' => $current['x'] + $x, 'y' => $current['y']];
                    $activePath['points'][] = $current;
                    $lastCubicControl = null;
                    $lastQuadraticControl = null;
                    break;

                case 'V':
                    if ($activePath === null) {
                        break 2;
                    }
                    $y = (float) $tokens[$index++];
                    $current = $absolute ? ['x' => $current['x'], 'y' => $y] : ['x' => $current['x'], 'y' => $current['y'] + $y];
                    $activePath['points'][] = $current;
                    $lastCubicControl = null;
                    $lastQuadraticControl = null;
                    break;

                case 'C':
                    if ($activePath === null) {
                        break 2;
                    }
                    $control1 = $this->svgPathPoint($tokens, $index, $absolute, $current);
                    $control2 = $this->svgPathPoint($tokens, $index, $absolute, $current);
                    $end = $this->svgPathPoint($tokens, $index, $absolute, $current);
                    $curve = $this->sampleSvgCubic($current, $control1, $control2, $end);
                    array_shift($curve);
                    $activePath['points'] = [...$activePath['points'], ...$curve];
                    $current = $end;
                    $lastCubicControl = $control2;
                    $lastQuadraticControl = null;
                    break;

                case 'S':
                    if ($activePath === null) {
                        break 2;
                    }
                    $control1 = $lastCubicControl === null
                        ? $current
                        : ['x' => 2 * $current['x'] - $lastCubicControl['x'], 'y' => 2 * $current['y'] - $lastCubicControl['y']];
                    $control2 = $this->svgPathPoint($tokens, $index, $absolute, $current);
                    $end = $this->svgPathPoint($tokens, $index, $absolute, $current);
                    $curve = $this->sampleSvgCubic($current, $control1, $control2, $end);
                    array_shift($curve);
                    $activePath['points'] = [...$activePath['points'], ...$curve];
                    $current = $end;
                    $lastCubicControl = $control2;
                    $lastQuadraticControl = null;
                    break;

                case 'Q':
                    if ($activePath === null) {
                        break 2;
                    }
                    $control = $this->svgPathPoint($tokens, $index, $absolute, $current);
                    $end = $this->svgPathPoint($tokens, $index, $absolute, $current);
                    $curve = $this->sampleSvgQuadratic($current, $control, $end);
                    array_shift($curve);
                    $activePath['points'] = [...$activePath['points'], ...$curve];
                    $current = $end;
                    $lastQuadraticControl = $control;
                    $lastCubicControl = null;
                    break;

                case 'T':
                    if ($activePath === null) {
                        break 2;
                    }
                    $control = $lastQuadraticControl === null
                        ? $current
                        : ['x' => 2 * $current['x'] - $lastQuadraticControl['x'], 'y' => 2 * $current['y'] - $lastQuadraticControl['y']];
                    $end = $this->svgPathPoint($tokens, $index, $absolute, $current);
                    $curve = $this->sampleSvgQuadratic($current, $control, $end);
                    array_shift($curve);
                    $activePath['points'] = [...$activePath['points'], ...$curve];
                    $current = $end;
                    $lastQuadraticControl = $control;
                    $lastCubicControl = null;
                    break;

                case 'Z':
                    if ($activePath !== null) {
                        $activePath['closed'] = true;
                        $current = $start;
                    }
                    $lastCubicControl = null;
                    $lastQuadraticControl = null;
                    break;

                default:
                    $index++;
                    break;
            }
        }

        return array_values(array_filter($paths, static fn (array $path): bool => count($path['points']) >= 2));
    }

    /**
     * @param list<string> $tokens
     * @param array{x: float, y: float} $current
     * @return array{x: float, y: float}
     */
    private function svgPathPoint(array $tokens, int &$index, bool $absolute, array $current): array
    {
        $x = (float) $tokens[$index++];
        $y = (float) $tokens[$index++];

        if ($absolute) {
            return ['x' => $x, 'y' => $y];
        }

        return ['x' => $current['x'] + $x, 'y' => $current['y'] + $y];
    }

    /**
     * @param array{x: float, y: float} $start
     * @param array{x: float, y: float} $control1
     * @param array{x: float, y: float} $control2
     * @param array{x: float, y: float} $end
     * @return list<array{x: float, y: float}>
     */
    private function sampleSvgCubic(array $start, array $control1, array $control2, array $end): array
    {
        $segments = max(12, (int) ceil($this->svgDistance($start, $control1) + $this->svgDistance($control1, $control2) + $this->svgDistance($control2, $end)) / 6);
        $points = [];

        for ($step = 0; $step <= $segments; $step++) {
            $t = $step / $segments;
            $mt = 1 - $t;
            $points[] = [
                'x' => ($mt ** 3) * $start['x']
                    + 3 * ($mt ** 2) * $t * $control1['x']
                    + 3 * $mt * ($t ** 2) * $control2['x']
                    + ($t ** 3) * $end['x'],
                'y' => ($mt ** 3) * $start['y']
                    + 3 * ($mt ** 2) * $t * $control1['y']
                    + 3 * $mt * ($t ** 2) * $control2['y']
                    + ($t ** 3) * $end['y'],
            ];
        }

        return $points;
    }

    /**
     * @param array{x: float, y: float} $start
     * @param array{x: float, y: float} $control
     * @param array{x: float, y: float} $end
     * @return list<array{x: float, y: float}>
     */
    private function sampleSvgQuadratic(array $start, array $control, array $end): array
    {
        $segments = max(12, (int) ceil($this->svgDistance($start, $control) + $this->svgDistance($control, $end)) / 6);
        $points = [];

        for ($step = 0; $step <= $segments; $step++) {
            $t = $step / $segments;
            $mt = 1 - $t;
            $points[] = [
                'x' => ($mt ** 2) * $start['x'] + 2 * $mt * $t * $control['x'] + ($t ** 2) * $end['x'],
                'y' => ($mt ** 2) * $start['y'] + 2 * $mt * $t * $control['y'] + ($t ** 2) * $end['y'],
            ];
        }

        return $points;
    }

    /**
     * @param array{x: float, y: float} $a
     * @param array{x: float, y: float} $b
     */
    private function svgDistance(array $a, array $b): float
    {
        return hypot($b['x'] - $a['x'], $b['y'] - $a['y']);
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $viewBox
     * @return array{0: int, 1: int}
     */
    private function svgPointToPixel(float $x, float $y, array $viewBox, int $pixelWidth, int $pixelHeight): array
    {
        $scaledX = (($x - $viewBox['x']) / max(0.00001, $viewBox['width'])) * $pixelWidth;
        $scaledY = (($y - $viewBox['y']) / max(0.00001, $viewBox['height'])) * $pixelHeight;

        return [(int) round($scaledX), (int) round($scaledY)];
    }

    private function svgLength(string $value): ?float
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^([-+]?(?:\d*\.\d+|\d+)(?:[eE][-+]?\d+)?)/', $trimmed, $matches) !== 1) {
            return null;
        }

        return (float) $matches[1];
    }

    /**
     * @return array{r: int, g: int, b: int}|null
     */
    private function svgColor(string $value): ?array
    {
        $trimmed = strtolower(trim($value));

        if ($trimmed === '' || $trimmed === 'none') {
            return null;
        }

        if (preg_match('/^#([0-9a-f]{3})$/i', $trimmed, $matches) === 1) {
            return [
                'r' => hexdec(str_repeat($matches[1][0], 2)),
                'g' => hexdec(str_repeat($matches[1][1], 2)),
                'b' => hexdec(str_repeat($matches[1][2], 2)),
            ];
        }

        if (preg_match('/^#([0-9a-f]{6})$/i', $trimmed, $matches) === 1) {
            return [
                'r' => hexdec(substr($matches[1], 0, 2)),
                'g' => hexdec(substr($matches[1], 2, 2)),
                'b' => hexdec(substr($matches[1], 4, 2)),
            ];
        }

        if (preg_match('/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/', $trimmed, $matches) === 1) {
            return [
                'r' => max(0, min(255, (int) $matches[1])),
                'g' => max(0, min(255, (int) $matches[2])),
                'b' => max(0, min(255, (int) $matches[3])),
            ];
        }

        return match ($trimmed) {
            'black' => ['r' => 0, 'g' => 0, 'b' => 0],
            'white' => ['r' => 255, 'g' => 255, 'b' => 255],
            'red' => ['r' => 255, 'g' => 0, 'b' => 0],
            'green' => ['r' => 0, 'g' => 128, 'b' => 0],
            'blue' => ['r' => 0, 'g' => 0, 'b' => 255],
            default => null,
        };
    }

    private function findExecutable(string $name): ?string
    {
        $result = shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null');

        if (!is_string($result)) {
            return null;
        }

        $path = trim($result);

        return $path === '' ? null : $path;
    }

    private function key(string $path): string
    {
        return sha1($path);
    }

    private function maxDecodedImageBytes(): int
    {
        $configured = $this->envValue('PDFTOOLKIT_MAX_DECODED_IMAGE_BYTES', 'PDFTOOLBOX_MAX_DECODED_IMAGE_BYTES');

        if (!is_string($configured) || $configured === '') {
            return self::DEFAULT_MAX_DECODED_IMAGE_BYTES;
        }

        $value = (int) $configured;

        return $value > 0 ? $value : self::DEFAULT_MAX_DECODED_IMAGE_BYTES;
    }

    private function maxRasterPixels(): int
    {
        $configured = $this->envValue('PDFTOOLKIT_MAX_RASTER_PIXELS', 'PDFTOOLBOX_MAX_RASTER_PIXELS');

        if (!is_string($configured) || $configured === '') {
            return self::DEFAULT_MAX_RASTER_PIXELS;
        }

        $value = (int) $configured;

        return $value > 0 ? $value : self::DEFAULT_MAX_RASTER_PIXELS;
    }

    private function assertRasterDimensions(string $path, int $width, int $height): void
    {
        if ($width <= 0 || $height <= 0) {
            throw new PdfException(sprintf('Invalid raster image dimensions for %s.', $path));
        }

        if (($width * $height) > $this->maxRasterPixels()) {
            throw new PdfException(sprintf(
                'Raster image exceeds the configured safety limit of %d pixels: %s',
                $this->maxRasterPixels(),
                $path
            ));
        }
    }

    private function envValue(string $name, ?string $legacyName = null): string|false
    {
        $value = getenv($name);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        if ($legacyName !== null) {
            return getenv($legacyName);
        }

        return $value;
    }

    private function svgMagickEnabled(): bool
    {
        return $this->envValue(self::SVG_MAGICK_ENV, self::LEGACY_SVG_MAGICK_ENV) === '1';
    }

    private function inflateImageData(string $data): string|false
    {
        set_error_handler(static fn (): bool => true);

        try {
            return gzuncompress($data, $this->maxDecodedImageBytes() + 1);
        } finally {
            restore_error_handler();
        }
    }
}
