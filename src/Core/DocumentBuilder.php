<?php

declare(strict_types=1);

namespace PdfToolkit\Core;

use PdfToolkit\Annotations\LinkAnnotation;
use PdfToolkit\Annotations\TextAnnotation;
use PdfToolkit\Forms\FormField;
use PdfToolkit\Graphics\Color;
use PdfToolkit\Graphics\Line;
use PdfToolkit\Graphics\Rectangle;
use PdfToolkit\Image\ImagePlacement;
use PdfToolkit\Layout\PageMargins;
use PdfToolkit\Layout\PanelStyle;
use PdfToolkit\Layout\TableCell;
use PdfToolkit\Layout\TableColumn;
use PdfToolkit\Layout\TableDataColumn;
use PdfToolkit\Layout\TableStyle;
use PdfToolkit\Layout\TextBlock;
use PdfToolkit\Layout\TextFrame;
use PdfToolkit\Navigation\DocumentView;
use PdfToolkit\Navigation\MarkInfo;
use PdfToolkit\Navigation\OpenAction;
use PdfToolkit\Navigation\PageLabelRange;
use PdfToolkit\Navigation\ViewerPreferences;
use PdfToolkit\Text\FontReference;
use PdfToolkit\Text\TextMeasurer;
use PdfToolkit\Text\TextRun;

final class DocumentBuilder
{
    private const DEFAULT_FLOW_TOP_MARGIN = 36.0;
    private const DEFAULT_FLOW_BOTTOM_MARGIN = 36.0;

    private Document $document;

    private ?PageBuilder $currentPage = null;

    private ?TextMeasurer $textMeasurer = null;

    /** @var array<string, array{lines: list<string>, offsets: list<float>, height: float}> */
    private array $wrappedTextLayouts = [];

    public function __construct(?Document $document = null)
    {
        $this->document = $document ?? new Document();
    }

    public function metadata(
        ?string $title = null,
        ?string $author = null,
        ?string $subject = null,
        array $keywords = []
    ): self {
        $this->document->setMetadata(new DocumentMetadata(
            title: $title,
            author: $author,
            subject: $subject,
            keywords: $keywords,
        ));

        return $this;
    }

    public function catalogMetadata(bool $enabled = true): self
    {
        $this->document->setGenerateCatalogMetadata($enabled);

        return $this;
    }

    public function addPage(float $width = 595.0, float $height = 842.0, int $rotation = 0): self
    {
        $page = new Page($width, $height);
        $page->setSize($width, $height);
        $page->setRotation($rotation);
        $this->document->addPage($page);
        $this->currentPage = new PageBuilder($page, $this);

        return $this;
    }

    public function pageSize(float $width, float $height): self
    {
        $this->requirePage()->setSize($width, $height);

        return $this;
    }

    public function text(TextRun $textRun): self
    {
        $this->requirePage()->addText($textRun);

        return $this;
    }

    public function flowText(
        string $text,
        float $x,
        float $y,
        float $width,
        float $fontSize = 12.0,
        ?FontReference $font = null,
        ?Color $color = null,
        float $lineHeight = 1.2,
        ?float $topMargin = null,
        ?float $bottomMargin = null,
        float $paragraphSpacing = 0.35,
    ): self {
        if ($width <= 0.0) {
            throw new PdfException('Flow text width must be greater than zero.');
        }

        if ($fontSize <= 0.0) {
            throw new PdfException('Flow text font size must be greater than zero.');
        }

        if ($lineHeight <= 0.0) {
            throw new PdfException('Flow text line height must be greater than zero.');
        }

        if ($paragraphSpacing < 0.0) {
            throw new PdfException('Flow text paragraph spacing must be zero or greater.');
        }

        $page = $this->requirePage();
        $topMargin ??= self::DEFAULT_FLOW_TOP_MARGIN;
        $bottomMargin ??= self::DEFAULT_FLOW_BOTTOM_MARGIN;

        if ($topMargin < 0.0 || $bottomMargin < 0.0) {
            throw new PdfException('Flow text margins must be zero or greater.');
        }

        $lineAdvance = $fontSize * $lineHeight;
        $paragraphAdvance = $fontSize * $paragraphSpacing;
        $measurer = $this->textMeasurer();
        $currentPage = $page;
        $currentY = $y;
        $paragraphs = $this->flowParagraphs($text);

        foreach ($paragraphs as $paragraphIndex => $paragraph) {
            foreach ($this->wrapFlowParagraph($paragraph, $width, $fontSize, $font, $measurer) as $line) {
                if ($currentY + $fontSize > $currentPage->height() - $bottomMargin) {
                    $currentPage = $this->addFlowContinuationPage($currentPage);
                    $currentY = $topMargin;
                }

                $currentPage->addText(new TextRun($line, $x, $currentY, $fontSize, $font, $color));
                $currentY += $lineAdvance;
            }

            if ($paragraphIndex === array_key_last($paragraphs)) {
                continue;
            }

            $currentY += $paragraphAdvance;
        }

        return $this;
    }

    public function flowTextFrame(
        string $text,
        TextFrame $frame,
        float $fontSize = 12.0,
        ?FontReference $font = null,
        ?Color $color = null,
        float $lineHeight = 1.2,
        float $paragraphSpacing = 0.35,
    ): self {
        if ($fontSize <= 0.0) {
            throw new PdfException('Flow text font size must be greater than zero.');
        }

        if ($lineHeight <= 0.0) {
            throw new PdfException('Flow text line height must be greater than zero.');
        }

        if ($paragraphSpacing < 0.0) {
            throw new PdfException('Flow text paragraph spacing must be zero or greater.');
        }

        [$currentPage] = $this->renderTextInFrame(
            $this->requirePage(),
            $frame,
            $frame->y,
            $text,
            $fontSize,
            $font,
            $color,
            $lineHeight,
            $paragraphSpacing,
        );

        return $this;
    }

    public function contentFrame(PageMargins $margins): TextFrame
    {
        return TextFrame::fromPage($this->requirePage(), $margins);
    }

    public function flowTextContentFrame(
        string $text,
        PageMargins $margins,
        float $fontSize = 12.0,
        ?FontReference $font = null,
        ?Color $color = null,
        float $lineHeight = 1.2,
        float $paragraphSpacing = 0.35,
    ): self {
        return $this->flowTextFrame(
            $text,
            $this->contentFrame($margins),
            $fontSize,
            $font,
            $color,
            $lineHeight,
            $paragraphSpacing,
        );
    }

    /**
     * @param list<TextFrame> $frames
     */
    public function flowTextFrames(
        string $text,
        array $frames,
        float $fontSize = 12.0,
        ?FontReference $font = null,
        ?Color $color = null,
        float $lineHeight = 1.2,
        float $paragraphSpacing = 0.35,
    ): self {
        if ($frames === []) {
            throw new PdfException('Flow text frames require at least one frame.');
        }

        if ($fontSize <= 0.0) {
            throw new PdfException('Flow text font size must be greater than zero.');
        }

        if ($lineHeight <= 0.0) {
            throw new PdfException('Flow text line height must be greater than zero.');
        }

        if ($paragraphSpacing < 0.0) {
            throw new PdfException('Flow text paragraph spacing must be zero or greater.');
        }

        foreach ($frames as $frame) {
            if (!$frame instanceof TextFrame) {
                throw new PdfException('flowTextFrames expects an array of TextFrame instances.');
            }
        }

        $this->renderTextInFrames(
            $this->requirePage(),
            $frames,
            $text,
            $fontSize,
            $font,
            $color,
            $lineHeight,
            $paragraphSpacing,
        );

        return $this;
    }

    /**
     * @return list<TextFrame>
     */
    public function columnFrames(PageMargins $margins, int $columns, float $gap = 12.0): array
    {
        if ($columns < 1) {
            throw new PdfException('Column frame count must be at least 1.');
        }

        if ($gap < 0.0) {
            throw new PdfException('Column frame gap must be zero or greater.');
        }

        $content = $this->contentFrame($margins);
        $totalGap = ($columns - 1) * $gap;
        $columnWidth = ($content->width - $totalGap) / $columns;

        if ($columnWidth <= 0.0) {
            throw new PdfException('Derived column width must be greater than zero.');
        }

        $frames = [];

        for ($index = 0; $index < $columns; $index++) {
            $frames[] = new TextFrame(
                $content->x + ($index * ($columnWidth + $gap)),
                $content->y,
                $columnWidth,
                $content->height,
            );
        }

        return $frames;
    }

    public function flowTextColumns(
        string $text,
        PageMargins $margins,
        int $columns,
        float $gap = 12.0,
        float $fontSize = 12.0,
        ?FontReference $font = null,
        ?Color $color = null,
        float $lineHeight = 1.2,
        float $paragraphSpacing = 0.35,
    ): self {
        return $this->flowTextFrames(
            $text,
            $this->columnFrames($margins, $columns, $gap),
            $fontSize,
            $font,
            $color,
            $lineHeight,
            $paragraphSpacing,
        );
    }

    public function flowTextPanel(
        string $text,
        TextFrame $frame,
        PanelStyle $style,
        float $fontSize = 12.0,
        ?FontReference $font = null,
        ?Color $color = null,
        float $lineHeight = 1.2,
        float $paragraphSpacing = 0.35,
    ): self {
        $panelDecorator = function (Page $page) use ($frame, $style): void {
            $this->drawPanel($page, $frame, $style);
        };
        $innerFrame = $frame->inset($style->padding);

        $panelDecorator($this->requirePage());

        $this->renderTextInFrame(
            $this->requirePage(),
            $innerFrame,
            $innerFrame->y,
            $text,
            $fontSize,
            $font,
            $color,
            $lineHeight,
            $paragraphSpacing,
            $panelDecorator,
        );

        return $this;
    }

