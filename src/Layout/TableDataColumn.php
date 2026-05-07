<?php

declare(strict_types=1);

namespace PdfToolkit\Layout;

use Closure;

final readonly class TableDataColumn
{
    public function __construct(
        public string $header,
        public string $field,
        public TableColumn $column,
        public ?TableCell $headerCell = null,
        public ?Closure $resolver = null,
        public ?Closure $formatter = null,
    ) {
        if ($this->header === '') {
            throw new \InvalidArgumentException('Table data column header must not be empty.');
        }

        if ($this->field === '' && $this->resolver === null) {
            throw new \InvalidArgumentException('Table data column field must not be empty when no resolver is provided.');
        }
    }
}
