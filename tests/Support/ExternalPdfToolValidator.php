<?php

declare(strict_types=1);

namespace PdfToolkit\Tests\Support;

final class ExternalPdfToolValidator
{
    public function qpdfPath(): ?string
    {
        return $this->findBinary('qpdf');
    }

    public function pdfInfoPath(): ?string
    {
        return $this->findBinary('pdfinfo');
    }

    public function pdfToTextPath(): ?string
    {
        return $this->findBinary('pdftotext');
    }

    public function hasAnyValidator(): bool
    {
        return $this->qpdfPath() !== null
            || $this->pdfInfoPath() !== null
            || $this->pdfToTextPath() !== null;
    }

    /**
     * @return array{
     *     qpdf: array{available: bool, ok: bool|null, output: string|null},
     *     pdfinfo: array{available: bool, ok: bool|null, output: string|null},
     *     pdftotext: array{available: bool, ok: bool|null, output: string|null}
     * }
     */
    public function validate(string $pdfPath): array
    {
        return [
            'qpdf' => $this->runQpdf($pdfPath),
            'pdfinfo' => $this->runPdfInfo($pdfPath),
            'pdftotext' => $this->runPdfToText($pdfPath),
        ];
    }

    /**
     * @return array{available: bool, ok: bool|null, output: string|null}
     */
    private function runQpdf(string $pdfPath): array
    {
        $binary = $this->qpdfPath();

        if ($binary === null) {
            return ['available' => false, 'ok' => null, 'output' => null];
        }

        return $this->runCommand(sprintf(
            '%s --check %s 2>&1',
            escapeshellarg($binary),
            escapeshellarg($pdfPath),
        ));
    }

    /**
     * @return array{available: bool, ok: bool|null, output: string|null}
     */
    private function runPdfInfo(string $pdfPath): array
    {
        $binary = $this->pdfInfoPath();

        if ($binary === null) {
            return ['available' => false, 'ok' => null, 'output' => null];
        }

        return $this->runCommand(sprintf(
            '%s %s 2>&1',
            escapeshellarg($binary),
            escapeshellarg($pdfPath),
        ));
    }

    /**
     * @return array{available: bool, ok: bool|null, output: string|null}
     */
    private function runPdfToText(string $pdfPath): array
    {
        $binary = $this->pdfToTextPath();

        if ($binary === null) {
            return ['available' => false, 'ok' => null, 'output' => null];
        }

        $tempOutput = tempnam(sys_get_temp_dir(), 'pdftoolkit-pdftotext-');

        if ($tempOutput === false) {
            return ['available' => true, 'ok' => false, 'output' => 'Unable to allocate temporary file for pdftotext output.'];
        }

        try {
            $result = $this->runCommand(sprintf(
                '%s %s %s 2>&1',
                escapeshellarg($binary),
                escapeshellarg($pdfPath),
                escapeshellarg($tempOutput),
            ));

            if ($result['ok'] !== true) {
                return $result;
            }

            $text = file_get_contents($tempOutput);

            return [
                'available' => true,
                'ok' => $text !== false,
                'output' => $text === false ? 'Unable to read pdftotext output.' : $text,
            ];
        } finally {
            @unlink($tempOutput);
        }
    }

    /**
     * @return array{available: bool, ok: bool|null, output: string|null}
     */
    private function runCommand(string $command): array
    {
        exec($command, $output, $exitCode);

        return [
            'available' => true,
            'ok' => $exitCode === 0,
            'output' => implode("\n", $output),
        ];
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