    public function flowTextContentPanel(
        string $text,
        PageMargins $margins,
        PanelStyle $style,
        float $fontSize = 12.0,
        ?FontReference $font = null,
        ?Color $color = null,
        float $lineHeight = 1.2,
        float $paragraphSpacing = 0.35,
    ): self {
        return $this->flowTextPanel(
            $text,
            $this->contentFrame($margins),
            $style,
            $fontSize,
            $font,
            $color,
            $lineHeight,
            $paragraphSpacing,
        );
    }

    /**
     * @param list<TextFrame> $frames
     */
    public function flowTextPanelFrames(
        string $text,
        array $frames,
        PanelStyle $style,
        float $fontSize = 12.0,
        ?FontReference $font = null,
        ?Color $color = null,
        float $lineHeight = 1.2,
        float $paragraphSpacing = 0.35,
    ): self {
        if ($frames === []) {
            throw new PdfException('Flow text panel frames require at least one frame.');
        }

        foreach ($frames as $frame) {
            if (!$frame instanceof TextFrame) {
                throw new PdfException('flowTextPanelFrames expects an array of TextFrame instances.');
            }
        }

        $panelDecorator = function (Page $page, int $frameIndex) use ($frames, $style): void {
            $this->drawPanel($page, $frames[$frameIndex], $style);
        };

        $innerFrames = array_map(
            static fn (TextFrame $frame): TextFrame => $frame->inset($style->padding),
            $frames,
        );

        $this->renderTextInFrames(
            $this->requirePage(),
            $innerFrames,
            $text,
            $fontSize,
            $font,
            $color,
            $lineHeight,
            $paragraphSpacing,
            $panelDecorator,
        );

        return $this;
    }

    public function flowTextPanelColumns(
        string $text,
        PageMargins $margins,
        int $columns,
        PanelStyle $style,
        float $gap = 12.0,
        float $fontSize = 12.0,
        ?FontReference $font = null,
        ?Color $color = null,
        float $lineHeight = 1.2,
        float $paragraphSpacing = 0.35,
    ): self {
        return $this->flowTextPanelFrames(
            $text,
            $this->columnFrames($margins, $columns, $gap),
            $style,
            $fontSize,
            $font,
            $color,
            $lineHeight,
            $paragraphSpacing,
        );
    }

    /**
     * @param list<TextBlock> $blocks
     */
    public function stackTextBlocksFrame(array $blocks, TextFrame $frame): self
    {
        $currentPage = $this->requirePage();
        $currentY = $frame->y;

        foreach ($blocks as $block) {
            if (!$block instanceof TextBlock) {
                throw new PdfException('stackTextBlocksFrame expects an array of TextBlock instances.');
            }

            [$currentPage, $currentY] = $this->renderTextInFrame(
                $currentPage,
                $frame,
                $currentY,
                $block->text,
                $block->fontSize,
                $block->font,
                $block->color,
                $block->lineHeight,
                $block->paragraphSpacing,
            );

            $currentY += $block->spacingAfter;
        }

        return $this;
    }

    /**
     * @param list<TextBlock> $blocks
     * @param list<TextFrame> $frames
     */
    public function stackTextBlocksFrames(array $blocks, array $frames): self
    {
        if ($frames === []) {
            throw new PdfException('Stacked text block frames require at least one frame.');
        }

        foreach ($frames as $frame) {
            if (!$frame instanceof TextFrame) {
                throw new PdfException('stackTextBlocksFrames expects an array of TextFrame instances.');
            }
        }

        foreach ($blocks as $block) {
            if (!$block instanceof TextBlock) {
                throw new PdfException('stackTextBlocksFrames expects an array of TextBlock instances.');
            }
        }

        $this->renderTextBlocksInFrames($this->requirePage(), $frames, $blocks);

        return $this;
    }

    /**
     * @param list<TextBlock> $blocks
     */
    public function stackTextBlocksColumns(
        array $blocks,
        PageMargins $margins,
        int $columns,
        float $gap = 12.0,
    ): self {
        return $this->stackTextBlocksFrames(
            $blocks,
            $this->columnFrames($margins, $columns, $gap),
        );
    }

    /**
     * @param list<TextBlock> $blocks
     * @param list<TextFrame> $frames
     */
    public function stackTextBlocksPanelFrames(array $blocks, array $frames, PanelStyle $style): self
    {
        if ($frames === []) {
            throw new PdfException('Stacked text block panel frames require at least one frame.');
        }

        foreach ($frames as $frame) {
            if (!$frame instanceof TextFrame) {
                throw new PdfException('stackTextBlocksPanelFrames expects an array of TextFrame instances.');
            }
        }

        foreach ($blocks as $block) {
            if (!$block instanceof TextBlock) {
                throw new PdfException('stackTextBlocksPanelFrames expects an array of TextBlock instances.');
            }
        }

        $innerFrames = array_map(
            static fn (TextFrame $frame): TextFrame => $frame->inset($style->padding),
            $frames,
        );

        $this->renderTextBlocksInFrames(
            $this->requirePage(),
            $innerFrames,
            $blocks,
            function (Page $page, int $frameIndex) use ($frames, $style): void {
                $this->drawPanel($page, $frames[$frameIndex], $style);
            },
        );

        return $this;
    }

    /**
     * @param list<TextBlock> $blocks
     */
    public function stackTextBlocksPanelColumns(
        array $blocks,
        PageMargins $margins,
        int $columns,
        PanelStyle $style,
        float $gap = 12.0,
    ): self {
        return $this->stackTextBlocksPanelFrames(
            $blocks,
            $this->columnFrames($margins, $columns, $gap),
            $style,
        );
    }

    /**
     * @param list<TextBlock> $blocks
     */
    public function stackTextBlocksContentFrame(array $blocks, PageMargins $margins): self
    {
        return $this->stackTextBlocksFrame($blocks, $this->contentFrame($margins));
    }

    public function panelFrame(TextFrame $frame, PanelStyle $style): TextFrame
    {
        $this->drawPanel($this->requirePage(), $frame, $style);

        return $frame->inset($style->padding);
    }

    /**
     * @param list<TextBlock> $blocks
     */
    public function stackTextBlocksPanel(array $blocks, TextFrame $frame, PanelStyle $style): self
    {
        $panelDecorator = function (Page $page) use ($frame, $style): void {
            $this->drawPanel($page, $frame, $style);
        };

        $panelDecorator($this->requirePage());
        $currentPage = $this->requirePage();
        $currentY = $frame->inset($style->padding)->y;
        $innerFrame = $frame->inset($style->padding);

        foreach ($blocks as $block) {
            if (!$block instanceof TextBlock) {
                throw new PdfException('stackTextBlocksPanel expects an array of TextBlock instances.');
            }

            [$currentPage, $currentY] = $this->renderTextInFrame(
                $currentPage,
                $innerFrame,
                $currentY,
                $block->text,
                $block->fontSize,
                $block->font,
                $block->color,
                $block->lineHeight,
                $block->paragraphSpacing,
                $panelDecorator,
            );

            $currentY += $block->spacingAfter;
        }

        return $this;
    }

    /**
     * @param list<TextBlock> $blocks
     */
    public function stackTextBlocksContentPanel(array $blocks, PageMargins $margins, PanelStyle $style): self
    {
        return $this->stackTextBlocksPanel($blocks, $this->contentFrame($margins), $style);
    }

    public function tableFrame(
        array $rows,
        array $columns,
        TextFrame $frame,
        ?TableStyle $style = null,
        bool $firstRowHeader = false,
    ): self {
        $this->renderTableFrame($rows, $columns, $frame, $style, $firstRowHeader);

        return $this;
    }

