<?php

declare(strict_types=1);

namespace PdfToolkit\Tests\Integration;

use PdfToolkit\Pdf;
use PdfToolkit\Tests\Support\CorpusFixture;
use PdfToolkit\Tests\Support\CorpusFixtures;
use PdfToolkit\Writer\WriteOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CorpusVariantIntegrationTest extends TestCase
{
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
    public function testCorpusFixtureSupportsCompressedRoundTrip(CorpusFixture $fixture): void
    {
        $imported = Pdf::load($fixture->path);

        $bytes = $imported->save(options: new WriteOptions(compressStreams: true));

        $this->assertStringContainsString('/Filter /FlateDecode', $bytes, $fixture->name . ' compressed save did not emit FlateDecode.');

        $reloaded = Pdf::loadString($bytes);

        $this->assertSame($imported->report()->pageCount, $reloaded->report()->pageCount, $fixture->name . ' compressed round-trip changed page count.');
    }

    #[DataProvider('fixtureProvider')]
    public function testCorpusFixtureSupportsEncryptedRoundTrip(CorpusFixture $fixture): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('OpenSSL extension is required for encrypted corpus-variant tests.');
        }

        $imported = Pdf::load($fixture->path);

        $bytes = $imported->save(options: new WriteOptions(
            userPassword: 'variant-user',
            ownerPassword: 'variant-owner',
            encryptionRevision: 5,
            encryptionMethod: 'AESV3',
        ));

        $this->assertStringContainsString('/Encrypt', $bytes, $fixture->name . ' encrypted save did not emit Encrypt dictionary.');

        $reloaded = Pdf::loadString($bytes, 'variant-user');

        $this->assertSame($imported->report()->pageCount, $reloaded->report()->pageCount, $fixture->name . ' encrypted round-trip changed page count.');
        $this->assertSame('AESV3', $reloaded->report()->security?->algorithm(), $fixture->name . ' encrypted round-trip reported unexpected algorithm.');
        $this->assertTrue($reloaded->report()->security?->openedWithPassword ?? false, $fixture->name . ' encrypted round-trip did not report explicit-password open.');

        $roundTripped = $reloaded->save();

        $this->assertStringNotContainsString('/Encrypt', $roundTripped, $fixture->name . ' decrypted round-trip unexpectedly kept encryption dictionary.');
        $this->assertSame($imported->report()->pageCount, Pdf::loadString($roundTripped)->report()->pageCount, $fixture->name . ' decrypted round-trip changed page count.');
    }

    #[DataProvider('fixtureProvider')]
    public function testCorpusFixtureSupportsWorkflowSpecificCompressedVariants(CorpusFixture $fixture): void
    {
        if (!in_array('form-fill', $fixture->workflows, true)) {
            $this->markTestSkipped($fixture->name . ' does not define a form-fill workflow.');
        }

        $imported = Pdf::load($fixture->path);
        $fieldNames = $imported->form()->fieldNames();

        $this->assertNotSame([], $fieldNames, $fixture->name . ' expected form fields for workflow-specific variant.');

        $bytes = $imported
            ->form()
            ->setText($fieldNames[0], 'Variant Fill')
            ->regenerateAppearances()
            ->done()
            ->save(options: new WriteOptions(compressStreams: true));

        $this->assertStringContainsString('/Filter /FlateDecode', $bytes, $fixture->name . ' compressed form-fill variant did not emit FlateDecode.');

        $reloaded = Pdf::loadString($bytes);

        $this->assertSame($imported->report()->pageCount, $reloaded->report()->pageCount, $fixture->name . ' compressed form-fill variant changed page count.');
        $this->assertGreaterThan(0, count($reloaded->form()->fieldNames()), $fixture->name . ' compressed form-fill variant lost form fields.');
    }
}
