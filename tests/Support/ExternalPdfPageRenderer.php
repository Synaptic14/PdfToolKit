<?php

declare(strict_types=1);

namespace PdfToolkit\Tests\Support;

final class ExternalPdfPageRenderer
{
    public function pdftoppmPath(): ?string
    {
        return $this->findBinary('pdftoppm');
    }

    public function isAvailable(): bool
    {
        return $this->pdftoppmPath() !== null;
    }

    public function renderFirstPage(string $pdfBytes, int $dpi = 72): RenderedPageImage
    {
        return $this->renderPage($pdfBytes, 1, $dpi);
    }

    public function renderPage(string $pdfBytes, int $pageNumber = 1, int $dpi = 72): RenderedPageImage
    {
        $binary = $this->pdftoppmPath();

        if ($binary === null) {
            throw new \RuntimeException('pdftoppm is not available.');
        }

        $inputPath = tempnam(sys_get_temp_dir(), 'pdftoolkit-render-input-');
        $outputBase = tempnam(sys_get_temp_dir(), 'pdftoolkit-render-output-');

        if ($inputPath === false || $outputBase === false) {
            if ($inputPath !== false) {
                @unlink($inputPath);
            }

            if ($outputBase !== false) {
                @unlink($outputBase);
            }

            throw new \RuntimeException('Unable to allocate temporary files for PDF rendering.');
        }

        @unlink($outputBase);
        $ppmPath = sprintf('%s-%06d.ppm', $outputBase, $pageNumber);

        try {
            if (file_put_contents($inputPath, $pdfBytes) === false) {
                throw new \RuntimeException('Unable to write temporary PDF render input.');
            }

            $command = sprintf(
                '%s -f %d -l %d -r %d %s %s 2>&1',
                escapeshellarg($binary),
                $pageNumber,
                $pageNumber,
                $dpi,
                escapeshellarg($inputPath),
                escapeshellarg($outputBase),
            );

            exec($command, $output, $exitCode);

            if (!is_file($ppmPath)) {
                throw new \RuntimeException(sprintf(
                    'pdftoppm failed to render page %d: %s',
                    $pageNumber,
                    implode("\n", $output)
                ));
            }

            $ppm = file_get_contents($ppmPath);

            if ($ppm === false) {
                throw new \RuntimeException('Unable to read rendered PPM output.');
            }

            return $this->parsePpm($ppm);
        } finally {
            @unlink($inputPath);
            @unlink($ppmPath);
        }
    }

    private function parsePpm(string $ppm): RenderedPageImage
    {
        $offset = 0;
        $magic = $this->readPpmToken($ppm, $offset);

        if ($magic !== 'P6') {
            throw new \RuntimeException(sprintf('Unsupported rendered image format: %s', $magic ?? 'unknown'));
        }

        $widthToken = $this->readPpmToken($ppm, $offset);
        $heightToken = $this->readPpmToken($ppm, $offset);
        $maxValueToken = $this->readPpmToken($ppm, $offset);

        if ($widthToken === null || $heightToken === null || $maxValueToken === null) {
            throw new \RuntimeException('Rendered PPM header is incomplete.');
        }

        $width = (int) $widthToken;
        $height = (int) $heightToken;
        $maxValue = (int) $maxValueToken;

        if ($width <= 0 || $height <= 0 || $maxValue !== 255) {
            throw new \RuntimeException('Rendered PPM header is invalid.');
        }

        while ($offset < strlen($ppm) && preg_match('/\s/', $ppm[$offset]) === 1) {
            $offset++;
        }

        $expectedLength = $width * $height * 3;
        $rgb = substr($ppm, $offset);

        if (strlen($rgb) !== $expectedLength) {
            throw new \RuntimeException('Rendered PPM pixel data length is invalid.');
        }

        return new RenderedPageImage($width, $height, $rgb);
    }

    private function readPpmToken(string $ppm, int &$offset): ?string
    {
        $length = strlen($ppm);

        while ($offset < $length) {
            $character = $ppm[$offset];

            if (preg_match('/\s/', $character) === 1) {
                $offset++;
                continue;
            }

            if ($character === '#') {
                while ($offset < $length && $ppm[$offset] !== "\n") {
                    $offset++;
                }

                continue;
            }

            break;
        }

        if ($offset >= $length) {
            return null;
        }

        $start = $offset;

        while ($offset < $length) {
            $character = $ppm[$offset];

            if (preg_match('/\s/', $character) === 1 || $character === '#') {
                break;
            }

            $offset++;
        }

        return substr($ppm, $start, $offset - $start);
    }

    private function findBinary(string $name): ?string
    {
        $result = shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null');

        if (!is_string($result)) {
            return null;
        }

        $path = trim($result);

        return $path === '' ? null : $path;
    }
}