    private function renderTableFrame(
        array $rows,
        array $columns,
        TextFrame $frame,
        ?TableStyle $style = null,
        bool $firstRowHeader = false,
        ?callable $onPageStart = null,
    ): void {
        $style ??= TableStyle::padded(4.0, borderColor: Color::black(), lineWidth: 0.75);
        $normalizedColumns = $this->normalizeTableColumns($columns);
        $columnWidths = array_map(static fn (TableColumn $column): float => $column->width, $normalizedColumns);
        $normalizedRows = $this->normalizeTableRows($rows, $columnWidths);
        $this->validateTableFrame($frame, $normalizedColumns, $style);

        if ($normalizedRows === []) {
            return;
        }

        $rowHeights = $this->tableRowHeights($normalizedRows, $columnWidths, $normalizedColumns, $style);
        $rowGroups = $this->tableRowGroups($normalizedRows);
        $currentPage = $this->requirePage();
        $currentY = $frame->y;
        $headerGroup = $firstRowHeader ? $rowGroups[0] : null;
        $headerRows = $headerGroup === null
            ? []
            : array_slice($normalizedRows, $headerGroup['start'], $headerGroup['end'] - $headerGroup['start'] + 1);
        $headerHeight = $headerGroup === null
            ? 0.0
            : $this->tableGroupHeight($rowHeights, $headerGroup['start'], $headerGroup['end']);

        if ($headerGroup !== null) {
            [$currentPage, $currentY] = $this->renderTableGroup(
                $currentPage,
                $frame,
                $currentY,
                $headerRows,
                array_slice($rowHeights, $headerGroup['start'], $headerGroup['end'] - $headerGroup['start'] + 1),
                $columnWidths,
                $normalizedColumns,
                $style,
                $headerGroup['start'],
                rowFillResolver: static fn (int $rowIndex): ?Color => $style->headerFillColor ?? $style->rowFillColor,
            );
        }

        foreach ($rowGroups as $groupIndex => $group) {
            if ($headerGroup !== null && $groupIndex === 0) {
                continue;
            }

            $groupHeight = $this->tableGroupHeight($rowHeights, $group['start'], $group['end']);

            if ($currentY + $groupHeight > $frame->bottom()) {
                $currentPage = $this->addFlowContinuationPage($currentPage);
                $currentY = $frame->y;

                if ($onPageStart !== null) {
                    $onPageStart($currentPage);
                }

                if ($headerGroup !== null) {
                    [$currentPage, $currentY] = $this->renderTableGroup(
                        $currentPage,
                        $frame,
                        $currentY,
                        $headerRows,
                        array_slice($rowHeights, $headerGroup['start'], $headerGroup['end'] - $headerGroup['start'] + 1),
                        $columnWidths,
                        $normalizedColumns,
                        $style,
                        $headerGroup['start'],
                        rowFillResolver: static fn (int $rowIndex): ?Color => $style->headerFillColor ?? $style->rowFillColor,
                    );
                }
            }

            [$currentPage, $currentY] = $this->renderTableGroup(
                $currentPage,
                $frame,
                $currentY,
                array_slice($normalizedRows, $group['start'], $group['end'] - $group['start'] + 1),
                array_slice($rowHeights, $group['start'], $group['end'] - $group['start'] + 1),
                $columnWidths,
                $normalizedColumns,
                $style,
                $group['start'],
                rowFillResolver: function (int $rowIndex) use ($style, $headerGroup): ?Color {
                    $startRowIndex = $headerGroup === null ? 0 : $headerGroup['end'] + 1;
                    $fillColor = $style->rowFillColor;

                    if ($style->alternateRowFillColor !== null && (($rowIndex - $startRowIndex) % 2) === 1) {
                        $fillColor = $style->alternateRowFillColor;
                    }

                    return $fillColor;
                },
            );
        }
    }

    public function tableContentFrame(
        array $rows,
        array $columns,
        PageMargins $margins,
        ?TableStyle $style = null,
        bool $firstRowHeader = false,
    ): self {
        return $this->tableFrame(
            $rows,
            $columns,
            $this->contentFrame($margins),
            $style,
            $firstRowHeader,
        );
    }

    /**
     * @param list<TextFrame> $frames
     */
    public function tableFrames(
        array $rows,
        array $columns,
        array $frames,
        ?TableStyle $style = null,
        bool $firstRowHeader = false,
    ): self {
        $this->renderTableFrames($rows, $columns, $frames, $style, $firstRowHeader);

        return $this;
    }

    /**
     * @param list<TextFrame> $frames
     */
    private function renderTableFrames(
        array $rows,
        array $columns,
        array $frames,
        ?TableStyle $style = null,
        bool $firstRowHeader = false,
        ?callable $onFrameStart = null,
    ): void {
        if ($frames === []) {
            throw new PdfException('Table frames require at least one frame.');
        }

        foreach ($frames as $frame) {
            if (!$frame instanceof TextFrame) {
                throw new PdfException('tableFrames expects an array of TextFrame instances.');
            }
        }

        $style ??= TableStyle::padded(4.0, borderColor: Color::black(), lineWidth: 0.75);
        $normalizedColumns = $this->normalizeTableColumns($columns);
        $columnWidths = array_map(static fn (TableColumn $column): float => $column->width, $normalizedColumns);

        foreach ($frames as $frame) {
            $this->validateTableFrame($frame, $normalizedColumns, $style);
        }

        $normalizedRows = $this->normalizeTableRows($rows, $columnWidths);

        if ($normalizedRows === []) {
            return;
        }

        $rowHeights = $this->tableRowHeights($normalizedRows, $columnWidths, $normalizedColumns, $style);
        $rowGroups = $this->tableRowGroups($normalizedRows);
        $currentPage = $this->requirePage();
        $frameIndex = 0;
        $currentFrame = $frames[$frameIndex];
        $currentY = $currentFrame->y;
        $headerGroup = $firstRowHeader ? $rowGroups[0] : null;
        $headerRows = $headerGroup === null
            ? []
            : array_slice($normalizedRows, $headerGroup['start'], $headerGroup['end'] - $headerGroup['start'] + 1);

        if ($headerGroup !== null) {
            [$currentPage, $currentY] = $this->renderTableGroup(
                $currentPage,
                $currentFrame,
                $currentY,
                $headerRows,
                array_slice($rowHeights, $headerGroup['start'], $headerGroup['end'] - $headerGroup['start'] + 1),
                $columnWidths,
                $normalizedColumns,
                $style,
                $headerGroup['start'],
                rowFillResolver: static fn (int $rowIndex): ?Color => $style->headerFillColor ?? $style->rowFillColor,
            );
        }

        foreach ($rowGroups as $groupIndex => $group) {
            if ($headerGroup !== null && $groupIndex === 0) {
                continue;
            }

            $groupHeight = $this->tableGroupHeight($rowHeights, $group['start'], $group['end']);

            if ($currentY + $groupHeight > $currentFrame->bottom()) {
                [$currentPage, $frameIndex, $currentFrame, $currentY] = $this->advanceTableFrame(
                    $currentPage,
                    $frames,
                    $frameIndex,
                    $onFrameStart,
                );

                if ($headerGroup !== null) {
                    [$currentPage, $currentY] = $this->renderTableGroup(
                        $currentPage,
                        $currentFrame,
                        $currentY,
                        $headerRows,
                        array_slice($rowHeights, $headerGroup['start'], $headerGroup['end'] - $headerGroup['start'] + 1),
                        $columnWidths,
                        $normalizedColumns,
                        $style,
                        $headerGroup['start'],
                        rowFillResolver: static fn (int $rowIndex): ?Color => $style->headerFillColor ?? $style->rowFillColor,
                    );
                }
            }

            [$currentPage, $currentY] = $this->renderTableGroup(
                $currentPage,
                $currentFrame,
                $currentY,
                array_slice($normalizedRows, $group['start'], $group['end'] - $group['start'] + 1),
                array_slice($rowHeights, $group['start'], $group['end'] - $group['start'] + 1),
                $columnWidths,
                $normalizedColumns,
                $style,
                $group['start'],
                rowFillResolver: function (int $rowIndex) use ($style, $headerGroup): ?Color {
                    $startRowIndex = $headerGroup === null ? 0 : $headerGroup['end'] + 1;
                    $fillColor = $style->rowFillColor;

                    if ($style->alternateRowFillColor !== null && (($rowIndex - $startRowIndex) % 2) === 1) {
                        $fillColor = $style->alternateRowFillColor;
                    }

                    return $fillColor;
                },
            );
        }
    }

    public function tableColumns(
        array $rows,
        array $columns,
        PageMargins $margins,
        int $frameCount,
        float $gap = 12.0,
        ?TableStyle $style = null,
        bool $firstRowHeader = false,
    ): self {
        return $this->tableFrames(
            $rows,
            $columns,
            $this->columnFrames($margins, $frameCount, $gap),
            $style,
            $firstRowHeader,
        );
    }

    public function tablePanelFrame(
        array $rows,
        array $columns,
        TextFrame $frame,
        PanelStyle $panelStyle,
        ?TableStyle $tableStyle = null,
        bool $firstRowHeader = false,
    ): self {
        $panelDecorator = function (Page $page) use ($frame, $panelStyle): void {
            $this->drawPanel($page, $frame, $panelStyle);
        };

        $panelDecorator($this->requirePage());

        $this->renderTableFrame(
            $rows,
            $columns,
            $frame->inset($panelStyle->padding),
            $tableStyle,
            $firstRowHeader,
            $panelDecorator,
        );

        return $this;
    }

    public function tableContentPanel(
        array $rows,
        array $columns,
        PageMargins $margins,
        PanelStyle $panelStyle,
        ?TableStyle $tableStyle = null,
        bool $firstRowHeader = false,
    ): self {
        return $this->tablePanelFrame(
            $rows,
            $columns,
            $this->contentFrame($margins),
            $panelStyle,
            $tableStyle,
            $firstRowHeader,
        );
    }

    /**
     * @param list<TextFrame> $frames
     */
    public function tablePanelFrames(
        array $rows,
        array $columns,
        array $frames,
        PanelStyle $panelStyle,
        ?TableStyle $tableStyle = null,
        bool $firstRowHeader = false,
    ): self {
        if ($frames === []) {
            throw new PdfException('Table panel frames require at least one frame.');
        }

        foreach ($frames as $frame) {
            if (!$frame instanceof TextFrame) {
                throw new PdfException('tablePanelFrames expects an array of TextFrame instances.');
            }
        }

        $panelDecorator = function (Page $page, int $frameIndex) use ($frames, $panelStyle): void {
            $this->drawPanel($page, $frames[$frameIndex], $panelStyle);
        };

        $panelDecorator($this->requirePage(), 0);

        $innerFrames = array_map(
            static fn (TextFrame $frame): TextFrame => $frame->inset($panelStyle->padding),
            $frames,
        );

        $this->renderTableFrames(
            $rows,
            $columns,
            $innerFrames,
            $tableStyle,
            $firstRowHeader,
            $panelDecorator,
        );

        return $this;
    }

    public function tablePanelColumns(
        array $rows,
        array $columns,
        PageMargins $margins,
        int $frameCount,
        PanelStyle $panelStyle,
        float $gap = 12.0,
        ?TableStyle $tableStyle = null,
        bool $firstRowHeader = false,
    ): self {
        return $this->tablePanelFrames(
            $rows,
            $columns,
            $this->columnFrames($margins, $frameCount, $gap),
            $panelStyle,
            $tableStyle,
            $firstRowHeader,
        );
    }

