<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Annotations\LinkAnnotation;
use PdfToolkit\Annotations\TextAnnotation;
use PdfToolkit\Core\PdfException;
use PdfToolkit\Pdf;
use PdfToolkit\Forms\FormField;
use PdfToolkit\Graphics\Color;
use PdfToolkit\Graphics\Rectangle;
use PdfToolkit\Image\ImagePlacement;
use PdfToolkit\Image\ImageReader;
use PdfToolkit\Layout\PageMargins;
use PdfToolkit\Layout\PanelStyle;
use PdfToolkit\Layout\TableCell;
use PdfToolkit\Layout\TableColumn;
use PdfToolkit\Layout\TableDataColumn;
use PdfToolkit\Layout\TableStyle;
use PdfToolkit\Layout\TextBlock;
use PdfToolkit\Layout\TextFrame;
use PdfToolkit\Text\FontReference;
use PdfToolkit\Text\TextRun;
use PdfToolkit\Text\TrueTypeFontParser;
use PHPUnit\Framework\TestCase;

final class DocumentBuilderTest extends TestCase
{
    public function testBuildsSinglePageDocument(): void
    {
        $document = Pdf::new()
            ->metadata(title: 'Spec')
            ->addPage()
            ->text(new TextRun('Hello', 72, 720))
            ->endPage()
            ->build();

        $this->assertCount(1, $document->pages());
        $this->assertSame('Spec', $document->metadata()->title);
    }

    public function testFlowTextWrapsIntoMultipleLines(): void
    {
        $width = Pdf::measureText('One', 12.0) + 1.0;

        $document = Pdf::new()
            ->addPage(width: 200, height: 200)
            ->flowText('One Two', 10, 10, $width, fontSize: 12.0)
            ->endPage()
            ->build();

        $texts = $document->page(0)->texts();

        $this->assertCount(2, $texts);
        $this->assertSame('One', $texts[0]->text);
        $this->assertSame('Two', $texts[1]->text);
        $this->assertEqualsWithDelta(24.4, $texts[1]->y, 0.001);
    }

    public function testFlowTextContinuesOntoNewPages(): void
    {
        $document = Pdf::new()
            ->addPage(width: 200, height: 50)
            ->flowText(
                "One\nTwo\nThree",
                10,
                10,
                100,
                fontSize: 20.0,
                lineHeight: 1.0,
                topMargin: 10.0,
                bottomMargin: 10.0,
            )
            ->endPage()
            ->build();

        $this->assertCount(3, $document->pages());
        $this->assertSame('One', $document->page(0)->texts()[0]->text);
        $this->assertSame('Two', $document->page(1)->texts()[0]->text);
        $this->assertSame('Three', $document->page(2)->texts()[0]->text);
        $this->assertSame(10.0, $document->page(1)->texts()[0]->y);
    }

    public function testFlowTextFrameWrapsWithinFrameWidth(): void
    {
        $frame = new TextFrame(20, 30, Pdf::measureText('One', 12.0) + 1.0, 80);

        $document = Pdf::new()
            ->addPage(width: 200, height: 200)
            ->flowTextFrame('One Two', $frame, fontSize: 12.0)
            ->endPage()
            ->build();

        $texts = $document->page(0)->texts();

        $this->assertCount(2, $texts);
        $this->assertSame('One', $texts[0]->text);
        $this->assertSame(20.0, $texts[0]->x);
        $this->assertSame(30.0, $texts[0]->y);
        $this->assertSame('Two', $texts[1]->text);
        $this->assertEqualsWithDelta(44.4, $texts[1]->y, 0.001);
    }

    public function testFlowTextFrameContinuesIntoMatchingFrameOnNewPages(): void
    {
        $frame = new TextFrame(20, 8, 100, 22);

        $document = Pdf::new()
            ->addPage(width: 200, height: 60)
            ->flowTextFrame(
                "One\nTwo\nThree",
                $frame,
                fontSize: 12.0,
                lineHeight: 1.0,
            )
            ->endPage()
            ->build();

        $this->assertCount(3, $document->pages());
        $this->assertCount(1, $document->page(0)->texts());
        $this->assertCount(1, $document->page(1)->texts());
        $this->assertCount(1, $document->page(2)->texts());
        $this->assertSame('Three', $document->page(2)->texts()[0]->text);
        $this->assertSame(20.0, $document->page(2)->texts()[0]->x);
        $this->assertSame(8.0, $document->page(2)->texts()[0]->y);
    }

    public function testContentFrameUsesCurrentPageMargins(): void
    {
        $builder = Pdf::new()
            ->addPage(width: 200, height: 300);

        $frame = $builder->contentFrame(PageMargins::symmetric(20, 30));

        $this->assertSame(30.0, $frame->x);
        $this->assertSame(20.0, $frame->y);
        $this->assertSame(140.0, $frame->width);
        $this->assertSame(260.0, $frame->height);
    }

    public function testFlowTextContentFrameUsesDerivedPageContentFrame(): void
    {
        $document = Pdf::new()
            ->addPage(width: 200, height: 200)
            ->flowTextContentFrame(
                'One Two',
                PageMargins::all(20),
                fontSize: 12.0,
            )
            ->endPage()
            ->build();

        $texts = $document->page(0)->texts();

        $this->assertCount(1, $texts);
        $this->assertSame('One Two', $texts[0]->text);
        $this->assertSame(20.0, $texts[0]->x);
        $this->assertSame(20.0, $texts[0]->y);
    }

    public function testColumnFramesDeriveEqualWidthColumnsFromContentFrame(): void
    {
        $builder = Pdf::new()
            ->addPage(width: 240, height: 300);

        $frames = $builder->columnFrames(PageMargins::all(20), 2, 10);

        $this->assertCount(2, $frames);
        $this->assertSame(20.0, $frames[0]->x);
        $this->assertSame(20.0, $frames[0]->y);
        $this->assertSame(95.0, $frames[0]->width);
        $this->assertSame(125.0, $frames[1]->x);
        $this->assertSame(260.0, $frames[1]->height);
    }

    public function testFlowTextFramesContinuesAcrossMultipleFramesBeforeNewPage(): void
    {
        $document = Pdf::new()
            ->addPage(width: 220, height: 80)
            ->flowTextFrames(
                "One\nTwo\nThree\nFour\nFive",
                [
                    new TextFrame(20, 8, 60, 22),
                    new TextFrame(110, 8, 60, 22),
                ],
                fontSize: 12.0,
                lineHeight: 1.0,
            )
            ->endPage()
            ->build();

        $this->assertCount(3, $document->pages());
        $this->assertSame('One', $document->page(0)->texts()[0]->text);
        $this->assertSame('Two', $document->page(0)->texts()[1]->text);
        $this->assertSame(20.0, $document->page(0)->texts()[0]->x);
        $this->assertSame(110.0, $document->page(0)->texts()[1]->x);
        $this->assertSame('Three', $document->page(1)->texts()[0]->text);
        $this->assertSame('Four', $document->page(1)->texts()[1]->text);
        $this->assertSame('Five', $document->page(2)->texts()[0]->text);
    }

    public function testFlowTextColumnsUsesDerivedColumnFrames(): void
    {
        $document = Pdf::new()
            ->addPage(width: 240, height: 52)
            ->flowTextColumns(
                "One\nTwo\nThree",
                PageMargins::all(20),
                2,
                gap: 10,
                fontSize: 12.0,
                lineHeight: 1.0,
            )
            ->endPage()
            ->build();

        $this->assertCount(2, $document->pages());
        $this->assertSame('One', $document->page(0)->texts()[0]->text);
        $this->assertSame(20.0, $document->page(0)->texts()[0]->x);
        $this->assertSame('Two', $document->page(0)->texts()[1]->text);
        $this->assertSame(125.0, $document->page(0)->texts()[1]->x);
        $this->assertSame('Three', $document->page(1)->texts()[0]->text);
        $this->assertSame(20.0, $document->page(1)->texts()[0]->x);
    }

    public function testFlowTextPanelDrawsPanelAndPlacesContentInsidePadding(): void
    {
        $document = Pdf::new()
            ->addPage(width: 200, height: 200)
            ->flowTextPanel(
                'Panel body',
                new TextFrame(20, 30, 100, 80),
                PanelStyle::padded(10, strokeColor: Color::black(), fillColor: Color::gray(0.95)),
                fontSize: 12.0,
                lineHeight: 1.0,
            )
            ->endPage()
            ->build();

        $rectangles = $document->page(0)->rectangles();
        $texts = $document->page(0)->texts();

        $this->assertCount(1, $rectangles);
        $this->assertSame(20.0, $rectangles[0]->x);
        $this->assertSame(30.0, $rectangles[0]->y);
        $this->assertSame(100.0, $rectangles[0]->width);
        $this->assertSame(80.0, $rectangles[0]->height);
        $this->assertSame('Panel body', $texts[0]->text);
        $this->assertSame(30.0, $texts[0]->x);
        $this->assertSame(40.0, $texts[0]->y);
    }

    public function testFlowTextPanelFramesRedrawsPanelsAcrossFramesAndPages(): void
    {
        $document = Pdf::new()
            ->addPage(width: 220, height: 80)
            ->flowTextPanelFrames(
                "One\nTwo\nThree\nFour\nFive",
                [
                    new TextFrame(20, 8, 60, 22),
                    new TextFrame(110, 8, 60, 22),
                ],
                PanelStyle::padded(2, strokeColor: Color::black(), fillColor: Color::gray(0.95)),
                fontSize: 12.0,
                lineHeight: 1.0,
            )
            ->endPage()
            ->build();

        $this->assertCount(3, $document->pages());
        $this->assertCount(2, $document->page(0)->rectangles());
        $this->assertCount(2, $document->page(1)->rectangles());
        $this->assertCount(1, $document->page(2)->rectangles());
        $this->assertSame('One', $document->page(0)->texts()[0]->text);
        $this->assertSame(22.0, $document->page(0)->texts()[0]->x);
        $this->assertSame('Two', $document->page(0)->texts()[1]->text);
        $this->assertSame(112.0, $document->page(0)->texts()[1]->x);
        $this->assertSame('Three', $document->page(1)->texts()[0]->text);
        $this->assertSame('Four', $document->page(1)->texts()[1]->text);
        $this->assertSame('Five', $document->page(2)->texts()[0]->text);
        $this->assertSame(22.0, $document->page(2)->texts()[0]->x);
    }

    public function testFlowTextPanelColumnsUsesDerivedColumnFrames(): void
    {
        $document = Pdf::new()
            ->addPage(width: 240, height: 64)
            ->flowTextPanelColumns(
                "One\nTwo\nThree",
                PageMargins::all(20),
                2,
                PanelStyle::padded(5, strokeColor: Color::black(), fillColor: Color::gray(0.95)),
                gap: 10,
                fontSize: 12.0,
                lineHeight: 1.0,
            )
            ->endPage()
            ->build();

        $this->assertCount(2, $document->pages());
        $this->assertCount(2, $document->page(0)->rectangles());
        $this->assertCount(1, $document->page(1)->rectangles());
        $this->assertSame('One', $document->page(0)->texts()[0]->text);
        $this->assertSame(25.0, $document->page(0)->texts()[0]->x);
        $this->assertSame('Two', $document->page(0)->texts()[1]->text);
        $this->assertSame(130.0, $document->page(0)->texts()[1]->x);
        $this->assertSame('Three', $document->page(1)->texts()[0]->text);
        $this->assertSame(25.0, $document->page(1)->texts()[0]->x);
    }

    public function testStackTextBlocksFramePlacesBlocksSequentially(): void
    {
        $document = Pdf::new()
            ->addPage(width: 200, height: 200)
            ->stackTextBlocksFrame([
                new TextBlock('Heading', fontSize: 16.0, spacingAfter: 8.0),
                new TextBlock('Body copy', fontSize: 12.0),
            ], new TextFrame(20, 20, 120, 120))
            ->endPage()
            ->build();

        $texts = $document->page(0)->texts();

        $this->assertCount(2, $texts);
        $this->assertSame('Heading', $texts[0]->text);
        $this->assertSame(20.0, $texts[0]->y);
        $this->assertSame('Body copy', $texts[1]->text);
        $this->assertEqualsWithDelta(47.2, $texts[1]->y, 0.001);
    }

    public function testStackTextBlocksFrameContinuesAcrossPages(): void
    {
        $document = Pdf::new()
            ->addPage(width: 200, height: 60)
            ->stackTextBlocksFrame([
                new TextBlock('Heading', fontSize: 16.0, lineHeight: 1.0, spacingAfter: 4.0),
                new TextBlock("One\nTwo", fontSize: 12.0, lineHeight: 1.0),
            ], new TextFrame(20, 8, 100, 28))
            ->endPage()
            ->build();

        $this->assertCount(2, $document->pages());
        $this->assertCount(1, $document->page(0)->texts());
        $this->assertCount(2, $document->page(1)->texts());
        $this->assertSame('Heading', $document->page(0)->texts()[0]->text);
        $this->assertSame('One', $document->page(1)->texts()[0]->text);
        $this->assertSame('Two', $document->page(1)->texts()[1]->text);
        $this->assertSame(8.0, $document->page(1)->texts()[0]->y);
    }

    public function testPanelFrameReturnsInsetInnerFrame(): void
    {
        $builder = Pdf::new()->addPage(width: 200, height: 200);

        $inner = $builder->panelFrame(
            new TextFrame(20, 30, 100, 80),
            PanelStyle::padded(10, strokeColor: Color::black())
        );

        $this->assertSame(30.0, $inner->x);
        $this->assertSame(40.0, $inner->y);
        $this->assertSame(80.0, $inner->width);
        $this->assertSame(60.0, $inner->height);
    }

    public function testStackTextBlocksPanelDrawsPanelAndPlacesContentInsidePadding(): void
    {
        $document = Pdf::new()
            ->addPage(width: 200, height: 200)
            ->stackTextBlocksPanel(
                [new TextBlock('Panel body', fontSize: 12.0)],
                new TextFrame(20, 30, 100, 80),
                PanelStyle::padded(10, strokeColor: Color::black(), fillColor: Color::gray(0.95))
            )
            ->endPage()
            ->build();

        $rectangles = $document->page(0)->rectangles();
        $texts = $document->page(0)->texts();

        $this->assertCount(1, $rectangles);
        $this->assertSame(20.0, $rectangles[0]->x);
        $this->assertSame(30.0, $rectangles[0]->y);
        $this->assertSame(100.0, $rectangles[0]->width);
        $this->assertSame(80.0, $rectangles[0]->height);
        $this->assertSame('Panel body', $texts[0]->text);
        $this->assertSame(30.0, $texts[0]->x);
        $this->assertSame(40.0, $texts[0]->y);
    }

    public function testStackTextBlocksPanelRedrawsPanelAcrossPages(): void
    {
        $document = Pdf::new()
            ->addPage(width: 200, height: 60)
            ->stackTextBlocksPanel(
                [new TextBlock("One\nTwo", fontSize: 12.0, lineHeight: 1.0)],
                new TextFrame(20, 8, 100, 22),
                PanelStyle::padded(2, strokeColor: Color::black())
            )
            ->endPage()
            ->build();

        $this->assertCount(2, $document->pages());
        $this->assertCount(1, $document->page(0)->rectangles());
        $this->assertCount(1, $document->page(1)->rectangles());
        $this->assertSame(22.0, $document->page(1)->texts()[0]->x);
        $this->assertSame(10.0, $document->page(1)->texts()[0]->y);
    }

