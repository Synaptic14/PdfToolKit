<?php

declare(strict_types=1);

namespace PdfToolkit\Parser;

final class PdfValueSerializer
{
    public function serialize(mixed $value): string
    {
        if ($value instanceof PdfReference) {
            return sprintf('%d %d R', $value->objectNumber, $value->generationNumber);
        }

        if ($value instanceof PdfStream) {
            $dictionary = $value->dictionary;
            unset($dictionary['__warnings']);
            $dictionary['Length'] = strlen($value->contents);

            return $this->serialize($dictionary) . "\nstream\n" . $value->contents . "\nendstream";
        }

        if ($value instanceof PdfLiteralString) {
            return '(' . $this->escapeLiteralString($value->value) . ')';
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return '[' . implode(' ', array_map($this->serialize(...), $value)) . ']';
            }

            $parts = [];

            foreach ($value as $key => $item) {
                if (is_string($key) && str_starts_with($key, '__')) {
                    continue;
                }

                $parts[] = '/' . $key . ' ' . $this->serialize($item);
            }

            return '<< ' . implode(' ', $parts) . ' >>';
        }

        if (is_string($value)) {
            return '/' . $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        return is_float($value)
            ? rtrim(rtrim(sprintf('%.6F', $value), '0'), '.')
            : (string) $value;
    }

    private function escapeLiteralString(string $value): string
    {
        $escaped = '';
        $length = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            $char = $value[$index];
            $byte = ord($char);

            if ($char === '\\' || $char === '(' || $char === ')') {
                $escaped .= '\\' . $char;
                continue;
            }

            if ($byte < 32 || $byte > 126) {
                $escaped .= sprintf('\\%03o', $byte);
                continue;
            }

            $escaped .= $char;
        }

        return $escaped;
    }
}
