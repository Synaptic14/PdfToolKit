<?php

declare(strict_types=1);

namespace PdfToolkit\Parser;

final readonly class ParsedPage
{
    /**
     * @param array<string, mixed> $dictionary
     * @param array<string, list<float>> $pageBoxes
     * @param array<string, mixed>|null $resources
     * @param list<ParsedContentStream> $contentStreams
     * @param array<int, string> $dependentObjects
     * @param list<string> $warnings
     */
    public function __construct(
        public int $objectNumber,
        public float $width,
        public float $height,
        public int $rotation = 0,
        public array $dictionary = [],
        public array $pageBoxes = [],
        public ?array $resources = null,
        public array $contentStreams = [],
        public array $dependentObjects = [],
        public array $warnings = [],
    ) {
    }
}
