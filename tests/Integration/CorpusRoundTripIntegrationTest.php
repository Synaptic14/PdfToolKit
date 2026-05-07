<?php

declare(strict_types=1);

namespace PdfToolkit\Tests\Integration;

use PdfToolkit\Pdf;
use PdfToolkit\Tests\Support\CorpusFixture;
use PdfToolkit\Tests\Support\CorpusFixtures;
use PdfToolkit\Tests\Support\ExternalPdfToolValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CorpusRoundTripIntegrationTest extends TestCase
{
    private ExternalPdfToolValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ExternalPdfToolValidator();
    }

    /**
     * @return iterable<string, array{0: CorpusFixture}>
     */
    public static function fixtureProvider(): iterable
    {
        foreach (CorpusFixtures::all() as $fixture) {
            yield $fixture->name => [$fixture];
        }
    }

    #[DataProvider('fixtureProvider')]
    public function testCorpusFixturesSupportLoadRoundTripAndOptionalValidators(CorpusFixture $fixture): void
    {
        $this->assertFileExists($fixture->path);

        $imported = Pdf::load($fixture->path);
        $report = $imported->report();

        if ($fixture->expectedVersion !== null) {
            $this->assertSame($fixture->expectedVersion, $report->version, $fixture->name . ' version mismatch.');
        }

        if ($fixture->expectedPageCount !== null) {
            $this->assertSame($fixture->expectedPageCount, $report->pageCount, $fixture->name . ' page count mismatch.');
            $this->assertCount($fixture->expectedPageCount, $imported->document()->pages(), $fixture->name . ' document page mismatch.');
        }

        $bytes = $imported->save();
        $this->assertStringStartsWith('%PDF-', $bytes, $fixture->name . ' did not save a PDF header.');

        $reloaded = Pdf::loadString($bytes);
        $reloadedReport = $reloaded->report();

        $this->assertSame($report->pageCount, $reloadedReport->pageCount, $fixture->name . ' round-trip page count changed.');

        if (in_array('overlay', $fixture->workflows, true)) {
            $overlayBytes = $reloaded
                ->pages()
                ->page(1)
                ->overlayText('Corpus Overlay', x: 72, y: 72, fontSize: 16)
                ->done()
                ->done()
                ->save();

            $this->assertStringContainsString('Corpus Overlay', $overlayBytes, $fixture->name . ' overlay text missing from output.');
            $this->assertSame($report->pageCount, Pdf::loadString($overlayBytes)->report()->pageCount, $fixture->name . ' overlay round-trip changed page count.');
        }

        if (in_array('form-fill', $fixture->workflows, true)) {
            $fieldNames = $imported->form()->fieldNames();
            $this->assertNotSame([], $fieldNames, $fixture->name . ' expected form fields.');

            $filledBytes = $imported
                ->form()
                ->setText($fieldNames[0], 'Corpus Fill')
                ->regenerateAppearances()
                ->done()
                ->save();

            $this->assertStringContainsString('Corpus Fill', $filledBytes, $fixture->name . ' form-fill text missing from output.');
            $this->assertSame($report->pageCount, Pdf::loadString($filledBytes)->report()->pageCount, $fixture->name . ' form-fill round-trip changed page count.');
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'pdftoolkit-corpus-');
        $this->assertNotFalse($tempPath);

        try {
            file_put_contents($tempPath, $bytes);
            $results = $this->validator->validate($tempPath);

            foreach ($results as $tool => $result) {
                if ($result['available'] !== true) {
                    continue;
                }

                $this->assertTrue(
                    $result['ok'] === true,
                    sprintf(
                        '%s validator failed for %s: %s',
                        $tool,
                        $fixture->name,
                        $result['output'] ?? '(no output)'
                    )
                );
            }
        } finally {
            @unlink($tempPath);
        }
    }
}