    public function testStackTextBlocksPanelFramesRedrawsPanelsAcrossFramesAndPages(): void
    {
        $document = Pdf::new()
            ->addPage(width: 220, height: 80)
            ->stackTextBlocksPanelFrames(
                [
                    new TextBlock("One\nTwo", fontSize: 12.0, lineHeight: 1.0, spacingAfter: 4.0),
                    new TextBlock("Three\nFour", fontSize: 12.0, lineHeight: 1.0),
                    new TextBlock('Five', fontSize: 12.0, lineHeight: 1.0),
                ],
                [
                    new TextFrame(20, 8, 60, 22),
                    new TextFrame(110, 8, 60, 22),
                ],
                PanelStyle::padded(2, strokeColor: Color::black(), fillColor: Color::gray(0.95)),
            )
            ->endPage()
            ->build();

        $this->assertCount(3, $document->pages());
        $this->assertCount(2, $document->page(0)->rectangles());
        $this->assertCount(2, $document->page(1)->rectangles());
        $this->assertCount(1, $document->page(2)->rectangles());
        $this->assertSame('One', $document->page(0)->texts()[0]->text);
        $this->assertSame(22.0, $document->page(0)->texts()[0]->x);
        $this->assertSame('Two', $document->page(0)->texts()[1]->text);
        $this->assertSame(112.0, $document->page(0)->texts()[1]->x);
        $this->assertSame('Five', $document->page(2)->texts()[0]->text);
        $this->assertSame(22.0, $document->page(2)->texts()[0]->x);
    }

    public function testStackTextBlocksFramesContinuesAcrossMultipleFramesBeforeNewPage(): void
    {
        $document = Pdf::new()
            ->addPage(width: 220, height: 80)
            ->stackTextBlocksFrames(
                [
                    new TextBlock("One\nTwo", fontSize: 12.0, lineHeight: 1.0, spacingAfter: 4.0),
                    new TextBlock("Three\nFour", fontSize: 12.0, lineHeight: 1.0),
                    new TextBlock('Five', fontSize: 12.0, lineHeight: 1.0),
                ],
                [
                    new TextFrame(20, 8, 60, 22),
                    new TextFrame(110, 8, 60, 22),
                ],
            )
            ->endPage()
            ->build();

        $this->assertCount(3, $document->pages());
        $this->assertSame('One', $document->page(0)->texts()[0]->text);
        $this->assertSame('Two', $document->page(0)->texts()[1]->text);
        $this->assertSame(20.0, $document->page(0)->texts()[0]->x);
        $this->assertSame(110.0, $document->page(0)->texts()[1]->x);
        $this->assertSame('Three', $document->page(1)->texts()[0]->text);
        $this->assertSame('Four', $document->page(1)->texts()[1]->text);
        $this->assertSame(20.0, $document->page(1)->texts()[0]->x);
        $this->assertSame(110.0, $document->page(1)->texts()[1]->x);
        $this->assertSame('Five', $document->page(2)->texts()[0]->text);
        $this->assertSame(20.0, $document->page(2)->texts()[0]->x);
    }

    public function testStackTextBlocksColumnsUsesDerivedColumnFrames(): void
    {
        $document = Pdf::new()
            ->addPage(width: 240, height: 52)
            ->stackTextBlocksColumns(
                [
                    new TextBlock('One', fontSize: 12.0, lineHeight: 1.0),
                    new TextBlock('Two', fontSize: 12.0, lineHeight: 1.0),
                    new TextBlock('Three', fontSize: 12.0, lineHeight: 1.0),
                ],
                PageMargins::all(20),
                2,
                gap: 10,
            )
            ->endPage()
            ->build();

        $this->assertCount(2, $document->pages());
        $this->assertSame('One', $document->page(0)->texts()[0]->text);
        $this->assertSame(20.0, $document->page(0)->texts()[0]->x);
        $this->assertSame('Two', $document->page(0)->texts()[1]->text);
        $this->assertSame(125.0, $document->page(0)->texts()[1]->x);
        $this->assertSame('Three', $document->page(1)->texts()[0]->text);
        $this->assertSame(20.0, $document->page(1)->texts()[0]->x);
    }

    public function testStackTextBlocksPanelColumnsUsesDerivedColumnFrames(): void
    {
        $document = Pdf::new()
            ->addPage(width: 240, height: 64)
            ->stackTextBlocksPanelColumns(
                [
                    new TextBlock('One', fontSize: 12.0, lineHeight: 1.0),
                    new TextBlock('Two', fontSize: 12.0, lineHeight: 1.0),
                    new TextBlock('Three', fontSize: 12.0, lineHeight: 1.0),
                ],
                PageMargins::all(20),
                2,
                PanelStyle::padded(5, strokeColor: Color::black(), fillColor: Color::gray(0.95)),
                gap: 10,
            )
            ->endPage()
            ->build();

        $this->assertCount(2, $document->pages());
        $this->assertCount(2, $document->page(0)->rectangles());
        $this->assertCount(1, $document->page(1)->rectangles());
        $this->assertSame('One', $document->page(0)->texts()[0]->text);
        $this->assertSame(25.0, $document->page(0)->texts()[0]->x);
        $this->assertSame('Two', $document->page(0)->texts()[1]->text);
        $this->assertSame(130.0, $document->page(0)->texts()[1]->x);
        $this->assertSame('Three', $document->page(1)->texts()[0]->text);
        $this->assertSame(25.0, $document->page(1)->texts()[0]->x);
    }

    public function testTableFrameDrawsCellsAndPlacesText(): void
    {
        $document = Pdf::new()
            ->addPage(width: 220, height: 200)
            ->tableFrame(
                [
                    ['Name', 'Value'],
                    ['Alpha', '42'],
                ],
                [80, 60],
                new TextFrame(20, 30, 160, 120),
                TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
                firstRowHeader: true,
            )
            ->endPage()
            ->build();

        $rectangles = $document->page(0)->rectangles();
        $texts = $document->page(0)->texts();

        $this->assertCount(4, $rectangles);
        $this->assertSame(20.0, $rectangles[0]->x);
        $this->assertSame(30.0, $rectangles[0]->y);
        $this->assertSame(80.0, $rectangles[0]->width);
        $this->assertSame(60.0, $rectangles[1]->width);
        $this->assertSame('Name', $texts[0]->text);
        $this->assertSame(24.0, $texts[0]->x);
        $this->assertSame(34.0, $texts[0]->y);
        $this->assertSame('Alpha', $texts[2]->text);
    }

    public function testTableFramesContinuesAcrossMultipleFramesBeforeNewPage(): void
    {
        $document = Pdf::new()
            ->addPage(width: 240, height: 80)
            ->tableFrames(
                [
                    ['Name', 'Value'],
                    ['Alpha', '1'],
                    ['Beta', '2'],
                    ['Gamma', '3'],
                ],
                [60, 40],
                [
                    new TextFrame(20, 8, 100, 36),
                    new TextFrame(130, 8, 100, 36),
                ],
                TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
                firstRowHeader: true,
            )
            ->endPage()
            ->build();

        $this->assertCount(2, $document->pages());
        $this->assertSame('Name', $document->page(0)->texts()[0]->text);
        $this->assertSame(24.0, $document->page(0)->texts()[0]->x);
        $this->assertSame('Name', $document->page(0)->texts()[2]->text);
        $this->assertSame(134.0, $document->page(0)->texts()[2]->x);
        $this->assertSame('Beta', $document->page(1)->texts()[2]->text);
        $this->assertSame(24.0, $document->page(1)->texts()[2]->x);
        $this->assertSame('Gamma', $document->page(1)->texts()[6]->text);
        $this->assertSame(134.0, $document->page(1)->texts()[6]->x);
    }

    public function testTableColumnsUsesDerivedColumnFrames(): void
    {
        $document = Pdf::new()
            ->addPage(width: 240, height: 80)
            ->tableColumns(
                [
                    ['Name', 'Value'],
                    ['Alpha', '1'],
                    ['Beta', '2'],
                ],
                [45, 50],
                PageMargins::all(20),
                2,
                gap: 10,
                style: TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
                firstRowHeader: true,
            )
            ->endPage()
            ->build();

        $this->assertCount(2, $document->pages());
        $this->assertSame('Name', $document->page(0)->texts()[0]->text);
        $this->assertSame(24.0, $document->page(0)->texts()[0]->x);
        $this->assertSame('Name', $document->page(0)->texts()[2]->text);
        $this->assertSame(129.0, $document->page(0)->texts()[2]->x);
        $this->assertSame('Alpha', $document->page(0)->texts()[4]->text);
        $this->assertSame('Beta', $document->page(1)->texts()[2]->text);
    }

    public function testTableRecordsFramesContinuesAcrossMultipleFramesBeforeNewPage(): void
    {
        $document = Pdf::new()
            ->addPage(width: 260, height: 80)
            ->tableRecordsFrames(
                [
                    ['name' => 'Alpha', 'count' => 1],
                    ['name' => 'Beta', 'count' => 2],
                    ['name' => 'Gamma', 'count' => 3],
                ],
                [
                    new TableDataColumn('Name', 'name', new TableColumn(60)),
                    new TableDataColumn('Count', 'count', new TableColumn(50, align: TableCell::ALIGN_RIGHT)),
                ],
                [
                    new TextFrame(20, 8, 110, 36),
                    new TextFrame(140, 8, 110, 36),
                ],
                TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
            )
            ->endPage()
            ->build();

        $this->assertCount(2, $document->pages());
        $this->assertSame('Name', $document->page(0)->texts()[0]->text);
        $this->assertSame('Name', $document->page(0)->texts()[2]->text);
        $this->assertSame('Alpha', $document->page(0)->texts()[4]->text);
        $this->assertSame('Beta', $document->page(1)->texts()[2]->text);
        $this->assertSame('Gamma', $document->page(1)->texts()[6]->text);
    }

    public function testTableRecordsColumnsUsesDerivedColumnFrames(): void
    {
        $document = Pdf::new()
            ->addPage(width: 260, height: 80)
            ->tableRecordsColumns(
                [
                    ['name' => 'Alpha', 'count' => 1],
                    ['name' => 'Beta', 'count' => 2],
                ],
                [
                    new TableDataColumn('Name', 'name', new TableColumn(50)),
                    new TableDataColumn('Count', 'count', new TableColumn(45, align: TableCell::ALIGN_RIGHT)),
                ],
                PageMargins::all(20),
                2,
                gap: 10,
                style: TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
            )
            ->endPage()
            ->build();

        $this->assertCount(2, $document->pages());
        $this->assertSame('Name', $document->page(0)->texts()[0]->text);
        $this->assertSame('Name', $document->page(0)->texts()[2]->text);
        $this->assertSame('Alpha', $document->page(0)->texts()[4]->text);
        $this->assertSame('Beta', $document->page(1)->texts()[2]->text);
    }

    public function testTablePanelFrameDrawsPanelAndPlacesTableInsidePadding(): void
    {
        $document = Pdf::new()
            ->addPage(width: 220, height: 200)
            ->tablePanelFrame(
                [
                    ['Name', 'Value'],
                    ['Alpha', '42'],
                ],
                [60, 40],
                new TextFrame(20, 30, 120, 80),
                PanelStyle::padded(10, strokeColor: Color::black(), fillColor: Color::gray(0.95)),
                TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
                firstRowHeader: true,
            )
            ->endPage()
            ->build();

        $rectangles = $document->page(0)->rectangles();
        $texts = $document->page(0)->texts();

        $this->assertCount(5, $rectangles);
        $this->assertSame(20.0, $rectangles[0]->x);
        $this->assertSame(30.0, $rectangles[0]->y);
        $this->assertSame(120.0, $rectangles[0]->width);
        $this->assertSame(80.0, $rectangles[0]->height);
        $this->assertSame('Name', $texts[0]->text);
        $this->assertSame(34.0, $texts[0]->x);
        $this->assertSame(44.0, $texts[0]->y);
    }

    public function testTableRecordsPanelFramePlacesRecordTableInsidePanel(): void
    {
        $document = Pdf::new()
            ->addPage(width: 260, height: 220)
            ->tableRecordsPanelFrame(
                [
                    ['name' => 'Alpha', 'count' => 2],
                    ['name' => 'Beta', 'count' => 5],
                ],
                [
                    new TableDataColumn('Name', 'name', new TableColumn(80)),
                    new TableDataColumn('Count', 'count', new TableColumn(60, align: TableCell::ALIGN_RIGHT)),
                ],
                new TextFrame(20, 30, 180, 100),
                PanelStyle::padded(8, strokeColor: Color::black(), fillColor: Color::gray(0.95)),
                TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
            )
            ->endPage()
            ->build();

        $rectangles = $document->page(0)->rectangles();
        $texts = $document->page(0)->texts();

        $this->assertCount(7, $rectangles);
        $this->assertSame(20.0, $rectangles[0]->x);
        $this->assertSame(30.0, $rectangles[0]->y);
        $this->assertSame(180.0, $rectangles[0]->width);
        $this->assertSame('Name', $texts[0]->text);
        $this->assertSame(32.0, $texts[0]->x);
        $this->assertSame(42.0, $texts[0]->y);
        $this->assertSame('5', $texts[5]->text);
        $this->assertGreaterThan(140.0, $texts[5]->x);
    }

    public function testTablePanelFrameRedrawsPanelAcrossPages(): void
    {
        $document = Pdf::new()
            ->addPage(width: 220, height: 72)
            ->tablePanelFrame(
                [
                    ['Name', 'Value'],
                    ['Alpha', '1'],
                    ['Beta', '2'],
                ],
                [60, 40],
                new TextFrame(20, 10, 120, 36),
                PanelStyle::padded(4, strokeColor: Color::black(), fillColor: Color::gray(0.95)),
                TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
                firstRowHeader: true,
            )
            ->endPage()
            ->build();

        $this->assertCount(3, $document->pages());
        $this->assertCount(3, $document->page(0)->rectangles());
        $this->assertCount(5, $document->page(1)->rectangles());
        $this->assertCount(5, $document->page(2)->rectangles());
        $this->assertSame(20.0, $document->page(1)->rectangles()[0]->x);
        $this->assertSame(10.0, $document->page(1)->rectangles()[0]->y);
    }

    public function testTablePanelFramesRedrawsPanelsAcrossFramesAndPages(): void
    {
        $document = Pdf::new()
            ->addPage(width: 280, height: 96)
            ->tablePanelFrames(
                [
                    ['Name', 'Value'],
                    ['Alpha', '1'],
                    ['Beta', '2'],
                    ['Gamma', '3'],
                ],
                [55, 35],
                [
                    new TextFrame(20, 8, 110, 44),
                    new TextFrame(150, 8, 110, 44),
                ],
                PanelStyle::padded(4, strokeColor: Color::black(), fillColor: Color::gray(0.95)),
                TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
                firstRowHeader: true,
            )
            ->endPage()
            ->build();

        $page0Panels = array_values(array_filter(
            $document->page(0)->rectangles(),
            static fn ($rectangle): bool => $rectangle->width === 110.0 && $rectangle->height === 44.0,
        ));
        $page1Panels = array_values(array_filter(
            $document->page(1)->rectangles(),
            static fn ($rectangle): bool => $rectangle->width === 110.0 && $rectangle->height === 44.0,
        ));

        $this->assertCount(2, $document->pages());
        $this->assertCount(2, $page0Panels);
        $this->assertCount(2, $page1Panels);
        $this->assertSame(20.0, $page0Panels[0]->x);
        $this->assertSame(150.0, $page0Panels[1]->x);
        $this->assertSame('Name', $document->page(0)->texts()[0]->text);
        $this->assertSame('Name', $document->page(0)->texts()[2]->text);
        $this->assertSame('Alpha', $document->page(0)->texts()[4]->text);
        $this->assertSame('Beta', $document->page(1)->texts()[2]->text);
        $this->assertSame('Gamma', $document->page(1)->texts()[6]->text);
    }

