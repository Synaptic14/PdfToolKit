<?php

declare(strict_types=1);

namespace PdfToolkit\Import;

final readonly class ImportReport
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public string $version,
        public int $pageCount,
        public array $warnings = [],
        public ?ImportSecurityInfo $security = null,
    ) {
    }
}
