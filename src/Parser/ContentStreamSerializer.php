<?php

declare(strict_types=1);

namespace PdfToolkit\Parser;

use PdfToolkit\Core\ImportedContentOperation;
use PdfToolkit\Core\PdfException;
use PdfToolkit\Text\EncodedText;
use PdfToolkit\Text\TextStringEncoder;

final class ContentStreamSerializer
{
    private ?TextStringEncoder $textStringEncoder = null;

    /**
     * @param list<ImportedContentOperation|ParsedContentOperation> $operations
     */
    public function serialize(array $operations): string
    {
        $lines = [];

        foreach ($operations as $operation) {
            $operands = array_map(fn (mixed $operand): string => $this->serializeOperand($operand), $operation->operands);
            $parts = [...$operands, $operation->operator];
            $lines[] = implode(' ', array_filter($parts, static fn (string $part): bool => $part !== ''));
        }

        return implode("\n", $lines);
    }

    private function serializeOperand(mixed $value): string
    {
        if ($value instanceof PdfName) {
            return '/' . $value->value;
        }

        if ($value instanceof EncodedText) {
            return $value->hex
                ? '<' . strtoupper(bin2hex($value->bytes)) . '>'
                : '(' . $this->textStringEncoder()->escapeLiteralContent($value->bytes) . ')';
        }

        if (is_string($value)) {
            return $this->textStringEncoder()->encodeContentString($value);
        }

        if (is_int($value) || is_float($value)) {
            return is_float($value)
                ? rtrim(rtrim(sprintf('%.6F', $value), '0'), '.')
                : (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return '[' . implode(' ', array_map(fn (mixed $item): string => $this->serializeOperand($item), $value)) . ']';
            }

            $parts = [];

            foreach ($value as $key => $item) {
                $parts[] = '/' . $key . ' ' . $this->serializeOperand($item);
            }

            return '<< ' . implode(' ', $parts) . ' >>';
        }

        throw new PdfException('Unsupported content-stream operand type encountered during serialization.');
    }

    private function textStringEncoder(): TextStringEncoder
    {
        return $this->textStringEncoder ??= new TextStringEncoder();
    }
}