    public function testTableRecordsPanelFrameRedrawsPanelAcrossPages(): void
    {
        $document = Pdf::new()
            ->addPage(width: 240, height: 72)
            ->tableRecordsPanelFrame(
                [
                    ['name' => 'Alpha', 'count' => 1],
                    ['name' => 'Beta', 'count' => 2],
                ],
                [
                    new TableDataColumn('Name', 'name', new TableColumn(80)),
                    new TableDataColumn('Count', 'count', new TableColumn(60, align: TableCell::ALIGN_RIGHT)),
                ],
                new TextFrame(20, 10, 160, 36),
                PanelStyle::padded(4, strokeColor: Color::black(), fillColor: Color::gray(0.95)),
                TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
            )
            ->endPage()
            ->build();

        $this->assertCount(3, $document->pages());
        $this->assertCount(3, $document->page(0)->rectangles());
        $this->assertCount(5, $document->page(1)->rectangles());
        $this->assertCount(5, $document->page(2)->rectangles());
        $this->assertSame('Name', $document->page(1)->texts()[0]->text);
        $this->assertSame('Beta', $document->page(2)->texts()[2]->text);
    }

    public function testTableRecordsPanelColumnsUsesDerivedColumnFrames(): void
    {
        $document = Pdf::new()
            ->addPage(width: 260, height: 80)
            ->tableRecordsPanelColumns(
                [
                    ['name' => 'Alpha', 'count' => 1],
                    ['name' => 'Beta', 'count' => 2],
                ],
                [
                    new TableDataColumn('Name', 'name', new TableColumn(50)),
                    new TableDataColumn('Count', 'count', new TableColumn(45, align: TableCell::ALIGN_RIGHT)),
                ],
                PageMargins::all(20),
                2,
                PanelStyle::padded(4, strokeColor: Color::black(), fillColor: Color::gray(0.95)),
                gap: 10,
                style: TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
            )
            ->endPage()
            ->build();

        $page0Panels = array_values(array_filter(
            $document->page(0)->rectangles(),
            static fn ($rectangle): bool => $rectangle->width === 105.0 && $rectangle->height === 40.0,
        ));
        $page1Panels = array_values(array_filter(
            $document->page(1)->rectangles(),
            static fn ($rectangle): bool => $rectangle->width === 105.0 && $rectangle->height === 40.0,
        ));

        $this->assertCount(2, $document->pages());
        $this->assertCount(2, $page0Panels);
        $this->assertCount(1, $page1Panels);
        $this->assertSame(20.0, $page0Panels[0]->x);
        $this->assertSame(135.0, $page0Panels[1]->x);
        $this->assertSame(20.0, $page1Panels[0]->x);
        $this->assertSame('Name', $document->page(0)->texts()[0]->text);
        $this->assertSame('Name', $document->page(0)->texts()[2]->text);
        $this->assertSame('Alpha', $document->page(0)->texts()[4]->text);
        $this->assertSame('Beta', $document->page(1)->texts()[2]->text);
    }

    public function testTableFrameWrapsLongCellTextIntoTallerRow(): void
    {
        $width = Pdf::measureText('One', 12.0) + 9.0;

        $document = Pdf::new()
            ->addPage(width: 220, height: 200)
            ->tableFrame(
                [
                    [new TableCell('One Two'), 'X'],
                ],
                [$width, 40],
                new TextFrame(20, 30, 160, 120),
                TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
            )
            ->endPage()
            ->build();

        $rectangles = $document->page(0)->rectangles();
        $texts = $document->page(0)->texts();

        $this->assertCount(2, $rectangles);
        $this->assertGreaterThan(20.0, $rectangles[0]->height);
        $this->assertCount(3, $texts);
        $this->assertSame('One', $texts[0]->text);
        $this->assertSame('Two', $texts[1]->text);
    }

    public function testTableFrameRepeatsHeaderOnContinuationPages(): void
    {
        $document = Pdf::new()
            ->addPage(width: 220, height: 80)
            ->tableFrame(
                [
                    ['Name', 'Value'],
                    ['Alpha', '1'],
                    ['Beta', '2'],
                ],
                [80, 60],
                new TextFrame(20, 10, 160, 40),
                TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
                firstRowHeader: true,
            )
            ->endPage()
            ->build();

        $this->assertCount(3, $document->pages());
        $this->assertCount(2, $document->page(0)->rectangles());
        $this->assertCount(4, $document->page(1)->rectangles());
        $this->assertCount(4, $document->page(2)->rectangles());
        $this->assertSame('Name', $document->page(1)->texts()[0]->text);
        $this->assertSame('Value', $document->page(1)->texts()[1]->text);
        $this->assertSame('Alpha', $document->page(1)->texts()[2]->text);
        $this->assertSame('Beta', $document->page(2)->texts()[2]->text);
    }

    public function testTableFrameSupportsCellAlignment(): void
    {
        $document = Pdf::new()
            ->addPage(width: 260, height: 200)
            ->tableFrame(
                [[
                    new TableCell('Left', align: TableCell::ALIGN_LEFT),
                    new TableCell('Mid', align: TableCell::ALIGN_CENTER),
                    new TableCell('9', align: TableCell::ALIGN_RIGHT),
                ]],
                [60, 60, 60],
                new TextFrame(20, 30, 200, 80),
                TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
            )
            ->endPage()
            ->build();

        $texts = $document->page(0)->texts();

        $this->assertCount(3, $texts);
        $this->assertSame('Left', $texts[0]->text);
        $this->assertSame(24.0, $texts[0]->x);
        $this->assertSame('Mid', $texts[1]->text);
        $this->assertGreaterThan(80.0, $texts[1]->x);
        $this->assertLessThan(102.0, $texts[1]->x);
        $this->assertSame('9', $texts[2]->text);
        $this->assertGreaterThan(186.0, $texts[2]->x);
    }

    public function testTableFrameSupportsCellLevelVisualOverrides(): void
    {
        $document = Pdf::new()
            ->addPage(width: 220, height: 200)
            ->tableFrame(
                [[
                    new TableCell(
                        'Alert',
                        borderColor: Color::rgb(1, 0, 0),
                        fillColor: Color::gray(0.9),
                        lineWidth: 2.0,
                    ),
                    'Plain',
                ]],
                [80, 60],
                new TextFrame(20, 30, 160, 80),
                TableStyle::padded(4, borderColor: Color::black(), rowFillColor: Color::white(), lineWidth: 0.5),
            )
            ->endPage()
            ->build();

        $rectangles = $document->page(0)->rectangles();

        $this->assertCount(2, $rectangles);
        $this->assertSame(2.0, $rectangles[0]->lineWidth);
        $this->assertEquals(1.0, $rectangles[0]->strokeColor?->r);
        $this->assertEquals(0.9, $rectangles[0]->fillColor?->r);
        $this->assertSame(0.5, $rectangles[1]->lineWidth);
        $this->assertEquals(0.0, $rectangles[1]->strokeColor?->r);
        $this->assertEquals(1.0, $rectangles[1]->fillColor?->r);
    }

    public function testTableFrameSupportsColumnSpans(): void
    {
        $document = Pdf::new()
            ->addPage(width: 260, height: 200)
            ->tableFrame(
                [
                    [
                        new TableCell('Summary', colspan: 2, align: TableCell::ALIGN_CENTER),
                        'Tail',
                    ],
                ],
                [50, 70, 60],
                new TextFrame(20, 30, 200, 80),
                TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
            )
            ->endPage()
            ->build();

        $rectangles = $document->page(0)->rectangles();
        $texts = $document->page(0)->texts();

        $this->assertCount(2, $rectangles);
        $this->assertSame(120.0, $rectangles[0]->width);
        $this->assertSame(60.0, $rectangles[1]->width);
        $this->assertSame('Summary', $texts[0]->text);
        $this->assertGreaterThan(40.0, $texts[0]->x);
        $this->assertLessThan(90.0, $texts[0]->x);
        $this->assertSame('Tail', $texts[1]->text);
    }

    public function testTableFrameRejectsInvalidColumnSpanOccupancy(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('occupy exactly 3 columns');

        Pdf::new()
            ->addPage(width: 260, height: 200)
            ->tableFrame(
                [
                    [
                        new TableCell('Summary', colspan: 2),
                        'Tail',
                        'Extra',
                    ],
                ],
                [50, 70, 60],
                new TextFrame(20, 30, 200, 80),
            );
    }

    public function testTableFrameSupportsRowSpans(): void
    {
        $document = Pdf::new()
            ->addPage(width: 260, height: 200)
            ->tableFrame(
                [
                    [
                        new TableCell('Group', rowspan: 2),
                        'A',
                    ],
                    [
                        'B',
                    ],
                ],
                [80, 60],
                new TextFrame(20, 30, 160, 120),
                TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
            )
            ->endPage()
            ->build();

        $rectangles = $document->page(0)->rectangles();
        $texts = $document->page(0)->texts();

        $this->assertCount(3, $rectangles);
        $this->assertGreaterThan($rectangles[1]->height, $rectangles[0]->height);
        $this->assertSame('Group', $texts[0]->text);
        $this->assertSame('A', $texts[1]->text);
        $this->assertSame('B', $texts[2]->text);
    }

    public function testTableFrameKeepsRowSpanGroupTogetherAcrossPages(): void
    {
        $document = Pdf::new()
            ->addPage(width: 260, height: 90)
            ->tableFrame(
                [
                    ['Header', 'Value'],
                    [
                        new TableCell('Group', rowspan: 2),
                        'A',
                    ],
                    [
                        'B',
                    ],
                ],
                [80, 60],
                new TextFrame(20, 10, 160, 40),
                TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
                firstRowHeader: true,
            )
            ->endPage()
            ->build();

        $this->assertCount(2, $document->pages());
        $this->assertCount(2, $document->page(0)->rectangles());
        $this->assertCount(5, $document->page(1)->rectangles());
        $this->assertSame('Header', $document->page(1)->texts()[0]->text);
        $this->assertSame('Group', $document->page(1)->texts()[2]->text);
    }

    public function testTableFrameSupportsVerticalAlignment(): void
    {
        $document = Pdf::new()
            ->addPage(width: 260, height: 220)
            ->tableFrame(
                [[
                    new TableCell('Top', valign: TableCell::VALIGN_TOP),
                    new TableCell('Mid', valign: TableCell::VALIGN_MIDDLE),
                    new TableCell('Bottom', valign: TableCell::VALIGN_BOTTOM),
                    new TableCell('Tall cell wraps into multiple lines to increase row height.'),
                ]],
                [50, 50, 50, 40],
                new TextFrame(20, 30, 220, 180),
                TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
            )
            ->endPage()
            ->build();

        $this->assertCount(1, $document->pages());

        $textByValue = [];

        foreach ($document->page(0)->texts() as $text) {
            $textByValue[$text->text] = $text;
        }

        $this->assertArrayHasKey('Top', $textByValue);
        $this->assertArrayHasKey('Mid', $textByValue);
        $this->assertArrayHasKey('Bottom', $textByValue);
        $this->assertSame(34.0, $textByValue['Top']->y);
        $this->assertGreaterThan($textByValue['Top']->y, $textByValue['Mid']->y);
        $this->assertGreaterThan($textByValue['Mid']->y, $textByValue['Bottom']->y);
    }

    public function testTableFrameSupportsColumnDefaultAlignment(): void
    {
        $document = Pdf::new()
            ->addPage(width: 260, height: 220)
            ->tableFrame(
                [[
                    new TableCell('9'),
                    new TableCell('Mid'),
                    new TableCell('Cell override', align: TableCell::ALIGN_LEFT),
                ]],
                [
                    new TableColumn(60, align: TableCell::ALIGN_RIGHT),
                    new TableColumn(60, align: TableCell::ALIGN_CENTER),
                    new TableColumn(80, align: TableCell::ALIGN_RIGHT),
                ],
                new TextFrame(20, 30, 220, 80),
                TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
            )
            ->endPage()
            ->build();

        $texts = $document->page(0)->texts();

        $this->assertCount(3, $texts);
        $this->assertSame('9', $texts[0]->text);
        $this->assertGreaterThan(66.0, $texts[0]->x);
        $this->assertSame('Mid', $texts[1]->text);
        $this->assertGreaterThan(84.0, $texts[1]->x);
        $this->assertLessThan(106.0, $texts[1]->x);
        $this->assertSame('Cell override', $texts[2]->text);
        $this->assertSame(144.0, $texts[2]->x);
    }

    public function testTableFrameSupportsColumnDefaultVerticalAlignment(): void
    {
        $document = Pdf::new()
            ->addPage(width: 260, height: 220)
            ->tableFrame(
                [[
                    new TableCell('Top'),
                    new TableCell('Mid'),
                    new TableCell('Bottom', valign: TableCell::VALIGN_TOP),
                    new TableCell('Tall cell wraps into multiple lines to increase row height.'),
                ]],
                [
                    new TableColumn(50, valign: TableCell::VALIGN_TOP),
                    new TableColumn(50, valign: TableCell::VALIGN_MIDDLE),
                    new TableColumn(50, valign: TableCell::VALIGN_BOTTOM),
                    40,
                ],
                new TextFrame(20, 30, 220, 180),
                TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
            )
            ->endPage()
            ->build();

        $this->assertCount(1, $document->pages());

        $textByValue = [];

        foreach ($document->page(0)->texts() as $text) {
            $textByValue[$text->text] = $text;
        }

        $this->assertArrayHasKey('Top', $textByValue);
        $this->assertArrayHasKey('Mid', $textByValue);
        $this->assertArrayHasKey('Bottom', $textByValue);
        $this->assertSame(34.0, $textByValue['Top']->y);
        $this->assertGreaterThan($textByValue['Top']->y, $textByValue['Mid']->y);
        $this->assertSame(34.0, $textByValue['Bottom']->y);
    }

    public function testTableFrameSupportsColumnDefaultFontAndColor(): void
    {
        $document = Pdf::new()
            ->addPage(width: 260, height: 220)
            ->tableFrame(
                [[
                    new TableCell('Defaulted'),
                    new TableCell(
                        'Override',
                        font: Pdf::font('Courier'),
                        color: Color::rgb(1, 0, 0),
                    ),
                ]],
                [
                    new TableColumn(90, font: Pdf::font('Helvetica', 'bold'), color: Color::gray(0.4)),
                    new TableColumn(90, font: Pdf::font('Times')),
                ],
                new TextFrame(20, 30, 220, 80),
                TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
            )
            ->endPage()
            ->build();

        $texts = $document->page(0)->texts();

        $this->assertCount(2, $texts);
        $this->assertSame('Defaulted', $texts[0]->text);
        $this->assertSame('Helvetica', $texts[0]->font?->family);
        $this->assertSame('bold', $texts[0]->font?->style);
        $this->assertEquals(0.4, $texts[0]->color?->r);
        $this->assertSame('Override', $texts[1]->text);
        $this->assertSame('Courier', $texts[1]->font?->family);
        $this->assertSame('normal', $texts[1]->font?->style);
        $this->assertEquals(1.0, $texts[1]->color?->r);
        $this->assertEquals(0.0, $texts[1]->color?->g);
        $this->assertEquals(0.0, $texts[1]->color?->b);
    }

