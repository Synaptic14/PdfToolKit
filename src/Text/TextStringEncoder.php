<?php

declare(strict_types=1);

namespace PdfToolkit\Text;

use PdfToolkit\Core\PdfException;

final class TextStringEncoder
{
    public function encodeContentString(string $value): string
    {
        if ($this->isAscii($value)) {
            return '(' . $this->escapeLiteralContent($value) . ')';
        }

        return '<' . $this->encodeUtf16Hex($value) . '>';
    }

    public function escapeLiteralContent(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }

    private function isAscii(string $value): bool
    {
        return mb_check_encoding($value, 'ASCII');
    }

    private function encodeUtf16Hex(string $value): string
    {
        $encoded = iconv('UTF-8', 'UTF-16BE', $value);

        if ($encoded === false) {
            throw new PdfException('Unable to encode text string as UTF-16BE.');
        }

        return strtoupper(bin2hex("\xFE\xFF" . $encoded));
    }
}
