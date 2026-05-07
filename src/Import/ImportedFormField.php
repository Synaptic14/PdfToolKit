<?php

declare(strict_types=1);

namespace PdfToolkit\Import;

final readonly class ImportedFormField
{
    /**
     * @param list<float> $rect
     */
    public function __construct(
        public string $name,
        public int $pageNumber,
        public array $rect,
        public string $type,
        public ?string $tooltip = null,
        public int $objectNumber = 0,
    ) {
    }

    public function x(): float
    {
        return $this->rect[0];
    }

    public function bottom(): float
    {
        return $this->rect[1];
    }

    public function right(): float
    {
        return $this->rect[2];
    }

    public function top(): float
    {
        return $this->rect[3];
    }

    public function width(): float
    {
        return $this->rect[2] - $this->rect[0];
    }

    public function height(): float
    {
        return $this->rect[3] - $this->rect[1];
    }
}