    public function testTableFrameSupportsColumnDefaultVisuals(): void
    {
        $document = Pdf::new()
            ->addPage(width: 260, height: 220)
            ->tableFrame(
                [[
                    new TableCell('Defaulted'),
                    new TableCell(
                        'Override',
                        borderColor: Color::rgb(1, 0, 0),
                        fillColor: Color::gray(0.8),
                        lineWidth: 2.0,
                    ),
                    new TableCell('Style fallback'),
                ]],
                [
                    new TableColumn(
                        60,
                        borderColor: Color::rgb(0, 0, 1),
                        fillColor: Color::gray(0.9),
                        lineWidth: 1.5,
                    ),
                    new TableColumn(
                        60,
                        borderColor: Color::rgb(0, 1, 0),
                        fillColor: Color::gray(0.7),
                        lineWidth: 1.0,
                    ),
                    new TableColumn(80),
                ],
                new TextFrame(20, 30, 220, 80),
                TableStyle::padded(4, borderColor: Color::black(), rowFillColor: Color::white(), lineWidth: 0.5),
            )
            ->endPage()
            ->build();

        $rectangles = $document->page(0)->rectangles();

        $this->assertCount(3, $rectangles);
        $this->assertEquals(1.5, $rectangles[0]->lineWidth);
        $this->assertEquals(1.0, $rectangles[0]->strokeColor?->b);
        $this->assertEquals(0.9, $rectangles[0]->fillColor?->r);
        $this->assertEquals(2.0, $rectangles[1]->lineWidth);
        $this->assertEquals(1.0, $rectangles[1]->strokeColor?->r);
        $this->assertEquals(0.8, $rectangles[1]->fillColor?->r);
        $this->assertEquals(0.5, $rectangles[2]->lineWidth);
        $this->assertEquals(0.0, $rectangles[2]->strokeColor?->r);
        $this->assertEquals(1.0, $rectangles[2]->fillColor?->r);
    }

    public function testTableFrameSupportsColumnDefaultTextMetrics(): void
    {
        $document = Pdf::new()
            ->addPage(width: 260, height: 220)
            ->tableFrame(
                [
                    [
                        new TableCell("One\n\nTwo"),
                        new TableCell('Override', fontSize: 10.0, lineHeight: 1.0),
                    ],
                ],
                [
                    new TableColumn(90, fontSize: 20.0, lineHeight: 2.0, paragraphSpacing: 0.5),
                    new TableColumn(90, fontSize: 16.0, lineHeight: 1.5),
                ],
                new TextFrame(20, 30, 220, 120),
                TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
            )
            ->endPage()
            ->build();

        $textByValue = [];

        foreach ($document->page(0)->texts() as $text) {
            $textByValue[$text->text] = $text;
        }

        $this->assertArrayHasKey('One', $textByValue);
        $this->assertArrayHasKey('Two', $textByValue);
        $this->assertArrayHasKey('Override', $textByValue);
        $this->assertSame(20.0, $textByValue['One']->fontSize);
        $this->assertSame(20.0, $textByValue['Two']->fontSize);
        $this->assertEqualsWithDelta(50.0, $textByValue['Two']->y - $textByValue['One']->y, 0.001);
        $this->assertSame(10.0, $textByValue['Override']->fontSize);
    }

    public function testTableFrameSupportsColumnDefaultPadding(): void
    {
        $document = Pdf::new()
            ->addPage(width: 260, height: 220)
            ->tableFrame(
                [[
                    new TableCell('Left pad'),
                    new TableCell('Tall'),
                ]],
                [
                    new TableColumn(90, padding: PageMargins::symmetric(4, 12)),
                    new TableColumn(90, padding: new PageMargins(10, 4, 18, 4)),
                ],
                new TextFrame(20, 30, 220, 120),
                TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
            )
            ->endPage()
            ->build();

        $texts = $document->page(0)->texts();
        $rectangles = $document->page(0)->rectangles();

        $this->assertCount(2, $texts);
        $this->assertCount(2, $rectangles);
        $this->assertSame('Left pad', $texts[0]->text);
        $this->assertSame(32.0, $texts[0]->x);
        $this->assertSame('Tall', $texts[1]->text);
        $this->assertSame(114.0, $texts[1]->x);
        $this->assertSame(40.0, $texts[1]->y);
        $this->assertGreaterThan(40.0, $rectangles[0]->height);
    }

    public function testTableRecordsFrameBuildsHeaderAndRows(): void
    {
        $document = Pdf::new()
            ->addPage(width: 260, height: 220)
            ->tableRecordsFrame(
                [
                    ['name' => 'Alpha', 'count' => 42],
                    ['name' => 'Beta', 'count' => 7],
                ],
                [
                    new TableDataColumn('Name', 'name', new TableColumn(90)),
                    new TableDataColumn('Count', 'count', new TableColumn(60, align: TableCell::ALIGN_RIGHT)),
                ],
                new TextFrame(20, 30, 180, 120),
                TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
            )
            ->endPage()
            ->build();

        $texts = $document->page(0)->texts();

        $this->assertSame('Name', $texts[0]->text);
        $this->assertSame('Count', $texts[1]->text);
        $this->assertSame('Alpha', $texts[2]->text);
        $this->assertSame('42', $texts[3]->text);
        $this->assertGreaterThan(146.0, $texts[3]->x);
        $this->assertSame('Beta', $texts[4]->text);
        $this->assertSame('7', $texts[5]->text);
    }

    public function testTableRecordsFrameSupportsNestedFieldsAndFormatters(): void
    {
        $document = Pdf::new()
            ->addPage(width: 280, height: 220)
            ->tableRecordsFrame(
                [
                    [
                        'customer' => ['name' => 'Grace'],
                        'active' => true,
                        'balance' => 19.5,
                    ],
                ],
                [
                    new TableDataColumn('Customer', 'customer.name', new TableColumn(100)),
                    new TableDataColumn(
                        'Active',
                        'active',
                        new TableColumn(60),
                        formatter: static fn (mixed $value): string => $value ? 'Yes' : 'No',
                    ),
                    new TableDataColumn(
                        'Balance',
                        'balance',
                        new TableColumn(80, align: TableCell::ALIGN_RIGHT),
                        formatter: static fn (mixed $value): string => '$' . number_format((float) $value, 2),
                    ),
                ],
                new TextFrame(20, 30, 240, 120),
                TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
            )
            ->endPage()
            ->build();

        $texts = $document->page(0)->texts();

        $this->assertSame('Grace', $texts[3]->text);
        $this->assertSame('Yes', $texts[4]->text);
        $this->assertSame('$19.50', $texts[5]->text);
        $this->assertGreaterThan(205.0, $texts[5]->x);
    }

    public function testTableRecordsFrameFormatterCanReturnTableCell(): void
    {
        $document = Pdf::new()
            ->addPage(width: 280, height: 220)
            ->tableRecordsFrame(
                [
                    ['name' => 'Alpha', 'status' => 'ok'],
                    ['name' => 'Beta', 'status' => 'alert'],
                ],
                [
                    new TableDataColumn('Name', 'name', new TableColumn(100)),
                    new TableDataColumn(
                        'Status',
                        'status',
                        new TableColumn(80),
                        formatter: static fn (mixed $value): TableCell => new TableCell(
                            strtoupper((string) $value),
                            color: $value === 'alert' ? Color::rgb(1, 0, 0) : Color::rgb(0, 0.5, 0),
                            fillColor: $value === 'alert' ? Color::gray(0.9) : null,
                            align: TableCell::ALIGN_CENTER,
                        ),
                    ),
                ],
                new TextFrame(20, 30, 220, 120),
                TableStyle::padded(4, borderColor: Color::black(), rowFillColor: Color::white(), lineWidth: 0.5),
            )
            ->endPage()
            ->build();

        $texts = $document->page(0)->texts();
        $rectangles = $document->page(0)->rectangles();

        $this->assertSame('ALERT', $texts[5]->text);
        $this->assertEquals(1.0, $texts[5]->color?->r);
        $this->assertGreaterThan(138.0, $texts[5]->x);
        $this->assertLessThan($texts[3]->x, $texts[5]->x);
        $this->assertEquals(0.9, $rectangles[5]->fillColor?->r);
    }

    public function testTableRecordsFrameSupportsCustomHeaderCells(): void
    {
        $document = Pdf::new()
            ->addPage(width: 280, height: 220)
            ->tableRecordsFrame(
                [
                    ['name' => 'Alpha', 'status' => 'ok'],
                ],
                [
                    new TableDataColumn(
                        'Name',
                        'name',
                        new TableColumn(100),
                        headerCell: new TableCell(
                            'NAME',
                            fontSize: 14.0,
                            color: Color::rgb(0, 0, 1),
                            align: TableCell::ALIGN_CENTER,
                            fillColor: Color::gray(0.85),
                        ),
                    ),
                    new TableDataColumn('Status', 'status', new TableColumn(80)),
                ],
                new TextFrame(20, 30, 220, 120),
                TableStyle::padded(4, borderColor: Color::black(), headerFillColor: Color::white(), lineWidth: 0.5),
            )
            ->endPage()
            ->build();

        $texts = $document->page(0)->texts();
        $rectangles = $document->page(0)->rectangles();

        $this->assertSame('NAME', $texts[0]->text);
        $this->assertSame(14.0, $texts[0]->fontSize);
        $this->assertEquals(1.0, $texts[0]->color?->b);
        $this->assertGreaterThan(45.0, $texts[0]->x);
        $this->assertEquals(0.85, $rectangles[0]->fillColor?->r);
    }

    public function testTableRecordsFrameSupportsResolverColumns(): void
    {
        $document = Pdf::new()
            ->addPage(width: 320, height: 220)
            ->tableRecordsFrame(
                [
                    ['first' => 'Grace', 'last' => 'Hopper', 'count' => 2],
                    ['first' => 'Ada', 'last' => 'Lovelace', 'count' => 5],
                ],
                [
                    new TableDataColumn(
                        'Full Name',
                        '',
                        new TableColumn(140),
                        resolver: static fn (array $record): string => $record['first'] . ' ' . $record['last'],
                    ),
                    new TableDataColumn(
                        'Label',
                        '',
                        new TableColumn(100, align: TableCell::ALIGN_RIGHT),
                        resolver: static fn (array $record): string => 'Rows: ' . $record['count'],
                    ),
                ],
                new TextFrame(20, 30, 260, 120),
                TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
            )
            ->endPage()
            ->build();

        $texts = $document->page(0)->texts();

        $this->assertSame('Grace Hopper', $texts[2]->text);
        $this->assertSame('Rows: 2', $texts[3]->text);
        $this->assertGreaterThan(196.0, $texts[3]->x);
        $this->assertSame('Ada Lovelace', $texts[4]->text);
        $this->assertSame('Rows: 5', $texts[5]->text);
    }

    public function testTableRecordsFrameSupportsRowFormatter(): void
    {
        $document = Pdf::new()
            ->addPage(width: 300, height: 220)
            ->tableRecordsFrame(
                [
                    ['name' => 'Alpha', 'status' => 'ok'],
                    ['name' => 'Beta', 'status' => 'alert'],
                ],
                [
                    new TableDataColumn('Name', 'name', new TableColumn(100)),
                    new TableDataColumn('Status', 'status', new TableColumn(80)),
                ],
                new TextFrame(20, 30, 220, 120),
                TableStyle::padded(4, borderColor: Color::black(), rowFillColor: Color::white(), lineWidth: 0.5),
                rowFormatter: static function (array $row, array $record): array {
                    if ($record['status'] !== 'alert') {
                        return $row;
                    }

                    return [
                        new TableCell((string) $row[0], fillColor: Color::gray(0.9)),
                        new TableCell(strtoupper((string) $row[1]), fillColor: Color::gray(0.9), color: Color::rgb(1, 0, 0)),
                    ];
                },
            )
            ->endPage()
            ->build();

        $texts = $document->page(0)->texts();
        $rectangles = $document->page(0)->rectangles();

        $this->assertSame('ALERT', $texts[5]->text);
        $this->assertEquals(1.0, $texts[5]->color?->r);
        $this->assertEquals(0.9, $rectangles[4]->fillColor?->r);
        $this->assertEquals(0.9, $rectangles[5]->fillColor?->r);
    }

    public function testTableRecordsFrameRowFormatterCanExpandIntoMultipleRows(): void
    {
        $document = Pdf::new()
            ->addPage(width: 320, height: 240)
            ->tableRecordsFrame(
                [
                    ['name' => 'Alpha', 'status' => 'ok', 'note' => 'Primary contact'],
                    ['name' => 'Beta', 'status' => 'alert', 'note' => 'Requires review'],
                ],
                [
                    new TableDataColumn('Name', 'name', new TableColumn(100)),
                    new TableDataColumn('Status', 'status', new TableColumn(80)),
                ],
                new TextFrame(20, 30, 240, 160),
                TableStyle::padded(4, borderColor: Color::black(), rowFillColor: Color::white(), lineWidth: 0.5),
                rowFormatter: static function (array $row, array $record): array {
                    return [
                        $row,
                        [
                            new TableCell(
                                'Note: ' . $record['note'],
                                colspan: 2,
                                fontSize: 10.0,
                                color: Color::gray(0.25),
                            ),
                        ],
                    ];
                },
            )
            ->endPage()
            ->build();

        $texts = $document->page(0)->texts();
        $rectangles = $document->page(0)->rectangles();

        $this->assertSame('Alpha', $texts[2]->text);
        $this->assertSame('Note: Primary contact', $texts[4]->text);
        $this->assertSame(10.0, $texts[4]->fontSize);
        $this->assertSame('Beta', $texts[5]->text);
        $this->assertSame('Note: Requires review', $texts[7]->text);
        $this->assertCount(8, $rectangles);
    }

    public function testTableRecordsFrameRowFormatterCanSkipRecords(): void
    {
        $document = Pdf::new()
            ->addPage(width: 300, height: 220)
            ->tableRecordsFrame(
                [
                    ['name' => 'Alpha', 'status' => 'ok'],
                    ['name' => 'Beta', 'status' => 'skip'],
                    ['name' => 'Gamma', 'status' => 'ok'],
                ],
                [
                    new TableDataColumn('Name', 'name', new TableColumn(100)),
                    new TableDataColumn('Status', 'status', new TableColumn(80)),
                ],
                new TextFrame(20, 30, 220, 120),
                TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
                rowFormatter: static function (array $row, array $record): ?array {
                    if ($record['status'] === 'skip') {
                        return null;
                    }

                    return $row;
                },
            )
            ->endPage()
            ->build();

        $texts = $document->page(0)->texts();
        $rectangles = $document->page(0)->rectangles();

        $this->assertCount(6, $texts);
        $this->assertSame('Alpha', $texts[2]->text);
        $this->assertSame('ok', $texts[3]->text);
        $this->assertSame('Gamma', $texts[4]->text);
        $this->assertSame('ok', $texts[5]->text);
        $this->assertCount(6, $rectangles);
    }

    public function testTableRecordsFrameSupportsFooterFormatter(): void
    {
        $document = Pdf::new()
            ->addPage(width: 320, height: 240)
            ->tableRecordsFrame(
                [
                    ['name' => 'Alpha', 'count' => 2],
                    ['name' => 'Beta', 'count' => 5],
                ],
                [
                    new TableDataColumn('Name', 'name', new TableColumn(100)),
                    new TableDataColumn('Count', 'count', new TableColumn(80, align: TableCell::ALIGN_RIGHT)),
                ],
                new TextFrame(20, 30, 240, 160),
                TableStyle::padded(4, borderColor: Color::black(), rowFillColor: Color::white(), lineWidth: 0.5),
                footerFormatter: static function (array $records): array {
                    $total = 0;

                    foreach ($records as $record) {
                        $total += (int) $record['count'];
                    }

                    return [[
                        new TableCell('Total', fillColor: Color::gray(0.9)),
                        new TableCell((string) $total, align: TableCell::ALIGN_RIGHT, fillColor: Color::gray(0.9)),
                    ]];
                },
            )
            ->endPage()
            ->build();

        $texts = $document->page(0)->texts();
        $rectangles = $document->page(0)->rectangles();

        $this->assertSame('Total', $texts[6]->text);
        $this->assertSame('7', $texts[7]->text);
        $this->assertGreaterThan(186.0, $texts[7]->x);
        $this->assertEquals(0.9, $rectangles[6]->fillColor?->r);
        $this->assertEquals(0.9, $rectangles[7]->fillColor?->r);
    }

