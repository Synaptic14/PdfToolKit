<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Core\PdfException;
use PdfToolkit\Pdf;
use PdfToolkit\Parser\PdfParser;
use PdfToolkit\Parser\PdfObjectRepository;
use PdfToolkit\Parser\PdfStream;
use PHPUnit\Framework\TestCase;

final class PdfParserTest extends TestCase
{
    public function testParsesClassicXrefAndPageTree(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 /MediaBox [0 0 612 792] >>',
            3 => '<< /Type /Page /Parent 2 0 R >>',
            4 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 500] >>',
        ]);

        $parsed = (new PdfParser())->parseString($pdf);

        $this->assertSame('1.7', $parsed->version());
        $this->assertCount(2, $parsed->pages());
        $this->assertSame(612.0, $parsed->pages()[0]->width);
        $this->assertSame(792.0, $parsed->pages()[0]->height);
        $this->assertSame(300.0, $parsed->pages()[1]->width);
        $this->assertSame(500.0, $parsed->pages()[1]->height);
    }

    public function testPreservesPageResourcesAndContentStreams(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> >>',
            3 => "<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>",
            4 => "<< /Length 34 >>\nstream\nBT\n/F1 12 Tf\n72 720 Td\n(Hello) Tj\nET\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ]);

        $parsed = (new PdfParser())->parseString($pdf);

        $this->assertSame("BT\n/F1 12 Tf\n72 720 Td\n(Hello) Tj\nET", $parsed->pages()[0]->contentStreams[0]->contents);
        $this->assertIsArray($parsed->pages()[0]->resources);
        $this->assertArrayHasKey('Font', $parsed->pages()[0]->resources);
        $this->assertArrayHasKey(5, $parsed->pages()[0]->dependentObjects);
        $this->assertCount(5, $parsed->pages()[0]->contentStreams[0]->operations);
        $this->assertSame('BT', $parsed->pages()[0]->contentStreams[0]->operations[0]->operator);
        $this->assertSame('Tj', $parsed->pages()[0]->contentStreams[0]->operations[3]->operator);
        $this->assertSame('ET', $parsed->pages()[0]->contentStreams[0]->operations[4]->operator);
    }

    public function testParsesXrefStreamAndCompressedObjectPage(): void
    {
        if (!function_exists('zlib_encode')) {
            $this->markTestSkipped('zlib extension is required for xref stream test fixtures.');
        }

        $objectStreamPayload = "3 0 << /Type /Page /Parent 2 0 R >>";
        $objectStream = zlib_encode($objectStreamPayload, ZLIB_ENCODING_DEFLATE);

        $body = "%PDF-1.5\n";
        $offset1 = strlen($body);
        $body .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $offset2 = strlen($body);
        $body .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 300 500] >>\nendobj\n";
        $offset4 = strlen($body);
        $body .= "4 0 obj\n<< /Type /ObjStm /N 1 /First 4 /Filter /FlateDecode /Length " . strlen($objectStream) . " >>\nstream\n" . $objectStream . "\nendstream\nendobj\n";
        $offset5 = strlen($body);
        $xrefData = $this->buildXrefStreamData([
            [0, 0, 65535],
            [1, $offset1, 0],
            [1, $offset2, 0],
            [2, 4, 0],
            [1, $offset4, 0],
            [1, $offset5, 0],
        ]);
        $xrefStream = zlib_encode($xrefData, ZLIB_ENCODING_DEFLATE);
        $body .= "5 0 obj\n<< /Type /XRef /Size 6 /Root 1 0 R /W [1 4 2] /Index [0 6] /Filter /FlateDecode /Length " . strlen($xrefStream) . " >>\nstream\n" . $xrefStream . "\nendstream\nendobj\n";
        $body .= "startxref\n" . $offset5 . "\n%%EOF";

        $parsed = (new PdfParser())->parseString($body);

        $this->assertSame('1.5', $parsed->version());
        $this->assertCount(1, $parsed->pages());
        $this->assertSame(300.0, $parsed->pages()[0]->width);
        $this->assertSame(500.0, $parsed->pages()[0]->height);
    }

    public function testParsesClassicXrefWithHybridXrefStream(): void
    {
        if (!function_exists('zlib_encode')) {
            $this->markTestSkipped('zlib extension is required for xref stream test fixtures.');
        }

        $objectStreamPayload = "3 0 << /Type /Page /Parent 2 0 R >>";
        $objectStream = zlib_encode($objectStreamPayload, ZLIB_ENCODING_DEFLATE);

        $body = "%PDF-1.7\n";
        $offset1 = strlen($body);
        $body .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $offset2 = strlen($body);
        $body .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 612 792] >>\nendobj\n";
        $offset4 = strlen($body);
        $body .= "4 0 obj\n<< /Type /ObjStm /N 1 /First 4 /Filter /FlateDecode /Length " . strlen($objectStream) . " >>\nstream\n" . $objectStream . "\nendstream\nendobj\n";
        $offset5 = strlen($body);
        $xrefData = $this->buildXrefStreamData([
            [0, 0, 65535],
            [1, $offset1, 0],
            [1, $offset2, 0],
            [2, 4, 0],
            [1, $offset4, 0],
            [1, $offset5, 0],
        ]);
        $xrefStream = zlib_encode($xrefData, ZLIB_ENCODING_DEFLATE);
        $body .= "5 0 obj\n<< /Type /XRef /Size 6 /Root 1 0 R /W [1 4 2] /Index [0 6] /Filter /FlateDecode /Length " . strlen($xrefStream) . " >>\nstream\n" . $xrefStream . "\nendstream\nendobj\n";

        $xrefOffset = strlen($body);
        $body .= "xref\n0 6\n";
        $body .= "0000000000 65535 f \n";
        $body .= sprintf("%010d 00000 n \n", $offset1);
        $body .= sprintf("%010d 00000 n \n", $offset2);
        $body .= "0000000000 00000 f \n";
        $body .= sprintf("%010d 00000 n \n", $offset4);
        $body .= sprintf("%010d 00000 n \n", $offset5);
        $body .= "trailer\n<< /Size 6 /Root 1 0 R /XRefStm " . $offset5 . " >>\n";
        $body .= "startxref\n" . $xrefOffset . "\n%%EOF";

        $parsed = (new PdfParser())->parseString($body);

        $this->assertSame('1.7', $parsed->version());
        $this->assertCount(1, $parsed->pages());
        $this->assertSame(612.0, $parsed->pages()[0]->width);
        $this->assertSame(792.0, $parsed->pages()[0]->height);
    }

    public function testRejectsFlateDecodeStreamsThatExceedConfiguredSafetyLimit(): void
    {
        if (!function_exists('zlib_encode')) {
            $this->markTestSkipped('zlib extension is required for compressed stream fixtures.');
        }

        $previous = getenv('PDFTOOLKIT_MAX_DECODED_STREAM_BYTES');
        putenv('PDFTOOLKIT_MAX_DECODED_STREAM_BYTES=1024');

        $contents = str_repeat('A', 2048);
        $compressed = zlib_encode($contents, ZLIB_ENCODING_DEFLATE);

        try {
            $this->expectException(PdfException::class);
            $this->expectExceptionMessage('configured safety limit');

            $repository = new PdfObjectRepository('', []);
            $method = new \ReflectionMethod($repository, 'decodeStream');
            $method->setAccessible(true);
            $method->invoke($repository, new PdfStream(['Filter' => 'FlateDecode'], $compressed));
        } finally {
            if ($previous === false) {
                putenv('PDFTOOLKIT_MAX_DECODED_STREAM_BYTES');
            } else {
                putenv('PDFTOOLKIT_MAX_DECODED_STREAM_BYTES=' . $previous);
            }
        }
    }

    public function testParsesXrefStreamWithPngPredictor(): void
    {
        if (!function_exists('zlib_encode')) {
            $this->markTestSkipped('zlib extension is required for xref stream test fixtures.');
        }

        $body = "%PDF-1.5\n";
        $offset1 = strlen($body);
        $body .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $offset2 = strlen($body);
        $body .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 320 480] >>\nendobj\n";
        $offset3 = strlen($body);
        $body .= "3 0 obj\n<< /Type /Page /Parent 2 0 R >>\nendobj\n";
        $offset4 = strlen($body);
        $xrefData = $this->buildXrefStreamData([
            [0, 0, 65535],
            [1, $offset1, 0],
            [1, $offset2, 0],
            [1, $offset3, 0],
            [1, $offset4, 0],
        ]);
        $predicted = "\x01" . $this->pngSubPredict($xrefData);
        $xrefStream = zlib_encode($predicted, ZLIB_ENCODING_DEFLATE);
        $body .= "4 0 obj\n<< /Type /XRef /Size 5 /Root 1 0 R /W [1 4 2] /Index [0 5] /Filter /FlateDecode /DecodeParms << /Predictor 11 /Columns " . strlen($xrefData) . " >> /Length " . strlen($xrefStream) . " >>\nstream\n" . $xrefStream . "\nendstream\nendobj\n";
        $body .= "startxref\n" . $offset4 . "\n%%EOF";

        $parsed = (new PdfParser())->parseString($body);

        $this->assertSame('1.5', $parsed->version());
        $this->assertCount(1, $parsed->pages());
        $this->assertSame(320.0, $parsed->pages()[0]->width);
        $this->assertSame(480.0, $parsed->pages()[0]->height);
    }

    public function testParsesStandardRc4EncryptedPdfWithEmptyUserPassword(): void
    {
        $pdf = $this->buildStandardRc4EncryptedPdf();
        $parsed = (new PdfParser())->parseString($pdf);

        $this->assertSame('Secret Title', $parsed->metadata()?->title);
        $this->assertSame("BT\n/F1 12 Tf\n72 720 Td\n(Hello) Tj\nET", $parsed->pages()[0]->contentStreams[0]->contents);

        $saved = Pdf::loadString($pdf)->save();

        $this->assertStringContainsString('/Title (Secret Title)', $saved);
        $this->assertStringContainsString('(Hello) Tj', $saved);
        $this->assertStringNotContainsString('/Encrypt', $saved);
    }

    public function testEncryptedPdfFailsWithWrongPassword(): void
    {
        $pdf = $this->buildStandardRc4EncryptedPdf();

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Unable to authenticate encrypted PDF with the provided password.');

        (new PdfParser())->parseString($pdf, 'wrong-password');
    }

    public function testStandardAesV3EncryptedPdfFailsWithWrongPassword(): void
    {
        if (!function_exists('openssl_encrypt')) {
            $this->markTestSkipped('OpenSSL extension is required for AESV3 encrypted test fixtures.');
        }

        $pdf = $this->buildStandardEncryptedPdf(
            version: 5,
            revision: 5,
            keyLength: 32,
            encryptMetadata: false,
            cryptMethod: 'AESV3',
        );

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Unable to authenticate encrypted PDF with the provided password.');

        (new PdfParser())->parseString($pdf, 'wrong-password');
    }

    public function testStandardAesV3EncryptedPdfFailsWhenPermsBlockIsTampered(): void
    {
        if (!function_exists('openssl_encrypt')) {
            $this->markTestSkipped('OpenSSL extension is required for AESV3 encrypted test fixtures.');
        }

        $pdf = $this->buildStandardEncryptedPdf(
            version: 5,
            revision: 5,
            keyLength: 32,
            encryptMetadata: false,
            cryptMethod: 'AESV3',
            tamperPerms: true,
        );

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Encrypted PDF has a tampered AESV3 permissions block.');

        Pdf::loadString($pdf);
    }

    public function testParsesStandardRc4Revision4EncryptedPdfWithCryptFilters(): void
    {
        $pdf = $this->buildStandardRc4EncryptedPdf(version: 4, revision: 4, keyLength: 16, encryptMetadata: false);
        $parsed = (new PdfParser())->parseString($pdf);

        $this->assertSame('Secret Title', $parsed->metadata()?->title);
        $this->assertSame("BT\n/F1 12 Tf\n72 720 Td\n(Hello) Tj\nET", $parsed->pages()[0]->contentStreams[0]->contents);

        $saved = Pdf::loadString($pdf)->save();

        $this->assertStringContainsString('/Title (Secret Title)', $saved);
        $this->assertStringContainsString('(Hello) Tj', $saved);
        $this->assertStringNotContainsString('/Encrypt', $saved);
    }

    public function testParsesStandardRc4Revision4EncryptedPdfWithExplicitCryptStreamFilter(): void
    {
        if (!function_exists('zlib_encode')) {
            $this->markTestSkipped('zlib extension is required for crypt-filtered stream test fixtures.');
        }

        $pdf = $this->buildStandardRc4EncryptedPdf(
            version: 4,
            revision: 4,
            keyLength: 16,
            encryptMetadata: false,
            useExplicitCryptStreamFilter: true,
        );
        $parsed = (new PdfParser())->parseString($pdf);

        $this->assertSame('Secret Title', $parsed->metadata()?->title);
        $this->assertSame("BT\n/F1 12 Tf\n72 720 Td\n(Hello) Tj\nET", $parsed->pages()[0]->contentStreams[0]->contents);

        $saved = Pdf::loadString($pdf)->save();

        $this->assertStringContainsString('(Hello) Tj', $saved);
        $this->assertStringNotContainsString('/Encrypt', $saved);
    }

    public function testParsesStandardAesV2Revision4EncryptedPdf(): void
    {
        if (!function_exists('openssl_encrypt')) {
            $this->markTestSkipped('OpenSSL extension is required for AESV2 encrypted test fixtures.');
        }

        $pdf = $this->buildStandardEncryptedPdf(
            version: 4,
            revision: 4,
            keyLength: 16,
            encryptMetadata: false,
            cryptMethod: 'AESV2',
        );
        $parsed = (new PdfParser())->parseString($pdf);

        $this->assertSame('Secret Title', $parsed->metadata()?->title);
        $this->assertSame("BT\n/F1 12 Tf\n72 720 Td\n(Hello) Tj\nET", $parsed->pages()[0]->contentStreams[0]->contents);

        $saved = Pdf::loadString($pdf)->save();

        $this->assertStringContainsString('/Title (Secret Title)', $saved);
        $this->assertStringContainsString('(Hello) Tj', $saved);
        $this->assertStringNotContainsString('/Encrypt', $saved);
    }

    public function testParsesStandardAesV3Revision5EncryptedPdf(): void
    {
        if (!function_exists('openssl_encrypt')) {
            $this->markTestSkipped('OpenSSL extension is required for AESV3 encrypted test fixtures.');
        }

        $pdf = $this->buildStandardEncryptedPdf(
            version: 5,
            revision: 5,
            keyLength: 32,
            encryptMetadata: false,
            cryptMethod: 'AESV3',
        );
        $parsed = (new PdfParser())->parseString($pdf);

        $this->assertSame('Secret Title', $parsed->metadata()?->title);
        $this->assertSame("BT\n/F1 12 Tf\n72 720 Td\n(Hello) Tj\nET", $parsed->pages()[0]->contentStreams[0]->contents);

        $imported = Pdf::loadString($pdf);
        $this->assertNotNull($imported->report()->security);
        $this->assertSame(5, $imported->report()->security->version);
        $this->assertSame(5, $imported->report()->security->revision);
        $this->assertSame(256, $imported->report()->security->keyLengthBits);
        $this->assertSame('AESV3', $imported->report()->security->algorithm());
        $this->assertSame('AESV3', $imported->report()->security->stringMethod);
        $this->assertSame('AESV3', $imported->report()->security->streamMethod);
        $this->assertTrue($imported->report()->security->usesAes());
        $this->assertTrue($imported->report()->security->uses128BitKeys());
        $this->assertSame(['StdCF'], $imported->report()->security->cryptFilterNames);

        $saved = $imported->save();

        $this->assertStringContainsString('/Title (Secret Title)', $saved);
        $this->assertStringContainsString('(Hello) Tj', $saved);
        $this->assertStringNotContainsString('/Encrypt', $saved);
    }

    public function testParsesStandardAesV2Revision4EncryptedPdfWithExplicitCryptStreamFilter(): void
    {
        if (!function_exists('openssl_encrypt') || !function_exists('zlib_encode')) {
            $this->markTestSkipped('OpenSSL and zlib extensions are required for AESV2 crypt-filtered stream test fixtures.');
        }

        $pdf = $this->buildStandardEncryptedPdf(
            version: 4,
            revision: 4,
            keyLength: 16,
            encryptMetadata: false,
            cryptMethod: 'AESV2',
            useExplicitCryptStreamFilter: true,
        );
        $parsed = (new PdfParser())->parseString($pdf);

        $this->assertSame('Secret Title', $parsed->metadata()?->title);
        $this->assertSame("BT\n/F1 12 Tf\n72 720 Td\n(Hello) Tj\nET", $parsed->pages()[0]->contentStreams[0]->contents);

        $saved = Pdf::loadString($pdf)->save();

        $this->assertStringContainsString('(Hello) Tj', $saved);
        $this->assertStringNotContainsString('/Encrypt', $saved);
    }

    public function testParsesStandardAesV3Revision5EncryptedPdfWithExplicitCryptStreamFilter(): void
    {
        if (!function_exists('openssl_encrypt') || !function_exists('zlib_encode')) {
            $this->markTestSkipped('OpenSSL and zlib extensions are required for AESV3 crypt-filtered stream test fixtures.');
        }

        $pdf = $this->buildStandardEncryptedPdf(
            version: 5,
            revision: 5,
            keyLength: 32,
            encryptMetadata: false,
            cryptMethod: 'AESV3',
            useExplicitCryptStreamFilter: true,
        );
        $parsed = (new PdfParser())->parseString($pdf);

        $this->assertSame('Secret Title', $parsed->metadata()?->title);
        $this->assertSame("BT\n/F1 12 Tf\n72 720 Td\n(Hello) Tj\nET", $parsed->pages()[0]->contentStreams[0]->contents);

        $saved = Pdf::loadString($pdf)->save();

        $this->assertStringContainsString('(Hello) Tj', $saved);
        $this->assertStringNotContainsString('/Encrypt', $saved);
    }

    public function testParsesEncryptedPdfWithIdentityStringFilter(): void
    {
        $pdf = $this->buildStandardEncryptedPdf(
            version: 4,
            revision: 4,
            keyLength: 16,
            encryptMetadata: false,
            cryptMethod: 'AESV2',
            stringFilterName: 'Identity',
        );
        $parsed = (new PdfParser())->parseString($pdf);

        $this->assertSame('Secret Title', $parsed->metadata()?->title);
        $this->assertSame("BT\n/F1 12 Tf\n72 720 Td\n(Hello) Tj\nET", $parsed->pages()[0]->contentStreams[0]->contents);

        $saved = Pdf::loadString($pdf)->save();

        $this->assertStringContainsString('/Title (Secret Title)', $saved);
        $this->assertStringContainsString('(Hello) Tj', $saved);
        $this->assertStringNotContainsString('/Encrypt', $saved);
    }

    public function testParsesEncryptedPdfWithNoneStringCryptFilterMethod(): void
    {
        $pdf = $this->buildStandardEncryptedPdf(
            version: 4,
            revision: 4,
            keyLength: 16,
            encryptMetadata: false,
            cryptMethod: 'AESV2',
            stringFilterName: 'NoCrypt',
            extraCryptFilters: [
                'NoCrypt' => ['CFM' => 'None'],
            ],
        );
        $parsed = (new PdfParser())->parseString($pdf);

        $this->assertSame('Secret Title', $parsed->metadata()?->title);
        $this->assertSame("BT\n/F1 12 Tf\n72 720 Td\n(Hello) Tj\nET", $parsed->pages()[0]->contentStreams[0]->contents);

        $saved = Pdf::loadString($pdf)->save();

        $this->assertStringContainsString('/Title (Secret Title)', $saved);
        $this->assertStringContainsString('(Hello) Tj', $saved);
        $this->assertStringNotContainsString('/Encrypt', $saved);
    }

    public function testParsesEncryptedPdfWithIdentityStreamFilter(): void
    {
        $pdf = $this->buildStandardEncryptedPdf(
            version: 4,
            revision: 4,
            keyLength: 16,
            encryptMetadata: false,
            cryptMethod: 'AESV2',
            streamFilterName: 'Identity',
        );
        $parsed = (new PdfParser())->parseString($pdf);

        $this->assertSame('Secret Title', $parsed->metadata()?->title);
        $this->assertSame("BT\n/F1 12 Tf\n72 720 Td\n(Hello) Tj\nET", $parsed->pages()[0]->contentStreams[0]->contents);

        $saved = Pdf::loadString($pdf)->save();

        $this->assertStringContainsString('/Title (Secret Title)', $saved);
        $this->assertStringContainsString('(Hello) Tj', $saved);
        $this->assertStringNotContainsString('/Encrypt', $saved);
    }

    public function testParsesEncryptedPdfWithNoneStreamCryptFilterMethod(): void
    {
        $pdf = $this->buildStandardEncryptedPdf(
            version: 4,
            revision: 4,
            keyLength: 16,
            encryptMetadata: false,
            cryptMethod: 'AESV2',
            streamFilterName: 'NoCrypt',
            extraCryptFilters: [
                'NoCrypt' => ['CFM' => 'None'],
            ],
        );
        $parsed = (new PdfParser())->parseString($pdf);

        $this->assertSame('Secret Title', $parsed->metadata()?->title);
        $this->assertSame("BT\n/F1 12 Tf\n72 720 Td\n(Hello) Tj\nET", $parsed->pages()[0]->contentStreams[0]->contents);

        $saved = Pdf::loadString($pdf)->save();

        $this->assertStringContainsString('/Title (Secret Title)', $saved);
        $this->assertStringContainsString('(Hello) Tj', $saved);
        $this->assertStringNotContainsString('/Encrypt', $saved);
    }

    public function testParsesEncryptedPdfWithExplicitIdentityCryptStreamFilter(): void
    {
        if (!function_exists('zlib_encode')) {
            $this->markTestSkipped('zlib extension is required for identity crypt-filtered stream test fixtures.');
        }

        $pdf = $this->buildStandardEncryptedPdf(
            version: 4,
            revision: 4,
            keyLength: 16,
            encryptMetadata: false,
            cryptMethod: 'AESV2',
            useExplicitCryptStreamFilter: true,
            explicitCryptFilterName: 'Identity',
        );
        $parsed = (new PdfParser())->parseString($pdf);

        $this->assertSame('Secret Title', $parsed->metadata()?->title);
        $this->assertSame("BT\n/F1 12 Tf\n72 720 Td\n(Hello) Tj\nET", $parsed->pages()[0]->contentStreams[0]->contents);

        $saved = Pdf::loadString($pdf)->save();

        $this->assertStringContainsString('(Hello) Tj', $saved);
        $this->assertStringNotContainsString('/Encrypt', $saved);
    }

    public function testParsesStandardAesV3EncryptedPdfWithExplicitNoCryptStreamFilter(): void
    {
        if (!function_exists('openssl_encrypt') || !function_exists('zlib_encode')) {
            $this->markTestSkipped('OpenSSL and zlib extensions are required for AESV3 identity crypt-filtered stream test fixtures.');
        }

        $pdf = $this->buildStandardEncryptedPdf(
            version: 5,
            revision: 5,
            keyLength: 32,
            encryptMetadata: false,
            cryptMethod: 'AESV3',
            useExplicitCryptStreamFilter: true,
            explicitCryptFilterName: 'NoCrypt',
            extraCryptFilters: [
                'NoCrypt' => ['CFM' => 'None', 'AuthEvent' => 'DocOpen'],
            ],
        );
        $parsed = (new PdfParser())->parseString($pdf);

        $this->assertSame('Secret Title', $parsed->metadata()?->title);
        $this->assertSame("BT\n/F1 12 Tf\n72 720 Td\n(Hello) Tj\nET", $parsed->pages()[0]->contentStreams[0]->contents);

        $saved = Pdf::loadString($pdf)->save();

        $this->assertStringContainsString('(Hello) Tj', $saved);
        $this->assertStringNotContainsString('/Encrypt', $saved);
    }

    public function testImportedSecurityReportPreservesEmbeddedFileCryptFilterSelection(): void
    {
        $pdf = $this->buildStandardEncryptedPdf(
            version: 4,
            revision: 4,
            keyLength: 16,
            encryptMetadata: false,
            cryptMethod: 'AESV2',
            embeddedFileFilterName: 'NoCrypt',
            extraCryptFilters: [
                'NoCrypt' => ['CFM' => 'None', 'AuthEvent' => 'DocOpen'],
            ],
        );

        $imported = Pdf::loadString($pdf);

        $this->assertNotNull($imported->report()->security);
        $this->assertSame('NoCrypt', $imported->report()->security->embeddedFileFilterName);
        $this->assertSame('Identity', $imported->report()->security->embeddedFileMethod);
        $this->assertFalse($imported->report()->security->usesInheritedEmbeddedFileFilter());
        $this->assertSame('NoCrypt', $imported->report()->security->effectiveEmbeddedFileFilterName());
        $this->assertSame('Identity', $imported->report()->security->effectiveEmbeddedFileMethod());
        $this->assertSame('Identity', $imported->report()->security->embeddedFileAlgorithm());
        $this->assertSame('Identity', $imported->report()->security->embeddedFileAlgorithmSummary());
        $this->assertTrue($imported->report()->security->usesEmbeddedFileCryptFilter());
        $this->assertFalse($imported->report()->security->usesIdentityEmbeddedFileFilter());
        $this->assertFalse($imported->report()->security->embeddedFilesEncrypted());
        $this->assertTrue($imported->report()->security->usesNoOpEmbeddedFileFilter());
        $this->assertTrue($imported->report()->security->usesNamedNoOpEmbeddedFileFilter());
        $this->assertSame('DocOpen', $imported->report()->security->embeddedFileAuthEvent());
        $this->assertTrue($imported->report()->security->usesEmbeddedFileDocOpenAuthEvent());
        $this->assertNull($imported->report()->security->embeddedFileKeyLengthBits());
        $this->assertFalse($imported->report()->security->embeddedFileFilterMatchesStringFilter());
        $this->assertFalse($imported->report()->security->embeddedFileFilterMatchesStreamFilter());
        $this->assertTrue($imported->report()->security->hasDistinctEmbeddedFileCryptFilter());

        $saved = $imported->save();

        $this->assertStringContainsString('/Title (Secret Title)', $saved);
        $this->assertStringContainsString('(Hello) Tj', $saved);
        $this->assertStringNotContainsString('/Encrypt', $saved);
    }

    public function testImportedSecurityReportUsesInheritedRevision5EmbeddedFileCryptFilter(): void
    {
        if (!function_exists('openssl_encrypt')) {
            $this->markTestSkipped('OpenSSL extension is required for AESV3 encrypted test fixtures.');
        }

        $pdf = $this->buildStandardEncryptedPdf(
            version: 5,
            revision: 5,
            keyLength: 32,
            encryptMetadata: false,
            cryptMethod: 'AESV3',
        );

        $imported = Pdf::loadString($pdf);

        $this->assertNotNull($imported->report()->security);
        $this->assertNull($imported->report()->security->embeddedFileFilterName);
        $this->assertNull($imported->report()->security->embeddedFileMethod);
        $this->assertTrue($imported->report()->security->usesInheritedEmbeddedFileFilter());
        $this->assertTrue($imported->report()->security->usesDefaultEmbeddedFileCryptFilter());
        $this->assertTrue($imported->report()->security->usesInheritedDefaultEmbeddedFileCryptFilter());
        $this->assertFalse($imported->report()->security->usesExplicitDefaultEmbeddedFileCryptFilter());
        $this->assertTrue($imported->report()->security->usesDefaultRevision5EmbeddedFileCryptFilter());
        $this->assertSame('StdCF', $imported->report()->security->effectiveEmbeddedFileFilterName());
        $this->assertSame('AESV3', $imported->report()->security->effectiveEmbeddedFileMethod());
        $this->assertSame('AESV3', $imported->report()->security->embeddedFileAlgorithm());
        $this->assertSame('Inherited AESV3', $imported->report()->security->embeddedFileAlgorithmSummary());
        $this->assertTrue($imported->report()->security->embeddedFilesEncrypted());
        $this->assertFalse($imported->report()->security->usesNoOpEmbeddedFileFilter());
        $this->assertSame('DocOpen', $imported->report()->security->embeddedFileAuthEvent());
        $this->assertTrue($imported->report()->security->usesEmbeddedFileDocOpenAuthEvent());
        $this->assertSame(256, $imported->report()->security->embeddedFileKeyLengthBits());
        $this->assertTrue($imported->report()->security->embeddedFileFilterMatchesStringFilter());
        $this->assertTrue($imported->report()->security->embeddedFileFilterMatchesStreamFilter());
        $this->assertFalse($imported->report()->security->hasDistinctEmbeddedFileCryptFilter());
    }

    public function testImportedSecurityReportPreservesExplicitRevision5EmbeddedFileCryptFilterSelection(): void
    {
        if (!function_exists('openssl_encrypt')) {
            $this->markTestSkipped('OpenSSL extension is required for AESV3 encrypted test fixtures.');
        }

        $pdf = $this->buildStandardEncryptedPdf(
            version: 5,
            revision: 5,
            keyLength: 32,
            encryptMetadata: false,
            cryptMethod: 'AESV3',
            embeddedFileFilterName: 'StdCF',
        );

        $imported = Pdf::loadString($pdf);

        $this->assertNotNull($imported->report()->security);
        $this->assertSame('StdCF', $imported->report()->security->embeddedFileFilterName);
        $this->assertSame('AESV3', $imported->report()->security->embeddedFileMethod);
        $this->assertFalse($imported->report()->security->usesInheritedEmbeddedFileFilter());
        $this->assertTrue($imported->report()->security->usesExplicitEmbeddedFileCryptFilter());
        $this->assertTrue($imported->report()->security->usesDefaultEmbeddedFileCryptFilter());
        $this->assertFalse($imported->report()->security->usesInheritedDefaultEmbeddedFileCryptFilter());
        $this->assertTrue($imported->report()->security->usesExplicitDefaultEmbeddedFileCryptFilter());
        $this->assertTrue($imported->report()->security->usesDefaultRevision5EmbeddedFileCryptFilter());
        $this->assertSame('StdCF', $imported->report()->security->effectiveEmbeddedFileFilterName());
        $this->assertSame('AESV3', $imported->report()->security->effectiveEmbeddedFileMethod());
        $this->assertSame('AESV3', $imported->report()->security->embeddedFileAlgorithm());
        $this->assertSame('AESV3', $imported->report()->security->embeddedFileAlgorithmSummary());
        $this->assertTrue($imported->report()->security->embeddedFilesEncrypted());
        $this->assertFalse($imported->report()->security->usesNoOpEmbeddedFileFilter());
        $this->assertSame('DocOpen', $imported->report()->security->embeddedFileAuthEvent());
        $this->assertTrue($imported->report()->security->usesEmbeddedFileDocOpenAuthEvent());
        $this->assertSame(256, $imported->report()->security->embeddedFileKeyLengthBits());
        $this->assertTrue($imported->report()->security->embeddedFileFilterMatchesStringFilter());
        $this->assertTrue($imported->report()->security->embeddedFileFilterMatchesStreamFilter());
        $this->assertFalse($imported->report()->security->hasDistinctEmbeddedFileCryptFilter());
    }

    public function testImportedSecurityReportPreservesRevision4EfOpenEmbeddedFileCryptFilterSelection(): void
    {
        $pdf = $this->buildStandardEncryptedPdf(
            version: 4,
            revision: 4,
            keyLength: 16,
            encryptMetadata: false,
            cryptMethod: 'AESV2',
            embeddedFileFilterName: 'EmbeddedStdCF',
            extraCryptFilters: [
                'EmbeddedStdCF' => ['Length' => 128, 'CFM' => 'AESV2', 'AuthEvent' => 'EFOpen'],
            ],
        );

        $imported = Pdf::loadString($pdf);

        $this->assertNotNull($imported->report()->security);
        $this->assertSame('EmbeddedStdCF', $imported->report()->security->embeddedFileFilterName);
        $this->assertSame('AESV2', $imported->report()->security->embeddedFileMethod);
        $this->assertSame('EFOpen', $imported->report()->security->embeddedFileAuthEvent());
        $this->assertTrue($imported->report()->security->usesEmbeddedFileEfOpenAuthEvent());
        $this->assertFalse($imported->report()->security->usesEmbeddedFileDocOpenAuthEvent());
        $this->assertSame(128, $imported->report()->security->embeddedFileKeyLengthBits());
        $this->assertTrue($imported->report()->security->definesCryptFilter('EmbeddedStdCF'));
        $this->assertSame('AESV2', $imported->report()->security->cryptFilterMethod('EmbeddedStdCF'));
        $this->assertSame('EFOpen', $imported->report()->security->cryptFilterAuthEvent('EmbeddedStdCF'));
        $this->assertTrue($imported->report()->security->usesEfOpenAuthEvent('EmbeddedStdCF'));
    }

    public function testImportedSecurityReportPreservesRevision5EfOpenNamedNoOpEmbeddedFileCryptFilterSelection(): void
    {
        if (!function_exists('openssl_encrypt')) {
            $this->markTestSkipped('OpenSSL extension is required for AESV3 encrypted test fixtures.');
        }

        $pdf = $this->buildStandardEncryptedPdf(
            version: 5,
            revision: 5,
            keyLength: 32,
            encryptMetadata: false,
            cryptMethod: 'AESV3',
            embeddedFileFilterName: 'EmbeddedNoCrypt',
            extraCryptFilters: [
                'EmbeddedNoCrypt' => ['CFM' => 'None', 'AuthEvent' => 'EFOpen'],
            ],
        );

        $imported = Pdf::loadString($pdf);

        $this->assertNotNull($imported->report()->security);
        $this->assertSame('EmbeddedNoCrypt', $imported->report()->security->embeddedFileFilterName);
        $this->assertSame('Identity', $imported->report()->security->embeddedFileMethod);
        $this->assertSame('EFOpen', $imported->report()->security->embeddedFileAuthEvent());
        $this->assertTrue($imported->report()->security->usesEmbeddedFileEfOpenAuthEvent());
        $this->assertFalse($imported->report()->security->usesEmbeddedFileDocOpenAuthEvent());
        $this->assertNull($imported->report()->security->embeddedFileKeyLengthBits());
        $this->assertTrue($imported->report()->security->definesCryptFilter('EmbeddedNoCrypt'));
        $this->assertSame('Identity', $imported->report()->security->cryptFilterMethod('EmbeddedNoCrypt'));
        $this->assertSame('EFOpen', $imported->report()->security->cryptFilterAuthEvent('EmbeddedNoCrypt'));
        $this->assertTrue($imported->report()->security->usesEfOpenAuthEvent('EmbeddedNoCrypt'));
    }

    private function buildPdf(array $objects, string $trailerExtras = ''): string
    {
        ksort($objects);

        $body = "%PDF-1.7\n";
        $offsets = [0 => 0];

        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($body);
            $body .= sprintf("%d 0 obj\n%s\nendobj\n", $id, $object);
        }

        $xrefOffset = strlen($body);
        $body .= sprintf("xref\n0 %d\n", max(array_keys($objects)) + 1);
        $body .= "0000000000 65535 f \n";

        for ($i = 1; $i <= max(array_keys($objects)); $i++) {
            $offset = $offsets[$i] ?? 0;
            $state = isset($offsets[$i]) ? 'n' : 'f';
            $body .= sprintf("%010d 00000 %s \n", $offset, $state);
        }

        $body .= "trailer\n<< /Size " . (max(array_keys($objects)) + 1) . " /Root 1 0 R" . $trailerExtras . " >>\n";
        $body .= "startxref\n" . $xrefOffset . "\n%%EOF";

        return $body;
    }

    /**
     * @param list<array{0: int, 1: int, 2: int}> $entries
     */
    private function buildXrefStreamData(array $entries): string
    {
        $data = '';

        foreach ($entries as [$type, $field2, $field3]) {
            $data .= chr($type);
            $data .= pack('N', $field2);
            $data .= pack('n', $field3);
        }

        return $data;
    }

    private function pngSubPredict(string $contents): string
    {
        $predicted = '';
        $previous = 0;

        foreach (str_split($contents) as $character) {
            $value = ord($character);
            $predicted .= chr(($value - $previous) & 0xFF);
            $previous = $value;
        }

        return $predicted;
    }

    private function buildStandardRc4EncryptedPdf(
        int $version = 1,
        int $revision = 2,
        int $keyLength = 5,
        bool $encryptMetadata = true,
        bool $useExplicitCryptStreamFilter = false,
    ): string {
        return $this->buildStandardEncryptedPdf(
            version: $version,
            revision: $revision,
            keyLength: $keyLength,
            encryptMetadata: $encryptMetadata,
            cryptMethod: 'V2',
            useExplicitCryptStreamFilter: $useExplicitCryptStreamFilter,
        );
    }

    private function buildStandardEncryptedPdf(
        int $version = 1,
        int $revision = 2,
        int $keyLength = 5,
        bool $encryptMetadata = true,
        string $cryptMethod = 'V2',
        bool $useExplicitCryptStreamFilter = false,
        ?string $explicitCryptFilterName = null,
        string $stringFilterName = 'StdCF',
        string $streamFilterName = 'StdCF',
        ?string $embeddedFileFilterName = null,
        array $extraCryptFilters = [],
        bool $tamperPerms = false,
    ): string
    {
        $userPassword = '';
        $ownerPassword = 'owner';
        $permissions = -4;
        $fileId = hex2bin('00112233445566778899aabbccddeeff');

        if ($fileId === false) {
            throw new \RuntimeException('Failed to build encrypted PDF file ID fixture.');
        }

        if ($version === 5 || $revision === 5 || $cryptMethod === 'AESV3') {
            return $this->buildStandardAesV3EncryptedPdf(
                encryptMetadata: $encryptMetadata,
                useExplicitCryptStreamFilter: $useExplicitCryptStreamFilter,
                explicitCryptFilterName: $explicitCryptFilterName,
                stringFilterName: $stringFilterName,
                streamFilterName: $streamFilterName,
                embeddedFileFilterName: $embeddedFileFilterName,
                extraCryptFilters: $extraCryptFilters,
                tamperPerms: $tamperPerms,
            );
        }

        $ownerEntry = $this->buildOwnerEntry($ownerPassword, $userPassword, $revision, $keyLength);
        $fileKey = $this->buildFileKey($userPassword, $ownerEntry, $permissions, $fileId, $revision, $keyLength, $encryptMetadata);
        $userEntry = $revision >= 3
            ? $this->encryptRc4Iterations(md5($this->passwordPadding() . $fileId, true), $fileKey)
                . str_repeat("\x00", 16)
            : $this->rc4($fileKey, $this->passwordPadding());
        $contentStream = "BT\n/F1 12 Tf\n72 720 Td\n(Hello) Tj\nET";
        $isNoOpFilter = static function (string $filterName, array $cryptFilters): bool {
            if ($filterName === 'Identity') {
                return true;
            }

            return (($cryptFilters[$filterName]['CFM'] ?? null) === 'None');
        };
        $cryptFilterName = $explicitCryptFilterName ?? 'StdCF';
        $streamBody = $useExplicitCryptStreamFilter && function_exists('zlib_encode')
            ? zlib_encode($contentStream, ZLIB_ENCODING_DEFLATE)
            : $contentStream;
        $streamUsesIdentity = ($useExplicitCryptStreamFilter && $isNoOpFilter($cryptFilterName, $extraCryptFilters))
            || (!$useExplicitCryptStreamFilter && $isNoOpFilter($streamFilterName, $extraCryptFilters));
        $encryptedContentStream = $streamUsesIdentity
            ? $streamBody
            : $this->encryptObjectBytes($streamBody, $fileKey, 4, 0, $keyLength, $cryptMethod);
        $encryptedTitle = $isNoOpFilter($stringFilterName, $extraCryptFilters)
            ? 'Secret Title'
            : $this->encryptObjectBytes('Secret Title', $fileKey, 5, 0, $keyLength, $cryptMethod);
        $encryptDictionary = '<< /Filter /Standard /V ' . $version . ' /R ' . $revision
            . ' /O <' . bin2hex($ownerEntry) . '> /U <' . bin2hex($userEntry) . '> /P ' . $permissions;

        if ($revision >= 3) {
            $encryptDictionary .= ' /Length ' . ($keyLength * 8);
        }

        if ($version === 4) {
            $cryptFilters = array_merge([
                'StdCF' => [
                    'Length' => $keyLength * 8,
                    'CFM' => $cryptMethod,
                    'AuthEvent' => 'DocOpen',
                ],
            ], $extraCryptFilters);
            $cryptFilterParts = [];

            foreach ($cryptFilters as $name => $definition) {
                $parts = [];

                foreach ($definition as $key => $value) {
                    if (is_string($value) && $value !== '' && ctype_alpha(str_replace(['2', '3'], '', $value))) {
                        $parts[] = '/' . $key . ' /' . $value;
                    } else {
                        $parts[] = '/' . $key . ' ' . $value;
                    }
                }

                $cryptFilterParts[] = '/' . $name . ' << ' . implode(' ', $parts) . ' >>';
            }

            $encryptDictionary .= ' /EncryptMetadata ' . ($encryptMetadata ? 'true' : 'false')
                . ' /CF << ' . implode(' ', $cryptFilterParts) . ' >>'
                . ' /StmF /' . $streamFilterName . ' /StrF /' . $stringFilterName;

            if ($embeddedFileFilterName !== null) {
                $encryptDictionary .= ' /EFF /' . $embeddedFileFilterName;
            }
        }

        $encryptDictionary .= ' >>';
        $contentObject = $useExplicitCryptStreamFilter
            ? "<< /Length " . strlen($encryptedContentStream) . " /Filter [/Crypt /FlateDecode] /DecodeParms [<< /Name /" . $cryptFilterName . " >> null] >>\nstream\n" . $encryptedContentStream . "\nendstream"
            : "<< /Length " . strlen($encryptedContentStream) . " >>\nstream\n" . $encryptedContentStream . "\nendstream";

        return $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => $contentObject,
            5 => '<< /Title ' . $this->literalString($encryptedTitle) . ' >>',
            6 => $encryptDictionary,
        ], trailerExtras: ' /Info 5 0 R /Encrypt 6 0 R /ID [<' . bin2hex($fileId) . '> <' . bin2hex($fileId) . '>]');
    }

    private function buildStandardAesV3EncryptedPdf(
        bool $encryptMetadata = true,
        bool $useExplicitCryptStreamFilter = false,
        ?string $explicitCryptFilterName = null,
        string $stringFilterName = 'StdCF',
        string $streamFilterName = 'StdCF',
        ?string $embeddedFileFilterName = null,
        array $extraCryptFilters = [],
        bool $tamperPerms = false,
    ): string {
        if (!function_exists('openssl_encrypt')) {
            throw new \RuntimeException('OpenSSL extension is required for AESV3 encrypted test fixtures.');
        }

        $userPassword = '';
        $ownerPassword = 'owner';
        $permissions = -4;
        $fileId = hex2bin('00112233445566778899aabbccddeeff');

        if ($fileId === false) {
            throw new \RuntimeException('Failed to build AESV3 encrypted PDF file ID fixture.');
        }

        $fileKey = hex2bin('00112233445566778899aabbccddeeff102132435465768798a9bacbdcedfe0f');

        if ($fileKey === false) {
            throw new \RuntimeException('Failed to build AESV3 encrypted PDF file key fixture.');
        }

        $userValidationSalt = 'uvsalt01';
        $userKeySalt = 'uksalt01';
        $ownerValidationSalt = 'ovsalt01';
        $ownerKeySalt = 'oksalt01';
        $userHash = hash('sha256', $userPassword . $userValidationSalt, true);
        $userEntry = $userHash . $userValidationSalt . $userKeySalt;
        $userEncryptionKey = hash('sha256', $userPassword . $userKeySalt, true);
        $userEncryption = openssl_encrypt(
            $fileKey,
            'aes-256-cbc',
            $userEncryptionKey,
            OPENSSL_RAW_DATA,
            str_repeat("\x00", 16),
        );

        if (!is_string($userEncryption)) {
            throw new \RuntimeException('Failed to build AESV3 user encryption fixture.');
        }

        $ownerHash = hash('sha256', $ownerPassword . $ownerValidationSalt . $userEntry, true);
        $ownerEntry = $ownerHash . $ownerValidationSalt . $ownerKeySalt;
        $ownerEncryptionKey = hash('sha256', $ownerPassword . $ownerKeySalt . $userEntry, true);
        $ownerEncryption = openssl_encrypt(
            $fileKey,
            'aes-256-cbc',
            $ownerEncryptionKey,
            OPENSSL_RAW_DATA,
            str_repeat("\x00", 16),
        );

        if (!is_string($ownerEncryption)) {
            throw new \RuntimeException('Failed to build AESV3 owner encryption fixture.');
        }

        $contentStream = "BT\n/F1 12 Tf\n72 720 Td\n(Hello) Tj\nET";
        $isNoOpFilter = static function (string $filterName, array $cryptFilters): bool {
            if ($filterName === 'Identity') {
                return true;
            }

            return (($cryptFilters[$filterName]['CFM'] ?? null) === 'None');
        };
        $cryptFilterName = $explicitCryptFilterName ?? 'StdCF';
        $streamBody = $useExplicitCryptStreamFilter && function_exists('zlib_encode')
            ? zlib_encode($contentStream, ZLIB_ENCODING_DEFLATE)
            : $contentStream;
        $streamUsesIdentity = ($useExplicitCryptStreamFilter && $isNoOpFilter($cryptFilterName, $extraCryptFilters))
            || (!$useExplicitCryptStreamFilter && $isNoOpFilter($streamFilterName, $extraCryptFilters));
        $encryptedContentStream = $streamUsesIdentity
            ? $streamBody
            : $this->encryptAesV3Bytes($streamBody, $fileKey);
        $encryptedTitle = $isNoOpFilter($stringFilterName, $extraCryptFilters)
            ? 'Secret Title'
            : $this->encryptAesV3Bytes('Secret Title', $fileKey);
        $cryptFilters = array_merge([
            'StdCF' => [
                'Length' => 256,
                'CFM' => 'AESV3',
                'AuthEvent' => 'DocOpen',
            ],
        ], $extraCryptFilters);
        $cryptFilterParts = [];

        foreach ($cryptFilters as $name => $definition) {
            $parts = [];

            foreach ($definition as $key => $value) {
                if (is_string($value) && $value !== '' && ctype_alpha(str_replace(['2', '3'], '', $value))) {
                    $parts[] = '/' . $key . ' /' . $value;
                } else {
                    $parts[] = '/' . $key . ' ' . $value;
                }
            }

            $cryptFilterParts[] = '/' . $name . ' << ' . implode(' ', $parts) . ' >>';
        }

        $permsBlock = pack('V', $permissions < 0 ? $permissions + 0x100000000 : $permissions)
            . "\xFF\xFF\xFF\xFF"
            . ($encryptMetadata ? 'T' : 'F')
            . 'adb'
            . "\x00\x00\x00\x00";
        $perms = openssl_encrypt(
            $permsBlock,
            'aes-256-ecb',
            $fileKey,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
        );

        if (!is_string($perms)) {
            throw new \RuntimeException('Failed to build AESV3 permissions fixture.');
        }

        if ($tamperPerms) {
            $perms[0] = $perms[0] === "\x00" ? "\x01" : "\x00";
        }

        $encryptDictionary = '<< /Filter /Standard /V 5 /R 5 /Length 256'
            . ' /O <' . bin2hex($ownerEntry) . '>'
            . ' /U <' . bin2hex($userEntry) . '>'
            . ' /OE <' . bin2hex($ownerEncryption) . '>'
            . ' /UE <' . bin2hex($userEncryption) . '>'
            . ' /Perms <' . bin2hex($perms) . '>'
            . ' /P ' . $permissions
            . ' /EncryptMetadata ' . ($encryptMetadata ? 'true' : 'false')
            . ' /CF << ' . implode(' ', $cryptFilterParts) . ' >>'
            . ' /StmF /' . $streamFilterName
            . ' /StrF /' . $stringFilterName;

        if ($embeddedFileFilterName !== null) {
            $encryptDictionary .= ' /EFF /' . $embeddedFileFilterName;
        }

        $encryptDictionary .= ' >>';
        $contentObject = $useExplicitCryptStreamFilter
            ? "<< /Length " . strlen($encryptedContentStream) . " /Filter [/Crypt /FlateDecode] /DecodeParms [<< /Name /" . $cryptFilterName . " >> null] >>\nstream\n" . $encryptedContentStream . "\nendstream"
            : "<< /Length " . strlen($encryptedContentStream) . " >>\nstream\n" . $encryptedContentStream . "\nendstream";

        return $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => $contentObject,
            5 => '<< /Title ' . $this->literalString($encryptedTitle) . ' >>',
            6 => $encryptDictionary,
        ], trailerExtras: ' /Info 5 0 R /Encrypt 6 0 R /ID [<' . bin2hex($fileId) . '> <' . bin2hex($fileId) . '>]');
    }

    private function buildOwnerEntry(string $ownerPassword, string $userPassword, int $revision, int $keyLength): string
    {
        $ownerKey = substr(md5($this->padPassword($ownerPassword), true), 0, $keyLength);
        $userPadding = $this->padPassword($userPassword);

        if ($revision >= 3) {
            for ($index = 0; $index < 50; $index++) {
                $ownerKey = substr(md5($ownerKey, true), 0, $keyLength);
            }

            $value = $userPadding;

            for ($index = 0; $index <= 19; $index++) {
                $value = $this->rc4($this->xorKey($ownerKey, $index), $value);
            }

            return $value;
        }

        return $this->rc4($ownerKey, $userPadding);
    }

    private function buildFileKey(
        string $userPassword,
        string $ownerEntry,
        int $permissions,
        string $fileId,
        int $revision,
        int $keyLength,
        bool $encryptMetadata,
    ): string {
        $payload = $this->padPassword($userPassword)
            . $ownerEntry
            . pack('V', $permissions < 0 ? $permissions + 0x100000000 : $permissions)
            . $fileId;

        if ($revision >= 4 && !$encryptMetadata) {
            $payload .= "\xFF\xFF\xFF\xFF";
        }

        $digest = md5($payload, true);

        if ($revision >= 3) {
            $digest = substr($digest, 0, $keyLength);

            for ($index = 0; $index < 50; $index++) {
                $digest = substr(md5($digest, true), 0, $keyLength);
            }

            return $digest;
        }

        return substr($digest, 0, 5);
    }

    private function encryptRc4Iterations(string $value, string $key): string
    {
        $result = $this->rc4($key, $value);

        for ($index = 1; $index <= 19; $index++) {
            $result = $this->rc4($this->xorKey($key, $index), $result);
        }

        return $result;
    }

    private function encryptObjectBytes(
        string $contents,
        string $fileKey,
        int $objectNumber,
        int $generationNumber,
        int $keyLength,
        string $method = 'V2',
    ): string
    {
        $keyMaterial = $fileKey
            . chr($objectNumber & 0xFF)
            . chr(($objectNumber >> 8) & 0xFF)
            . chr(($objectNumber >> 16) & 0xFF)
            . chr($generationNumber & 0xFF)
            . chr(($generationNumber >> 8) & 0xFF);

        if ($method === 'AESV2') {
            $keyMaterial .= 'sAlT';
        }

        $objectKey = substr(md5($keyMaterial, true), 0, min($keyLength + 5, 16));

        if ($method === 'AESV2') {
            $iv = str_repeat("\x10", 16);
            $ciphertext = openssl_encrypt($contents, 'aes-128-cbc', $objectKey, OPENSSL_RAW_DATA, $iv);

            if (!is_string($ciphertext)) {
                throw new \RuntimeException('Failed to build AESV2 encrypted test fixture.');
            }

            return $iv . $ciphertext;
        }

        return $this->rc4($objectKey, $contents);
    }

    private function encryptAesV3Bytes(string $contents, string $fileKey): string
    {
        $iv = str_repeat("\x20", 16);
        $ciphertext = openssl_encrypt($contents, 'aes-256-cbc', $fileKey, OPENSSL_RAW_DATA, $iv);

        if (!is_string($ciphertext)) {
            throw new \RuntimeException('Failed to build AESV3 encrypted test fixture.');
        }

        return $iv . $ciphertext;
    }

    private function padPassword(string $password): string
    {
        $password = substr($password, 0, 32);

        return str_pad($password, 32, $this->passwordPadding());
    }

    private function passwordPadding(): string
    {
        return "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08"
            . "\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";
    }

    private function literalString(string $bytes): string
    {
        $literal = '';
        $length = strlen($bytes);

        for ($index = 0; $index < $length; $index++) {
            $byte = ord($bytes[$index]);
            $char = $bytes[$index];

            if ($char === '\\' || $char === '(' || $char === ')') {
                $literal .= '\\' . $char;
                continue;
            }

            if ($byte < 32 || $byte > 126) {
                $literal .= sprintf('\\%03o', $byte);
                continue;
            }

            $literal .= $char;
        }

        return '(' . $literal . ')';
    }

    private function xorKey(string $key, int $value): string
    {
        $output = '';

        for ($index = 0, $length = strlen($key); $index < $length; $index++) {
            $output .= chr(ord($key[$index]) ^ $value);
        }

        return $output;
    }

    private function rc4(string $key, string $data): string
    {
        $state = range(0, 255);
        $keyLength = strlen($key);
        $j = 0;

        for ($index = 0; $index < 256; $index++) {
            $j = ($j + $state[$index] + ord($key[$index % $keyLength])) & 0xFF;
            [$state[$index], $state[$j]] = [$state[$j], $state[$index]];
        }

        $i = 0;
        $j = 0;
        $output = '';

        for ($index = 0, $length = strlen($data); $index < $length; $index++) {
            $i = ($i + 1) & 0xFF;
            $j = ($j + $state[$i]) & 0xFF;
            [$state[$i], $state[$j]] = [$state[$j], $state[$i]];
            $output .= chr(ord($data[$index]) ^ $state[($state[$i] + $state[$j]) & 0xFF]);
        }

        return $output;
    }
}
