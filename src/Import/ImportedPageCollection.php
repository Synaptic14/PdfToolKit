<?php

declare(strict_types=1);

namespace PdfToolkit\Import;

use PdfToolkit\Core\Document;
use PdfToolkit\Core\PdfException;

final readonly class ImportedPageCollection
{
    public function __construct(
        private Document $document,
        private ImportedDocument $importedDocument,
    ) {
    }

    public function page(int $number): ImportedPageEditor
    {
        $index = $number - 1;

        if ($index < 0) {
            throw new PdfException('Page numbers start at 1.');
        }

        return new ImportedPageEditor($this->document->page($index), $this);
    }

    public function document(): Document
    {
        return $this->document;
    }

    public function done(): ImportedDocument
    {
        return $this->importedDocument;
    }
}