    public function testTableRecordsFrameSupportsGroupedSections(): void
    {
        $document = Pdf::new()
            ->addPage(width: 320, height: 260)
            ->tableRecordsFrame(
                [
                    ['category' => 'Open', 'name' => 'Alpha'],
                    ['category' => 'Open', 'name' => 'Beta'],
                    ['category' => 'Closed', 'name' => 'Gamma'],
                ],
                [
                    new TableDataColumn('Name', 'name', new TableColumn(140)),
                ],
                new TextFrame(20, 30, 180, 180),
                TableStyle::padded(4, borderColor: Color::black(), rowFillColor: Color::white(), lineWidth: 0.5),
                groupResolver: static fn (array $record): string => $record['category'],
            )
            ->endPage()
            ->build();

        $texts = $document->page(0)->texts();
        $rectangles = $document->page(0)->rectangles();

        $this->assertSame('Name', $texts[0]->text);
        $this->assertSame('Open', $texts[1]->text);
        $this->assertSame('Alpha', $texts[2]->text);
        $this->assertSame('Beta', $texts[3]->text);
        $this->assertSame('Closed', $texts[4]->text);
        $this->assertSame('Gamma', $texts[5]->text);
        $this->assertEquals(0.92, $rectangles[1]->fillColor?->r);
        $this->assertEquals(0.92, $rectangles[4]->fillColor?->r);
    }

    public function testTableRecordsFrameSupportsGroupFooterRows(): void
    {
        $document = Pdf::new()
            ->addPage(width: 340, height: 280)
            ->tableRecordsFrame(
                [
                    ['category' => 'Open', 'name' => 'Alpha', 'count' => 2],
                    ['category' => 'Open', 'name' => 'Beta', 'count' => 3],
                    ['category' => 'Closed', 'name' => 'Gamma', 'count' => 5],
                ],
                [
                    new TableDataColumn('Name', 'name', new TableColumn(140)),
                    new TableDataColumn('Count', 'count', new TableColumn(80, align: TableCell::ALIGN_RIGHT)),
                ],
                new TextFrame(20, 30, 240, 200),
                TableStyle::padded(4, borderColor: Color::black(), rowFillColor: Color::white(), lineWidth: 0.5),
                groupResolver: static fn (array $record): string => $record['category'],
                groupFooterFormatter: static function (string $groupKey, array $groupRecords): array {
                    $subtotal = 0;

                    foreach ($groupRecords as $record) {
                        $subtotal += (int) $record['count'];
                    }

                    return [[
                        new TableCell($groupKey . ' subtotal', fillColor: Color::gray(0.88)),
                        new TableCell((string) $subtotal, align: TableCell::ALIGN_RIGHT, fillColor: Color::gray(0.88)),
                    ]];
                },
            )
            ->endPage()
            ->build();

        $texts = $document->page(0)->texts();
        $rectangles = $document->page(0)->rectangles();

        $this->assertSame('Open subtotal', $texts[7]->text);
        $this->assertSame('5', $texts[8]->text);
        $this->assertSame('Closed subtotal', $texts[12]->text);
        $this->assertSame('5', $texts[13]->text);
        $this->assertEquals(0.88, $rectangles[7]->fillColor?->r);
        $this->assertEquals(0.88, $rectangles[8]->fillColor?->r);
        $this->assertEquals(0.88, $rectangles[12]->fillColor?->r);
        $this->assertEquals(0.88, $rectangles[13]->fillColor?->r);
    }

    public function testTableRecordsFrameGroupHeaderFormatterCanUseFullGroupRecords(): void
    {
        $document = Pdf::new()
            ->addPage(width: 340, height: 280)
            ->tableRecordsFrame(
                [
                    ['category' => 'Open', 'name' => 'Alpha'],
                    ['category' => 'Open', 'name' => 'Beta'],
                    ['category' => 'Closed', 'name' => 'Gamma'],
                ],
                [
                    new TableDataColumn('Name', 'name', new TableColumn(160)),
                ],
                new TextFrame(20, 30, 220, 200),
                TableStyle::padded(4, borderColor: Color::black(), rowFillColor: Color::white(), lineWidth: 0.5),
                groupResolver: static fn (array $record): string => $record['category'],
                groupHeaderFormatter: static function (string $groupKey, array $firstRecord, array $columns, array $groupRecords): array {
                    return [[
                        new TableCell(
                            sprintf('%s (%d)', $groupKey, count($groupRecords)),
                            colspan: count($columns),
                            fillColor: Color::gray(0.9),
                        ),
                    ]];
                },
            )
            ->endPage()
            ->build();

        $texts = $document->page(0)->texts();
        $rectangles = $document->page(0)->rectangles();

        $this->assertSame('Open (2)', $texts[1]->text);
        $this->assertSame('Closed (1)', $texts[4]->text);
        $this->assertEquals(0.9, $rectangles[1]->fillColor?->r);
        $this->assertEquals(0.9, $rectangles[4]->fillColor?->r);
    }

    public function testTableRecordsFrameGroupHeaderFormatterCanUseGroupPositionContext(): void
    {
        $document = Pdf::new()
            ->addPage(width: 340, height: 300)
            ->tableRecordsFrame(
                [
                    ['category' => 'Open', 'name' => 'Alpha'],
                    ['category' => 'Open', 'name' => 'Beta'],
                    ['category' => 'Closed', 'name' => 'Gamma'],
                    ['category' => 'Archived', 'name' => 'Delta'],
                ],
                [
                    new TableDataColumn('Name', 'name', new TableColumn(160)),
                ],
                new TextFrame(20, 30, 220, 220),
                TableStyle::padded(4, borderColor: Color::black(), rowFillColor: Color::white(), lineWidth: 0.5),
                groupResolver: static fn (array $record): string => $record['category'],
                groupHeaderFormatter: static function (
                    string $groupKey,
                    array $firstRecord,
                    array $columns,
                    array $groupRecords,
                    int $groupIndex,
                    int $groupCount,
                ): array {
                    return [[
                        new TableCell(
                            sprintf('%d/%d %s', $groupIndex, $groupCount, $groupKey),
                            colspan: count($columns),
                            fillColor: Color::gray(0.91),
                        ),
                    ]];
                },
            )
            ->endPage()
            ->build();

        $texts = $document->page(0)->texts();

        $this->assertSame('1/3 Open', $texts[1]->text);
        $this->assertSame('2/3 Closed', $texts[4]->text);
        $this->assertSame('3/3 Archived', $texts[6]->text);
    }

    public function testTableRecordsFrameSupportsEmptyFormatter(): void
    {
        $document = Pdf::new()
            ->addPage(width: 320, height: 220)
            ->tableRecordsFrame(
                [],
                [
                    new TableDataColumn('Name', 'name', new TableColumn(120)),
                    new TableDataColumn('Count', 'count', new TableColumn(80)),
                ],
                new TextFrame(20, 30, 220, 120),
                TableStyle::padded(4, borderColor: Color::black(), headerFillColor: Color::white(), lineWidth: 0.5),
                emptyFormatter: static fn (array $columns): array => [[
                    new TableCell(
                        'No records found',
                        colspan: count($columns),
                        align: TableCell::ALIGN_CENTER,
                        color: Color::gray(0.35),
                        fillColor: Color::gray(0.94),
                    ),
                ]],
            )
            ->endPage()
            ->build();

        $texts = $document->page(0)->texts();
        $rectangles = $document->page(0)->rectangles();

        $this->assertSame('Name', $texts[0]->text);
        $this->assertSame('Count', $texts[1]->text);
        $this->assertSame('No records found', $texts[2]->text);
        $this->assertGreaterThan(70.0, $texts[2]->x);
        $this->assertEquals(0.35, $texts[2]->color?->r);
        $this->assertEquals(0.94, $rectangles[2]->fillColor?->r);
    }

    public function testTableRecordsFrameSupportsRecordSorter(): void
    {
        $document = Pdf::new()
            ->addPage(width: 320, height: 220)
            ->tableRecordsFrame(
                [
                    ['name' => 'Gamma', 'count' => 3],
                    ['name' => 'Alpha', 'count' => 1],
                    ['name' => 'Beta', 'count' => 2],
                ],
                [
                    new TableDataColumn('Name', 'name', new TableColumn(120)),
                    new TableDataColumn('Count', 'count', new TableColumn(80, align: TableCell::ALIGN_RIGHT)),
                ],
                new TextFrame(20, 30, 220, 140),
                TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
                recordSorter: static function (array $records): array {
                    usort($records, static fn (array $a, array $b): int => $a['count'] <=> $b['count']);

                    return $records;
                },
            )
            ->endPage()
            ->build();

        $texts = $document->page(0)->texts();

        $this->assertSame('Alpha', $texts[2]->text);
        $this->assertSame('1', $texts[3]->text);
        $this->assertSame('Beta', $texts[4]->text);
        $this->assertSame('2', $texts[5]->text);
        $this->assertSame('Gamma', $texts[6]->text);
        $this->assertSame('3', $texts[7]->text);
    }

    public function testTableRecordsFrameSupportsColumnFilter(): void
    {
        $document = Pdf::new()
            ->addPage(width: 340, height: 220)
            ->tableRecordsFrame(
                [
                    ['name' => 'Alpha', 'count' => 1, 'status' => 'ok'],
                    ['name' => 'Beta', 'count' => 2, 'status' => 'alert'],
                ],
                [
                    new TableDataColumn('Name', 'name', new TableColumn(120)),
                    new TableDataColumn('Count', 'count', new TableColumn(80, align: TableCell::ALIGN_RIGHT)),
                    new TableDataColumn('Status', 'status', new TableColumn(100)),
                ],
                new TextFrame(20, 30, 300, 140),
                TableStyle::padded(4, borderColor: Color::black(), lineWidth: 0.5),
                columnFilter: static function (array $columns): array {
                    return array_values(array_filter(
                        $columns,
                        static fn (TableDataColumn $column): bool => $column->header !== 'Count',
                    ));
                },
            )
            ->endPage()
            ->build();

        $texts = $document->page(0)->texts();
        $rectangles = $document->page(0)->rectangles();

        $this->assertCount(6, $texts);
        $this->assertSame('Name', $texts[0]->text);
        $this->assertSame('Status', $texts[1]->text);
        $this->assertSame('Alpha', $texts[2]->text);
        $this->assertSame('ok', $texts[3]->text);
        $this->assertSame('Beta', $texts[4]->text);
        $this->assertSame('alert', $texts[5]->text);
        $this->assertCount(6, $rectangles);
    }

