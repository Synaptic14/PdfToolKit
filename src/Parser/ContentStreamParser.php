<?php

declare(strict_types=1);

namespace PdfToolkit\Parser;

use PdfToolkit\Core\PdfException;

final class ContentStreamParser
{
    private int $length;

    public function __construct(private readonly string $source)
    {
        $this->length = strlen($source);
    }

    /**
     * @param list<string> $warnings
     * @return list<ParsedContentOperation>
     */
    public function parse(array &$warnings = []): array
    {
        $offset = 0;
        $operands = [];
        $operations = [];

        while (true) {
            $this->skipWhitespaceAndComments($offset);

            if ($offset >= $this->length) {
                break;
            }

            $token = $this->parseToken($offset, $warnings);

            if ($token['type'] === 'operand') {
                $operands[] = $token['value'];
                continue;
            }

            $operations[] = new ParsedContentOperation(
                operator: $token['value'],
                operands: $operands,
            );
            $operands = [];
        }

        if ($operands !== []) {
            $warnings[] = 'Trailing content-stream operands were encountered without a terminating operator.';
        }

        return $operations;
    }

    /**
     * @param list<string> $warnings
     * @return array{type: 'operand'|'operator', value: mixed}
     */
    private function parseToken(int &$offset, array &$warnings): array
    {
        if ($this->startsWith($offset, '<<') || $this->isOperandLeadingCharacter($this->source[$offset])) {
            return ['type' => 'operand', 'value' => $this->parseOperandValue($offset, $warnings)];
        }

        $token = $this->readToken($offset);

        if ($token === 'true') {
            return ['type' => 'operand', 'value' => true];
        }

        if ($token === 'false') {
            return ['type' => 'operand', 'value' => false];
        }

        if ($token === 'null') {
            return ['type' => 'operand', 'value' => null];
        }

        if ($this->isNumericToken($token)) {
            return ['type' => 'operand', 'value' => str_contains($token, '.') ? (float) $token : (int) $token];
        }

        if ($token === 'BI') {
            $warnings[] = 'Inline image data is not parsed yet; BI/ID/EI content is preserved only as raw bytes.';
        }

        return ['type' => 'operator', 'value' => $token];
    }

    /**
     * @param list<string> $warnings
     * @return array<string, mixed>
     */
    private function parseDictionary(int &$offset, array &$warnings): array
    {
        $offset += 2;
        $dictionary = [];

        while (true) {
            $this->skipWhitespaceAndComments($offset);

            if ($offset >= $this->length) {
                throw new PdfException('Unterminated content-stream dictionary.');
            }

            if ($this->startsWith($offset, '>>')) {
                $offset += 2;

                return $dictionary;
            }

            $key = $this->parseName($offset);
            $dictionary[$key->value] = $this->parseOperandValue($offset, $warnings);
        }
    }

    /**
     * @param list<string> $warnings
     * @return list<mixed>
     */
    private function parseArray(int &$offset, array &$warnings): array
    {
        $offset++;
        $items = [];

        while (true) {
            $this->skipWhitespaceAndComments($offset);

            if ($offset >= $this->length) {
                throw new PdfException('Unterminated content-stream array.');
            }

            if ($this->source[$offset] === ']') {
                $offset++;

                return $items;
            }

            $items[] = $this->parseOperandValue($offset, $warnings);
        }
    }

    /**
     * @param list<string> $warnings
     */
    private function parseOperandValue(int &$offset, array &$warnings): mixed
    {
        $this->skipWhitespaceAndComments($offset);

        if ($offset >= $this->length) {
            throw new PdfException('Unexpected end of content stream while parsing operand.');
        }

        if ($this->startsWith($offset, '<<')) {
            return $this->parseDictionary($offset, $warnings);
        }

        $char = $this->source[$offset];

        if ($char === '[') {
            return $this->parseArray($offset, $warnings);
        }

        if ($char === '/') {
            return $this->parseName($offset);
        }

        if ($char === '(') {
            return $this->parseLiteralString($offset);
        }

        if ($char === '<') {
            return $this->parseHexString($offset);
        }

        $token = $this->readToken($offset);

        if ($token === 'true') {
            return true;
        }

        if ($token === 'false') {
            return false;
        }

        if ($token === 'null') {
            return null;
        }

        if ($this->isNumericToken($token)) {
            return str_contains($token, '.') ? (float) $token : (int) $token;
        }

        throw new PdfException(sprintf('Unexpected operator token "%s" where an operand was required.', $token));
    }

    private function isOperandLeadingCharacter(string $char): bool
    {
        return $char === '[' || $char === '/' || $char === '(' || $char === '<' || $char === '+' || $char === '-' || $char === '.'
            || ($char >= '0' && $char <= '9');
    }

    private function parseName(int &$offset): PdfName
    {
        if ($this->source[$offset] !== '/') {
            throw new PdfException('Expected content-stream name token.');
        }

        $offset++;
        $start = $offset;

        while ($offset < $this->length && !$this->isDelimiter($this->source[$offset])) {
            $offset++;
        }

        $raw = substr($this->source, $start, $offset - $start);

        $value = preg_replace_callback(
            '/#([0-9A-Fa-f]{2})/',
            static fn (array $matches): string => chr((int) hexdec($matches[1])),
            $raw
        ) ?? $raw;

        return new PdfName($value);
    }

    private function parseLiteralString(int &$offset): string
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
                    return $buffer;
                }

                $buffer .= $char;
                continue;
            }

            $buffer .= $char;
        }

        throw new PdfException('Unterminated content-stream literal string.');
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

    private function parseHexString(int &$offset): string
    {
        $offset++;
        $start = $offset;

        while ($offset < $this->length && $this->source[$offset] !== '>') {
            $offset++;
        }

        if ($offset >= $this->length) {
            throw new PdfException('Unterminated content-stream hex string.');
        }

        $raw = preg_replace('/\s+/', '', substr($this->source, $start, $offset - $start)) ?? '';
        $offset++;

        if (strlen($raw) % 2 === 1) {
            $raw .= '0';
        }

        $decoded = hex2bin($raw);

        if ($decoded === false) {
            throw new PdfException('Invalid content-stream hex string.');
        }

        return $decoded;
    }

    private function readToken(int &$offset): string
    {
        $start = $offset;

        while ($offset < $this->length && !$this->isDelimiter($this->source[$offset])) {
            $offset++;
        }

        if ($start === $offset) {
            throw new PdfException('Expected content-stream token.');
        }

        return substr($this->source, $start, $offset - $start);
    }

    private function skipWhitespaceAndComments(int &$offset): void
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
