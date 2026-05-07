<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Pdf;
use PHPUnit\Framework\TestCase;

final class RealWorldFixtureIntegrationTest extends TestCase
{
    public function testF1099FixtureSupportsLoadOverlayAndSave(): void
    {
        $imported = Pdf::load($this->fixturePath('f1099msc.pdf'));

        $this->assertSame('1.7', $imported->report()->version);
        $this->assertSame(6, $imported->report()->pageCount);
        $this->assertSame([], $imported->report()->warnings);
        $this->assertCount(6, $imported->document()->pages());

        $bytes = $imported
            ->pages()
            ->page(1)
            ->overlayText('Fixture Overlay', x: 72, y: 72, fontSize: 18)
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('Fixture Overlay', $bytes);
    }

    public function testF1099FixtureSupportsImportedAcroFormFillAndSave(): void
    {
        $imported = Pdf::load($this->fixturePath('f1099msc.pdf'));
        $fieldNames = $imported->form()->fieldNames();

        $this->assertGreaterThan(100, count($fieldNames));

        $bytes = $imported
            ->form()
            ->setText($fieldNames[0], 'Fixture Fill')
            ->regenerateAppearances()
            ->done()
            ->save();

        $this->assertStringContainsString('Fixture Fill', $bytes);
        $this->assertSame(6, Pdf::loadString($bytes)->report()->pageCount);
    }

    public function testF1099FixtureCheckboxSetterInfersRealOnStateName(): void
    {
        $imported = Pdf::load($this->fixturePath('f1099msc.pdf'));
        $checkboxName = null;

        foreach ($imported->form()->fieldNames() as $fieldName) {
            if (str_contains($fieldName, 'c1_1')) {
                $checkboxName = $fieldName;
                break;
            }
        }

        $this->assertNotNull($checkboxName);

        $bytes = $imported
            ->form()
            ->setCheckbox($checkboxName, true)
            ->done()
            ->save();

        $this->assertStringContainsString('/V /1', $bytes);
        $this->assertStringContainsString('/AS /1', $bytes);
        $this->assertStringNotContainsString('/V /Yes', $bytes);
    }

    public function testF1099FixtureFieldNamesAreDecodedCleanly(): void
    {
        $fieldNames = Pdf::load($this->fixturePath('f1099msc.pdf'))
            ->form()
            ->fieldNames();

        $this->assertGreaterThan(100, count($fieldNames));
        $this->assertStringStartsWith('topmostSubform', $fieldNames[0]);
        $this->assertStringNotContainsString('376377', $fieldNames[0]);
    }

    public function testF1099FixtureSupportsFlattenWithoutLeavingWidgetObjectsBehind(): void
    {
        $imported = Pdf::load($this->fixturePath('f1099msc.pdf'));
        $fieldNames = $imported->form()->fieldNames();

        $this->assertGreaterThan(100, count($fieldNames));

        $bytes = $imported
            ->form()
            ->setText($fieldNames[0], 'Flatten Fixture')
            ->flatten()
            ->done()
            ->save();

        $this->assertStringNotContainsString('/AcroForm', $bytes);
        $this->assertStringNotContainsString('/Subtype /Widget', $bytes);
        $this->assertStringContainsString('Flatten Fixture', $bytes);
        $this->assertSame(6, Pdf::loadString($bytes)->report()->pageCount);
        $this->assertSame([], Pdf::loadString($bytes)->form()->fieldNames());
    }

    public function testChubbApplicationFixtureSupportsLoadOverlayAndSave(): void
    {
        $imported = Pdf::load($this->fixturePath('chubb_application_form.pdf'));

        $this->assertSame('1.3', $imported->report()->version);
        $this->assertSame(4, $imported->report()->pageCount);
        $this->assertSame([], $imported->report()->warnings);
        $this->assertCount(4, $imported->document()->pages());

        $bytes = $imported
            ->pages()
            ->page(1)
            ->overlayText('Fixture Overlay', x: 72, y: 72, fontSize: 18)
            ->done()
            ->done()
            ->save();

        $this->assertStringContainsString('Fixture Overlay', $bytes);
        $this->assertSame(4, Pdf::loadString($bytes)->report()->pageCount);
    }

    private function fixturePath(string $filename): string
    {
        return dirname(__DIR__) . '/examples/' . $filename;
    }
}
