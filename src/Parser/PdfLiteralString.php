<?php

declare(strict_types=1);

namespace PdfToolkit\Parser;

final readonly class PdfLiteralString
{
    public function __construct(public string $value)
    {
    }
}
