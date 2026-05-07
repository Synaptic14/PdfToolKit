<?php

declare(strict_types=1);

namespace PdfToolkit\Parser;

use PdfToolkit\Navigation\OpenAction;
use PdfToolkit\Navigation\MarkInfo;

final readonly class ParsedPdfDocument
{
    /**
     * @param list<ParsedPage> $pages
     * @param list<string> $warnings
     */
    public function __construct(
        private string $version,
        private array $pages,
        private array $trailer = [],
        private ?ParsedAcroForm $acroForm = null,
        private ?ParsedOutline $outline = null,
        private ?ParsedNameTree $nameTree = null,
        private ?ParsedMetadata $metadata = null,
        private ?ParsedCatalogMetadata $catalogMetadata = null,
        private ?ParsedPageLabels $pageLabels = null,
        private ?ParsedViewerPreferences $viewerPreferences = null,
        private ?ParsedOutputIntents $outputIntents = null,
        private ?ParsedStructTree $structTree = null,
        private ?ParsedEncryption $encryption = null,
        private ?OpenAction $openAction = null,
        private ?MarkInfo $markInfo = null,
        private ?string $pageLayout = null,
        private ?string $pageMode = null,
        private ?string $language = null,
        private ?string $uriBase = null,
        private array $warnings = [],
    ) {
    }

    public function version(): string
    {
        return $this->version;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /**
     * @return list<ParsedPage>
     */
    public function pages(): array
    {
        return $this->pages;
    }

    /**
     * @return array<string, mixed>
     */
    public function trailer(): array
    {
        return $this->trailer;
    }

    public function acroForm(): ?ParsedAcroForm
    {
        return $this->acroForm;
    }

    public function outline(): ?ParsedOutline
    {
        return $this->outline;
    }

    public function nameTree(): ?ParsedNameTree
    {
        return $this->nameTree;
    }

    public function metadata(): ?ParsedMetadata
    {
        return $this->metadata;
    }

    public function catalogMetadata(): ?ParsedCatalogMetadata
    {
        return $this->catalogMetadata;
    }

    public function pageLabels(): ?ParsedPageLabels
    {
        return $this->pageLabels;
    }

    public function viewerPreferences(): ?ParsedViewerPreferences
    {
        return $this->viewerPreferences;
    }

    public function outputIntents(): ?ParsedOutputIntents
    {
        return $this->outputIntents;
    }

    public function structTree(): ?ParsedStructTree
    {
        return $this->structTree;
    }

    public function encryption(): ?ParsedEncryption
    {
        return $this->encryption;
    }

    public function openAction(): ?OpenAction
    {
        return $this->openAction;
    }

    public function markInfo(): ?MarkInfo
    {
        return $this->markInfo;
    }

    public function pageLayout(): ?string
    {
        return $this->pageLayout;
    }

    public function pageMode(): ?string
    {
        return $this->pageMode;
    }

    public function language(): ?string
    {
        return $this->language;
    }

    public function uriBase(): ?string
    {
        return $this->uriBase;
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
