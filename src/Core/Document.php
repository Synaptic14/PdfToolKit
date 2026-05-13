<?php

declare(strict_types=1);

namespace PdfToolkit\Core;

use Closure;
use PdfToolkit\Navigation\NamedDestination;
use PdfToolkit\Navigation\DocumentView;
use PdfToolkit\Navigation\MarkInfo;
use PdfToolkit\Navigation\OpenAction;
use PdfToolkit\Navigation\PageLabelRange;
use PdfToolkit\Navigation\ViewerPreferences;
use PdfToolkit\Outline\OutlineItem;
use PdfToolkit\Graphics\Color;
use PdfToolkit\Graphics\Line;
use PdfToolkit\Graphics\Rectangle;
use PdfToolkit\Text\TextRun;
use PdfToolkit\Writer\PdfWriter;
use PdfToolkit\Writer\WriteOptions;

final class Document
{
    /**
     * @var list<Page>
     */
    private array $pages = [];

    /**
     * @var list<OutlineItem>
     */
    private array $outlineItems = [];

    /**
     * @var list<NamedDestination>
     */
    private array $namedDestinations = [];

    /**
     * @var list<PageLabelRange>
     */
    private array $pageLabelRanges = [];

    private ?ViewerPreferences $viewerPreferences = null;

    private ?OpenAction $openAction = null;

    private ?MarkInfo $markInfo = null;

    private ?string $pageLayout = null;

    private ?string $pageMode = null;

    private ?string $language = null;

    private ?string $uriBase = null;

    private DocumentMetadata $metadata;

    private bool $generateCatalogMetadata = false;

    private ?ImportedAcroFormSource $importedAcroFormSource = null;

    private ?ImportedOutlineSource $importedOutlineSource = null;

    private ?ImportedNameTreeSource $importedNameTreeSource = null;

    private ?ImportedCatalogMetadataSource $importedCatalogMetadataSource = null;

    private ?ImportedPageLabelsSource $importedPageLabelsSource = null;

    private ?ImportedViewerPreferencesSource $importedViewerPreferencesSource = null;

    private ?ImportedOutputIntentsSource $importedOutputIntentsSource = null;

    private ?ImportedStructTreeSource $importedStructTreeSource = null;

    private ?Closure $pageHeaderRenderer = null;

    private ?Closure $pageFooterRenderer = null;

    public function __construct(?DocumentMetadata $metadata = null)
    {
        $this->metadata = $metadata ?? new DocumentMetadata();
    }

    public function __clone()
    {
        foreach ($this->pages as $index => $page) {
            $this->pages[$index] = clone $page;
        }
    }

    public function metadata(): DocumentMetadata
    {
        return $this->metadata;
    }