    /**
     * @param list<array<string, mixed>|object> $records
     * @param list<TableDataColumn> $columns
     */
    public function tableRecordsFrame(
        array $records,
        array $columns,
        TextFrame $frame,
        ?TableStyle $style = null,
        bool $header = true,
        ?callable $columnFilter = null,
        ?callable $recordSorter = null,
        ?callable $rowFormatter = null,
        ?callable $footerFormatter = null,
        ?callable $groupResolver = null,
        ?callable $groupHeaderFormatter = null,
        ?callable $groupFooterFormatter = null,
        ?callable $emptyFormatter = null,
    ): self {
        [$rows, $columns] = $this->buildRecordTableRows(
            $records,
            $columns,
            $header,
            $columnFilter,
            $recordSorter,
            $rowFormatter,
            $footerFormatter,
            $groupResolver,
            $groupHeaderFormatter,
            $groupFooterFormatter,
            $emptyFormatter,
        );

        $this->renderTableFrame(
            $rows,
            array_map(static fn (TableDataColumn $column): TableColumn => $column->column, $columns),
            $frame,
            $style,
            $header,
        );

        return $this;
    }

    /**
     * @param list<array<string, mixed>|object> $records
     * @param list<TableDataColumn> $columns
     * @return array{0: array, 1: array}
     */
    private function buildRecordTableRows(
        array $records,
        array $columns,
        bool $header = true,
        ?callable $columnFilter = null,
        ?callable $recordSorter = null,
        ?callable $rowFormatter = null,
        ?callable $footerFormatter = null,
        ?callable $groupResolver = null,
        ?callable $groupHeaderFormatter = null,
        ?callable $groupFooterFormatter = null,
        ?callable $emptyFormatter = null,
    ): array {
        $rows = [];
        $currentGroupKey = null;
        $hasCurrentGroup = false;
        $currentGroupRecords = [];
        $currentGroupIndex = 0;
        $totalGroups = $groupResolver !== null ? $this->countGroupedRecordGroups($records, $groupResolver) : 0;

        if ($columnFilter !== null) {
            $columns = $columnFilter($columns, $records);

            if (!is_array($columns) || $columns === []) {
                throw new PdfException('Record table column filter must return a non-empty array of columns.');
            }

            $columns = array_values($columns);
        }

        if ($recordSorter !== null) {
            $records = $recordSorter($records, $columns);

            if (!is_array($records)) {
                throw new PdfException('Record table sorter must return an array of records.');
            }

            $records = array_values($records);
        }

        if ($header) {
            $rows[] = array_map(
                static fn (TableDataColumn $column): TableCell|string => $column->headerCell ?? $column->header,
                $columns,
            );
        }

        if ($records === [] && $emptyFormatter !== null) {
            $emptyRows = $this->normalizeRecordFormatterRows(
                $emptyFormatter($columns),
            );

            foreach ($emptyRows as $emptyRow) {
                $rows[] = $emptyRow;
            }
        }

        foreach ($records as $recordIndex => $record) {
            if ($groupResolver !== null) {
                $groupKey = $groupResolver($record);

                if ($hasCurrentGroup && $groupKey !== $currentGroupKey && $groupFooterFormatter !== null) {
                    $groupFooterRows = $this->normalizeRecordFormatterRows(
                        $this->invokeCallableWithArgs(
                            $groupFooterFormatter,
                            [$currentGroupKey, $currentGroupRecords, $columns, $currentGroupIndex, $totalGroups],
                        ),
                    );

                    foreach ($groupFooterRows as $groupFooterRow) {
                        $rows[] = $groupFooterRow;
                    }
                }

                if (!$hasCurrentGroup || $groupKey !== $currentGroupKey) {
                    $currentGroupIndex++;
                    $groupRecords = $this->collectGroupedRecords($records, $recordIndex, $groupResolver, $groupKey);
                    $groupRows = $groupHeaderFormatter !== null
                        ? $this->normalizeRecordFormatterRows($this->invokeCallableWithArgs(
                            $groupHeaderFormatter,
                            [$groupKey, $record, $columns, $groupRecords, $currentGroupIndex, $totalGroups],
                        ))
                        : [[new TableCell(
                            $this->normalizeTableRecordString($groupKey),
                            colspan: count($columns),
                            fillColor: Color::gray(0.92),
                        )]];

                    foreach ($groupRows as $groupRow) {
                        $rows[] = $groupRow;
                    }

                    $currentGroupKey = $groupKey;
                    $currentGroupRecords = [];
                    $hasCurrentGroup = true;
                }

                $currentGroupRecords[] = $record;
            }

            $row = [];

            foreach ($columns as $column) {
                $value = $column->resolver !== null
                    ? ($column->resolver)($record, $column)
                    : $this->resolveTableRecordFieldValue($record, $column->field);

                if ($column->formatter !== null) {
                    $value = ($column->formatter)($value, $record, $column);
                }

                $row[] = $this->normalizeTableRecordCell($value);
            }

            if ($rowFormatter !== null) {
                $formattedRows = $this->normalizeRecordFormatterRows(
                    $rowFormatter($row, $record, $columns),
                );

                foreach ($formattedRows as $formattedRow) {
                    $rows[] = $formattedRow;
                }

                continue;
            }

            $rows[] = $row;
        }

        if ($groupResolver !== null && $hasCurrentGroup && $groupFooterFormatter !== null) {
            $groupFooterRows = $this->normalizeRecordFormatterRows(
                $this->invokeCallableWithArgs(
                    $groupFooterFormatter,
                    [$currentGroupKey, $currentGroupRecords, $columns, $currentGroupIndex, $totalGroups],
                ),
            );

            foreach ($groupFooterRows as $groupFooterRow) {
                $rows[] = $groupFooterRow;
            }
        }

        if ($footerFormatter !== null) {
            $footerRows = $this->normalizeRecordFormatterRows(
                $footerFormatter($records, $columns),
            );

            foreach ($footerRows as $footerRow) {
                $rows[] = $footerRow;
            }
        }

        return [$rows, $columns];
    }

    /**
     * @param list<array<string, mixed>|object> $records
     */
    private function countGroupedRecordGroups(array $records, callable $groupResolver): int
    {
        $count = 0;
        $hasCurrent = false;
        $currentGroupKey = null;

        foreach ($records as $record) {
            $groupKey = $groupResolver($record);

            if (!$hasCurrent || $groupKey !== $currentGroupKey) {
                $count++;
                $currentGroupKey = $groupKey;
                $hasCurrent = true;
            }
        }

        return $count;
    }

    /**
     * @param list<array<string, mixed>|object> $records
     * @return list<array<string, mixed>|object>
     */
    private function collectGroupedRecords(array $records, int $startIndex, callable $groupResolver, mixed $groupKey): array
    {
        $groupRecords = [];
        $total = count($records);

        for ($index = $startIndex; $index < $total; $index++) {
            $record = $records[$index];

            if ($groupResolver($record) !== $groupKey) {
                break;
            }

            $groupRecords[] = $record;
        }

        return $groupRecords;
    }

    /**
     * @param list<mixed> $args
     */
    private function invokeCallableWithArgs(callable $callable, array $args): mixed
    {
        $accepted = $this->callableAcceptedArgumentCount($callable, count($args));

        return $callable(...array_slice($args, 0, $accepted));
    }

    private function callableAcceptedArgumentCount(callable $callable, int $provided): int
    {
        if (is_array($callable)) {
            $reflection = new \ReflectionMethod($callable[0], $callable[1]);
        } elseif (is_string($callable) && str_contains($callable, '::')) {
            $reflection = new \ReflectionMethod($callable);
        } elseif (is_object($callable) && !$callable instanceof \Closure) {
            $reflection = new \ReflectionMethod($callable, '__invoke');
        } else {
            $reflection = new \ReflectionFunction($callable);
        }

        if ($reflection->isVariadic()) {
            return $provided;
        }

        return min($provided, $reflection->getNumberOfParameters());
    }

    /**
     * @param list<array<string, mixed>|object> $records
     * @param list<TableDataColumn> $columns
     */
    public function tableRecordsContentFrame(
        array $records,
        array $columns,
        PageMargins $margins,
        ?TableStyle $style = null,
        bool $header = true,
        ?callable $columnFilter = null,
        ?callable $recordSorter = null,
        ?callable $rowFormatter = null,
        ?callable $footerFormatter = null,
        ?callable $groupResolver = null,
        ?callable $groupHeaderFormatter = null,
        ?callable $groupFooterFormatter = null,
        ?callable $emptyFormatter = null,
    ): self {
        return $this->tableRecordsFrame(
            $records,
            $columns,
            $this->contentFrame($margins),
            $style,
            $header,
            $columnFilter,
            $recordSorter,
            $rowFormatter,
            $footerFormatter,
            $groupResolver,
            $groupHeaderFormatter,
            $groupFooterFormatter,
            $emptyFormatter,
        );
    }

