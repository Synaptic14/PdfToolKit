<?php

declare(strict_types=1);

namespace PdfToolkit\Core;

final readonly class ImportedAcroFormSource
{
    /**
     * @param array<int, string> $dependentObjects
     */
    public function __construct(
        public int $objectNumber,
        public array $dependentObjects = [],
    ) {
    }

    /**
     * @param array<int, string> $dependentObjects
     */
    public function withDependentObjects(array $dependentObjects): self
    {
        return new self($this->objectNumber, $dependentObjects);
    }
}
