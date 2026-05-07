<?php

declare(strict_types=1);

namespace PdfToolkit\Parser;

final readonly class ParsedMetadata
{
    /**
     * @param list<string> $keywords
     */
    public function __construct(
        public ?string $title = null,
        public ?string $author = null,
        public ?string $subject = null,
        public array $keywords = [],
    ) {
    }
}