    /**
     * @param list<array<string, mixed>|object> $records
     * @param list<TableDataColumn> $columns
     * @param list<TextFrame> $frames
     */
    public function tableRecordsFrames(
        array $records,
        array $columns,
        array $frames,
        ?TableStyle $style = null,
        bool $header = true,
        ?callable $columnFilter = null,
        ?callable $recordSorter = null,
        ?callable $rowFormatter = null,
        ?callable $footerFormatter = null,
        ?callable $groupResolver = null,
        ?callable $groupHeaderFormatter = null,
        ?callable $groupFooterFormatter = null,
        ?callable $emptyFormatter = null,
    ): self {
        [$rows, $columns] = $this->buildRecordTableRows(
            $records,
            $columns,
            $header,
            $columnFilter,
            $recordSorter,
            $rowFormatter,
            $footerFormatter,
            $groupResolver,
            $groupHeaderFormatter,
            $groupFooterFormatter,
            $emptyFormatter,
        );

        $this->tableFrames(
            $rows,
            array_map(static fn (TableDataColumn $column): TableColumn => $column->column, $columns),
            $frames,
            $style,
            $header,
        );

        return $this;
    }

    /**
     * @param list<array<string, mixed>|object> $records
     * @param list<TableDataColumn> $columns
     */
    public function tableRecordsColumns(
        array $records,
        array $columns,
        PageMargins $margins,
        int $frameCount,
        float $gap = 12.0,
        ?TableStyle $style = null,
        bool $header = true,
        ?callable $columnFilter = null,
        ?callable $recordSorter = null,
        ?callable $rowFormatter = null,
        ?callable $footerFormatter = null,
        ?callable $groupResolver = null,
        ?callable $groupHeaderFormatter = null,
        ?callable $groupFooterFormatter = null,
        ?callable $emptyFormatter = null,
    ): self {
        return $this->tableRecordsFrames(
            $records,
            $columns,
            $this->columnFrames($margins, $frameCount, $gap),
            $style,
            $header,
            $columnFilter,
            $recordSorter,
            $rowFormatter,
            $footerFormatter,
            $groupResolver,
            $groupHeaderFormatter,
            $groupFooterFormatter,
            $emptyFormatter,
        );
    }

    /**
     * @param list<array<string, mixed>|object> $records
     * @param list<TableDataColumn> $columns
     */
    public function tableRecordsPanelFrame(
        array $records,
        array $columns,
        TextFrame $frame,
        PanelStyle $panelStyle,
        ?TableStyle $style = null,
        bool $header = true,
        ?callable $columnFilter = null,
        ?callable $recordSorter = null,
        ?callable $rowFormatter = null,
        ?callable $footerFormatter = null,
        ?callable $groupResolver = null,
        ?callable $groupHeaderFormatter = null,
        ?callable $groupFooterFormatter = null,
        ?callable $emptyFormatter = null,
    ): self {
        [$rows, $columns] = $this->buildRecordTableRows(
            $records,
            $columns,
            $header,
            $columnFilter,
            $recordSorter,
            $rowFormatter,
            $footerFormatter,
            $groupResolver,
            $groupHeaderFormatter,
            $groupFooterFormatter,
            $emptyFormatter,
        );

        $panelDecorator = function (Page $page) use ($frame, $panelStyle): void {
            $this->drawPanel($page, $frame, $panelStyle);
        };

        $panelDecorator($this->requirePage());

        $this->renderTableFrame(
            $rows,
            array_map(static fn (TableDataColumn $column): TableColumn => $column->column, $columns),
            $frame->inset($panelStyle->padding),
            $style,
            $header,
            $panelDecorator,
        );

        return $this;
    }

    /**
     * @param list<array<string, mixed>|object> $records
     * @param list<TableDataColumn> $columns
     * @param list<TextFrame> $frames
     */
    public function tableRecordsPanelFrames(
        array $records,
        array $columns,
        array $frames,
        PanelStyle $panelStyle,
        ?TableStyle $style = null,
        bool $header = true,
        ?callable $columnFilter = null,
        ?callable $recordSorter = null,
        ?callable $rowFormatter = null,
        ?callable $footerFormatter = null,
        ?callable $groupResolver = null,
        ?callable $groupHeaderFormatter = null,
        ?callable $groupFooterFormatter = null,
        ?callable $emptyFormatter = null,
    ): self {
        [$rows, $columns] = $this->buildRecordTableRows(
            $records,
            $columns,
            $header,
            $columnFilter,
            $recordSorter,
            $rowFormatter,
            $footerFormatter,
            $groupResolver,
            $groupHeaderFormatter,
            $groupFooterFormatter,
            $emptyFormatter,
        );

        $this->tablePanelFrames(
            $rows,
            array_map(static fn (TableDataColumn $column): TableColumn => $column->column, $columns),
            $frames,
            $panelStyle,
            $style,
            $header,
        );

        return $this;
    }

    /**
     * @param list<array<string, mixed>|object> $records
     * @param list<TableDataColumn> $columns
     */
    public function tableRecordsPanelColumns(
        array $records,
        array $columns,
        PageMargins $margins,
        int $frameCount,
        PanelStyle $panelStyle,
        float $gap = 12.0,
        ?TableStyle $style = null,
        bool $header = true,
        ?callable $columnFilter = null,
        ?callable $recordSorter = null,
        ?callable $rowFormatter = null,
        ?callable $footerFormatter = null,
        ?callable $groupResolver = null,
        ?callable $groupHeaderFormatter = null,
        ?callable $groupFooterFormatter = null,
        ?callable $emptyFormatter = null,
    ): self {
        return $this->tableRecordsPanelFrames(
            $records,
            $columns,
            $this->columnFrames($margins, $frameCount, $gap),
            $panelStyle,
            $style,
            $header,
            $columnFilter,
            $recordSorter,
            $rowFormatter,
            $footerFormatter,
            $groupResolver,
            $groupHeaderFormatter,
            $groupFooterFormatter,
            $emptyFormatter,
        );
    }

    /**
     * @param list<array<string, mixed>|object> $records
     * @param list<TableDataColumn> $columns
     */
    public function tableRecordsContentPanel(
        array $records,
        array $columns,
        PageMargins $margins,
        PanelStyle $panelStyle,
        ?TableStyle $style = null,
        bool $header = true,
        ?callable $columnFilter = null,
        ?callable $recordSorter = null,
        ?callable $rowFormatter = null,
        ?callable $footerFormatter = null,
        ?callable $groupResolver = null,
        ?callable $groupHeaderFormatter = null,
        ?callable $groupFooterFormatter = null,
        ?callable $emptyFormatter = null,
    ): self {
        return $this->tableRecordsPanelFrame(
            $records,
            $columns,
            $this->contentFrame($margins),
            $panelStyle,
            $style,
            $header,
            $columnFilter,
            $recordSorter,
            $rowFormatter,
            $footerFormatter,
            $groupResolver,
            $groupHeaderFormatter,
            $groupFooterFormatter,
            $emptyFormatter,
        );
    }

    public function line(
        float $x1,
        float $y1,
        float $x2,
        float $y2,
        float $width = 1.0,
        ?Color $strokeColor = null
    ): self {
        $this->requirePage()->addLine(new Line($x1, $y1, $x2, $y2, $width, $strokeColor));

        return $this;
    }

    public function rectangle(Rectangle $rectangle): self
    {
        $this->requirePage()->addRectangle($rectangle);

        return $this;
    }

    /**
     * @param list<float> $box
     */
    public function pageBox(string $name, array $box): self
    {
        $this->requirePage()->setPageBox($name, $box);

        return $this;
    }

    /**
     * @param list<float> $box
     */
    public function cropBox(array $box): self
    {
        $this->requirePage()->setCropBox($box);

        return $this;
    }

    /**
     * @param list<float> $box
     */
    public function bleedBox(array $box): self
    {
        $this->requirePage()->setBleedBox($box);

        return $this;
    }

    /**
     * @param list<float> $box
     */
    public function trimBox(array $box): self
    {
        $this->requirePage()->setTrimBox($box);

        return $this;
    }

    /**
     * @param list<float> $box
     */
    public function artBox(array $box): self
    {
        $this->requirePage()->setArtBox($box);

        return $this;
    }

    public function image(ImagePlacement $image): self
    {
        $this->requirePage()->addImage($image);

        return $this;
    }

    public function formField(FormField $field): self
    {
        $this->requirePage()->addFormField($field);

        return $this;
    }

    public function flattenGeneratedFormFields(): self
    {
        $this->document->flattenGeneratedFormFields();

        return $this;
    }

    public function textAnnotation(TextAnnotation $annotation): self
    {
        $this->requirePage()->addTextAnnotation($annotation);

        return $this;
    }

    public function linkAnnotation(LinkAnnotation $annotation): self
    {
        $this->requirePage()->addLinkAnnotation($annotation);

        return $this;
    }

    public function outline(
        string $title,
        int $pageNumber,
        int $level = 0,
        ?float $left = null,
        ?float $top = null,
        ?float $zoom = null,
    ): self
    {
        $this->document->addOutline($title, $pageNumber, $level, $left, $top, $zoom);

        return $this;
    }

    public function namedDestination(
        string $name,
        int $pageNumber,
        ?float $left = null,
        ?float $top = null,
        ?float $zoom = null,
    ): self {
        $this->document->addNamedDestination($name, $pageNumber, $left, $top, $zoom);

        return $this;
    }

    public function pageLabel(
        int $startPage,
        ?string $style = PageLabelRange::DECIMAL,
        ?string $prefix = null,
        int $startNumber = 1,
    ): self {
        $this->document->addPageLabel($startPage, $style, $prefix, $startNumber);

        return $this;
    }

    public function viewerPreferences(ViewerPreferences $viewerPreferences): self
    {
        $this->document->setViewerPreferences($viewerPreferences);

        return $this;
    }

    public function pageLayout(string $pageLayout): self
    {
        $this->document->setPageLayout($pageLayout);

        return $this;
    }

    public function pageMode(string $pageMode): self
    {
        $this->document->setPageMode($pageMode);

        return $this;
    }

