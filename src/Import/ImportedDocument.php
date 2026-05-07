<?php

declare(strict_types=1);

namespace PdfToolkit\Import;

use PdfToolkit\Core\Document;
use PdfToolkit\Core\PdfException;
use PdfToolkit\Text\TextRun;
use PdfToolkit\Writer\WriteOptions;

final class ImportedDocument
{
    public function __construct(
        private Document $document,
        private ImportReport $report,
    ) {
    }

    public function document(): Document
    {
        return $this->document;
    }

    public function report(): ImportReport
    {
        return $this->report;
    }

    public function pages(): ImportedPageCollection
    {
        return new ImportedPageCollection($this->document, $this);
    }

    public function form(): ImportedAcroFormEditor
    {
        return new ImportedAcroFormEditor($this->document, $this);
    }

    public function save(?string $path = null, ?WriteOptions $options = null): string
    {
        return $this->document->save($path, $options);
    }
}