    public function testSavesPdfBytes(): void
    {
        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('Hello', 72, 720))
            ->endPage()
            ->build()
            ->save();

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringContainsString('Hello', $bytes);
    }

    public function testGeneratedPageCanBeRotated(): void
    {
        $bytes = Pdf::new()
            ->addPage(rotation: 90)
            ->endPage()
            ->build()
            ->save();

        $this->assertStringContainsString('/Rotate 90', $bytes);
    }

    public function testGeneratedPageSizeCanBeChangedFluently(): void
    {
        $bytes = Pdf::new()
            ->addPage()
            ->pageSize(320, 640)
            ->endPage()
            ->build()
            ->save();

        $this->assertStringContainsString('/MediaBox [0 0 320.000000 640.000000]', $bytes);
    }

    public function testGeneratedPageRejectsInvalidSize(): void
    {
        $this->expectException(PdfException::class);

        Pdf::new()->addPage(width: 0);
    }

    public function testGeneratedPageBoxesCanBeSetFluently(): void
    {
        $bytes = Pdf::new()
            ->addPage()
            ->cropBox([10, 20, 290, 480])
            ->trimBox([20, 30, 280, 470])
            ->bleedBox([5, 15, 295, 490])
            ->artBox([30, 40, 270, 460])
            ->endPage()
            ->build()
            ->save();

        $this->assertStringContainsString('/CropBox [10 20 290 480]', $bytes);
        $this->assertStringContainsString('/TrimBox [20 30 280 470]', $bytes);
        $this->assertStringContainsString('/BleedBox [5 15 295 490]', $bytes);
        $this->assertStringContainsString('/ArtBox [30 40 270 460]', $bytes);
    }

    public function testGeneratedPageBoxRejectsInvalidInput(): void
    {
        $this->expectException(PdfException::class);

        Pdf::new()
            ->addPage()
            ->pageBox('UnknownBox', [10, 20, 30, 40]);
    }

    public function testGeneratedContentUsesSharedOperationSerialization(): void
    {
        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('Hello', 72, 720))
            ->line(10, 20, 30, 40)
            ->rectangle(new Rectangle(50, 60, 70, 80, strokeColor: Color::black(), fillColor: new Color(1, 0, 0)))
            ->endPage()
            ->build()
            ->save();

        $this->assertStringContainsString('/PT_F1 12 Tf', $bytes);
        $this->assertStringContainsString('1 0 0 1 72 110 Tm', $bytes);
        $this->assertStringContainsString('10 822 m', $bytes);
        $this->assertStringContainsString('30 802 l', $bytes);
        $this->assertStringContainsString('50 702 70 80 re', $bytes);
        $this->assertStringContainsString('B', $bytes);
    }

    public function testGeneratedContentSupportsGrayAndCmykColors(): void
    {
        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('Gray text', 72, 720, color: Color::gray(0.25)))
            ->line(10, 20, 30, 40, strokeColor: Color::cmyk(1, 0.5, 0, 0.25))
            ->rectangle(new Rectangle(50, 60, 70, 80, strokeColor: Color::gray(0.5), fillColor: Color::cmyk(0.1, 0.2, 0.3, 0.4)))
            ->endPage()
            ->build()
            ->save();

        $this->assertStringContainsString('0.25 g', $bytes);
        $this->assertStringContainsString('1 0.5 0 0.25 K', $bytes);
        $this->assertStringContainsString('0.1 0.2 0.3 0.4 k', $bytes);
        $this->assertStringContainsString('0.5 G', $bytes);
    }

    public function testSavesMultipleBaseFontsAsSeparateResources(): void
    {
        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('Sans', 72, 720, font: Pdf::font('Helvetica', 'bold')))
            ->text(new TextRun('Serif', 72, 700, font: Pdf::font('Times', 'italic')))
            ->text(new TextRun('Mono', 72, 680, font: Pdf::font('Courier', 'normal')))
            ->endPage()
            ->build()
            ->save();

        $this->assertStringContainsString('/BaseFont /Helvetica-Bold', $bytes);
        $this->assertStringContainsString('/BaseFont /Times-Italic', $bytes);
        $this->assertStringContainsString('/BaseFont /Courier', $bytes);
        $this->assertStringContainsString('/PT_F1', $bytes);
        $this->assertStringContainsString('/PT_F2', $bytes);
        $this->assertStringContainsString('/PT_F3', $bytes);
    }

    public function testSavesNonAsciiTextAsUtf16HexString(): void
    {
        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('Café', 72, 720))
            ->endPage()
            ->build()
            ->save();

        $this->assertStringContainsString('<FEFF00430061006600E9> Tj', $bytes);
    }

    public function testGeneratedFontsIncludeToUnicodeCMapForUsedSingleByteCharacters(): void
    {
        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('Az', 72, 720))
            ->endPage()
            ->build()
            ->save();

        $this->assertStringContainsString('/ToUnicode', $bytes);
        $this->assertStringContainsString('begincmap', $bytes);
        $this->assertStringContainsString('<41> <0041>', $bytes);
        $this->assertStringContainsString('<7A> <007A>', $bytes);
    }

    public function testEmbedsPngImageXObject(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, $this->rgbPngFixture());

        $bytes = Pdf::new()
            ->addPage()
            ->image(new ImagePlacement($path, 10, 20, 30, 40))
            ->endPage()
            ->build()
            ->save();

        unlink($path);

        $this->assertStringContainsString('/Subtype /Image', $bytes);
        $this->assertStringContainsString('/XObject', $bytes);
        $this->assertStringContainsString('/PT_Im1', $bytes);
        $this->assertStringContainsString('30 0 0 40 10 782 cm', $bytes);
        $this->assertStringContainsString('/PT_Im1 Do', $bytes);
    }

    public function testEmbedsRgbaPngWithSoftMask(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, $this->rgbaPngFixture());

        $bytes = Pdf::new()
            ->addPage()
            ->image(new ImagePlacement($path, 10, 20, 30, 40))
            ->endPage()
            ->build()
            ->save();

        unlink($path);

        $this->assertStringContainsString('/SMask', $bytes);
        $this->assertStringContainsString('/ColorSpace /DeviceGray', $bytes);
        $this->assertStringContainsString('/ColorSpace /DeviceRGB', $bytes);
        $this->assertStringContainsString('/PT_Im1 Do', $bytes);
    }

    public function testEmbedsGrayAlphaPngWithSoftMask(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, $this->grayAlphaPngFixture());

        $bytes = Pdf::new()
            ->addPage()
            ->image(new ImagePlacement($path, 10, 20, 30, 40))
            ->endPage()
            ->build()
            ->save();

        unlink($path);

        $this->assertStringContainsString('/SMask', $bytes);
        $this->assertStringContainsString('/ColorSpace /DeviceGray', $bytes);
        $this->assertStringContainsString('/PT_Im1 Do', $bytes);
    }

    public function testEmbedsGrayscalePngTransparencyMaskNatively(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, $this->transparentGrayPngFixture());

        $bytes = Pdf::new()
            ->addPage()
            ->image(new ImagePlacement($path, 10, 20, 30, 40))
            ->endPage()
            ->build()
            ->save();

        unlink($path);

        $this->assertStringContainsString('/Subtype /Image', $bytes);
        $this->assertStringContainsString('/ColorSpace /DeviceGray', $bytes);
        $this->assertStringContainsString('/Mask [153 153]', $bytes);
        $this->assertStringNotContainsString('/SMask', $bytes);
    }

    public function testEmbedsRgbPngTransparencyMaskNatively(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, $this->transparentRgbPngFixture());

        $bytes = Pdf::new()
            ->addPage()
            ->image(new ImagePlacement($path, 10, 20, 30, 40))
            ->endPage()
            ->build()
            ->save();

        unlink($path);

        $this->assertStringContainsString('/Subtype /Image', $bytes);
        $this->assertStringContainsString('/ColorSpace /DeviceRGB', $bytes);
        $this->assertStringContainsString('/Mask [255 255 0 0 0 0]', $bytes);
        $this->assertStringNotContainsString('/SMask', $bytes);
    }

    public function testEmbedsLowBitDepthGrayscalePngNatively(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, $this->lowBitDepthGrayPngFixture());

        $bytes = Pdf::new()
            ->addPage()
            ->image(new ImagePlacement($path, 10, 20, 30, 40))
            ->endPage()
            ->build()
            ->save();

        unlink($path);

        $this->assertStringContainsString('/Subtype /Image', $bytes);
        $this->assertStringContainsString('/ColorSpace /DeviceGray', $bytes);
        $this->assertStringContainsString('/BitsPerComponent 1', $bytes);
    }

    public function testEmbedsLowBitDepthGrayscalePngTransparencyMaskNatively(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, $this->transparentLowBitDepthGrayPngFixture());

        $bytes = Pdf::new()
            ->addPage()
            ->image(new ImagePlacement($path, 10, 20, 30, 40))
            ->endPage()
            ->build()
            ->save();

        unlink($path);

        $this->assertStringContainsString('/Subtype /Image', $bytes);
        $this->assertStringContainsString('/ColorSpace /DeviceGray', $bytes);
        $this->assertStringContainsString('/BitsPerComponent 1', $bytes);
        $this->assertStringContainsString('/Mask [1 1]', $bytes);
        $this->assertStringNotContainsString('/SMask', $bytes);
    }

    public function testEmbedsLowBitDepthIndexedPngNatively(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, $this->lowBitDepthIndexedPngFixture());

        $bytes = Pdf::new()
            ->addPage()
            ->image(new ImagePlacement($path, 10, 20, 30, 40))
            ->endPage()
            ->build()
            ->save();

        unlink($path);

        $this->assertStringContainsString('/Subtype /Image', $bytes);
        $this->assertStringContainsString('/ColorSpace [/Indexed /DeviceRGB', $bytes);
        $this->assertStringContainsString('/BitsPerComponent 4', $bytes);
    }

    public function testEmbedsLowBitDepthIndexedPngTransparencyWithSoftMask(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, $this->transparentLowBitDepthIndexedPngFixture());

        $bytes = Pdf::new()
            ->addPage()
            ->image(new ImagePlacement($path, 10, 20, 30, 40))
            ->endPage()
            ->build()
            ->save();

        unlink($path);

        $this->assertStringContainsString('/Subtype /Image', $bytes);
        $this->assertStringContainsString('/ColorSpace [/Indexed /DeviceRGB', $bytes);
        $this->assertStringContainsString('/BitsPerComponent 4', $bytes);
        $this->assertStringContainsString('/SMask', $bytes);
    }

    public function testWritesAcroFormTextField(): void
    {
        $bytes = Pdf::new()
            ->addPage()
            ->formField(new FormField('customer_name', 'text', 72, 700, 200, 24, ['value' => 'Ada']))
            ->endPage()
            ->build()
            ->save();

        $this->assertStringContainsString('/AcroForm', $bytes);
        $this->assertStringContainsString('/Subtype /Widget', $bytes);
        $this->assertStringContainsString('/FT /Tx', $bytes);
        $this->assertStringContainsString('/T (customer_name)', $bytes);
        $this->assertStringContainsString('/V (Ada)', $bytes);
        $this->assertStringContainsString('/Annots [', $bytes);
    }

    public function testWritesAcroFormCheckboxField(): void
    {
        $bytes = Pdf::new()
            ->addPage()
            ->formField(new FormField('accepted', 'checkbox', 72, 700, 16, 16, ['checked' => true]))
            ->endPage()
            ->build()
            ->save();

        $this->assertStringContainsString('/AcroForm', $bytes);
        $this->assertStringContainsString('/FT /Btn', $bytes);
        $this->assertStringContainsString('/V /Yes', $bytes);
        $this->assertStringContainsString('/AS /Yes', $bytes);
    }

    public function testFlattensGeneratedTextFieldsIntoPageContent(): void
    {
        $document = Pdf::new()
            ->addPage()
            ->formField(new FormField('customer_name', 'text', 72, 700, 200, 24, ['value' => 'Ada']))
            ->endPage()
            ->build()
            ->flattenGeneratedFormFields();

        $bytes = $document->save();

        $this->assertStringNotContainsString('/AcroForm', $bytes);
        $this->assertStringNotContainsString('/Subtype /Widget', $bytes);
        $this->assertStringContainsString('(Ada) Tj', $bytes);
    }

    public function testFlattensGeneratedCheckboxFieldsIntoPageContent(): void
    {
        $document = Pdf::new()
            ->addPage()
            ->formField(new FormField('accepted', 'checkbox', 72, 700, 16, 16, ['checked' => true]))
            ->endPage()
            ->build()
            ->flattenGeneratedFormFields();

        $bytes = $document->save();

        $this->assertStringNotContainsString('/AcroForm', $bytes);
        $this->assertStringNotContainsString('/Subtype /Widget', $bytes);
        $this->assertStringContainsString('72 126 16 16 re', $bytes);
        $this->assertStringContainsString('74 134 m', $bytes);
        $this->assertStringContainsString('86 128 l', $bytes);
    }

    public function testBuilderCanFlattenGeneratedFormFieldsBeforeBuild(): void
    {
        $bytes = Pdf::new()
            ->addPage()
            ->formField(new FormField('customer_name', 'text', 72, 700, 200, 24, ['value' => 'Ada']))
            ->endPage()
            ->flattenGeneratedFormFields()
            ->build()
            ->save();

        $this->assertStringNotContainsString('/AcroForm', $bytes);
        $this->assertStringContainsString('(Ada) Tj', $bytes);
    }

    public function testWritesTextAnnotation(): void
    {
        $bytes = Pdf::new()
            ->addPage()
            ->textAnnotation(new TextAnnotation('Review this', 72, 700, open: true))
            ->endPage()
            ->build()
            ->save();

        $this->assertStringContainsString('/Annots [', $bytes);
        $this->assertStringContainsString('/Subtype /Text', $bytes);
        $this->assertStringContainsString('/Contents (Review this)', $bytes);
        $this->assertStringContainsString('/Open true', $bytes);
        $this->assertStringContainsString('/Name /Note', $bytes);
    }

    public function testWritesLinkAnnotation(): void
    {
        $bytes = Pdf::new()
            ->addPage()
            ->linkAnnotation(new LinkAnnotation('https://example.test', 72, 700, 120, 20))
            ->endPage()
            ->build()
            ->save();

        $this->assertStringContainsString('/Annots [', $bytes);
        $this->assertStringContainsString('/Subtype /Link', $bytes);
        $this->assertStringContainsString('/Border [0 0 0]', $bytes);
        $this->assertStringContainsString('/S /URI', $bytes);
        $this->assertStringContainsString('/URI (https://example.test)', $bytes);
    }

    public function testWritesInternalPageLinkAnnotation(): void
    {
        $bytes = Pdf::new()
            ->addPage()
            ->linkAnnotation(LinkAnnotation::toPage(2, 72, 700, 120, 20, left: 10, top: 500, zoom: 2.0))
            ->endPage()
            ->addPage()
            ->endPage()
            ->build()
            ->save();

        $this->assertStringContainsString('/Subtype /Link', $bytes);
        $this->assertStringContainsString('/Dest [', $bytes);
        $this->assertStringContainsString('/XYZ 10 342 2', $bytes);
        $this->assertStringNotContainsString('/S /URI', $bytes);
    }

    public function testEmbedsIndexedPngNatively(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, $this->indexedPngFixture());

        $bytes = Pdf::new()
            ->addPage()
            ->image(new ImagePlacement($path, 10, 20, 30, 40))
            ->endPage()
            ->build()
            ->save();

        unlink($path);

        $this->assertStringContainsString('/Subtype /Image', $bytes);
        $this->assertStringContainsString('/ColorSpace [/Indexed /DeviceRGB', $bytes);
        $this->assertStringContainsString('/PT_Im1 Do', $bytes);
    }

    public function testEmbedsTransparentIndexedPngWithSoftMask(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, $this->transparentIndexedPngFixture());

        $bytes = Pdf::new()
            ->addPage()
            ->image(new ImagePlacement($path, 10, 20, 30, 40))
            ->endPage()
            ->build()
            ->save();

        unlink($path);

        $this->assertStringContainsString('/Subtype /Image', $bytes);
        $this->assertStringContainsString('/ColorSpace [/Indexed /DeviceRGB', $bytes);
        $this->assertStringContainsString('/SMask', $bytes);
        $this->assertStringContainsString('/ColorSpace /DeviceGray', $bytes);
        $this->assertStringContainsString('/PT_Im1 Do', $bytes);
    }

    public function testEmbedsInterlacedPngViaRasterFallback(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is required for interlaced PNG fixture generation.');
        }

        $path = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, $this->interlacedPngFixture());

        $bytes = Pdf::new()
            ->addPage()
            ->image(new ImagePlacement($path, 10, 20, 30, 40))
            ->endPage()
            ->build()
            ->save();

        unlink($path);

        $this->assertStringContainsString('/Subtype /Image', $bytes);
        $this->assertStringContainsString('/ColorSpace /DeviceRGB', $bytes);
        $this->assertStringContainsString('/PT_Im1 Do', $bytes);
    }

    public function testEmbedsWebpViaRasterFallback(): void
    {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support is required for WebP fixture generation.');
        }

        $path = tempnam(sys_get_temp_dir(), 'webp');
        file_put_contents($path, $this->webpFixture());

        $bytes = Pdf::new()
            ->addPage()
            ->image(new ImagePlacement($path, 10, 20, 30, 40))
            ->endPage()
            ->build()
            ->save();

        unlink($path);

        $this->assertStringContainsString('/Subtype /Image', $bytes);
        $this->assertStringContainsString('/ColorSpace /DeviceRGB', $bytes);
        $this->assertStringContainsString('/PT_Im1 Do', $bytes);
    }

    public function testEmbedsSvgViaMagickRasterFallback(): void
    {
        if (trim((string) shell_exec('command -v magick 2>/dev/null')) === '') {
            $this->markTestSkipped('ImageMagick is required for SVG fixture rendering.');
        }

        $previous = getenv('PDFTOOLKIT_ENABLE_SVG_MAGICK');
        putenv('PDFTOOLKIT_ENABLE_SVG_MAGICK=1');

        $path = tempnam(sys_get_temp_dir(), 'svg');
        file_put_contents($path, $this->svgFixture());

        try {
            $bytes = Pdf::new()
                ->addPage()
                ->image(new ImagePlacement($path, 10, 20, 30, 40))
                ->endPage()
                ->build()
                ->save();

            $this->assertStringContainsString('/Subtype /Image', $bytes);
            $this->assertStringContainsString('/ColorSpace /DeviceRGB', $bytes);
            $this->assertStringContainsString('/PT_Im1 Do', $bytes);
        } finally {
            unlink($path);

            if ($previous === false) {
                putenv('PDFTOOLKIT_ENABLE_SVG_MAGICK');
            } else {
                putenv('PDFTOOLKIT_ENABLE_SVG_MAGICK=' . $previous);
            }
        }
    }

    public function testRejectsSvgRasterizationByDefaultForSecurity(): void
    {
        $previous = getenv('PDFTOOLKIT_ENABLE_SVG_MAGICK');
        putenv('PDFTOOLKIT_ENABLE_SVG_MAGICK=0');

        $path = tempnam(sys_get_temp_dir(), 'svg');
        file_put_contents($path, $this->svgFixture());

        try {
            $this->expectException(PdfException::class);
            $this->expectExceptionMessage('SVG decoding via ImageMagick is disabled by default for security');

            (new ImageReader())->read($path);
        } finally {
            unlink($path);

            if ($previous === false) {
                putenv('PDFTOOLKIT_ENABLE_SVG_MAGICK');
            } else {
                putenv('PDFTOOLKIT_ENABLE_SVG_MAGICK=' . $previous);
            }
        }
    }

    public function testEmbedsRawInlineSvgDataWhenExplicitlyEnabled(): void
    {
        $previous = getenv('PDFTOOLKIT_ENABLE_SVG_MAGICK');
        putenv('PDFTOOLKIT_ENABLE_SVG_MAGICK=1');

        try {
            $bytes = Pdf::new()
                ->addPage()
                ->image(ImagePlacement::svgData($this->svgFixture(), 10, 20, 30, 40))
                ->endPage()
                ->build()
                ->save();

            $this->assertStringContainsString('/Subtype /Image', $bytes);
            $this->assertStringContainsString('/ColorSpace /DeviceRGB', $bytes);
            $this->assertStringContainsString('/PT_Im1 Do', $bytes);
        } finally {
            if ($previous === false) {
                putenv('PDFTOOLKIT_ENABLE_SVG_MAGICK');
            } else {
                putenv('PDFTOOLKIT_ENABLE_SVG_MAGICK=' . $previous);
            }
        }
    }

    public function testPathBasedInlineSvgDataProducesVisiblePixelsWhenExplicitlyEnabled(): void
    {
        $previous = getenv('PDFTOOLKIT_ENABLE_SVG_MAGICK');
        putenv('PDFTOOLKIT_ENABLE_SVG_MAGICK=1');

        try {
            $reader = new ImageReader();
            $image = $reader->readPlacement(ImagePlacement::svgData(
                $this->signatureSvgFixture(),
                10,
                20,
                30,
                40
            ));

            $rgb = gzuncompress($image->data);

            $this->assertIsString($rgb);
            $this->assertMatchesRegularExpression('/[^\x00]/', $rgb);
            $this->assertTrue(
                $image->softMask !== null || isset($image->dictionary['Mask']),
                'Expected fallback SVG image to preserve transparency via either SMask or color-key Mask.'
            );
        } finally {
            if ($previous === false) {
                putenv('PDFTOOLKIT_ENABLE_SVG_MAGICK');
            } else {
                putenv('PDFTOOLKIT_ENABLE_SVG_MAGICK=' . $previous);
            }
        }
    }

    public function testRejectsRawInlineSvgRasterizationByDefaultForSecurity(): void
    {
        $previous = getenv('PDFTOOLKIT_ENABLE_SVG_MAGICK');
        putenv('PDFTOOLKIT_ENABLE_SVG_MAGICK=0');

        try {
            $this->expectException(PdfException::class);
            $this->expectExceptionMessage('SVG decoding via ImageMagick is disabled by default for security');

            Pdf::new()
                ->addPage()
                ->image(ImagePlacement::svgData($this->svgFixture(), 10, 20, 30, 40))
                ->endPage()
                ->build()
                ->save();
        } finally {
            if ($previous === false) {
                putenv('PDFTOOLKIT_ENABLE_SVG_MAGICK');
            } else {
                putenv('PDFTOOLKIT_ENABLE_SVG_MAGICK=' . $previous);
            }
        }
    }

    public function testRejectsPngDecodesThatExceedConfiguredSafetyLimit(): void
    {
        $previous = getenv('PDFTOOLKIT_MAX_DECODED_IMAGE_BYTES');
        putenv('PDFTOOLKIT_MAX_DECODED_IMAGE_BYTES=1');

        $path = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, $this->rgbaPngFixture());

        try {
            $this->expectException(PdfException::class);
            $this->expectExceptionMessage('Unable to decode PNG alpha data');

            (new ImageReader())->read($path);
        } finally {
            unlink($path);

            if ($previous === false) {
                putenv('PDFTOOLKIT_MAX_DECODED_IMAGE_BYTES');
            } else {
                putenv('PDFTOOLKIT_MAX_DECODED_IMAGE_BYTES=' . $previous);
            }
        }
    }

    public function testEmbedsCmykJpegWithAdobeDecodeArrayWhenNeeded(): void
    {
        if (trim((string) shell_exec('command -v magick 2>/dev/null')) === '') {
            $this->markTestSkipped('ImageMagick is required for CMYK JPEG fixture generation.');
        }

        $path = tempnam(sys_get_temp_dir(), 'jpg');
        file_put_contents($path, $this->cmykJpegFixture());

        $bytes = Pdf::new()
            ->addPage()
            ->image(new ImagePlacement($path, 10, 20, 30, 40))
            ->endPage()
            ->build()
            ->save();

        $reader = new ImageReader();
        $method = new \ReflectionMethod($reader, 'jpegDecodeArray');
        $method->setAccessible(true);
        $decode = $method->invoke($reader, file_get_contents($path), 4);

        unlink($path);

        $this->assertStringContainsString('/Subtype /Image', $bytes);
        $this->assertStringContainsString('/ColorSpace /DeviceCMYK', $bytes);

        if ($decode !== null) {
            $this->assertStringContainsString('/Decode [1 0 1 0 1 0 1 0]', $bytes);
        } else {
            $this->assertStringNotContainsString('/Decode [1 0 1 0 1 0 1 0]', $bytes);
        }
    }

    public function testEmbedsJpegWithIccProfileWhenPresent(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD JPEG support is required for JPEG fixture generation.');
        }

        $path = tempnam(sys_get_temp_dir(), 'jpg');
        file_put_contents($path, $this->jpegWithIccProfileFixture());

        $bytes = Pdf::new()
            ->addPage()
            ->image(new ImagePlacement($path, 10, 20, 30, 40))
            ->endPage()
            ->build()
            ->save();

        unlink($path);

        $this->assertStringContainsString('/ColorSpace [/ICCBased ', $bytes);
        $this->assertStringContainsString('/Alternate /DeviceRGB', $bytes);
        $this->assertStringContainsString('/N 3', $bytes);
    }

    public function testDetectsAdobeApp14JpegMarkerForCmykDecodeHandling(): void
    {
        $reader = new ImageReader();
        $method = new \ReflectionMethod($reader, 'jpegDecodeArray');
        $method->setAccessible(true);

        $jpegWithAdobe = "\xFF\xD8"
            . "\xFF\xEE\x00\x0EAdobe\x00\x64\x00\x00\x00\x00\x02"
            . "\xFF\xDA\x00\x08\x01\x01\x00\x00\x3F\x00"
            . "\xFF\xD9";
        $jpegWithoutAdobe = "\xFF\xD8"
            . "\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
            . "\xFF\xDA\x00\x08\x01\x01\x00\x00\x3F\x00"
            . "\xFF\xD9";

        $this->assertSame([1, 0, 1, 0, 1, 0, 1, 0], $method->invoke($reader, $jpegWithAdobe, 4));
        $this->assertNull($method->invoke($reader, $jpegWithoutAdobe, 4));
        $this->assertNull($method->invoke($reader, $jpegWithAdobe, 3));
    }

    public function testReassemblesJpegIccProfileSegments(): void
    {
        $reader = new ImageReader();
        $method = new \ReflectionMethod($reader, 'jpegIccProfile');
        $method->setAccessible(true);

        $jpeg = "\xFF\xD8"
            . $this->jpegAppSegment(0xE2, "ICC_PROFILE\0" . chr(2) . chr(2) . 'world')
            . $this->jpegAppSegment(0xE2, "ICC_PROFILE\0" . chr(1) . chr(2) . 'hello ')
            . "\xFF\xDA\x00\x08\x01\x01\x00\x00\x3F\x00"
            . "\xFF\xD9";

        $this->assertSame('hello world', $method->invoke($reader, $jpeg));
    }

    public function testEmbedsCustomTrueTypeFontForBasicLatinText(): void
    {
        $fontPath = $this->customTrueTypeFixturePath();

        if ($fontPath === null || !function_exists('imagettfbbox')) {
            $this->markTestSkipped('A local TrueType font and FreeType support are required for custom font embedding.');
        }

        $parsedFont = (new TrueTypeFontParser())->parse($fontPath);

        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('Hello TTF', 72, 720, font: Pdf::trueTypeFont($fontPath, 'CustomTTF')))
            ->endPage()
            ->build()
            ->save();

        $this->assertStringContainsString('/Subtype /TrueType', $bytes);
        $this->assertMatchesRegularExpression(
            '/\/BaseFont \/[A-Z]{6}\+' . preg_quote((string) $parsedFont->postScriptName, '/') . '/',
            $bytes
        );
        $this->assertStringContainsString('/FontDescriptor', $bytes);
        $this->assertMatchesRegularExpression(
            '/\/FontName \/[A-Z]{6}\+' . preg_quote((string) $parsedFont->postScriptName, '/') . '/',
            $bytes
        );
        $this->assertStringContainsString(sprintf('/Flags %d', $parsedFont->descriptorFlags()), $bytes);
        $this->assertStringContainsString('/FontFile2', $bytes);
        $this->assertStringContainsString('/WinAnsiEncoding', $bytes);
        $this->assertStringContainsString('/FirstChar 32', $bytes);
        $this->assertStringContainsString('/LastChar 111', $bytes);
        $this->assertStringNotContainsString('/LastChar 126', $bytes);
        $this->assertSame(
            $this->fontDescriptorBaseFontName($bytes),
            $this->embeddedFontProgramPostScriptName($bytes),
        );
        $this->assertLessThan(
            strlen((new TrueTypeFontParser())->fontProgram($fontPath)),
            strlen($this->embeddedFontFile2Stream($bytes))
        );
        $this->assertStringContainsString(
            sprintf('/Ascent %d', $this->normalizeTrueTypeMetricForTest($parsedFont->ascent, $parsedFont->unitsPerEm)),
            $bytes
        );
        $this->assertStringContainsString(
            sprintf('/Descent %d', $this->normalizeTrueTypeMetricForTest($parsedFont->descent, $parsedFont->unitsPerEm)),
            $bytes
        );
        $this->assertStringContainsString(
            sprintf('/Leading %d', $this->normalizeTrueTypeMetricForTest($parsedFont->lineGap, $parsedFont->unitsPerEm)),
            $bytes
        );
        $this->assertStringContainsString(
            sprintf('/CapHeight %d', $this->normalizeTrueTypeMetricForTest($parsedFont->capHeight, $parsedFont->unitsPerEm)),
            $bytes
        );
        $this->assertStringContainsString(
            sprintf('/XHeight %d', $this->normalizeTrueTypeMetricForTest($parsedFont->xHeight, $parsedFont->unitsPerEm)),
            $bytes
        );
        $this->assertStringContainsString(
            sprintf('/AvgWidth %d', $this->normalizeTrueTypeMetricForTest($parsedFont->averageWidth(), $parsedFont->unitsPerEm)),
            $bytes
        );
        $this->assertStringContainsString(
            sprintf('/MaxWidth %d', $this->normalizeTrueTypeMetricForTest($parsedFont->maxWidth(), $parsedFont->unitsPerEm)),
            $bytes
        );
        $this->assertStringContainsString(
            sprintf('/MissingWidth %d', $this->normalizeTrueTypeMetricForTest($parsedFont->missingWidth(), $parsedFont->unitsPerEm)),
            $bytes
        );
        $this->assertStringContainsString(sprintf('/StemV %d', $parsedFont->stemV()), $bytes);
    }

    public function testEmbedsCustomTrueTypeCollectionFontForBasicLatinText(): void
    {
        $fontPath = $this->customTrueTypeCollectionFixturePath();

        if ($fontPath === null || !function_exists('imagettfbbox')) {
            $this->markTestSkipped('A local TrueType collection font and FreeType support are required for TTC embedding.');
        }

        $parsedFont = (new TrueTypeFontParser())->parse($fontPath);

        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('Hello TTC', 72, 720, font: Pdf::trueTypeFont($fontPath, 'CustomTTC')))
            ->endPage()
            ->build()
            ->save();

        $this->assertStringContainsString('/Subtype /TrueType', $bytes);
        $this->assertMatchesRegularExpression(
            '/\/BaseFont \/[A-Z]{6}\+' . preg_quote((string) $parsedFont->postScriptName, '/') . '/',
            $bytes
        );
        $this->assertStringContainsString('/FontDescriptor', $bytes);
        $this->assertStringContainsString(sprintf('/Flags %d', $parsedFont->descriptorFlags()), $bytes);
        $this->assertStringContainsString('/FontFile2', $bytes);
        $this->assertStringContainsString('/WinAnsiEncoding', $bytes);
    }

    public function testEmbedsSelectedTrueTypeCollectionFaceForBasicLatinText(): void
    {
        $fontPath = $this->customTrueTypeCollectionFixturePath();

        if ($fontPath === null || !function_exists('imagettfbbox')) {
            $this->markTestSkipped('A local TrueType collection font and FreeType support are required for TTC face embedding.');
        }

        $parser = new TrueTypeFontParser();

        if ($parser->faceCount($fontPath) < 2) {
            $this->markTestSkipped('The local TTC fixture does not expose multiple selectable faces.');
        }

        $parsedFont = $parser->parse($fontPath, 1);

        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('Hello TTC Face', 72, 720, font: Pdf::trueTypeFont($fontPath, 'CustomTTCFace', faceIndex: 1)))
            ->endPage()
            ->build()
            ->save();

        $this->assertStringContainsString('/Subtype /TrueType', $bytes);
        $this->assertMatchesRegularExpression(
            '/\/BaseFont \/[A-Z]{6}\+' . preg_quote((string) $parsedFont->postScriptName, '/') . '/',
            $bytes
        );
        $this->assertStringContainsString(sprintf('/Flags %d', $parsedFont->descriptorFlags()), $bytes);
        $this->assertStringContainsString('/FontFile2', $bytes);
        $this->assertStringContainsString('/WinAnsiEncoding', $bytes);
        $this->assertStringNotContainsString('ttcf', $bytes);
    }

    public function testEmbedsCompositeTrueTypeFontForExtendedUnicodeText(): void
    {
        $fontPath = $this->customTrueTypeFixturePath();

        if ($fontPath === null) {
            $this->markTestSkipped('A local TrueType font is required for composite font embedding.');
        }

        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('Café Łódź', 72, 720, font: Pdf::trueTypeFont($fontPath, 'CustomTTF')))
            ->endPage()
            ->build()
            ->save();

        $this->assertStringContainsString('/Subtype /Type0', $bytes);
        $this->assertStringContainsString('/CIDFontType2', $bytes);
        $this->assertStringContainsString('/Encoding /Identity-H', $bytes);
        $this->assertStringContainsString('/CIDToGIDMap', $bytes);
        $this->assertStringContainsString('/CIDSet', $bytes);
        $this->assertMatchesRegularExpression('/\/BaseFont \/[A-Z]{6}\+[A-Za-z0-9_-]+/', $bytes);
        $this->assertMatchesRegularExpression('/\/FontName \/[A-Z]{6}\+[A-Za-z0-9_-]+/', $bytes);
        $this->assertSame(
            $this->fontDescriptorBaseFontName($bytes),
            $this->embeddedFontProgramPostScriptName($bytes),
        );
        $this->assertLessThan(
            strlen((new TrueTypeFontParser())->fontProgram($fontPath)),
            strlen($this->embeddedFontFile2Stream($bytes))
        );
        $this->assertMatchesRegularExpression('/<[0-9A-F]+> Tj/', $bytes);
        $this->assertStringNotContainsString('<FEFF00430061006600E90020014100F30064017A> Tj', $bytes);
    }

    public function testEmbedsCompositeTrueTypeFontForSupplementaryPlaneText(): void
    {
        $fontPath = $this->supplementaryPlaneTrueTypeFixturePath();

        if ($fontPath === null) {
            $this->markTestSkipped('A local TrueType font with supplementary-plane glyphs is required.');
        }

        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('𐍈', 72, 720, font: Pdf::trueTypeFont($fontPath, 'SupplementaryTTF')))
            ->endPage()
            ->build()
            ->save();

        $this->assertStringContainsString('/Subtype /Type0', $bytes);
        $this->assertStringContainsString('/CIDFontType2', $bytes);
        $this->assertStringContainsString('/CIDToGIDMap', $bytes);
        $this->assertStringContainsString('/CIDSet', $bytes);
        $this->assertStringContainsString('<D800DF48>', $bytes);
        $this->assertMatchesRegularExpression('/<[0-9A-F]+> Tj/', $bytes);
        $this->assertStringNotContainsString('<FEFFD800DF48> Tj', $bytes);
    }

    public function testShapesUnicodeMappableLigaturesIntoCompositeTrueTypeOutput(): void
    {
        $fontPath = $this->customTrueTypeFixturePath();

        if ($fontPath === null) {
            $this->markTestSkipped('A local TrueType font is required for ligature shaping output tests.');
        }

        $parsedFont = (new TrueTypeFontParser())->parse($fontPath);
        $candidate = $this->ligatureCandidate($parsedFont);

        if ($candidate === null) {
            $this->markTestSkipped('The local TrueType fixture does not expose a Unicode-mappable GSUB ligature.');
        }

        [$sourceText, $ligatureCharacter] = $candidate;

        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun($sourceText, 72, 720, font: Pdf::trueTypeFont($fontPath, 'LigatureTTF')))
            ->endPage()
            ->build()
            ->save();

        $this->assertStringContainsString('/Subtype /Type0', $bytes);
        $this->assertStringContainsString(strtoupper(bin2hex(iconv('UTF-8', 'UTF-16BE', $ligatureCharacter) ?: '')), $bytes);
        $this->assertStringContainsString(strtoupper(bin2hex(iconv('UTF-8', 'UTF-16BE', $sourceText) ?: '')), $bytes);
    }

    public function testAppliesKerningPairsForCustomTrueTypeTextWhenAvailable(): void
    {
        $fontPath = $this->customTrueTypeFixturePath();

        if ($fontPath === null) {
            $this->markTestSkipped('A local TrueType font is required for kerning output tests.');
        }

        $parsedFont = (new TrueTypeFontParser())->parse($fontPath);
        $leftGlyphId = $parsedFont->glyphIdForCodePoint(mb_ord('A'));
        $rightGlyphId = $parsedFont->glyphIdForCodePoint(mb_ord('V'));

        if ($leftGlyphId === null || $rightGlyphId === null || $parsedFont->kerningForGlyphPair($leftGlyphId, $rightGlyphId) === 0) {
            $this->markTestSkipped('The local TrueType fixture does not expose an AV kerning pair.');
        }

        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('AV', 72, 720, font: Pdf::trueTypeFont($fontPath, 'KerningTTF')))
            ->endPage()
            ->build()
            ->save();

        $this->assertMatchesRegularExpression('/\[[^\]]+\] TJ/', $bytes);
        $this->assertStringNotContainsString('(AV) Tj', $bytes);
    }

    public function testAppliesKerningPairsForCompositeCustomTrueTypeTextWhenAvailable(): void
    {
        $fontPath = $this->customTrueTypeFixturePath();

        if ($fontPath === null) {
            $this->markTestSkipped('A local TrueType font is required for composite kerning output tests.');
        }

        $parsedFont = (new TrueTypeFontParser())->parse($fontPath);
        $leftGlyphId = $parsedFont->glyphIdForCodePoint(mb_ord('A'));
        $rightGlyphId = $parsedFont->glyphIdForCodePoint(mb_ord('V'));
        $accentGlyphId = $parsedFont->glyphIdForCodePoint(mb_ord('é'));

        if (
            $leftGlyphId === null
            || $rightGlyphId === null
            || $accentGlyphId === null
            || $parsedFont->kerningForGlyphPair($leftGlyphId, $rightGlyphId) === 0
        ) {
            $this->markTestSkipped('The local TrueType fixture does not expose the glyphs needed for composite kerning tests.');
        }

        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('AVé', 72, 720, font: Pdf::trueTypeFont($fontPath, 'CompositeKerningTTF')))
            ->endPage()
            ->build()
            ->save();

        $this->assertStringContainsString('/Subtype /Type0', $bytes);
        $this->assertMatchesRegularExpression('/\[[^\]]*<[0-9A-F]{4}> -?[0-9]+ <[0-9A-F]{4}>[^\]]*\] TJ/', $bytes);
        $this->assertStringNotContainsString('<FEFF0041005600E9> Tj', $bytes);
    }

    public function testPdfFacadeCanBuildFontReferences(): void
    {
        $font = Pdf::font('Helvetica', 'bold');

        $this->assertSame('Helvetica', $font->family);
        $this->assertSame('bold', $font->style);
        $this->assertNull($font->sourcePath);
    }

    public function testInternalPageLinkRejectsMissingTargetOnSave(): void
    {
        $document = Pdf::new()
            ->addPage()
            ->linkAnnotation(LinkAnnotation::toPage(2, 72, 700, 120, 20))
            ->endPage()
            ->build();

        $this->expectException(\LogicException::class);

        $document->save();
    }

    private function rgbPngFixture(): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };

        $ihdr = pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0);
        $idat = gzcompress("\x00\xFF\x00\x00");

        return "\x89PNG\r\n\x1A\n"
            . $chunk('IHDR', $ihdr)
            . $chunk('IDAT', $idat)
            . $chunk('IEND', '');
    }

    private function rgbaPngFixture(): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };

        $ihdr = pack('NNCCCCC', 1, 1, 8, 6, 0, 0, 0);
        $idat = gzcompress("\x00\xFF\x00\x00\x80");

        return "\x89PNG\r\n\x1A\n"
            . $chunk('IHDR', $ihdr)
            . $chunk('IDAT', $idat)
            . $chunk('IEND', '');
    }

    private function indexedPngFixture(): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };

        $ihdr = pack('NNCCCCC', 2, 1, 8, 3, 0, 0, 0);
        $plte = "\xFF\x00\x00\x00\x80\xFF";
        $idat = gzcompress("\x00\x00\x01");

        return "\x89PNG\r\n\x1A\n"
            . $chunk('IHDR', $ihdr)
            . $chunk('PLTE', $plte)
            . $chunk('IDAT', $idat)
            . $chunk('IEND', '');
    }

    private function transparentIndexedPngFixture(): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };

        $ihdr = pack('NNCCCCC', 2, 1, 8, 3, 0, 0, 0);
        $plte = "\xFF\x00\x00\x00\x80\xFF";
        $trns = "\x00\xFF";
        $idat = gzcompress("\x00\x00\x01");

        return "\x89PNG\r\n\x1A\n"
            . $chunk('IHDR', $ihdr)
            . $chunk('PLTE', $plte)
            . $chunk('tRNS', $trns)
            . $chunk('IDAT', $idat)
            . $chunk('IEND', '');
    }

    private function grayAlphaPngFixture(): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };

        $ihdr = pack('NNCCCCC', 1, 1, 8, 4, 0, 0, 0);
        $idat = gzcompress("\x00\x99\x80");

        return "\x89PNG\r\n\x1A\n"
            . $chunk('IHDR', $ihdr)
            . $chunk('IDAT', $idat)
            . $chunk('IEND', '');
    }

    private function transparentGrayPngFixture(): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };

        $ihdr = pack('NNCCCCC', 1, 1, 8, 0, 0, 0, 0);
        $trns = pack('n', 0x0099);
        $idat = gzcompress("\x00\x99");

        return "\x89PNG\r\n\x1A\n"
            . $chunk('IHDR', $ihdr)
            . $chunk('tRNS', $trns)
            . $chunk('IDAT', $idat)
            . $chunk('IEND', '');
    }

    private function transparentRgbPngFixture(): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };

        $ihdr = pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0);
        $trns = pack('nnn', 0x00FF, 0x0000, 0x0000);
        $idat = gzcompress("\x00\xFF\x00\x00");

        return "\x89PNG\r\n\x1A\n"
            . $chunk('IHDR', $ihdr)
            . $chunk('tRNS', $trns)
            . $chunk('IDAT', $idat)
            . $chunk('IEND', '');
    }

    private function lowBitDepthGrayPngFixture(): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };

        $ihdr = pack('NNCCCCC', 8, 1, 1, 0, 0, 0, 0);
        $idat = gzcompress("\x00\x55");

        return "\x89PNG\r\n\x1A\n"
            . $chunk('IHDR', $ihdr)
            . $chunk('IDAT', $idat)
            . $chunk('IEND', '');
    }

    private function transparentLowBitDepthGrayPngFixture(): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };

        $ihdr = pack('NNCCCCC', 8, 1, 1, 0, 0, 0, 0);
        $trns = pack('n', 0x0001);
        $idat = gzcompress("\x00\x55");

        return "\x89PNG\r\n\x1A\n"
            . $chunk('IHDR', $ihdr)
            . $chunk('tRNS', $trns)
            . $chunk('IDAT', $idat)
            . $chunk('IEND', '');
    }

    private function lowBitDepthIndexedPngFixture(): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };

        $ihdr = pack('NNCCCCC', 2, 1, 4, 3, 0, 0, 0);
        $plte = "\xFF\x00\x00\x00\x80\xFF";
        $idat = gzcompress("\x00\x01");

        return "\x89PNG\r\n\x1A\n"
            . $chunk('IHDR', $ihdr)
            . $chunk('PLTE', $plte)
            . $chunk('IDAT', $idat)
            . $chunk('IEND', '');
    }

    private function transparentLowBitDepthIndexedPngFixture(): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };

        $ihdr = pack('NNCCCCC', 2, 1, 4, 3, 0, 0, 0);
        $plte = "\xFF\x00\x00\x00\x80\xFF";
        $trns = "\x00\xFF";
        $idat = gzcompress("\x00\x01");

        return "\x89PNG\r\n\x1A\n"
            . $chunk('IHDR', $ihdr)
            . $chunk('PLTE', $plte)
            . $chunk('tRNS', $trns)
            . $chunk('IDAT', $idat)
            . $chunk('IEND', '');
    }

    private function interlacedPngFixture(): string
    {
        $image = imagecreatetruecolor(2, 2);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imageinterlace($image, true);

        $red = imagecolorallocate($image, 255, 0, 0);
        $green = imagecolorallocate($image, 0, 255, 0);
        $blue = imagecolorallocate($image, 0, 0, 255);
        $white = imagecolorallocate($image, 255, 255, 255);

        imagesetpixel($image, 0, 0, $red);
        imagesetpixel($image, 1, 0, $green);
        imagesetpixel($image, 0, 1, $blue);
        imagesetpixel($image, 1, 1, $white);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function webpFixture(): string
    {
        $image = imagecreatetruecolor(2, 2);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $red = imagecolorallocate($image, 255, 0, 0);
        $green = imagecolorallocate($image, 0, 255, 0);
        $blue = imagecolorallocate($image, 0, 0, 255);
        $white = imagecolorallocate($image, 255, 255, 255);

        imagesetpixel($image, 0, 0, $red);
        imagesetpixel($image, 1, 0, $green);
        imagesetpixel($image, 0, 1, $blue);
        imagesetpixel($image, 1, 1, $white);

        ob_start();
        imagewebp($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function cmykJpegFixture(): string
    {
        $sourcePath = tempnam(sys_get_temp_dir(), 'png');
        $targetBasePath = tempnam(sys_get_temp_dir(), 'jpg');

        if ($sourcePath === false || $targetBasePath === false) {
            $this->fail('Unable to allocate temporary files for CMYK JPEG fixture generation.');
        }

        $targetPath = $targetBasePath . '.jpg';
        @unlink($targetBasePath);

        file_put_contents($sourcePath, $this->rgbPngFixture());

        $command = sprintf(
            'magick %s -colorspace CMYK JPEG:%s 2>/dev/null',
            escapeshellarg($sourcePath),
            escapeshellarg($targetPath)
        );

        exec($command, $output, $exitCode);
        @unlink($sourcePath);

        if ($exitCode !== 0 || !is_file($targetPath)) {
            @unlink($targetPath);
            $this->fail('Unable to generate CMYK JPEG fixture with ImageMagick.');
        }

        $bytes = file_get_contents($targetPath);
        @unlink($targetPath);

        if (!is_string($bytes) || $bytes === '') {
            $this->fail('Unable to read generated CMYK JPEG fixture.');
        }

        return $bytes;
    }

    private function jpegWithIccProfileFixture(): string
    {
        $image = imagecreatetruecolor(1, 1);
        $red = imagecolorallocate($image, 255, 0, 0);
        imagesetpixel($image, 0, 0, $red);

        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        return substr($jpeg, 0, 2)
            . $this->jpegAppSegment(0xE2, "ICC_PROFILE\0" . chr(1) . chr(1) . 'Demo ICC Profile')
            . substr($jpeg, 2);
    }

    private function jpegAppSegment(int $marker, string $payload): string
    {
        return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
    }

    private function normalizeTrueTypeMetricForTest(int $metric, int $unitsPerEm): int
    {
        return (int) round(($metric / max(1, $unitsPerEm)) * 1000);
    }

    private function embeddedFontFile2Stream(string $pdfBytes): string
    {
        if (!preg_match('/\/FontFile2\s+(\d+)\s+0\s+R/', $pdfBytes, $matches)) {
            $this->fail('Unable to locate FontFile2 reference in generated PDF.');
        }

        $objectNumber = (int) $matches[1];

        if (!preg_match(
            sprintf('/%d 0 obj\s*<<.*?>>\s*stream\r?\n(.*?)\r?\nendstream\s*endobj/s', $objectNumber),
            $pdfBytes,
            $streamMatches
        )) {
            $this->fail(sprintf('Unable to locate FontFile2 stream object %d.', $objectNumber));
        }

        return $streamMatches[1];
    }

    private function fontDescriptorBaseFontName(string $pdfBytes): string
    {
        if (!preg_match('/\/FontName \/(.+?)(?:\s|\/|>>)/', $pdfBytes, $matches)) {
            $this->fail('Unable to locate FontName in generated PDF.');
        }

        return $matches[1];
    }

    private function embeddedFontProgramPostScriptName(string $pdfBytes): ?string
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'ptf');

        if ($temporaryFile === false) {
            $this->fail('Unable to allocate a temporary file for embedded font parsing.');
        }

        file_put_contents($temporaryFile, $this->embeddedFontFile2Stream($pdfBytes));

        try {
            return (new TrueTypeFontParser())->parse($temporaryFile)->postScriptName;
        } finally {
            @unlink($temporaryFile);
        }
    }

    private function customTrueTypeFixturePath(): ?string
    {
        $fontPath = trim((string) shell_exec("fc-match -f '%{file}\n' 'DejaVu Sans' | head -n 1 2>/dev/null"));

        return $fontPath !== '' && is_file($fontPath) ? $fontPath : null;
    }

    private function customTrueTypeCollectionFixturePath(): ?string
    {
        $fontPath = trim((string) shell_exec("fc-match -f '%{file}\n' 'Menlo' | head -n 1 2>/dev/null"));

        return str_ends_with(strtolower($fontPath), '.ttc') && is_file($fontPath) ? $fontPath : null;
    }

    private function supplementaryPlaneTrueTypeFixturePath(): ?string
    {
        $fontPath = trim((string) shell_exec("fc-match -f '%{file}\n' 'Noto Sans Gothic' | head -n 1 2>/dev/null"));

        return str_ends_with(strtolower($fontPath), '.ttf') && is_file($fontPath) ? $fontPath : null;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function ligatureCandidate(object $parsedFont): ?array
    {
        foreach ($parsedFont->ligatureSubstitutions as $sequenceKey => $ligatureGlyphId) {
            $ligatureCodePoint = $parsedFont->codePointForGlyphId($ligatureGlyphId);

            if ($ligatureCodePoint === null) {
                continue;
            }

            $sourceText = '';

            foreach (explode(':', $sequenceKey) as $glyphIdString) {
                $codePoint = $parsedFont->codePointForGlyphId((int) $glyphIdString);

                if ($codePoint === null) {
                    continue 2;
                }

                $sourceText .= mb_chr($codePoint);
            }

            if (mb_strlen($sourceText) < 2) {
                continue;
            }

            return [$sourceText, mb_chr($ligatureCodePoint)];
        }

        return null;
    }

    private function svgFixture(): string
    {
        return <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20">
  <rect width="20" height="20" fill="#ffffff"/>
  <circle cx="10" cy="10" r="6" fill="#ff0000"/>
</svg>
SVG;
    }

    private function signatureSvgFixture(): string
    {
        return <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="320" height="60" viewBox="0 0 320 60">
  <path d="M20 42 C40 8, 75 10, 96 36 S145 55, 168 18 S222 8, 246 36 S286 52, 300 28"
        fill="none" stroke="#2f4fe0" stroke-width="4" stroke-linecap="round"/>
</svg>
SVG;
    }
}