    public function language(string $language): self
    {
        $this->document->setLanguage($language);

        return $this;
    }

    public function uriBase(string $uriBase): self
    {
        $this->document->setUriBase($uriBase);

        return $this;
    }

    public function markInfo(MarkInfo $markInfo): self
    {
        $this->document->setMarkInfo($markInfo);

        return $this;
    }

    public function openAction(OpenAction $openAction): self
    {
        $this->document->setOpenAction($openAction);

        return $this;
    }

    public function endPage(): self
    {
        $this->currentPage = null;

        return $this;
    }

    public function build(): Document
    {
        return $this->document;
    }

    /**
     * @return list<string>
     */
    private function flowParagraphs(string $text): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $paragraphs = preg_split("/\n{2,}/", $normalized) ?: [''];
        $result = [];

        foreach ($paragraphs as $paragraph) {
            $result[] = trim($paragraph);
        }

        return $result === [] ? [''] : $result;
    }

    private function resolveTableRecordFieldValue(array|object $record, string $field): mixed
    {
        $value = $record;

        foreach (explode('.', $field) as $segment) {
            if (is_array($value)) {
                $value = $value[$segment] ?? null;
                continue;
            }

            if (is_object($value)) {
                $value = isset($value->{$segment}) ? $value->{$segment} : null;
                continue;
            }

            return null;
        }

        return $value;
    }

    private function normalizeTableRecordCell(mixed $value): TableCell|string
    {
        if ($value instanceof TableCell) {
            return $value;
        }

        return $this->normalizeTableRecordString($value);
    }

    private function normalizeTableRecordString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @return list<array<int, TableCell|string>>
     */
    private function normalizeRecordFormatterRows(mixed $value): array
    {
        if ($value === null || $value === []) {
            return [];
        }

        if (!is_array($value)) {
            throw new PdfException('Record table row formatter must return null, a row, or a list of rows.');
        }

        $first = reset($value);

        if ($first instanceof TableCell || is_string($first)) {
            /** @var array<int, TableCell|string> $value */
            return [$value];
        }

        $rows = [];

        foreach ($value as $row) {
            if (!is_array($row) || $row === []) {
                throw new PdfException('Record table row formatter must return only non-empty rows.');
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function wrapFlowParagraph(
        string $paragraph,
        float $width,
        float $fontSize,
        ?FontReference $font,
        TextMeasurer $measurer,
    ): array {
        $segments = preg_split("/\n/", $paragraph) ?: [''];
        $lines = [];
        $widthCache = [];
        $measureWidth = static function (string $value) use (&$widthCache, $measurer, $fontSize, $font): float {
            return $widthCache[$value] ??= $measurer->width($value, $fontSize, $font);
        };

        foreach ($segments as $segmentIndex => $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                if ($segmentIndex === count($segments) - 1 && $lines !== []) {
                    continue;
                }

                $lines[] = '';
                continue;
            }

            $currentLine = '';

            foreach (preg_split('/\s+/', $segment) ?: [] as $word) {
                $candidate = $currentLine === '' ? $word : $currentLine . ' ' . $word;

                if ($measureWidth($candidate) <= $width) {
                    $currentLine = $candidate;
                    continue;
                }

                if ($currentLine !== '') {
                    $lines[] = $currentLine;
                    $currentLine = '';
                }

                foreach ($this->splitFlowWord($word, $width, $measureWidth) as $chunk) {
                    if ($measureWidth($chunk) <= $width) {
                        $currentLine = $chunk;
                        continue;
                    }

                    $lines[] = $chunk;
                }
            }

            if ($currentLine !== '') {
                $lines[] = $currentLine;
            }
        }

        return $lines === [] ? [''] : $lines;
    }

    /**
     * @return list<string>
     */
    private function splitFlowWord(
        string $word,
        float $width,
        callable $measureWidth,
    ): array {
        if ($word === '') {
            return [''];
        }

        if ($measureWidth($word) <= $width) {
            return [$word];
        }

        $chunks = [];
        $current = '';

        foreach (mb_str_split($word) as $character) {
            $candidate = $current . $character;

            if ($current !== '' && $measureWidth($candidate) > $width) {
                $chunks[] = $current;
                $current = $character;
                continue;
            }

            $current = $candidate;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function addFlowContinuationPage(Page $template): Page
    {
        $page = new Page(
            $template->width(),
            $template->height(),
            $template->rotation(),
            $template->pageBoxes(),
        );
        $page->setRotation($template->rotation());
        $this->document->addPage($page);
        $this->currentPage = new PageBuilder($page, $this);

        return $page;
    }

    /**
     * @param list<TextFrame> $frames
     */
    private function renderTextInFrames(
        Page $page,
        array $frames,
        string $text,
        float $fontSize,
        ?FontReference $font,
        ?Color $color,
        float $lineHeight,
        float $paragraphSpacing,
        ?callable $onFrameStart = null,
    ): void {
        $lineAdvance = $fontSize * $lineHeight;
        $paragraphAdvance = $fontSize * $paragraphSpacing;
        $measurer = $this->textMeasurer();
        $currentPage = $page;
        $frameIndex = 0;
        $currentFrame = $frames[$frameIndex];
        $currentY = $currentFrame->y;
        $paragraphs = $this->flowParagraphs($text);

        if ($onFrameStart !== null) {
            $onFrameStart($currentPage, $frameIndex);
        }

        foreach ($paragraphs as $paragraphIndex => $paragraph) {
            foreach ($this->wrapFlowParagraph($paragraph, $currentFrame->width, $fontSize, $font, $measurer) as $line) {
                if ($currentY + $fontSize > $currentFrame->bottom()) {
                    [$currentPage, $frameIndex, $currentFrame, $currentY] = $this->advanceTextFlowFrame(
                        $currentPage,
                        $frames,
                        $frameIndex,
                        $onFrameStart,
                    );
                }

                $currentPage->addText(new TextRun($line, $currentFrame->x, $currentY, $fontSize, $font, $color));
                $currentY += $lineAdvance;
            }

            if ($paragraphIndex === array_key_last($paragraphs)) {
                continue;
            }

            $currentY += $paragraphAdvance;
        }
    }

    /**
     * @param list<TextFrame> $frames
     * @param list<TextBlock> $blocks
     */
    private function renderTextBlocksInFrames(
        Page $page,
        array $frames,
        array $blocks,
        ?callable $onFrameStart = null,
    ): void {
        $currentPage = $page;
        $frameIndex = 0;
        $currentFrame = $frames[$frameIndex];
        $currentY = $currentFrame->y;

        if ($onFrameStart !== null) {
            $onFrameStart($currentPage, $frameIndex);
        }

        foreach ($blocks as $block) {
            [$currentPage, $frameIndex, $currentFrame, $currentY] = $this->renderTextBlockInFrames(
                $currentPage,
                $frames,
                $frameIndex,
                $currentY,
                $block,
                $onFrameStart,
            );

            $currentY += $block->spacingAfter;

            while ($currentY > $currentFrame->bottom()) {
                [$currentPage, $frameIndex, $currentFrame, $currentY] = $this->advanceTextFlowFrame(
                    $currentPage,
                    $frames,
                    $frameIndex,
                    $onFrameStart,
                );
            }
        }
    }

    /**
     * @param list<TextFrame> $frames
     * @return array{0: Page, 1: int, 2: TextFrame, 3: float}
     */
    private function renderTextBlockInFrames(
        Page $page,
        array $frames,
        int $frameIndex,
        float $startY,
        TextBlock $block,
        ?callable $onFrameStart = null,
    ): array {
        $lineAdvance = $block->fontSize * $block->lineHeight;
        $paragraphAdvance = $block->fontSize * $block->paragraphSpacing;
        $measurer = $this->textMeasurer();
        $currentPage = $page;
        $currentFrameIndex = $frameIndex;
        $currentFrame = $frames[$currentFrameIndex];
        $currentY = $startY;
        $paragraphs = $this->flowParagraphs($block->text);

        foreach ($paragraphs as $paragraphIndex => $paragraph) {
            foreach ($this->wrapFlowParagraph($paragraph, $currentFrame->width, $block->fontSize, $block->font, $measurer) as $line) {
                if ($currentY + $block->fontSize > $currentFrame->bottom()) {
                    [$currentPage, $currentFrameIndex, $currentFrame, $currentY] = $this->advanceTextFlowFrame(
                        $currentPage,
                        $frames,
                        $currentFrameIndex,
                        $onFrameStart,
                    );
                }

                $currentPage->addText(new TextRun(
                    $line,
                    $currentFrame->x,
                    $currentY,
                    $block->fontSize,
                    $block->font,
                    $block->color,
                ));
                $currentY += $lineAdvance;
            }

            if ($paragraphIndex === array_key_last($paragraphs)) {
                continue;
            }

            $currentY += $paragraphAdvance;
        }

        return [$currentPage, $currentFrameIndex, $currentFrame, $currentY];
    }

    /**
     * @param list<TextFrame> $frames
     * @return array{0: Page, 1: int, 2: TextFrame, 3: float}
     */
    private function advanceTextFlowFrame(
        Page $page,
        array $frames,
        int $frameIndex,
        ?callable $onFrameStart = null,
    ): array
    {
        $nextIndex = $frameIndex + 1;

        if ($nextIndex < count($frames)) {
            $nextFrame = $frames[$nextIndex];

            if ($onFrameStart !== null) {
                $onFrameStart($page, $nextIndex);
            }

            return [$page, $nextIndex, $nextFrame, $nextFrame->y];
        }

        $nextPage = $this->addFlowContinuationPage($page);
        $firstFrame = $frames[0];

        if ($onFrameStart !== null) {
            $onFrameStart($nextPage, 0);
        }

        return [$nextPage, 0, $firstFrame, $firstFrame->y];
    }

    /**
     * @param list<TextFrame> $frames
     * @return array{0: Page, 1: int, 2: TextFrame, 3: float}
     */
    private function advanceTableFrame(
        Page $page,
        array $frames,
        int $frameIndex,
        ?callable $onFrameStart = null,
    ): array
    {
        $nextIndex = $frameIndex + 1;

        if ($nextIndex < count($frames)) {
            $nextFrame = $frames[$nextIndex];

            if ($onFrameStart !== null) {
                $onFrameStart($page, $nextIndex);
            }

            return [$page, $nextIndex, $nextFrame, $nextFrame->y];
        }

        $nextPage = $this->addFlowContinuationPage($page);
        $firstFrame = $frames[0];

        if ($onFrameStart !== null) {
            $onFrameStart($nextPage, 0);
        }

        return [$nextPage, 0, $firstFrame, $firstFrame->y];
    }

    /**
     * @return array{0: Page, 1: float}
     */
    private function renderTextInFrame(
        Page $page,
        TextFrame $frame,
        float $startY,
        string $text,
        float $fontSize,
        ?FontReference $font,
        ?Color $color,
        float $lineHeight,
        float $paragraphSpacing,
        ?callable $onPageStart = null,
    ): array {
        $lineAdvance = $fontSize * $lineHeight;
        $paragraphAdvance = $fontSize * $paragraphSpacing;
        $measurer = $this->textMeasurer();
        $currentPage = $page;
        $currentY = $startY;
        $paragraphs = $this->flowParagraphs($text);

        foreach ($paragraphs as $paragraphIndex => $paragraph) {
            foreach ($this->wrapFlowParagraph($paragraph, $frame->width, $fontSize, $font, $measurer) as $line) {
                if ($currentY + $fontSize > $frame->bottom()) {
                    $currentPage = $this->addFlowContinuationPage($currentPage);
                    if ($onPageStart !== null) {
                        $onPageStart($currentPage);
                    }
                    $currentY = $frame->y;
                }

                $currentPage->addText(new TextRun($line, $frame->x, $currentY, $fontSize, $font, $color));
                $currentY += $lineAdvance;
            }

            if ($paragraphIndex === array_key_last($paragraphs)) {
                continue;
            }

            $currentY += $paragraphAdvance;
        }

        return [$currentPage, $currentY];
    }

    private function drawPanel(Page $page, TextFrame $frame, PanelStyle $style): void
    {
        $page->addRectangle(new Rectangle(
            $frame->x,
            $frame->y,
            $frame->width,
            $frame->height,
            strokeColor: $style->strokeColor,
            fillColor: $style->fillColor,
            lineWidth: $style->lineWidth,
        ));
    }

    /**
     * @param list<int|float|TableColumn> $columns
     * @return list<TableColumn>
     */
    private function normalizeTableColumns(array $columns): array
    {
        $normalized = [];

        foreach ($columns as $column) {
            if ($column instanceof TableColumn) {
                $normalized[] = $column;
                continue;
            }

            if (is_int($column) || is_float($column)) {
                $normalized[] = new TableColumn((float) $column);
                continue;
            }

            throw new PdfException('Table columns must be numeric widths or TableColumn instances.');
        }

        return $normalized;
    }

    /**
     * @param list<TableColumn> $columns
     */
    private function validateTableFrame(TextFrame $frame, array $columns, TableStyle $style): void
    {
        if ($columns === []) {
            throw new PdfException('Tables require at least one column width.');
        }

        $totalWidth = 0.0;

        foreach ($columns as $column) {
            $width = $column->width;
            if ($width <= 0.0) {
                throw new PdfException('Table column widths must be greater than zero.');
            }

            if ($width <= $style->cellPadding->left + $style->cellPadding->right) {
                throw new PdfException('Table column width must exceed left and right cell padding.');
            }

            $totalWidth += $width;
        }

        if ($totalWidth > $frame->width) {
            throw new PdfException('Table columns exceed the target frame width.');
        }
    }

    /**
     * @return list<list<array{cell: TableCell, startColumn: int, rowIndex: int}>>
     */
    private function normalizeTableRows(array $rows, array $columnWidths): array
    {
        $normalized = [];
        $columnCount = count($columnWidths);
        $activeRowspans = array_fill(0, $columnCount, 0);

        foreach ($rows as $rowIndex => $row) {
            if (!is_array($row) || $row === []) {
                throw new PdfException('Each table row must be a non-empty array.');
            }

            $normalizedRow = [];
            $columnIndex = 0;
            $occupiedColumns = 0;

            foreach ($activeRowspans as $span) {
                if ($span > 0) {
                    $occupiedColumns++;
                }
            }

            foreach ($row as $cell) {
                if ($cell instanceof TableCell) {
                    $normalizedCell = $cell;
                } elseif (is_string($cell)) {
                    $normalizedCell = new TableCell($cell);
                } else {
                    throw new PdfException('Table cells must be strings or TableCell instances.');
                }

                while ($columnIndex < $columnCount && $activeRowspans[$columnIndex] > 0) {
                    $columnIndex++;
                }

                if ($columnIndex >= $columnCount) {
                    throw new PdfException(sprintf(
                        'Each table row must occupy exactly %d columns after colspan/rowspan is applied.',
                        $columnCount
                    ));
                }

                for ($offset = 0; $offset < $normalizedCell->colspan; $offset++) {
                    $targetColumn = $columnIndex + $offset;

                    if (!isset($activeRowspans[$targetColumn])) {
                        throw new PdfException('Table cell colspan extends past the available columns.');
                    }

                    if ($activeRowspans[$targetColumn] > 0) {
                        throw new PdfException('Table cell overlap detected while applying rowspan.');
                    }
                }

                $normalizedRow[] = [
                    'cell' => $normalizedCell,
                    'startColumn' => $columnIndex,
                    'rowIndex' => $rowIndex,
                ];
                $occupiedColumns += $normalizedCell->colspan;

                if ($normalizedCell->rowspan > 1) {
                    for ($offset = 0; $offset < $normalizedCell->colspan; $offset++) {
                        $activeRowspans[$columnIndex + $offset] = $normalizedCell->rowspan;
                    }
                }

                $columnIndex += $normalizedCell->colspan;
            }

            if ($occupiedColumns !== $columnCount) {
                throw new PdfException(sprintf(
                    'Each table row must occupy exactly %d columns after colspan/rowspan is applied.',
                    $columnCount
                ));
            }

            $normalized[] = $normalizedRow;

            foreach ($activeRowspans as $index => $span) {
                if ($span > 0) {
                    $activeRowspans[$index]--;
                }
            }
        }

        return $normalized;
    }

    /**
     * @param list<list<array{cell: TableCell, startColumn: int, rowIndex: int}>> $rows
     * @param list<TableColumn> $columns
     * @return list<float>
     */
    private function tableRowHeights(array $rows, array $columnWidths, array $columns, TableStyle $style): array
    {
        $rowHeights = array_fill(0, count($rows), 0.0);
        $spanningPlacements = [];

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $placement) {
                $cell = $placement['cell'];
                $column = $columns[$placement['startColumn']];
                $effectivePadding = $column->padding ?? $style->cellPadding;
                $cellWidth = $this->tableCellWidth($columnWidths, $placement['startColumn'], $cell->colspan);
                $innerWidth = $cellWidth - $effectivePadding->left - $effectivePadding->right;
                $effectiveFontSize = $cell->fontSize ?? $column->fontSize ?? 12.0;
                $effectiveLineHeight = $cell->lineHeight ?? $column->lineHeight ?? 1.2;
                $effectiveParagraphSpacing = $cell->paragraphSpacing ?? $column->paragraphSpacing ?? 0.35;
                $layout = $this->layoutWrappedText(
                    $cell->text,
                    $innerWidth,
                    $effectiveFontSize,
                    $cell->font ?? $column->font,
                    $effectiveLineHeight,
                    $effectiveParagraphSpacing,
                );
                $height = $effectivePadding->top + $layout['height'] + $effectivePadding->bottom;

                if ($cell->rowspan === 1) {
                    $rowHeights[$rowIndex] = max($rowHeights[$rowIndex], $height);
                    continue;
                }

                $spanningPlacements[] = [
                    'rowIndex' => $rowIndex,
                    'rowspan' => $cell->rowspan,
                    'requiredHeight' => $height,
                ];
            }
        }

        foreach ($spanningPlacements as $placement) {
            $spanHeight = 0.0;

            for ($offset = 0; $offset < $placement['rowspan']; $offset++) {
                $targetRow = $placement['rowIndex'] + $offset;

                if (!isset($rowHeights[$targetRow])) {
                    throw new PdfException('Table cell rowspan extends past the available rows.');
                }

                $spanHeight += $rowHeights[$targetRow];
            }

            if ($spanHeight < $placement['requiredHeight']) {
                $rowHeights[$placement['rowIndex'] + $placement['rowspan'] - 1] += $placement['requiredHeight'] - $spanHeight;
            }
        }

        return $rowHeights;
    }

    /**
     * @param list<list<array{cell: TableCell, startColumn: int, rowIndex: int}>> $rows
     * @return list<array{start: int, end: int}>
     */
    private function tableRowGroups(array $rows): array
    {
        $groups = [];
        $rowIndex = 0;

        while ($rowIndex < count($rows)) {
            $groupEnd = $rowIndex;

            for ($scan = $rowIndex; $scan <= $groupEnd; $scan++) {
                foreach ($rows[$scan] as $placement) {
                    $groupEnd = max($groupEnd, $placement['rowIndex'] + $placement['cell']->rowspan - 1);
                }
            }

            $groups[] = [
                'start' => $rowIndex,
                'end' => $groupEnd,
            ];
            $rowIndex = $groupEnd + 1;
        }

        return $groups;
    }

    private function tableGroupHeight(array $rowHeights, int $startRow, int $endRow): float
    {
        $height = 0.0;

        for ($rowIndex = $startRow; $rowIndex <= $endRow; $rowIndex++) {
            $height += $rowHeights[$rowIndex] ?? 0.0;
        }

        return $height;
    }

    /**
     * @param list<list<array{cell: TableCell, startColumn: int, rowIndex: int}>> $rows
     * @param list<float> $rowHeights
     * @param list<float> $columnWidths
     * @param list<TableColumn> $columns
     * @return array{0: Page, 1: float}
     */
    private function renderTableGroup(
        Page $page,
        TextFrame $frame,
        float $startY,
        array $rows,
        array $rowHeights,
        array $columnWidths,
        array $columns,
        TableStyle $style,
        int $baseRowIndex,
        callable $rowFillResolver,
    ): array {
        $currentY = $startY;
        $rowOffsets = [];

        foreach ($rowHeights as $relativeIndex => $rowHeight) {
            $rowOffsets[$baseRowIndex + $relativeIndex] = $currentY;
            $currentY += $rowHeight;
        }

        $measurer = $this->textMeasurer();

        foreach ($rows as $relativeRowIndex => $row) {
            $absoluteRowIndex = $baseRowIndex + $relativeRowIndex;

            foreach ($row as $placement) {
                $cell = $placement['cell'];
                $x = $frame->x;

                for ($column = 0; $column < $placement['startColumn']; $column++) {
                    $x += $columns[$column]->width;
                }

                $width = $this->tableCellWidth($columnWidths, $placement['startColumn'], $cell->colspan);
                $height = $this->tableGroupHeight(
                    $rowHeights,
                    $placement['rowIndex'],
                    $placement['rowIndex'] + $cell->rowspan - 1
                );
                $column = $columns[$placement['startColumn']];
                $effectivePadding = $column->padding ?? $style->cellPadding;
                $cellBorderColor = $cell->borderColor ?? $column->borderColor ?? $style->borderColor;
                $cellFillColor = $cell->fillColor ?? $column->fillColor ?? $rowFillResolver($absoluteRowIndex);
                $cellLineWidth = $cell->lineWidth ?? $column->lineWidth ?? $style->lineWidth;
                $effectiveFontSize = $cell->fontSize ?? $column->fontSize ?? 12.0;
                $effectiveLineHeight = $cell->lineHeight ?? $column->lineHeight ?? 1.2;
                $effectiveParagraphSpacing = $cell->paragraphSpacing ?? $column->paragraphSpacing ?? 0.35;
                $y = $rowOffsets[$placement['rowIndex']];

                $page->addRectangle(new Rectangle(
                    $x,
                    $y,
                    $width,
                    $height,
                    strokeColor: $cellBorderColor,
                    fillColor: $cellFillColor,
                    lineWidth: $cellLineWidth,
                ));

                $layout = $this->layoutWrappedText(
                    $cell->text,
                    $width - $effectivePadding->left - $effectivePadding->right,
                    $effectiveFontSize,
                    $cell->font ?? $column->font,
                    $effectiveLineHeight,
                    $effectiveParagraphSpacing,
                );
                $effectiveAlign = $cell->align ?? $column->align ?? TableCell::ALIGN_LEFT;
                $effectiveValign = $cell->valign ?? $column->valign ?? TableCell::VALIGN_TOP;
                $effectiveFont = $cell->font ?? $column->font;
                $effectiveColor = $cell->color ?? $column->color;
                $innerHeight = $height - $effectivePadding->top - $effectivePadding->bottom;
                $verticalOffset = 0.0;

                if ($effectiveValign === TableCell::VALIGN_MIDDLE) {
                    $verticalOffset = max(0.0, ($innerHeight - $layout['height']) / 2.0);
                } elseif ($effectiveValign === TableCell::VALIGN_BOTTOM) {
                    $verticalOffset = max(0.0, $innerHeight - $layout['height']);
                }

                foreach ($layout['lines'] as $lineIndex => $line) {
                    $innerWidth = $width - $effectivePadding->left - $effectivePadding->right;
                    $lineWidth = $measurer->width($line, $effectiveFontSize, $effectiveFont);
                    $textX = $x + $effectivePadding->left;

                    if ($effectiveAlign === TableCell::ALIGN_CENTER) {
                        $textX = $x + $effectivePadding->left + max(0.0, ($innerWidth - $lineWidth) / 2.0);
                    } elseif ($effectiveAlign === TableCell::ALIGN_RIGHT) {
                        $textX = $x + $width - $effectivePadding->right - $lineWidth;
                    }

                    $page->addText(new TextRun(
                        $line,
                        $textX,
                        $y + $effectivePadding->top + $verticalOffset + $layout['offsets'][$lineIndex],
                        $effectiveFontSize,
                        $effectiveFont,
                        $effectiveColor,
                    ));
                }
            }
        }

        return [$page, $currentY];
    }

    /**
     * @param list<array{cell: TableCell, startColumn: int, rowIndex: int}> $row
     * @param list<TableColumn> $columns
     */
    private function tableRowHeight(array $row, array $columnWidths, array $columns, TableStyle $style): float
    {
        $maxHeight = 0.0;

        foreach ($row as $placement) {
            $cell = $placement['cell'];
            $column = $columns[$placement['startColumn']];
            $effectivePadding = $column->padding ?? $style->cellPadding;
            $cellWidth = $this->tableCellWidth($columnWidths, $placement['startColumn'], $cell->colspan);
            $innerWidth = $cellWidth - $effectivePadding->left - $effectivePadding->right;
            $effectiveFontSize = $cell->fontSize ?? $column->fontSize ?? 12.0;
            $effectiveLineHeight = $cell->lineHeight ?? $column->lineHeight ?? 1.2;
            $effectiveParagraphSpacing = $cell->paragraphSpacing ?? $column->paragraphSpacing ?? 0.35;
            $layout = $this->layoutWrappedText(
                $cell->text,
                $innerWidth,
                $effectiveFontSize,
                $cell->font ?? $column->font,
                $effectiveLineHeight,
                $effectiveParagraphSpacing,
            );

            $height = $effectivePadding->top + $layout['height'] + $effectivePadding->bottom;
            $maxHeight = max($maxHeight, $height);
        }

        return $maxHeight;
    }

    private function tableCellWidth(array $columnWidths, int $columnIndex, int $colspan): float
    {
        $width = 0.0;

        for ($offset = 0; $offset < $colspan; $offset++) {
            if (!isset($columnWidths[$columnIndex + $offset])) {
                throw new PdfException('Table cell colspan extends past the available columns.');
            }

            $width += $columnWidths[$columnIndex + $offset];
        }

        return $width;
    }

    /**
     * @return array{lines: list<string>, offsets: list<float>, height: float}
     */
    private function layoutWrappedText(
        string $text,
        float $width,
        float $fontSize,
        ?FontReference $font,
        float $lineHeight,
        float $paragraphSpacing,
    ): array {
        $cacheKey = $this->wrappedTextLayoutCacheKey(
            $text,
            $width,
            $fontSize,
            $font,
            $lineHeight,
            $paragraphSpacing,
        );

        if (isset($this->wrappedTextLayouts[$cacheKey])) {
            return $this->wrappedTextLayouts[$cacheKey];
        }

        $lineAdvance = $fontSize * $lineHeight;
        $paragraphAdvance = $fontSize * $paragraphSpacing;
        $measurer = $this->textMeasurer();
        $paragraphs = $this->flowParagraphs($text);
        $lines = [];
        $offsets = [];
        $currentY = 0.0;

        foreach ($paragraphs as $paragraphIndex => $paragraph) {
            foreach ($this->wrapFlowParagraph($paragraph, $width, $fontSize, $font, $measurer) as $line) {
                $lines[] = $line;
                $offsets[] = $currentY;
                $currentY += $lineAdvance;
            }

            if ($paragraphIndex === array_key_last($paragraphs)) {
                continue;
            }

            $currentY += $paragraphAdvance;
        }

        return $this->wrappedTextLayouts[$cacheKey] = [
            'lines' => $lines === [] ? [''] : $lines,
            'offsets' => $offsets === [] ? [0.0] : $offsets,
            'height' => max($fontSize, $currentY === 0.0 ? $fontSize : $currentY),
        ];
    }

    private function wrappedTextLayoutCacheKey(
        string $text,
        float $width,
        float $fontSize,
        ?FontReference $font,
        float $lineHeight,
        float $paragraphSpacing,
    ): string {
        $fontKey = $font === null
            ? 'default'
            : implode('|', [
                $font->family,
                $font->style,
                $font->sourcePath ?? '',
                (string) $font->faceIndex,
            ]);

        return implode('|', [
            $fontKey,
            sprintf('%.6F', $width),
            sprintf('%.6F', $fontSize),
            sprintf('%.6F', $lineHeight),
            sprintf('%.6F', $paragraphSpacing),
            sha1($text),
        ]);
    }

    private function textMeasurer(): TextMeasurer
    {
        return $this->textMeasurer ??= new TextMeasurer();
    }

    private function requirePage(): Page
    {
        if ($this->currentPage === null) {
            throw new PdfException('No current page exists. Call addPage() before adding content.');
        }

        return $this->currentPage->page();
    }
}
