<?php

declare(strict_types=1);

namespace PdfToolkit\Parser;

use PdfToolkit\Core\PdfException;

final class PdfValueParser
{
    private int $length;

    public function __construct(private readonly string $source)
    {
        $this->length = strlen($source);
    }

    public function parseValue(int &$offset): mixed
    {
        $this->skipWhitespaceAndComments($offset);

        if ($offset >= $this->length) {
            throw new PdfException('Unexpected end of PDF while parsing value.');
        }

        if ($this->startsWith($offset, '<<')) {
            return $this->parseDictionary($offset);
        }

        if ($this->startsWith($offset, '[')) {
            return $this->parseArray($offset);
        }

        $char = $this->source[$offset];

        return match ($char) {
            '/' => $this->parseName($offset),
            '(' => $this->parseLiteralString($offset),
            '<' => $this->parseHexString($offset),
            default => $this->parseKeywordNumberOrReference($offset),
        };
    }

    public function skipWhitespaceAndComments(int &$offset): void
    {
        while ($offset < $this->length) {
            $char = $this->source[$offset];

            if ($this->isWhitespace($char)) {
                $offset++;
                continue;
            }

            if ($char === '%') {
                while ($offset < $this->length && !in_array($this->source[$offset], ["\r", "\n"], true)) {
                    $offset++;
                }

                continue;
            }

            break;
        }
    }

    public function readKeyword(int &$offset): string
    {
        $this->skipWhitespaceAndComments($offset);
        $start = $offset;

        while ($offset < $this->length && !$this->isDelimiter($this->source[$offset])) {
            $offset++;
        }

        if ($start === $offset) {
            throw new PdfException('Expected keyword.');
        }

        return substr($this->source, $start, $offset - $start);
    }

    private function parseDictionary(int &$offset): array
    {
        $offset += 2;
        $dictionary = [];

        while (true) {
            $this->skipWhitespaceAndComments($offset);

            if ($this->startsWith($offset, '>>')) {
                $offset += 2;
                break;
            }

            $key = $this->parseName($offset);
            $dictionary[$key] = $this->parseValue($offset);
        }

        return $dictionary;
    }

    private function parseArray(int &$offset): array
    {
        $offset++;
        $items = [];

        while (true) {
            $this->skipWhitespaceAndComments($offset);

            if ($offset >= $this->length) {
                throw new PdfException('Unterminated PDF array.');
            }

            if ($this->source[$offset] === ']') {
                $offset++;
                break;
            }

            $items[] = $this->parseValue($offset);
        }

        return $items;
    }

    private function parseName(int &$offset): string
    {
        if ($this->source[$offset] !== '/') {
            throw new PdfException('Expected PDF name.');
        }

        $offset++;
        $start = $offset;

        while ($offset < $this->length && !$this->isDelimiter($this->source[$offset])) {
            $offset++;
        }

        $raw = substr($this->source, $start, $offset - $start);

        return preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn (array $matches): string => chr((int) hexdec($matches[1])), $raw) ?? $raw;
    }

    private function parseLiteralString(int &$offset): PdfLiteralString
    {
        $offset++;
        $depth = 1;
        $buffer = '';

        while ($offset < $this->length) {
            $char = $this->source[$offset++];

            if ($char === '\\') {
                if ($offset >= $this->length) {
                    break;
                }

                $escaped = $this->source[$offset++];
                $buffer .= match ($escaped) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'b' => "\x08",
                    'f' => "\x0C",
                    '(', ')', '\\' => $escaped,
                    "\n" => '',
                    "\r" => ($offset < $this->length && $this->source[$offset] === "\n") ? (++$offset && '') : '',
                    default => ctype_digit($escaped) ? $this->parseOctalEscape($escaped, $offset) : $escaped,
                };
                continue;
            }

            if ($char === '(') {
                $depth++;
                $buffer .= $char;
                continue;
            }

            if ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    return new PdfLiteralString($buffer);
                }

                $buffer .= $char;
                continue;
            }

            $buffer .= $char;
        }

        throw new PdfException('Unterminated literal string.');
    }

    private function parseOctalEscape(string $firstDigit, int &$offset): string
    {
        $digits = $firstDigit;

        for ($i = 0; $i < 2 && $offset < $this->length; $i++) {
            $char = $this->source[$offset];

            if ($char < '0' || $char > '7') {
                break;
            }

            $digits .= $char;
            $offset++;
        }

        return chr(octdec($digits));
    }

    private function parseHexString(int &$offset): PdfLiteralString
    {
        $offset++;
        $start = $offset;

        while ($offset < $this->length && $this->source[$offset] !== '>') {
            $offset++;
        }

        if ($offset >= $this->length) {
            throw new PdfException('Unterminated hex string.');
        }

        $raw = preg_replace('/\s+/', '', substr($this->source, $start, $offset - $start)) ?? '';
        $offset++;

        if (strlen($raw) % 2 === 1) {
            $raw .= '0';
        }

        $decoded = hex2bin($raw);

        if ($decoded === false) {
            throw new PdfException('Invalid hex string.');
        }

        return new PdfLiteralString($decoded);
    }

    private function parseKeywordNumberOrReference(int &$offset): mixed
    {
        $first = $this->readToken($offset);

        if ($first === 'true') {
            return true;
        }

        if ($first === 'false') {
            return false;
        }

        if ($first === 'null') {
            return null;
        }

        if ($this->isNumericToken($first)) {
            $checkpoint = $offset;
            $this->skipWhitespaceAndComments($checkpoint);
            $second = $this->readToken($checkpoint, false);

            if ($second !== null && ctype_digit($second)) {
                $afterSecond = $checkpoint;
                $this->skipWhitespaceAndComments($afterSecond);
                $third = $this->readToken($afterSecond, false);

                if ($third === 'R') {
                    $offset = $afterSecond;

                    return new PdfReference((int) $first, (int) $second);
                }
            }

            return str_contains($first, '.') ? (float) $first : (int) $first;
        }

        return $first;
    }

    private function readToken(int &$offset, bool $required = true): ?string
    {
        $this->skipWhitespaceAndComments($offset);

        if ($offset >= $this->length) {
            if ($required) {
                throw new PdfException('Unexpected end of PDF while reading token.');
            }

            return null;
        }

        $start = $offset;

        while ($offset < $this->length && !$this->isDelimiter($this->source[$offset])) {
            $offset++;
        }

        if ($start === $offset) {
            if ($required) {
                throw new PdfException('Expected token.');
            }

            return null;
        }

        return substr($this->source, $start, $offset - $start);
    }

    private function startsWith(int $offset, string $value): bool
    {
        return substr($this->source, $offset, strlen($value)) === $value;
    }

    private function isWhitespace(string $char): bool
    {
        return $char === "\x00" || $char === "\t" || $char === "\n" || $char === "\f" || $char === "\r" || $char === ' ';
    }

    private function isDelimiter(string $char): bool
    {
        return $this->isWhitespace($char) || in_array($char, ['(', ')', '<', '>', '[', ']', '{', '}', '/', '%'], true);
    }

    private function isNumericToken(string $token): bool
    {
        return preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)$/', $token) === 1;
    }
}
