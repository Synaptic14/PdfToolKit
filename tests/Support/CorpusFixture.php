<?php

declare(strict_types=1);

namespace PdfToolkit\Tests\Support;

final readonly class CorpusFixture
{
    /**
     * @param list<string> $workflows
     */
    public function __construct(
        public string $name,
        public string $path,
        public ?int $expectedPageCount = null,
        public ?string $expectedVersion = null,
        public array $workflows = ['load', 'roundtrip', 'overlay'],
    ) {
    }
}