    public function setMetadata(DocumentMetadata $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function setGenerateCatalogMetadata(bool $generateCatalogMetadata = true): self
    {
        $this->generateCatalogMetadata = $generateCatalogMetadata;

        return $this;
    }

    public function generateCatalogMetadata(): bool
    {
        return $this->generateCatalogMetadata;
    }

    public function addPage(?Page $page = null): self
    {
        $this->pages[] = $page ?? Page::a4();

        return $this;
    }

    public function setPageHeaderRenderer(?callable $renderer): self
    {
        $this->pageHeaderRenderer = $renderer === null ? null : Closure::fromCallable($renderer);

        return $this;
    }

    public function pageHeaderRenderer(): ?Closure
    {
        return $this->pageHeaderRenderer;
    }

    public function setPageFooterRenderer(?callable $renderer): self
    {
        $this->pageFooterRenderer = $renderer === null ? null : Closure::fromCallable($renderer);

        return $this;
    }

    public function pageFooterRenderer(): ?Closure
    {
        return $this->pageFooterRenderer;
    }

    public function addNamedDestination(
        string $name,
        int $pageNumber,
        ?float $left = null,
        ?float $top = null,
        ?float $zoom = null,
    ): self {
        if ($pageNumber < 1) {
            throw new PdfException('Named destination page numbers start at 1.');
        }

        $this->namedDestinations[] = new NamedDestination($name, $pageNumber, $left, $top, $zoom);

        return $this;
    }

    /**
     * @return list<NamedDestination>
     */
    public function namedDestinations(): array
    {
        return $this->namedDestinations;
    }

    public function addPageLabel(
        int $startPage,
        ?string $style = PageLabelRange::DECIMAL,
        ?string $prefix = null,
        int $startNumber = 1,
    ): self {
        if ($startPage < 1) {
            throw new PdfException('Page label ranges start at page 1.');
        }

        if ($startNumber < 1) {
            throw new PdfException('Page label start numbers must be 1 or greater.');
        }

        if ($style !== null && !in_array($style, [
            PageLabelRange::DECIMAL,
            PageLabelRange::ROMAN_UPPER,
            PageLabelRange::ROMAN_LOWER,
            PageLabelRange::LETTERS_UPPER,
            PageLabelRange::LETTERS_LOWER,
        ], true)) {
            throw new PdfException(sprintf('Unsupported page label style: %s', $style));
        }

        $this->pageLabelRanges[] = new PageLabelRange($startPage, $style, $prefix, $startNumber);

        return $this;
    }

    /**
     * @return list<PageLabelRange>
     */
    public function pageLabelRanges(): array
    {
        return $this->pageLabelRanges;
    }

    public function setViewerPreferences(?ViewerPreferences $viewerPreferences): self
    {
        if ($viewerPreferences?->printScaling !== null && !in_array($viewerPreferences->printScaling, [
            ViewerPreferences::PRINT_SCALING_APP_DEFAULT,
            ViewerPreferences::PRINT_SCALING_NONE,
        ], true)) {
            throw new PdfException(sprintf('Unsupported print scaling preference: %s', $viewerPreferences->printScaling));
        }

        $this->viewerPreferences = $viewerPreferences;

        return $this;
    }

    public function viewerPreferences(): ?ViewerPreferences
    {
        return $this->viewerPreferences;
    }

    public function setPageLayout(?string $pageLayout): self
    {
        if ($pageLayout !== null && !in_array($pageLayout, [
            DocumentView::PAGE_LAYOUT_SINGLE_PAGE,
            DocumentView::PAGE_LAYOUT_ONE_COLUMN,
            DocumentView::PAGE_LAYOUT_TWO_COLUMN_LEFT,
            DocumentView::PAGE_LAYOUT_TWO_COLUMN_RIGHT,
            DocumentView::PAGE_LAYOUT_TWO_PAGE_LEFT,
            DocumentView::PAGE_LAYOUT_TWO_PAGE_RIGHT,
        ], true)) {
            throw new PdfException(sprintf('Unsupported page layout: %s', $pageLayout));
        }

        $this->pageLayout = $pageLayout;

        return $this;
    }

    public function pageLayout(): ?string
    {
        return $this->pageLayout;
    }

    public function setPageMode(?string $pageMode): self
    {
        if ($pageMode !== null && !in_array($pageMode, [
            DocumentView::PAGE_MODE_USE_NONE,
            DocumentView::PAGE_MODE_USE_OUTLINES,
            DocumentView::PAGE_MODE_USE_THUMBS,
            DocumentView::PAGE_MODE_FULL_SCREEN,
            DocumentView::PAGE_MODE_USE_OC,
            DocumentView::PAGE_MODE_USE_ATTACHMENTS,
        ], true)) {
            throw new PdfException(sprintf('Unsupported page mode: %s', $pageMode));
        }

        $this->pageMode = $pageMode;

        return $this;
    }

    public function pageMode(): ?string
    {
        return $this->pageMode;
    }

    public function setLanguage(?string $language): self
    {
        if ($language !== null && trim($language) === '') {
            throw new PdfException('Document language cannot be empty.');
        }

        $this->language = $language === null ? null : trim($language);

        return $this;
    }

    public function language(): ?string
    {
        return $this->language;
    }

    public function setUriBase(?string $uriBase): self
    {
        if ($uriBase !== null && trim($uriBase) === '') {
            throw new PdfException('URI base cannot be empty.');
        }

        $this->uriBase = $uriBase === null ? null : trim($uriBase);

        return $this;
    }

    public function uriBase(): ?string
    {
        return $this->uriBase;
    }

    public function setOpenAction(?OpenAction $openAction): self
    {
        if ($openAction?->pageNumber !== null && $openAction->pageNumber < 1) {
            throw new PdfException('Open action page numbers start at 1.');
        }

        $this->openAction = $openAction;

        return $this;
    }

    public function openAction(): ?OpenAction
    {
        return $this->openAction;
    }

    public function setMarkInfo(?MarkInfo $markInfo): self
    {
        $this->markInfo = $markInfo;

        return $this;
    }

    public function markInfo(): ?MarkInfo
    {
        return $this->markInfo;
    }

    public function addOutline(
        string $title,
        int $pageNumber,
        int $level = 0,
        ?float $left = null,
        ?float $top = null,
        ?float $zoom = null,
    ): self
    {
        if ($pageNumber < 1) {
            throw new PdfException('Outline page numbers start at 1.');
        }

        if ($level < 0) {
            throw new PdfException('Outline levels must be zero or greater.');
        }

        $this->outlineItems[] = new OutlineItem($title, $pageNumber, $level, $left, $top, $zoom);

        return $this;
    }

    /**
     * @return list<OutlineItem>
     */
    public function outlineItems(): array
    {
        return $this->outlineItems;
    }

    public function appendDocument(self $document): self
    {
        $pageOffset = count($this->pages);

        foreach ($document->pages() as $page) {
            $this->addPage(clone $page);
        }

        foreach ($document->outlineItems() as $outlineItem) {
            $this->addOutline(
                $outlineItem->title,
                $outlineItem->pageNumber + $pageOffset,
                $outlineItem->level,
                $outlineItem->left,
                $outlineItem->top,
                $outlineItem->zoom
            );
        }

        foreach ($document->namedDestinations() as $destination) {
            $this->addNamedDestination(
                $destination->name,
                $destination->pageNumber + $pageOffset,
                $destination->left,
                $destination->top,
                $destination->zoom
            );
        }

        foreach ($document->pageLabelRanges() as $range) {
            $this->addPageLabel(
                $range->startPage + $pageOffset,
                $range->style,
                $range->prefix,
                $range->startNumber
            );
        }

        if ($document->openAction()?->pageNumber !== null) {
            $this->setOpenAction(OpenAction::toPage(
                $document->openAction()->pageNumber + $pageOffset,
                $document->openAction()->left,
                $document->openAction()->top,
                $document->openAction()->zoom
            ));
        } elseif ($document->openAction()?->destinationName !== null) {
            $this->setOpenAction(OpenAction::toNamedDestination($document->openAction()->destinationName));
        }

        return $this;
    }

    public function extractPages(int $startPage, ?int $endPage = null): self
    {
        $endPage ??= $startPage;
        $this->assertValidPageRange($startPage, $endPage);

        $document = new self($this->metadata);

        for ($page = $startPage; $page <= $endPage; $page++) {
            $document->addPage(clone $this->page($page - 1));
        }

        $outlineItems = array_values(array_filter(
            $this->outlineItems,
            static fn (OutlineItem $outlineItem): bool => $outlineItem->pageNumber >= $startPage && $outlineItem->pageNumber <= $endPage
        ));
        $levelOffset = $outlineItems === [] ? 0 : min(array_map(
            static fn (OutlineItem $outlineItem): int => $outlineItem->level,
            $outlineItems
        ));

        foreach ($outlineItems as $outlineItem) {
            if ($outlineItem->pageNumber < $startPage || $outlineItem->pageNumber > $endPage) {
                continue;
            }

            $document->addOutline(
                $outlineItem->title,
                $outlineItem->pageNumber - $startPage + 1,
                max(0, $outlineItem->level - $levelOffset),
                $outlineItem->left,
                $outlineItem->top,
                $outlineItem->zoom
            );
        }

        foreach ($this->namedDestinations as $destination) {
            if ($destination->pageNumber < $startPage || $destination->pageNumber > $endPage) {
                continue;
            }

            $document->addNamedDestination(
                $destination->name,
                $destination->pageNumber - $startPage + 1,
                $destination->left,
                $destination->top,
                $destination->zoom
            );
        }

        foreach ($this->pageLabelRanges as $range) {
            if ($range->startPage < $startPage || $range->startPage > $endPage) {
                continue;
            }

            $document->addPageLabel(
                $range->startPage - $startPage + 1,
                $range->style,
                $range->prefix,
                $range->startNumber
            );
        }

        if ($this->openAction?->pageNumber !== null && $this->openAction->pageNumber >= $startPage && $this->openAction->pageNumber <= $endPage) {
            $document->setOpenAction(OpenAction::toPage(
                $this->openAction->pageNumber - $startPage + 1,
                $this->openAction->left,
                $this->openAction->top,
                $this->openAction->zoom
            ));
        } elseif ($this->openAction?->destinationName !== null) {
            $document->setOpenAction(OpenAction::toNamedDestination($this->openAction->destinationName));
        }

        return $document;
    }

    /**
     * @return list<Document>
     */
    public function split(): array
    {
        $documents = [];

        for ($page = 1; $page <= count($this->pages); $page++) {
            $documents[] = $this->extractPages($page);
        }

        return $documents;
    }

    public function flattenGeneratedFormFields(): self
    {
        foreach ($this->pages as $page) {
            foreach ($page->formFields() as $field) {
                $type = strtolower($field->type);

                if (in_array($type, ['text', 'tx', 'textfield'], true)) {
                    $value = isset($field->options['value']) ? (string) $field->options['value'] : '';

                    if ($value !== '') {
                        $page->addText(new TextRun($value, $field->x + 2.0, $field->y + max(10.0, $field->height / 2.0), 12.0));
                    }
                } elseif (in_array($type, ['checkbox', 'check', 'btn'], true)) {
                    $page->addRectangle(new Rectangle($field->x, $field->y, $field->width, $field->height, strokeColor: Color::black()));

                    if ((bool) ($field->options['checked'] ?? false)) {
                        $page->addLine(new Line($field->x + 2.0, $field->y + ($field->height / 2.0), $field->x + ($field->width / 2.0), $field->y + 2.0, 1.0, Color::black()));
                        $page->addLine(new Line($field->x + ($field->width / 2.0), $field->y + 2.0, $field->x + $field->width - 2.0, $field->y + $field->height - 2.0, 1.0, Color::black()));
                    }
                }
            }

            $page->clearFormFields();
        }

        return $this;
    }

    /**
     * @return list<Page>
     */
    public function pages(): array
    {
        return $this->pages;
    }

    public function page(int $index): Page
    {
        if (!isset($this->pages[$index])) {
            throw new PdfException(sprintf('Page index %d does not exist.', $index));
        }

        return $this->pages[$index];
    }

    public function importedAcroFormSource(): ?ImportedAcroFormSource
    {
        return $this->importedAcroFormSource;
    }

    public function setImportedAcroFormSource(?ImportedAcroFormSource $importedAcroFormSource): self
    {
        $this->importedAcroFormSource = $importedAcroFormSource;

        return $this;
    }

    public function importedOutlineSource(): ?ImportedOutlineSource
    {
        return $this->importedOutlineSource;
    }

    public function setImportedOutlineSource(?ImportedOutlineSource $importedOutlineSource): self
    {
        $this->importedOutlineSource = $importedOutlineSource;

        return $this;
    }

    public function importedNameTreeSource(): ?ImportedNameTreeSource
    {
        return $this->importedNameTreeSource;
    }

    public function setImportedNameTreeSource(?ImportedNameTreeSource $importedNameTreeSource): self
    {
        $this->importedNameTreeSource = $importedNameTreeSource;

        return $this;
    }

    public function importedCatalogMetadataSource(): ?ImportedCatalogMetadataSource
    {
        return $this->importedCatalogMetadataSource;
    }

    public function setImportedCatalogMetadataSource(?ImportedCatalogMetadataSource $importedCatalogMetadataSource): self
    {
        $this->importedCatalogMetadataSource = $importedCatalogMetadataSource;

        return $this;
    }

    public function importedPageLabelsSource(): ?ImportedPageLabelsSource
    {
        return $this->importedPageLabelsSource;
    }

    public function setImportedPageLabelsSource(?ImportedPageLabelsSource $importedPageLabelsSource): self
    {
        $this->importedPageLabelsSource = $importedPageLabelsSource;

        return $this;
    }

    public function importedViewerPreferencesSource(): ?ImportedViewerPreferencesSource
    {
        return $this->importedViewerPreferencesSource;
    }

    public function setImportedViewerPreferencesSource(?ImportedViewerPreferencesSource $importedViewerPreferencesSource): self
    {
        $this->importedViewerPreferencesSource = $importedViewerPreferencesSource;

        return $this;
    }

    public function importedOutputIntentsSource(): ?ImportedOutputIntentsSource
    {
        return $this->importedOutputIntentsSource;
    }

    public function setImportedOutputIntentsSource(?ImportedOutputIntentsSource $importedOutputIntentsSource): self
    {
        $this->importedOutputIntentsSource = $importedOutputIntentsSource;

        return $this;
    }

    public function importedStructTreeSource(): ?ImportedStructTreeSource
    {
        return $this->importedStructTreeSource;
    }

    public function setImportedStructTreeSource(?ImportedStructTreeSource $importedStructTreeSource): self
    {
        $this->importedStructTreeSource = $importedStructTreeSource;

        return $this;
    }

    public function save(?string $path = null, ?WriteOptions $options = null): string
    {
        $bytes = (new PdfWriter())->write($this, $options);

        if ($path !== null) {
            file_put_contents($path, $bytes);
        }

        return $bytes;
    }

    private function assertValidPageRange(int $startPage, int $endPage): void
    {
        if ($startPage < 1) {
            throw new PdfException('Page numbers start at 1.');
        }

        if ($endPage < $startPage) {
            throw new PdfException('The end page must be greater than or equal to the start page.');
        }

        if ($endPage > count($this->pages)) {
            throw new PdfException(sprintf('Page %d does not exist.', $endPage));
        }
    }
}
