<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Core\PdfException;
use PdfToolkit\Pdf;
use PdfToolkit\Text\TextRun;
use PdfToolkit\Writer\StandardPermissions;
use PdfToolkit\Writer\WriteOptions;
use PHPUnit\Framework\TestCase;

final class WriteOptionsTest extends TestCase
{
    public function testGeneratedContentStreamsCanBeCompressed(): void
    {
        if (!function_exists('zlib_decode')) {
            $this->markTestSkipped('zlib extension is required for compression tests.');
        }

        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('Compress me', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(compressStreams: true));

        $this->assertStringContainsString('/Filter /FlateDecode', $bytes);
        $this->assertStringNotContainsString('(Compress me) Tj', $bytes);
        $this->assertStringContainsString('(Compress me) Tj', $this->firstFlateDecodedStream($bytes));
    }

    public function testImportedContentStreamsCanBeCompressedOnSave(): void
    {
        if (!function_exists('zlib_decode')) {
            $this->markTestSkipped('zlib extension is required for compression tests.');
        }

        $imported = Pdf::loadString($this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 19 >>\nstream\nBT\n(Imported) Tj\nET\nendstream",
        ]));

        $bytes = $imported->save(options: new WriteOptions(compressStreams: true));

        $this->assertStringContainsString('/Filter /FlateDecode', $bytes);
        $this->assertStringContainsString('(Imported) Tj', $this->firstFlateDecodedStream($bytes));
    }

    public function testGeneratedPdfCanBeSavedWithStandardSecurityRc4Encryption(): void
    {
        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('Encrypted hello', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: '',
                ownerPassword: 'owner-secret',
            ));

        $this->assertStringContainsString('/Encrypt', $bytes);
        $this->assertStringContainsString('/Filter /Standard', $bytes);
        $this->assertStringContainsString('/V 1', $bytes);
        $this->assertStringContainsString('/R 2', $bytes);
        $this->assertStringNotContainsString('(Encrypted hello) Tj', $bytes);

        $imported = Pdf::loadString($bytes);

        $this->assertSame(1, $imported->report()->pageCount);
        $this->assertNotNull($imported->report()->security);
        $this->assertSame(40, $imported->report()->security->keyLengthBits);
        $this->assertTrue($imported->report()->security->isLegacy40Bit());
        $this->assertFalse($imported->report()->security->uses128BitKeys());
        $this->assertSame([], $imported->report()->security->cryptFilterNames);
        $this->assertSame([], $imported->report()->security->cryptFilters);
        $this->assertSame([], $imported->report()->security->cryptFilterAuthEvents);
        $this->assertSame([], $imported->report()->security->cryptFilterKeyLengthBits);
        $this->assertFalse($imported->report()->security->usesCryptFilters());
        $this->assertFalse($imported->report()->security->usesCustomNamedCryptFilters());
        $this->assertFalse($imported->report()->security->definesCryptFilter('StdCF'));
        $this->assertNull($imported->report()->security->cryptFilterMethod('StdCF'));
        $this->assertNull($imported->report()->security->cryptFilterAuthEvent('StdCF'));
        $this->assertNull($imported->report()->security->cryptFilterKeyLengthBits('StdCF'));
        $this->assertFalse($imported->report()->security->usesDocOpenAuthEvent('StdCF'));
        $this->assertTrue($imported->report()->security->usesLegacyStandardFilters());
        $this->assertFalse($imported->report()->security->usesDefaultRevision4CryptFilters());
        $this->assertFalse($imported->report()->security->usesCustomCryptFilterConfiguration());
        $this->assertSame('RC4', $imported->report()->security->algorithm());
        $this->assertSame('Standard', $imported->report()->security->stringFilterName);
        $this->assertSame('Standard', $imported->report()->security->streamFilterName);
        $this->assertFalse($imported->report()->security->hasMixedCryptFilters());
        $this->assertFalse($imported->report()->security->hasMixedCryptMethods());
        $this->assertTrue($imported->report()->security->isPasswordProtected());
        $this->assertTrue($imported->report()->security->isEffectivelyEncrypted());
        $this->assertFalse($imported->report()->security->usesIdentityFilters());
        $this->assertFalse($imported->report()->security->hasMixedStringAndStreamEncryption());
        $this->assertFalse($imported->report()->security->openedWithPassword);
        $this->assertTrue($imported->report()->security->openedWithoutPassword());
        $this->assertTrue($imported->report()->security->allowsAllPermissions());
        $this->assertFalse($imported->report()->security->hasRestrictedPermissions());

        $roundTripped = $imported->save();

        $this->assertStringContainsString('(Encrypted hello) Tj', $roundTripped);
        $this->assertStringNotContainsString('/Encrypt', $roundTripped);
    }

    public function testStandardSecurityPermissionHelpersProduceExpectedBitmasks(): void
    {
        $this->assertSame(-4, StandardPermissions::all(2));
        $this->assertSame(-64, StandardPermissions::none(2));
        $this->assertSame(-4, StandardPermissions::all(4));
        $this->assertSame(-3904, StandardPermissions::none(4));
        $this->assertSame(
            -3884,
            StandardPermissions::allow([StandardPermissions::PRINT, StandardPermissions::COPY], 4),
        );
    }

    public function testGeneratedPdfCanUsePermissionHelperForRevision4Encryption(): void
    {
        $permissions = StandardPermissions::allow([
            StandardPermissions::PRINT,
            StandardPermissions::COPY,
        ], 4);

        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('Permission helper hello', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: '',
                ownerPassword: 'owner-secret',
                encryptionRevision: 4,
                permissions: $permissions,
            ));

        $this->assertStringContainsString('/P -3884', $bytes);
        $this->assertStringNotContainsString('(Permission helper hello) Tj', $bytes);

        $imported = Pdf::loadString($bytes);
        $this->assertNotNull($imported->report()->security);
        $this->assertTrue($imported->report()->security->isPasswordProtected());
        $this->assertTrue($imported->report()->security->isEffectivelyEncrypted());
        $this->assertSame(128, $imported->report()->security->keyLengthBits);
        $this->assertFalse($imported->report()->security->isLegacy40Bit());
        $this->assertTrue($imported->report()->security->uses128BitKeys());
        $this->assertSame(['StdCF'], $imported->report()->security->cryptFilterNames);
        $this->assertSame(['StdCF' => 'RC4'], $imported->report()->security->cryptFilters);
        $this->assertSame(['StdCF' => 'DocOpen'], $imported->report()->security->cryptFilterAuthEvents);
        $this->assertSame(['StdCF' => 128], $imported->report()->security->cryptFilterKeyLengthBits);
        $this->assertTrue($imported->report()->security->usesCryptFilters());
        $this->assertFalse($imported->report()->security->usesCustomNamedCryptFilters());
        $this->assertTrue($imported->report()->security->definesCryptFilter('StdCF'));
        $this->assertSame('RC4', $imported->report()->security->cryptFilterMethod('StdCF'));
        $this->assertSame('DocOpen', $imported->report()->security->cryptFilterAuthEvent('StdCF'));
        $this->assertSame(128, $imported->report()->security->cryptFilterKeyLengthBits('StdCF'));
        $this->assertTrue($imported->report()->security->usesDocOpenAuthEvent('StdCF'));
        $this->assertFalse($imported->report()->security->definesCryptFilter('NoCrypt'));
        $this->assertNull($imported->report()->security->cryptFilterMethod('NoCrypt'));
        $this->assertNull($imported->report()->security->cryptFilterAuthEvent('NoCrypt'));
        $this->assertNull($imported->report()->security->cryptFilterKeyLengthBits('NoCrypt'));
        $this->assertFalse($imported->report()->security->usesEmbeddedFileCryptFilter());
        $this->assertFalse($imported->report()->security->usesExplicitEmbeddedFileCryptFilter());
        $this->assertTrue($imported->report()->security->usesInheritedEmbeddedFileFilter());
        $this->assertSame('StdCF', $imported->report()->security->effectiveEmbeddedFileFilterName());
        $this->assertSame('RC4', $imported->report()->security->effectiveEmbeddedFileMethod());
        $this->assertTrue($imported->report()->security->usesDefaultEmbeddedFileCryptFilter());
        $this->assertTrue($imported->report()->security->usesInheritedDefaultEmbeddedFileCryptFilter());
        $this->assertFalse($imported->report()->security->usesExplicitDefaultEmbeddedFileCryptFilter());
        $this->assertTrue($imported->report()->security->usesDefaultRevision4EmbeddedFileCryptFilter());
        $this->assertFalse($imported->report()->security->usesDefaultRevision5EmbeddedFileCryptFilter());
        $this->assertTrue($imported->report()->security->embeddedFilesEncrypted());
        $this->assertFalse($imported->report()->security->usesNoOpEmbeddedFileFilter());
        $this->assertSame('DocOpen', $imported->report()->security->embeddedFileAuthEvent());
        $this->assertTrue($imported->report()->security->usesEmbeddedFileDocOpenAuthEvent());
        $this->assertSame(128, $imported->report()->security->embeddedFileKeyLengthBits());
        $this->assertTrue($imported->report()->security->embeddedFileFilterMatchesStringFilter());
        $this->assertTrue($imported->report()->security->embeddedFileFilterMatchesStreamFilter());
        $this->assertFalse($imported->report()->security->hasDistinctEmbeddedFileCryptFilter());
        $this->assertSame('Inherited RC4', $imported->report()->security->embeddedFileAlgorithmSummary());
        $this->assertFalse($imported->report()->security->usesLegacyStandardFilters());
        $this->assertTrue($imported->report()->security->usesDefaultStandardCryptFilters());
        $this->assertTrue($imported->report()->security->usesDefaultRevision4CryptFilters());
        $this->assertFalse($imported->report()->security->usesDefaultRevision5CryptFilters());
        $this->assertFalse($imported->report()->security->usesCustomCryptFilterConfiguration());
        $this->assertFalse($imported->report()->security->hasMixedCryptFilters());
        $this->assertFalse($imported->report()->security->hasMixedCryptMethods());
        $this->assertFalse($imported->report()->security->usesIdentityFilters());
        $this->assertFalse($imported->report()->security->hasMixedStringAndStreamEncryption());
        $this->assertFalse($imported->report()->security->openedWithPassword);
        $this->assertTrue($imported->report()->security->openedWithoutPassword());
        $this->assertFalse($imported->report()->security->allowsAllPermissions());
        $this->assertTrue($imported->report()->security->hasRestrictedPermissions());
        $this->assertSame($permissions, $imported->report()->security->permissions);
        $this->assertSame('RC4', $imported->report()->security->algorithm());
        $this->assertSame('StdCF', $imported->report()->security->stringFilterName);
        $this->assertSame('StdCF', $imported->report()->security->streamFilterName);
        $this->assertTrue($imported->report()->security->allowsPrint());
        $this->assertFalse($imported->report()->security->allowsModify());
        $this->assertTrue($imported->report()->security->allowsCopy());
        $this->assertFalse($imported->report()->security->allowsAnnotate());
        $this->assertFalse($imported->report()->security->allowsFillForms());
        $roundTripped = $imported->save();

        $this->assertStringContainsString('(Permission helper hello) Tj', $roundTripped);
    }

    public function testImportedSecurityReportCanShowUserPasswordAuthenticationPath(): void
    {
        if (!function_exists('openssl_encrypt')) {
            $this->markTestSkipped('OpenSSL extension is required for AESV2 writer encryption tests.');
        }

        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('User auth hello', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: 'user-secret',
                ownerPassword: 'owner-secret',
                encryptionRevision: 4,
                encryptionMethod: 'AESV2',
            ));

        $imported = Pdf::loadString($bytes, 'user-secret');

        $this->assertNotNull($imported->report()->security);
        $this->assertTrue($imported->report()->security->isPasswordProtected());
        $this->assertTrue($imported->report()->security->isEffectivelyEncrypted());
        $this->assertSame(128, $imported->report()->security->keyLengthBits);
        $this->assertFalse($imported->report()->security->isLegacy40Bit());
        $this->assertTrue($imported->report()->security->uses128BitKeys());
        $this->assertSame('user', $imported->report()->security->authenticatedAs);
        $this->assertFalse($imported->report()->security->usesIdentityFilters());
        $this->assertFalse($imported->report()->security->hasMixedStringAndStreamEncryption());
        $this->assertTrue($imported->report()->security->openedWithPassword);
        $this->assertFalse($imported->report()->security->openedWithoutPassword());
        $this->assertSame('StdCF', $imported->report()->security->stringFilterName);
        $this->assertSame('StdCF', $imported->report()->security->streamFilterName);
        $this->assertTrue($imported->report()->security->authenticatedAsUser());
        $this->assertFalse($imported->report()->security->authenticatedAsOwner());
    }

    public function testImportedSecurityReportCanShowOwnerPasswordAuthenticationPath(): void
    {
        if (!function_exists('openssl_encrypt')) {
            $this->markTestSkipped('OpenSSL extension is required for AESV2 writer encryption tests.');
        }

        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('Owner auth hello', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: 'user-secret',
                ownerPassword: 'owner-secret',
                encryptionRevision: 4,
                encryptionMethod: 'AESV2',
            ));

        $imported = Pdf::loadString($bytes, 'owner-secret');

        $this->assertNotNull($imported->report()->security);
        $this->assertTrue($imported->report()->security->isPasswordProtected());
        $this->assertTrue($imported->report()->security->isEffectivelyEncrypted());
        $this->assertSame(128, $imported->report()->security->keyLengthBits);
        $this->assertFalse($imported->report()->security->isLegacy40Bit());
        $this->assertTrue($imported->report()->security->uses128BitKeys());
        $this->assertSame('owner', $imported->report()->security->authenticatedAs);
        $this->assertFalse($imported->report()->security->usesIdentityFilters());
        $this->assertFalse($imported->report()->security->hasMixedStringAndStreamEncryption());
        $this->assertTrue($imported->report()->security->openedWithPassword);
        $this->assertFalse($imported->report()->security->openedWithoutPassword());
        $this->assertSame('StdCF', $imported->report()->security->stringFilterName);
        $this->assertSame('StdCF', $imported->report()->security->streamFilterName);
        $this->assertFalse($imported->report()->security->authenticatedAsUser());
        $this->assertTrue($imported->report()->security->authenticatedAsOwner());
    }

    public function testGeneratedPdfCanBeSavedWithStandardSecurityRevision4Rc4Encryption(): void
    {
        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('Revision 4 hello', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: '',
                ownerPassword: 'owner-secret',
                encryptionRevision: 4,
                encryptMetadata: false,
            ));

        $this->assertStringContainsString('/Encrypt', $bytes);
        $this->assertStringContainsString('/V 4', $bytes);
        $this->assertStringContainsString('/R 4', $bytes);
        $this->assertStringContainsString('/CFM /V2', $bytes);
        $this->assertStringContainsString('/EncryptMetadata false', $bytes);
        $this->assertStringNotContainsString('(Revision 4 hello) Tj', $bytes);

        $imported = Pdf::loadString($bytes);

        $this->assertSame(1, $imported->report()->pageCount);
        $this->assertNotNull($imported->report()->security);
        $this->assertSame(128, $imported->report()->security->keyLengthBits);
        $this->assertFalse($imported->report()->security->isLegacy40Bit());
        $this->assertTrue($imported->report()->security->uses128BitKeys());

        $roundTripped = $imported->save();

        $this->assertSame('RC4', $imported->report()->security->algorithm());
        $this->assertSame('StdCF', $imported->report()->security->stringFilterName);
        $this->assertSame('StdCF', $imported->report()->security->streamFilterName);
        $this->assertTrue($imported->report()->security->usesRc4());
        $this->assertFalse($imported->report()->security->usesAes());
        $this->assertStringContainsString('(Revision 4 hello) Tj', $roundTripped);
        $this->assertStringNotContainsString('/Encrypt', $roundTripped);
    }

    public function testRevision2WriterRejectsRevision4OnlyEncryptionOptions(): void
    {
        $document = Pdf::new()
            ->addPage()
            ->text(new TextRun('Legacy revision options', 72, 720))
            ->endPage()
            ->build();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('encryptMetadata=false requires revision 4 writer encryption.');

        $document->save(options: new WriteOptions(
            userPassword: '',
            ownerPassword: 'owner-secret',
            encryptMetadata: false,
        ));
    }

    public function testRevision3WriterRejectsExplicitCryptFilters(): void
    {
        $document = Pdf::new()
            ->addPage()
            ->text(new TextRun('Revision 3 explicit crypt filters', 72, 720))
            ->endPage()
            ->build();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Explicit crypt filters require revision 4 writer encryption.');

        $document->save(options: new WriteOptions(
            userPassword: '',
            ownerPassword: 'owner-secret',
            encryptionRevision: 3,
            useExplicitCryptFilters: true,
        ));
    }

    public function testRevision3WriterRejectsIdentityStringOrStreamOptions(): void
    {
        $document = Pdf::new()
            ->addPage()
            ->text(new TextRun('Revision 3 identity controls', 72, 720))
            ->endPage()
            ->build();

        try {
            $document->save(options: new WriteOptions(
                userPassword: '',
                ownerPassword: 'owner-secret',
                encryptionRevision: 3,
                encryptStrings: false,
            ));
            $this->fail('Expected revision 3 writer encryption to reject encryptStrings=false.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame(
                'encryptStrings=false requires revision 4 writer encryption.',
                $exception->getMessage(),
            );
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('encryptStreams=false requires revision 4 writer encryption.');

        $document->save(options: new WriteOptions(
            userPassword: '',
            ownerPassword: 'owner-secret',
            encryptionRevision: 3,
            encryptStreams: false,
        ));
    }

    public function testRevision3WriterRejectsEmbeddedFileFilterSelection(): void
    {
        $document = Pdf::new()
            ->addPage()
            ->text(new TextRun('Revision 3 embedded file filter', 72, 720))
            ->endPage()
            ->build();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('embeddedFileFilterName requires revision 4 writer encryption.');

        $document->save(options: new WriteOptions(
            userPassword: '',
            ownerPassword: 'owner-secret',
            encryptionRevision: 3,
            embeddedFileFilterName: 'StdCF',
        ));
    }

    public function testWriterRejectsUnsupportedEmbeddedFileFilterName(): void
    {
        $document = Pdf::new()
            ->addPage()
            ->text(new TextRun('Unsupported embedded file filter', 72, 720))
            ->endPage()
            ->build();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported embedded file crypt filter name: CustomCF');

        $document->save(options: new WriteOptions(
            userPassword: '',
            ownerPassword: 'owner-secret',
            encryptionRevision: 4,
            embeddedFileFilterName: 'CustomCF',
        ));
    }

    public function testWriterRejectsEfOpenEmbeddedFileAuthEventWithoutEmbeddedFileFilterSelection(): void
    {
        $document = Pdf::new()
            ->addPage()
            ->text(new TextRun('Missing embedded file filter for EFOpen', 72, 720))
            ->endPage()
            ->build();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('embeddedFileAuthEvent=EFOpen requires an embeddedFileFilterName.');

        $document->save(options: new WriteOptions(
            userPassword: '',
            ownerPassword: 'owner-secret',
            encryptionRevision: 4,
            embeddedFileAuthEvent: 'EFOpen',
        ));
    }

    public function testGeneratedPdfCanBeSavedWithStandardSecurityRevision3Rc4Encryption(): void
    {
        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('Revision 3 hello', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: '',
                ownerPassword: 'owner-secret',
                encryptionRevision: 3,
            ));

        $this->assertStringContainsString('/Encrypt', $bytes);
        $this->assertStringContainsString('/V 2', $bytes);
        $this->assertStringContainsString('/R 3', $bytes);
        $this->assertStringContainsString('/Length 128', $bytes);
        $this->assertStringNotContainsString('(Revision 3 hello) Tj', $bytes);

        $imported = Pdf::loadString($bytes);

        $this->assertSame(1, $imported->report()->pageCount);

        $roundTripped = $imported->save();

        $this->assertNotNull($imported->report()->security);
        $this->assertSame('RC4', $imported->report()->security->algorithm());
        $this->assertSame('RC4', $imported->report()->security->algorithmSummary());
        $this->assertSame('Standard', $imported->report()->security->stringFilterName);
        $this->assertSame('Standard', $imported->report()->security->streamFilterName);
        $this->assertTrue($imported->report()->security->usesRc4());
        $this->assertFalse($imported->report()->security->usesAes());
        $this->assertStringContainsString('(Revision 3 hello) Tj', $roundTripped);
        $this->assertStringNotContainsString('/Encrypt', $roundTripped);
    }

    public function testGeneratedPdfCanBeSavedWithStandardSecurityRevision4AesV2Encryption(): void
    {
        if (!function_exists('openssl_encrypt')) {
            $this->markTestSkipped('OpenSSL extension is required for AESV2 writer encryption tests.');
        }

        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('AES hello', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: '',
                ownerPassword: 'owner-secret',
                encryptionRevision: 4,
                encryptionMethod: 'AESV2',
                encryptMetadata: false,
            ));

        $this->assertStringContainsString('/Encrypt', $bytes);
        $this->assertStringContainsString('/V 4', $bytes);
        $this->assertStringContainsString('/R 4', $bytes);
        $this->assertStringContainsString('/CFM /AESV2', $bytes);
        $this->assertStringContainsString('/EncryptMetadata false', $bytes);
        $this->assertStringNotContainsString('(AES hello) Tj', $bytes);

        $imported = Pdf::loadString($bytes);

        $this->assertSame(1, $imported->report()->pageCount);
        $this->assertNotNull($imported->report()->security);
        $this->assertSame('Standard', $imported->report()->security->filter);
        $this->assertSame(4, $imported->report()->security->revision);
        $this->assertSame(128, $imported->report()->security->keyLengthBits);
        $this->assertFalse($imported->report()->security->isLegacy40Bit());
        $this->assertTrue($imported->report()->security->uses128BitKeys());
        $this->assertSame(-4, $imported->report()->security->permissions);
        $this->assertSame('user', $imported->report()->security->authenticatedAs);
        $this->assertSame('AESV2', $imported->report()->security->algorithm());
        $this->assertSame('StdCF', $imported->report()->security->stringFilterName);
        $this->assertSame('StdCF', $imported->report()->security->streamFilterName);
        $this->assertTrue($imported->report()->security->isPasswordProtected());
        $this->assertTrue($imported->report()->security->isEffectivelyEncrypted());
        $this->assertFalse($imported->report()->security->usesIdentityFilters());
        $this->assertFalse($imported->report()->security->hasMixedStringAndStreamEncryption());
        $this->assertTrue($imported->report()->security->allowsAllPermissions());
        $this->assertFalse($imported->report()->security->hasRestrictedPermissions());
        $this->assertFalse($imported->report()->security->usesRc4());
        $this->assertTrue($imported->report()->security->usesAes());
        $this->assertTrue($imported->report()->security->stringsEncrypted());
        $this->assertTrue($imported->report()->security->streamsEncrypted());
        $this->assertFalse($imported->report()->security->usesIdentityStringFilter());
        $this->assertFalse($imported->report()->security->usesIdentityStreamFilter());
        $this->assertTrue($imported->report()->security->allowsPrint());
        $this->assertTrue($imported->report()->security->allowsModify());
        $this->assertTrue($imported->report()->security->allowsCopy());
        $this->assertTrue($imported->report()->security->allowsAnnotate());
        $this->assertTrue($imported->report()->security->allowsFillForms());
        $this->assertTrue($imported->report()->security->allowsAccessibility());
        $this->assertTrue($imported->report()->security->allowsAssemble());
        $this->assertTrue($imported->report()->security->allowsHighQualityPrint());
        $this->assertSame('AESV2', $imported->report()->security->stringMethod);
        $this->assertSame('AESV2', $imported->report()->security->streamMethod);
        $this->assertFalse($imported->report()->security->encryptMetadata);

        $roundTripped = $imported->save();

        $this->assertStringContainsString('(AES hello) Tj', $roundTripped);
        $this->assertStringNotContainsString('/Encrypt', $roundTripped);
    }

    public function testGeneratedPdfCanBeSavedWithStandardSecurityRevision5AesV3Encryption(): void
    {
        if (!function_exists('openssl_encrypt')) {
            $this->markTestSkipped('OpenSSL extension is required for AESV3 writer encryption tests.');
        }

        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('AESV3 hello', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: '',
                ownerPassword: 'owner-secret',
                encryptionRevision: 5,
                encryptionMethod: 'AESV3',
                encryptMetadata: false,
            ));

        $this->assertStringContainsString('/Encrypt', $bytes);
        $this->assertStringContainsString('/V 5', $bytes);
        $this->assertStringContainsString('/R 5', $bytes);
        $this->assertStringContainsString('/CFM /AESV3', $bytes);
        $this->assertStringContainsString('/OE <', $bytes);
        $this->assertStringContainsString('/UE <', $bytes);
        $this->assertStringContainsString('/Perms <', $bytes);
        $this->assertStringContainsString('/EncryptMetadata false', $bytes);
        $this->assertStringNotContainsString('(AESV3 hello) Tj', $bytes);

        $imported = Pdf::loadString($bytes);

        $this->assertSame(1, $imported->report()->pageCount);
        $this->assertNotNull($imported->report()->security);
        $this->assertSame('Standard', $imported->report()->security->filter);
        $this->assertSame(5, $imported->report()->security->revision);
        $this->assertSame(5, $imported->report()->security->version);
        $this->assertSame(256, $imported->report()->security->keyLengthBits);
        $this->assertFalse($imported->report()->security->isLegacy40Bit());
        $this->assertTrue($imported->report()->security->uses128BitKeys());
        $this->assertSame(-4, $imported->report()->security->permissions);
        $this->assertSame('user', $imported->report()->security->authenticatedAs);
        $this->assertSame('AESV3', $imported->report()->security->algorithm());
        $this->assertSame('StdCF', $imported->report()->security->stringFilterName);
        $this->assertSame('StdCF', $imported->report()->security->streamFilterName);
        $this->assertFalse($imported->report()->security->usesEmbeddedFileCryptFilter());
        $this->assertFalse($imported->report()->security->usesExplicitEmbeddedFileCryptFilter());
        $this->assertTrue($imported->report()->security->usesInheritedEmbeddedFileFilter());
        $this->assertSame('StdCF', $imported->report()->security->effectiveEmbeddedFileFilterName());
        $this->assertSame('AESV3', $imported->report()->security->effectiveEmbeddedFileMethod());
        $this->assertTrue($imported->report()->security->usesDefaultEmbeddedFileCryptFilter());
        $this->assertTrue($imported->report()->security->usesInheritedDefaultEmbeddedFileCryptFilter());
        $this->assertFalse($imported->report()->security->usesExplicitDefaultEmbeddedFileCryptFilter());
        $this->assertFalse($imported->report()->security->usesDefaultRevision4EmbeddedFileCryptFilter());
        $this->assertTrue($imported->report()->security->usesDefaultRevision5EmbeddedFileCryptFilter());
        $this->assertTrue($imported->report()->security->embeddedFilesEncrypted());
        $this->assertFalse($imported->report()->security->usesLegacyStandardFilters());
        $this->assertTrue($imported->report()->security->usesDefaultStandardCryptFilters());
        $this->assertFalse($imported->report()->security->usesDefaultRevision4CryptFilters());
        $this->assertTrue($imported->report()->security->usesDefaultRevision5CryptFilters());
        $this->assertFalse($imported->report()->security->usesCustomCryptFilterConfiguration());
        $this->assertTrue($imported->report()->security->isPasswordProtected());
        $this->assertTrue($imported->report()->security->isEffectivelyEncrypted());
        $this->assertFalse($imported->report()->security->usesIdentityFilters());
        $this->assertFalse($imported->report()->security->hasMixedStringAndStreamEncryption());
        $this->assertTrue($imported->report()->security->allowsAllPermissions());
        $this->assertFalse($imported->report()->security->hasRestrictedPermissions());
        $this->assertFalse($imported->report()->security->usesRc4());
        $this->assertTrue($imported->report()->security->usesAes());
        $this->assertTrue($imported->report()->security->stringsEncrypted());
        $this->assertTrue($imported->report()->security->streamsEncrypted());
        $this->assertFalse($imported->report()->security->usesIdentityStringFilter());
        $this->assertFalse($imported->report()->security->usesIdentityStreamFilter());
        $this->assertSame('AESV3', $imported->report()->security->stringMethod);
        $this->assertSame('AESV3', $imported->report()->security->streamMethod);
        $this->assertFalse($imported->report()->security->encryptMetadata);

        $roundTripped = $imported->save();

        $this->assertStringContainsString('(AESV3 hello) Tj', $roundTripped);
        $this->assertStringNotContainsString('/Encrypt', $roundTripped);
    }

    public function testRevision4WriterRejectsAesV3Method(): void
    {
        $document = Pdf::new()
            ->addPage()
            ->text(new TextRun('Revision 4 AESV3', 72, 720))
            ->endPage()
            ->build();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('AESV3 writer encryption requires revision 5.');

        $document->save(options: new WriteOptions(
            userPassword: '',
            ownerPassword: 'owner-secret',
            encryptionRevision: 4,
            encryptionMethod: 'AESV3',
        ));
    }

    public function testRevision5WriterRejectsNonAesV3Method(): void
    {
        $document = Pdf::new()
            ->addPage()
            ->text(new TextRun('Revision 5 RC4', 72, 720))
            ->endPage()
            ->build();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Revision 5 writer encryption requires AESV3.');

        $document->save(options: new WriteOptions(
            userPassword: '',
            ownerPassword: 'owner-secret',
            encryptionRevision: 5,
            encryptionMethod: 'RC4',
        ));
    }

    public function testRevision5EncryptedWriterOutputCanRequireANonEmptyUserPassword(): void
    {
        if (!function_exists('openssl_encrypt')) {
            $this->markTestSkipped('OpenSSL extension is required for AESV3 writer encryption tests.');
        }

        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('Protected AESV3 hello', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: 'user-secret',
                ownerPassword: 'owner-secret',
                encryptionRevision: 5,
                encryptionMethod: 'AESV3',
            ));

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Unable to authenticate encrypted PDF with the provided password.');

        Pdf::loadString($bytes);
    }

    public function testRevision5EncryptedWriterOutputCanBeOpenedWithUserOrOwnerPassword(): void
    {
        if (!function_exists('openssl_encrypt')) {
            $this->markTestSkipped('OpenSSL extension is required for AESV3 writer encryption tests.');
        }

        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('Dual password AESV3 hello', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: 'user-secret',
                ownerPassword: 'owner-secret',
                encryptionRevision: 5,
                encryptionMethod: 'AESV3',
            ));

        $userLoaded = Pdf::loadString($bytes, 'user-secret');
        $ownerLoaded = Pdf::loadString($bytes, 'owner-secret');

        $this->assertSame(1, $userLoaded->report()->pageCount);
        $this->assertSame(1, $ownerLoaded->report()->pageCount);
        $this->assertStringContainsString('(Dual password AESV3 hello) Tj', $userLoaded->save());
        $this->assertStringContainsString('(Dual password AESV3 hello) Tj', $ownerLoaded->save());
    }

    public function testImportedSecurityReportCanShowRevision5UserPasswordAuthenticationPath(): void
    {
        if (!function_exists('openssl_encrypt')) {
            $this->markTestSkipped('OpenSSL extension is required for AESV3 writer encryption tests.');
        }

        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('Revision 5 user auth hello', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: 'user-secret',
                ownerPassword: 'owner-secret',
                encryptionRevision: 5,
                encryptionMethod: 'AESV3',
            ));

        $imported = Pdf::loadString($bytes, 'user-secret');

        $this->assertNotNull($imported->report()->security);
        $this->assertSame(5, $imported->report()->security->revision);
        $this->assertSame('user', $imported->report()->security->authenticatedAs);
        $this->assertTrue($imported->report()->security->openedWithPassword);
        $this->assertFalse($imported->report()->security->openedWithoutPassword());
        $this->assertTrue($imported->report()->security->authenticatedAsUser());
        $this->assertFalse($imported->report()->security->authenticatedAsOwner());
        $this->assertSame('AESV3', $imported->report()->security->algorithm());
    }

    public function testImportedSecurityReportCanShowRevision5OwnerPasswordAuthenticationPath(): void
    {
        if (!function_exists('openssl_encrypt')) {
            $this->markTestSkipped('OpenSSL extension is required for AESV3 writer encryption tests.');
        }

        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('Revision 5 owner auth hello', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: 'user-secret',
                ownerPassword: 'owner-secret',
                encryptionRevision: 5,
                encryptionMethod: 'AESV3',
            ));

        $imported = Pdf::loadString($bytes, 'owner-secret');

        $this->assertNotNull($imported->report()->security);
        $this->assertSame(5, $imported->report()->security->revision);
        $this->assertSame('owner', $imported->report()->security->authenticatedAs);
        $this->assertTrue($imported->report()->security->openedWithPassword);
        $this->assertFalse($imported->report()->security->openedWithoutPassword());
        $this->assertFalse($imported->report()->security->authenticatedAsUser());
        $this->assertTrue($imported->report()->security->authenticatedAsOwner());
        $this->assertSame('AESV3', $imported->report()->security->algorithm());
    }

    public function testEncryptedWriterOutputCanRequireANonEmptyUserPassword(): void
    {
        if (!function_exists('openssl_encrypt')) {
            $this->markTestSkipped('OpenSSL extension is required for AESV2 writer encryption tests.');
        }

        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('Protected hello', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: 'user-secret',
                ownerPassword: 'owner-secret',
                encryptionRevision: 4,
                encryptionMethod: 'AESV2',
            ));

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Unable to authenticate encrypted PDF with the provided password.');

        Pdf::loadString($bytes);
    }

    public function testEncryptedWriterOutputCanBeOpenedWithUserOrOwnerPassword(): void
    {
        if (!function_exists('openssl_encrypt')) {
            $this->markTestSkipped('OpenSSL extension is required for AESV2 writer encryption tests.');
        }

        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('Dual password hello', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: 'user-secret',
                ownerPassword: 'owner-secret',
                encryptionRevision: 4,
                encryptionMethod: 'AESV2',
            ));

        $userLoaded = Pdf::loadString($bytes, 'user-secret');
        $ownerLoaded = Pdf::loadString($bytes, 'owner-secret');

        $this->assertSame(1, $userLoaded->report()->pageCount);
        $this->assertSame(1, $ownerLoaded->report()->pageCount);
        $this->assertStringContainsString('(Dual password hello) Tj', $userLoaded->save());
        $this->assertStringContainsString('(Dual password hello) Tj', $ownerLoaded->save());
    }

    public function testRevision4EncryptionCanLeaveStringsUnencryptedViaIdentityStringFilter(): void
    {
        $bytes = Pdf::new()
            ->metadata(title: 'Visible Title', author: 'PdfToolkit')
            ->addPage()
            ->text(new TextRun('Hidden stream text', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: '',
                ownerPassword: 'owner-secret',
                encryptionRevision: 4,
                encryptStrings: false,
            ));

        $this->assertStringContainsString('/StrF /Identity', $bytes);
        $this->assertStringContainsString('/Title (Visible Title)', $bytes);
        $this->assertStringNotContainsString('(Hidden stream text) Tj', $bytes);

        $imported = Pdf::loadString($bytes);
        $this->assertNotNull($imported->report()->security);
        $this->assertTrue($imported->report()->security->isPasswordProtected());
        $this->assertTrue($imported->report()->security->isEffectivelyEncrypted());
        $this->assertFalse($imported->report()->security->stringsEncrypted());
        $this->assertTrue($imported->report()->security->streamsEncrypted());
        $this->assertTrue($imported->report()->security->usesIdentityStringFilter());
        $this->assertFalse($imported->report()->security->usesIdentityStreamFilter());
        $this->assertSame('RC4', $imported->report()->security->algorithm());
        $this->assertSame('Identity', $imported->report()->security->stringFilterName);
        $this->assertSame('StdCF', $imported->report()->security->streamFilterName);
        $this->assertTrue($imported->report()->security->usesIdentityFilters());
        $this->assertTrue($imported->report()->security->hasMixedStringAndStreamEncryption());
        $roundTripped = $imported->save();

        $this->assertStringContainsString('/Title (Visible Title)', $roundTripped);
        $this->assertStringContainsString('(Hidden stream text) Tj', $roundTripped);
    }

    public function testRevision4EncryptionCanSelectStdCfForEmbeddedFiles(): void
    {
        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('Embedded file StdCF hello', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: '',
                ownerPassword: 'owner-secret',
                encryptionRevision: 4,
                embeddedFileFilterName: 'StdCF',
            ));

        $this->assertStringContainsString('/EFF /StdCF', $bytes);

        $imported = Pdf::loadString($bytes);
        $this->assertNotNull($imported->report()->security);
        $this->assertSame('StdCF', $imported->report()->security->embeddedFileFilterName);
        $this->assertSame('RC4', $imported->report()->security->embeddedFileMethod);
        $this->assertTrue($imported->report()->security->usesEmbeddedFileCryptFilter());
        $this->assertFalse($imported->report()->security->usesInheritedEmbeddedFileFilter());
        $this->assertSame('StdCF', $imported->report()->security->effectiveEmbeddedFileFilterName());
        $this->assertSame('RC4', $imported->report()->security->effectiveEmbeddedFileMethod());
        $this->assertTrue($imported->report()->security->embeddedFilesEncrypted());
        $this->assertFalse($imported->report()->security->usesNoOpEmbeddedFileFilter());
        $this->assertFalse($imported->report()->security->usesNamedNoOpEmbeddedFileFilter());
        $this->assertSame('DocOpen', $imported->report()->security->embeddedFileAuthEvent());
        $this->assertTrue($imported->report()->security->usesEmbeddedFileDocOpenAuthEvent());
        $this->assertSame(128, $imported->report()->security->embeddedFileKeyLengthBits());
        $this->assertTrue($imported->report()->security->embeddedFileFilterMatchesStringFilter());
        $this->assertTrue($imported->report()->security->embeddedFileFilterMatchesStreamFilter());
        $this->assertFalse($imported->report()->security->hasDistinctEmbeddedFileCryptFilter());
    }

    public function testRevision5EncryptionCanSelectNamedNoOpFilterForEmbeddedFiles(): void
    {
        if (!function_exists('openssl_encrypt')) {
            $this->markTestSkipped('OpenSSL extension is required for AESV3 writer encryption tests.');
        }

        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('Embedded file AESV3 NoCrypt hello', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: '',
                ownerPassword: 'owner-secret',
                encryptionRevision: 5,
                encryptionMethod: 'AESV3',
                embeddedFileFilterName: 'NoCrypt',
            ));

        $this->assertStringContainsString('/EFF /NoCrypt', $bytes);
        $this->assertStringContainsString('/NoCrypt << /CFM /None /AuthEvent /DocOpen >>', $bytes);

        $imported = Pdf::loadString($bytes);
        $this->assertNotNull($imported->report()->security);
        $this->assertSame('NoCrypt', $imported->report()->security->embeddedFileFilterName);
        $this->assertSame('Identity', $imported->report()->security->embeddedFileMethod);
        $this->assertTrue($imported->report()->security->usesEmbeddedFileCryptFilter());
        $this->assertFalse($imported->report()->security->usesInheritedEmbeddedFileFilter());
        $this->assertSame('NoCrypt', $imported->report()->security->effectiveEmbeddedFileFilterName());
        $this->assertSame('Identity', $imported->report()->security->effectiveEmbeddedFileMethod());
        $this->assertFalse($imported->report()->security->embeddedFilesEncrypted());
        $this->assertTrue($imported->report()->security->usesNoOpEmbeddedFileFilter());
        $this->assertTrue($imported->report()->security->usesNamedNoOpEmbeddedFileFilter());
        $this->assertSame('DocOpen', $imported->report()->security->embeddedFileAuthEvent());
        $this->assertTrue($imported->report()->security->usesEmbeddedFileDocOpenAuthEvent());
        $this->assertNull($imported->report()->security->embeddedFileKeyLengthBits());
        $this->assertFalse($imported->report()->security->embeddedFileFilterMatchesStringFilter());
        $this->assertFalse($imported->report()->security->embeddedFileFilterMatchesStreamFilter());
        $this->assertTrue($imported->report()->security->hasDistinctEmbeddedFileCryptFilter());
    }

    public function testRevision5EncryptionCanSelectStdCfForEmbeddedFiles(): void
    {
        if (!function_exists('openssl_encrypt')) {
            $this->markTestSkipped('OpenSSL extension is required for AESV3 writer encryption tests.');
        }

        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('Embedded file AESV3 StdCF hello', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: '',
                ownerPassword: 'owner-secret',
                encryptionRevision: 5,
                encryptionMethod: 'AESV3',
                embeddedFileFilterName: 'StdCF',
            ));

        $this->assertStringContainsString('/EFF /StdCF', $bytes);

        $imported = Pdf::loadString($bytes);
        $this->assertNotNull($imported->report()->security);
        $this->assertSame('StdCF', $imported->report()->security->embeddedFileFilterName);
        $this->assertSame('AESV3', $imported->report()->security->embeddedFileMethod);
        $this->assertTrue($imported->report()->security->usesEmbeddedFileCryptFilter());
        $this->assertTrue($imported->report()->security->usesExplicitEmbeddedFileCryptFilter());
        $this->assertFalse($imported->report()->security->usesInheritedEmbeddedFileFilter());
        $this->assertSame('StdCF', $imported->report()->security->effectiveEmbeddedFileFilterName());
        $this->assertSame('AESV3', $imported->report()->security->effectiveEmbeddedFileMethod());
        $this->assertTrue($imported->report()->security->usesDefaultEmbeddedFileCryptFilter());
        $this->assertFalse($imported->report()->security->usesInheritedDefaultEmbeddedFileCryptFilter());
        $this->assertTrue($imported->report()->security->usesExplicitDefaultEmbeddedFileCryptFilter());
        $this->assertFalse($imported->report()->security->usesDefaultRevision4EmbeddedFileCryptFilter());
        $this->assertTrue($imported->report()->security->usesDefaultRevision5EmbeddedFileCryptFilter());
        $this->assertTrue($imported->report()->security->embeddedFilesEncrypted());
        $this->assertFalse($imported->report()->security->usesNoOpEmbeddedFileFilter());
        $this->assertFalse($imported->report()->security->usesNamedNoOpEmbeddedFileFilter());
        $this->assertSame('DocOpen', $imported->report()->security->embeddedFileAuthEvent());
        $this->assertTrue($imported->report()->security->usesEmbeddedFileDocOpenAuthEvent());
        $this->assertSame(256, $imported->report()->security->embeddedFileKeyLengthBits());
        $this->assertTrue($imported->report()->security->embeddedFileFilterMatchesStringFilter());
        $this->assertTrue($imported->report()->security->embeddedFileFilterMatchesStreamFilter());
        $this->assertFalse($imported->report()->security->hasDistinctEmbeddedFileCryptFilter());
    }

    public function testRevision4EncryptionCanSelectEfOpenEmbeddedStdCfForEmbeddedFiles(): void
    {
        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('Embedded file revision 4 EFOpen hello', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: '',
                ownerPassword: 'owner-secret',
                encryptionRevision: 4,
                embeddedFileFilterName: 'StdCF',
                embeddedFileAuthEvent: 'EFOpen',
            ));

        $this->assertStringContainsString('/EFF /EmbeddedStdCF', $bytes);
        $this->assertStringContainsString('/EmbeddedStdCF << /Length 128 /CFM /V2 /AuthEvent /EFOpen >>', $bytes);

        $imported = Pdf::loadString($bytes);
        $this->assertNotNull($imported->report()->security);
        $this->assertSame('EmbeddedStdCF', $imported->report()->security->embeddedFileFilterName);
        $this->assertSame('RC4', $imported->report()->security->embeddedFileMethod);
        $this->assertTrue($imported->report()->security->usesEmbeddedFileCryptFilter());
        $this->assertTrue($imported->report()->security->usesExplicitEmbeddedFileCryptFilter());
        $this->assertFalse($imported->report()->security->usesInheritedEmbeddedFileFilter());
        $this->assertSame('EmbeddedStdCF', $imported->report()->security->effectiveEmbeddedFileFilterName());
        $this->assertSame('RC4', $imported->report()->security->effectiveEmbeddedFileMethod());
        $this->assertTrue($imported->report()->security->embeddedFilesEncrypted());
        $this->assertSame('EFOpen', $imported->report()->security->embeddedFileAuthEvent());
        $this->assertTrue($imported->report()->security->usesEmbeddedFileEfOpenAuthEvent());
        $this->assertFalse($imported->report()->security->usesEmbeddedFileDocOpenAuthEvent());
        $this->assertSame(128, $imported->report()->security->embeddedFileKeyLengthBits());
        $this->assertFalse($imported->report()->security->embeddedFileFilterMatchesStringFilter());
        $this->assertFalse($imported->report()->security->embeddedFileFilterMatchesStreamFilter());
        $this->assertTrue($imported->report()->security->hasDistinctEmbeddedFileCryptFilter());
        $this->assertTrue($imported->report()->security->definesCryptFilter('EmbeddedStdCF'));
        $this->assertSame('RC4', $imported->report()->security->cryptFilterMethod('EmbeddedStdCF'));
        $this->assertSame('EFOpen', $imported->report()->security->cryptFilterAuthEvent('EmbeddedStdCF'));
        $this->assertTrue($imported->report()->security->usesEfOpenAuthEvent('EmbeddedStdCF'));
    }

    public function testRevision4EncryptionCanSelectNamedNoOpFilterForEmbeddedFiles(): void
    {
        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('Embedded file NoCrypt hello', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: '',
                ownerPassword: 'owner-secret',
                encryptionRevision: 4,
                embeddedFileFilterName: 'NoCrypt',
            ));

        $this->assertStringContainsString('/EFF /NoCrypt', $bytes);
        $this->assertStringContainsString('/NoCrypt << /CFM /None /AuthEvent /DocOpen >>', $bytes);

        $imported = Pdf::loadString($bytes);
        $this->assertNotNull($imported->report()->security);
        $this->assertSame('NoCrypt', $imported->report()->security->embeddedFileFilterName);
        $this->assertSame('Identity', $imported->report()->security->embeddedFileMethod);
        $this->assertTrue($imported->report()->security->usesEmbeddedFileCryptFilter());
        $this->assertTrue($imported->report()->security->usesExplicitEmbeddedFileCryptFilter());
        $this->assertFalse($imported->report()->security->usesInheritedEmbeddedFileFilter());
        $this->assertSame('NoCrypt', $imported->report()->security->effectiveEmbeddedFileFilterName());
        $this->assertSame('Identity', $imported->report()->security->effectiveEmbeddedFileMethod());
        $this->assertFalse($imported->report()->security->usesDefaultEmbeddedFileCryptFilter());
        $this->assertFalse($imported->report()->security->usesInheritedDefaultEmbeddedFileCryptFilter());
        $this->assertFalse($imported->report()->security->usesExplicitDefaultEmbeddedFileCryptFilter());
        $this->assertFalse($imported->report()->security->usesDefaultRevision4EmbeddedFileCryptFilter());
        $this->assertFalse($imported->report()->security->usesDefaultRevision5EmbeddedFileCryptFilter());
        $this->assertFalse($imported->report()->security->embeddedFilesEncrypted());
        $this->assertTrue($imported->report()->security->usesNoOpEmbeddedFileFilter());
        $this->assertTrue($imported->report()->security->usesNamedNoOpEmbeddedFileFilter());
        $this->assertSame('DocOpen', $imported->report()->security->embeddedFileAuthEvent());
        $this->assertTrue($imported->report()->security->usesEmbeddedFileDocOpenAuthEvent());
        $this->assertNull($imported->report()->security->embeddedFileKeyLengthBits());
        $this->assertFalse($imported->report()->security->embeddedFileFilterMatchesStringFilter());
        $this->assertFalse($imported->report()->security->embeddedFileFilterMatchesStreamFilter());
        $this->assertTrue($imported->report()->security->hasDistinctEmbeddedFileCryptFilter());
    }

    public function testRevision5EncryptionCanSelectEfOpenNamedNoOpFilterForEmbeddedFiles(): void
    {
        if (!function_exists('openssl_encrypt')) {
            $this->markTestSkipped('OpenSSL extension is required for AESV3 writer encryption tests.');
        }

        $bytes = Pdf::new()
            ->addPage()
            ->text(new TextRun('Embedded file revision 5 EFOpen NoCrypt hello', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: '',
                ownerPassword: 'owner-secret',
                encryptionRevision: 5,
                encryptionMethod: 'AESV3',
                embeddedFileFilterName: 'NoCrypt',
                embeddedFileAuthEvent: 'EFOpen',
            ));

        $this->assertStringContainsString('/EFF /EmbeddedNoCrypt', $bytes);
        $this->assertStringContainsString('/EmbeddedNoCrypt << /CFM /None /AuthEvent /EFOpen >>', $bytes);

        $imported = Pdf::loadString($bytes);
        $this->assertNotNull($imported->report()->security);
        $this->assertSame('EmbeddedNoCrypt', $imported->report()->security->embeddedFileFilterName);
        $this->assertSame('Identity', $imported->report()->security->embeddedFileMethod);
        $this->assertTrue($imported->report()->security->usesEmbeddedFileCryptFilter());
        $this->assertTrue($imported->report()->security->usesExplicitEmbeddedFileCryptFilter());
        $this->assertFalse($imported->report()->security->usesInheritedEmbeddedFileFilter());
        $this->assertSame('EmbeddedNoCrypt', $imported->report()->security->effectiveEmbeddedFileFilterName());
        $this->assertSame('Identity', $imported->report()->security->effectiveEmbeddedFileMethod());
        $this->assertFalse($imported->report()->security->embeddedFilesEncrypted());
        $this->assertTrue($imported->report()->security->usesNoOpEmbeddedFileFilter());
        $this->assertTrue($imported->report()->security->usesNamedNoOpEmbeddedFileFilter());
        $this->assertSame('EFOpen', $imported->report()->security->embeddedFileAuthEvent());
        $this->assertTrue($imported->report()->security->usesEmbeddedFileEfOpenAuthEvent());
        $this->assertFalse($imported->report()->security->usesEmbeddedFileDocOpenAuthEvent());
        $this->assertNull($imported->report()->security->embeddedFileKeyLengthBits());
        $this->assertFalse($imported->report()->security->embeddedFileFilterMatchesStringFilter());
        $this->assertFalse($imported->report()->security->embeddedFileFilterMatchesStreamFilter());
        $this->assertTrue($imported->report()->security->hasDistinctEmbeddedFileCryptFilter());
        $this->assertTrue($imported->report()->security->definesCryptFilter('EmbeddedNoCrypt'));
        $this->assertSame('Identity', $imported->report()->security->cryptFilterMethod('EmbeddedNoCrypt'));
        $this->assertSame('EFOpen', $imported->report()->security->cryptFilterAuthEvent('EmbeddedNoCrypt'));
        $this->assertTrue($imported->report()->security->usesEfOpenAuthEvent('EmbeddedNoCrypt'));
    }

    public function testRevision4EncryptionCanLeaveStreamsUnencryptedViaIdentityStreamFilter(): void
    {
        $bytes = Pdf::new()
            ->metadata(title: 'Hidden Title', author: 'PdfToolkit')
            ->addPage()
            ->text(new TextRun('Visible stream text', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: '',
                ownerPassword: 'owner-secret',
                encryptionRevision: 4,
                encryptStreams: false,
                useExplicitCryptFilters: true,
            ));

        $this->assertStringContainsString('/StmF /NoCrypt', $bytes);
        $this->assertStringContainsString('/NoCrypt << /CFM /None /AuthEvent /DocOpen >>', $bytes);
        $this->assertStringContainsString('/DecodeParms << /Name /NoCrypt >>', $bytes);
        $this->assertStringContainsString('(Visible stream text) Tj', $bytes);
        $this->assertStringNotContainsString('/Title (Hidden Title)', $bytes);

        $imported = Pdf::loadString($bytes);
        $this->assertNotNull($imported->report()->security);
        $this->assertTrue($imported->report()->security->isPasswordProtected());
        $this->assertTrue($imported->report()->security->isEffectivelyEncrypted());
        $this->assertTrue($imported->report()->security->stringsEncrypted());
        $this->assertFalse($imported->report()->security->streamsEncrypted());
        $this->assertSame(['NoCrypt', 'StdCF'], $imported->report()->security->cryptFilterNames);
        $this->assertSame(['StdCF' => 'RC4', 'NoCrypt' => 'Identity'], $imported->report()->security->cryptFilters);
        $this->assertSame(['StdCF' => 'DocOpen', 'NoCrypt' => 'DocOpen'], $imported->report()->security->cryptFilterAuthEvents);
        $this->assertSame(['StdCF' => 128], $imported->report()->security->cryptFilterKeyLengthBits);
        $this->assertTrue($imported->report()->security->usesCryptFilters());
        $this->assertTrue($imported->report()->security->usesCustomNamedCryptFilters());
        $this->assertTrue($imported->report()->security->definesCryptFilter('StdCF'));
        $this->assertSame('RC4', $imported->report()->security->cryptFilterMethod('StdCF'));
        $this->assertSame('DocOpen', $imported->report()->security->cryptFilterAuthEvent('StdCF'));
        $this->assertSame(128, $imported->report()->security->cryptFilterKeyLengthBits('StdCF'));
        $this->assertTrue($imported->report()->security->usesDocOpenAuthEvent('StdCF'));
        $this->assertTrue($imported->report()->security->definesCryptFilter('NoCrypt'));
        $this->assertSame('Identity', $imported->report()->security->cryptFilterMethod('NoCrypt'));
        $this->assertSame('DocOpen', $imported->report()->security->cryptFilterAuthEvent('NoCrypt'));
        $this->assertNull($imported->report()->security->cryptFilterKeyLengthBits('NoCrypt'));
        $this->assertTrue($imported->report()->security->usesDocOpenAuthEvent('NoCrypt'));
        $this->assertFalse($imported->report()->security->usesLegacyStandardFilters());
        $this->assertFalse($imported->report()->security->usesDefaultStandardCryptFilters());
        $this->assertFalse($imported->report()->security->usesDefaultRevision4CryptFilters());
        $this->assertFalse($imported->report()->security->usesDefaultRevision5CryptFilters());
        $this->assertTrue($imported->report()->security->usesCustomCryptFilterConfiguration());
        $this->assertFalse($imported->report()->security->usesIdentityStringFilter());
        $this->assertFalse($imported->report()->security->usesIdentityStreamFilter());
        $this->assertFalse($imported->report()->security->usesNoOpStringFilter());
        $this->assertTrue($imported->report()->security->usesNoOpStreamFilter());
        $this->assertFalse($imported->report()->security->usesNamedNoOpStringFilter());
        $this->assertTrue($imported->report()->security->usesNamedNoOpStreamFilter());
        $this->assertSame('RC4', $imported->report()->security->algorithm());
        $this->assertSame('Mixed', $imported->report()->security->algorithmSummary());
        $this->assertSame('StdCF', $imported->report()->security->stringFilterName);
        $this->assertSame('NoCrypt', $imported->report()->security->streamFilterName);
        $this->assertTrue($imported->report()->security->hasMixedCryptFilters());
        $this->assertTrue($imported->report()->security->hasMixedCryptMethods());
        $this->assertFalse($imported->report()->security->usesIdentityFilters());
        $this->assertTrue($imported->report()->security->hasMixedStringAndStreamEncryption());
        $roundTripped = $imported->save();

        $this->assertStringContainsString('/Title (Hidden Title)', $roundTripped);
        $this->assertStringContainsString('(Visible stream text) Tj', $roundTripped);
    }

    public function testRevision5EncryptionCanLeaveStreamsUnencryptedViaNamedNoOpStreamFilter(): void
    {
        if (!function_exists('openssl_encrypt')) {
            $this->markTestSkipped('OpenSSL extension is required for AESV3 writer encryption tests.');
        }

        $bytes = Pdf::new()
            ->metadata(title: 'Hidden AESV3 Title', author: 'PdfToolkit')
            ->addPage()
            ->text(new TextRun('Visible AESV3 stream text', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: '',
                ownerPassword: 'owner-secret',
                encryptionRevision: 5,
                encryptionMethod: 'AESV3',
                encryptStreams: false,
                useExplicitCryptFilters: true,
            ));

        $this->assertStringContainsString('/StmF /NoCrypt', $bytes);
        $this->assertStringContainsString('/NoCrypt << /CFM /None /AuthEvent /DocOpen >>', $bytes);
        $this->assertStringContainsString('/DecodeParms << /Name /NoCrypt >>', $bytes);
        $this->assertStringContainsString('(Visible AESV3 stream text) Tj', $bytes);
        $this->assertStringNotContainsString('/Title (Hidden AESV3 Title)', $bytes);

        $imported = Pdf::loadString($bytes);
        $this->assertNotNull($imported->report()->security);
        $this->assertTrue($imported->report()->security->isPasswordProtected());
        $this->assertTrue($imported->report()->security->isEffectivelyEncrypted());
        $this->assertTrue($imported->report()->security->stringsEncrypted());
        $this->assertFalse($imported->report()->security->streamsEncrypted());
        $this->assertSame(['NoCrypt', 'StdCF'], $imported->report()->security->cryptFilterNames);
        $this->assertSame(['StdCF' => 'AESV3', 'NoCrypt' => 'Identity'], $imported->report()->security->cryptFilters);
        $this->assertSame(['StdCF' => 'DocOpen', 'NoCrypt' => 'DocOpen'], $imported->report()->security->cryptFilterAuthEvents);
        $this->assertSame(['StdCF' => 256], $imported->report()->security->cryptFilterKeyLengthBits);
        $this->assertTrue($imported->report()->security->usesCryptFilters());
        $this->assertTrue($imported->report()->security->usesCustomNamedCryptFilters());
        $this->assertTrue($imported->report()->security->definesCryptFilter('StdCF'));
        $this->assertSame('AESV3', $imported->report()->security->cryptFilterMethod('StdCF'));
        $this->assertSame('DocOpen', $imported->report()->security->cryptFilterAuthEvent('StdCF'));
        $this->assertSame(256, $imported->report()->security->cryptFilterKeyLengthBits('StdCF'));
        $this->assertTrue($imported->report()->security->usesDocOpenAuthEvent('StdCF'));
        $this->assertTrue($imported->report()->security->definesCryptFilter('NoCrypt'));
        $this->assertSame('Identity', $imported->report()->security->cryptFilterMethod('NoCrypt'));
        $this->assertSame('DocOpen', $imported->report()->security->cryptFilterAuthEvent('NoCrypt'));
        $this->assertNull($imported->report()->security->cryptFilterKeyLengthBits('NoCrypt'));
        $this->assertTrue($imported->report()->security->usesDocOpenAuthEvent('NoCrypt'));
        $this->assertFalse($imported->report()->security->usesLegacyStandardFilters());
        $this->assertFalse($imported->report()->security->usesDefaultStandardCryptFilters());
        $this->assertFalse($imported->report()->security->usesDefaultRevision4CryptFilters());
        $this->assertFalse($imported->report()->security->usesDefaultRevision5CryptFilters());
        $this->assertTrue($imported->report()->security->usesCustomCryptFilterConfiguration());
        $this->assertFalse($imported->report()->security->usesIdentityStringFilter());
        $this->assertFalse($imported->report()->security->usesIdentityStreamFilter());
        $this->assertFalse($imported->report()->security->usesNoOpStringFilter());
        $this->assertTrue($imported->report()->security->usesNoOpStreamFilter());
        $this->assertFalse($imported->report()->security->usesNamedNoOpStringFilter());
        $this->assertTrue($imported->report()->security->usesNamedNoOpStreamFilter());
        $this->assertSame('AESV3', $imported->report()->security->algorithm());
        $this->assertSame('Mixed', $imported->report()->security->algorithmSummary());
        $this->assertSame('StdCF', $imported->report()->security->stringFilterName);
        $this->assertSame('NoCrypt', $imported->report()->security->streamFilterName);
        $this->assertTrue($imported->report()->security->hasMixedCryptFilters());
        $this->assertTrue($imported->report()->security->hasMixedCryptMethods());
        $this->assertFalse($imported->report()->security->usesIdentityFilters());
        $this->assertTrue($imported->report()->security->hasMixedStringAndStreamEncryption());

        $roundTripped = $imported->save();

        $this->assertStringContainsString('/Title (Hidden AESV3 Title)', $roundTripped);
        $this->assertStringContainsString('(Visible AESV3 stream text) Tj', $roundTripped);
    }

    public function testRevision4ExplicitCryptFilterEncryptionCanLeaveStringsUnencryptedViaNamedNoOpFilter(): void
    {
        $bytes = Pdf::new()
            ->metadata(title: 'Visible Title', author: 'PdfToolkit')
            ->addPage()
            ->text(new TextRun('Hidden stream text', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: '',
                ownerPassword: 'owner-secret',
                encryptionRevision: 4,
                encryptStrings: false,
                useExplicitCryptFilters: true,
            ));

        $this->assertStringContainsString('/StrF /NoCrypt', $bytes);
        $this->assertStringContainsString('/NoCrypt << /CFM /None /AuthEvent /DocOpen >>', $bytes);
        $this->assertStringContainsString('/Title (Visible Title)', $bytes);
        $this->assertStringNotContainsString('(Hidden stream text) Tj', $bytes);

        $imported = Pdf::loadString($bytes);
        $this->assertNotNull($imported->report()->security);
        $this->assertTrue($imported->report()->security->isPasswordProtected());
        $this->assertTrue($imported->report()->security->isEffectivelyEncrypted());
        $this->assertFalse($imported->report()->security->stringsEncrypted());
        $this->assertTrue($imported->report()->security->streamsEncrypted());
        $this->assertFalse($imported->report()->security->usesIdentityStringFilter());
        $this->assertFalse($imported->report()->security->usesIdentityStreamFilter());
        $this->assertTrue($imported->report()->security->usesNoOpStringFilter());
        $this->assertFalse($imported->report()->security->usesNoOpStreamFilter());
        $this->assertTrue($imported->report()->security->usesNoOpFilters());
        $this->assertTrue($imported->report()->security->usesNamedNoOpStringFilter());
        $this->assertFalse($imported->report()->security->usesNamedNoOpStreamFilter());
        $this->assertTrue($imported->report()->security->usesNamedNoOpFilters());
        $this->assertFalse($imported->report()->security->isFullyNoOpEncrypted());
        $this->assertSame('RC4', $imported->report()->security->algorithm());
        $this->assertSame('Mixed', $imported->report()->security->algorithmSummary());
        $this->assertSame('NoCrypt', $imported->report()->security->stringFilterName);
        $this->assertSame('StdCF', $imported->report()->security->streamFilterName);
        $this->assertTrue($imported->report()->security->hasMixedCryptFilters());
        $this->assertTrue($imported->report()->security->hasMixedCryptMethods());
        $this->assertFalse($imported->report()->security->usesIdentityFilters());
        $this->assertTrue($imported->report()->security->hasMixedStringAndStreamEncryption());

        $roundTripped = $imported->save();

        $this->assertStringContainsString('/Title (Visible Title)', $roundTripped);
        $this->assertStringContainsString('(Hidden stream text) Tj', $roundTripped);
    }

    public function testRevision5ExplicitCryptFilterEncryptionCanLeaveStringsUnencryptedViaNamedNoOpFilter(): void
    {
        if (!function_exists('openssl_encrypt')) {
            $this->markTestSkipped('OpenSSL extension is required for AESV3 writer encryption tests.');
        }

        $bytes = Pdf::new()
            ->metadata(title: 'Visible AESV3 Title', author: 'PdfToolkit')
            ->addPage()
            ->text(new TextRun('Hidden AESV3 stream text', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: '',
                ownerPassword: 'owner-secret',
                encryptionRevision: 5,
                encryptionMethod: 'AESV3',
                encryptStrings: false,
                useExplicitCryptFilters: true,
            ));

        $this->assertStringContainsString('/StrF /NoCrypt', $bytes);
        $this->assertStringContainsString('/NoCrypt << /CFM /None /AuthEvent /DocOpen >>', $bytes);
        $this->assertStringContainsString('/Title (Visible AESV3 Title)', $bytes);
        $this->assertStringNotContainsString('(Hidden AESV3 stream text) Tj', $bytes);

        $imported = Pdf::loadString($bytes);
        $this->assertNotNull($imported->report()->security);
        $this->assertTrue($imported->report()->security->isPasswordProtected());
        $this->assertTrue($imported->report()->security->isEffectivelyEncrypted());
        $this->assertFalse($imported->report()->security->stringsEncrypted());
        $this->assertTrue($imported->report()->security->streamsEncrypted());
        $this->assertFalse($imported->report()->security->usesIdentityStringFilter());
        $this->assertFalse($imported->report()->security->usesIdentityStreamFilter());
        $this->assertTrue($imported->report()->security->usesNoOpStringFilter());
        $this->assertFalse($imported->report()->security->usesNoOpStreamFilter());
        $this->assertTrue($imported->report()->security->usesNoOpFilters());
        $this->assertTrue($imported->report()->security->usesNamedNoOpStringFilter());
        $this->assertFalse($imported->report()->security->usesNamedNoOpStreamFilter());
        $this->assertTrue($imported->report()->security->usesNamedNoOpFilters());
        $this->assertFalse($imported->report()->security->isFullyNoOpEncrypted());
        $this->assertSame('AESV3', $imported->report()->security->algorithm());
        $this->assertSame('Mixed', $imported->report()->security->algorithmSummary());
        $this->assertSame('NoCrypt', $imported->report()->security->stringFilterName);
        $this->assertSame('StdCF', $imported->report()->security->streamFilterName);
        $this->assertTrue($imported->report()->security->hasMixedCryptFilters());
        $this->assertTrue($imported->report()->security->hasMixedCryptMethods());
        $this->assertFalse($imported->report()->security->usesIdentityFilters());
        $this->assertTrue($imported->report()->security->hasMixedStringAndStreamEncryption());

        $roundTripped = $imported->save();

        $this->assertStringContainsString('/Title (Visible AESV3 Title)', $roundTripped);
        $this->assertStringContainsString('(Hidden AESV3 stream text) Tj', $roundTripped);
    }

    public function testRevision4ExplicitCryptFilterEncryptionCanLeaveStringsAndStreamsUnencryptedViaNamedNoOpFilters(): void
    {
        $bytes = Pdf::new()
            ->metadata(title: 'Visible Title', author: 'PdfToolkit')
            ->addPage()
            ->text(new TextRun('Visible stream text', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: '',
                ownerPassword: 'owner-secret',
                encryptionRevision: 4,
                encryptStrings: false,
                encryptStreams: false,
                useExplicitCryptFilters: true,
            ));

        $this->assertStringContainsString('/StrF /NoCrypt', $bytes);
        $this->assertStringContainsString('/StmF /NoCrypt', $bytes);
        $this->assertStringContainsString('/NoCrypt << /CFM /None /AuthEvent /DocOpen >>', $bytes);
        $this->assertStringContainsString('/Title (Visible Title)', $bytes);
        $this->assertStringContainsString('(Visible stream text) Tj', $bytes);

        $imported = Pdf::loadString($bytes);
        $this->assertNotNull($imported->report()->security);
        $this->assertTrue($imported->report()->security->isPasswordProtected());
        $this->assertFalse($imported->report()->security->isEffectivelyEncrypted());
        $this->assertFalse($imported->report()->security->stringsEncrypted());
        $this->assertFalse($imported->report()->security->streamsEncrypted());
        $this->assertSame(['NoCrypt', 'StdCF'], $imported->report()->security->cryptFilterNames);
        $this->assertSame(['StdCF' => 'RC4', 'NoCrypt' => 'Identity'], $imported->report()->security->cryptFilters);
        $this->assertSame(['StdCF' => 'DocOpen', 'NoCrypt' => 'DocOpen'], $imported->report()->security->cryptFilterAuthEvents);
        $this->assertSame(['StdCF' => 128], $imported->report()->security->cryptFilterKeyLengthBits);
        $this->assertTrue($imported->report()->security->usesCryptFilters());
        $this->assertTrue($imported->report()->security->usesCustomNamedCryptFilters());
        $this->assertTrue($imported->report()->security->definesCryptFilter('StdCF'));
        $this->assertSame('RC4', $imported->report()->security->cryptFilterMethod('StdCF'));
        $this->assertSame('DocOpen', $imported->report()->security->cryptFilterAuthEvent('StdCF'));
        $this->assertSame(128, $imported->report()->security->cryptFilterKeyLengthBits('StdCF'));
        $this->assertTrue($imported->report()->security->usesDocOpenAuthEvent('StdCF'));
        $this->assertTrue($imported->report()->security->definesCryptFilter('NoCrypt'));
        $this->assertSame('Identity', $imported->report()->security->cryptFilterMethod('NoCrypt'));
        $this->assertSame('DocOpen', $imported->report()->security->cryptFilterAuthEvent('NoCrypt'));
        $this->assertNull($imported->report()->security->cryptFilterKeyLengthBits('NoCrypt'));
        $this->assertTrue($imported->report()->security->usesDocOpenAuthEvent('NoCrypt'));
        $this->assertFalse($imported->report()->security->usesLegacyStandardFilters());
        $this->assertFalse($imported->report()->security->usesDefaultStandardCryptFilters());
        $this->assertFalse($imported->report()->security->usesDefaultRevision4CryptFilters());
        $this->assertFalse($imported->report()->security->usesDefaultRevision5CryptFilters());
        $this->assertTrue($imported->report()->security->usesCustomCryptFilterConfiguration());
        $this->assertFalse($imported->report()->security->usesIdentityStringFilter());
        $this->assertFalse($imported->report()->security->usesIdentityStreamFilter());
        $this->assertTrue($imported->report()->security->usesNoOpStringFilter());
        $this->assertTrue($imported->report()->security->usesNoOpStreamFilter());
        $this->assertTrue($imported->report()->security->usesNoOpFilters());
        $this->assertTrue($imported->report()->security->usesNamedNoOpStringFilter());
        $this->assertTrue($imported->report()->security->usesNamedNoOpStreamFilter());
        $this->assertTrue($imported->report()->security->usesNamedNoOpFilters());
        $this->assertTrue($imported->report()->security->isFullyNoOpEncrypted());
        $this->assertSame('Identity', $imported->report()->security->algorithm());
        $this->assertSame('Identity', $imported->report()->security->algorithmSummary());
        $this->assertSame('NoCrypt', $imported->report()->security->stringFilterName);
        $this->assertSame('NoCrypt', $imported->report()->security->streamFilterName);
        $this->assertFalse($imported->report()->security->hasMixedCryptFilters());
        $this->assertFalse($imported->report()->security->hasMixedCryptMethods());
        $this->assertFalse($imported->report()->security->usesIdentityFilters());
        $this->assertFalse($imported->report()->security->hasMixedStringAndStreamEncryption());

        $roundTripped = $imported->save();

        $this->assertStringContainsString('/Title (Visible Title)', $roundTripped);
        $this->assertStringContainsString('(Visible stream text) Tj', $roundTripped);
    }

    public function testRevision5ExplicitCryptFilterEncryptionCanLeaveStringsAndStreamsUnencryptedViaNamedNoOpFilters(): void
    {
        if (!function_exists('openssl_encrypt')) {
            $this->markTestSkipped('OpenSSL extension is required for AESV3 writer encryption tests.');
        }

        $bytes = Pdf::new()
            ->metadata(title: 'Visible AESV3 Title', author: 'PdfToolkit')
            ->addPage()
            ->text(new TextRun('Visible AESV3 stream text', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: '',
                ownerPassword: 'owner-secret',
                encryptionRevision: 5,
                encryptionMethod: 'AESV3',
                encryptStrings: false,
                encryptStreams: false,
                useExplicitCryptFilters: true,
            ));

        $this->assertStringContainsString('/StrF /NoCrypt', $bytes);
        $this->assertStringContainsString('/StmF /NoCrypt', $bytes);
        $this->assertStringContainsString('/NoCrypt << /CFM /None /AuthEvent /DocOpen >>', $bytes);
        $this->assertStringContainsString('/Title (Visible AESV3 Title)', $bytes);
        $this->assertStringContainsString('(Visible AESV3 stream text) Tj', $bytes);

        $imported = Pdf::loadString($bytes);
        $this->assertNotNull($imported->report()->security);
        $this->assertTrue($imported->report()->security->isPasswordProtected());
        $this->assertFalse($imported->report()->security->isEffectivelyEncrypted());
        $this->assertFalse($imported->report()->security->stringsEncrypted());
        $this->assertFalse($imported->report()->security->streamsEncrypted());
        $this->assertSame(['NoCrypt', 'StdCF'], $imported->report()->security->cryptFilterNames);
        $this->assertSame(['StdCF' => 'AESV3', 'NoCrypt' => 'Identity'], $imported->report()->security->cryptFilters);
        $this->assertSame(['StdCF' => 'DocOpen', 'NoCrypt' => 'DocOpen'], $imported->report()->security->cryptFilterAuthEvents);
        $this->assertSame(['StdCF' => 256], $imported->report()->security->cryptFilterKeyLengthBits);
        $this->assertTrue($imported->report()->security->usesCryptFilters());
        $this->assertTrue($imported->report()->security->usesCustomNamedCryptFilters());
        $this->assertTrue($imported->report()->security->definesCryptFilter('StdCF'));
        $this->assertSame('AESV3', $imported->report()->security->cryptFilterMethod('StdCF'));
        $this->assertSame('DocOpen', $imported->report()->security->cryptFilterAuthEvent('StdCF'));
        $this->assertSame(256, $imported->report()->security->cryptFilterKeyLengthBits('StdCF'));
        $this->assertTrue($imported->report()->security->usesDocOpenAuthEvent('StdCF'));
        $this->assertTrue($imported->report()->security->definesCryptFilter('NoCrypt'));
        $this->assertSame('Identity', $imported->report()->security->cryptFilterMethod('NoCrypt'));
        $this->assertSame('DocOpen', $imported->report()->security->cryptFilterAuthEvent('NoCrypt'));
        $this->assertNull($imported->report()->security->cryptFilterKeyLengthBits('NoCrypt'));
        $this->assertTrue($imported->report()->security->usesDocOpenAuthEvent('NoCrypt'));
        $this->assertFalse($imported->report()->security->usesLegacyStandardFilters());
        $this->assertFalse($imported->report()->security->usesDefaultStandardCryptFilters());
        $this->assertFalse($imported->report()->security->usesDefaultRevision4CryptFilters());
        $this->assertFalse($imported->report()->security->usesDefaultRevision5CryptFilters());
        $this->assertTrue($imported->report()->security->usesCustomCryptFilterConfiguration());
        $this->assertFalse($imported->report()->security->usesIdentityStringFilter());
        $this->assertFalse($imported->report()->security->usesIdentityStreamFilter());
        $this->assertTrue($imported->report()->security->usesNoOpStringFilter());
        $this->assertTrue($imported->report()->security->usesNoOpStreamFilter());
        $this->assertTrue($imported->report()->security->usesNoOpFilters());
        $this->assertTrue($imported->report()->security->usesNamedNoOpStringFilter());
        $this->assertTrue($imported->report()->security->usesNamedNoOpStreamFilter());
        $this->assertTrue($imported->report()->security->usesNamedNoOpFilters());
        $this->assertTrue($imported->report()->security->isFullyNoOpEncrypted());
        $this->assertSame('Identity', $imported->report()->security->algorithm());
        $this->assertSame('Identity', $imported->report()->security->algorithmSummary());
        $this->assertSame('NoCrypt', $imported->report()->security->stringFilterName);
        $this->assertSame('NoCrypt', $imported->report()->security->streamFilterName);
        $this->assertFalse($imported->report()->security->hasMixedCryptFilters());
        $this->assertFalse($imported->report()->security->hasMixedCryptMethods());
        $this->assertFalse($imported->report()->security->usesIdentityFilters());
        $this->assertFalse($imported->report()->security->hasMixedStringAndStreamEncryption());

        $roundTripped = $imported->save();

        $this->assertStringContainsString('/Title (Visible AESV3 Title)', $roundTripped);
        $this->assertStringContainsString('(Visible AESV3 stream text) Tj', $roundTripped);
    }

    public function testRevision4EncryptionCanLeaveCatalogMetadataStreamUnencrypted(): void
    {
        if (!function_exists('openssl_encrypt')) {
            $this->markTestSkipped('OpenSSL extension is required for metadata encryption tests.');
        }

        $bytes = Pdf::new()
            ->metadata(title: 'Visible Metadata', author: 'PdfToolkit')
            ->catalogMetadata()
            ->addPage()
            ->text(new TextRun('Hidden page text', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: '',
                ownerPassword: 'owner-secret',
                encryptionRevision: 4,
                encryptionMethod: 'AESV2',
                encryptMetadata: false,
            ));

        $this->assertStringContainsString('/EncryptMetadata false', $bytes);
        $this->assertStringContainsString('<dc:title>', $bytes);
        $this->assertStringContainsString('Visible Metadata', $bytes);
        $this->assertStringNotContainsString('(Hidden page text) Tj', $bytes);

        $imported = Pdf::loadString($bytes);
        $roundTripped = $imported->save();

        $this->assertStringContainsString('Visible Metadata', $roundTripped);
        $this->assertStringContainsString('(Hidden page text) Tj', $roundTripped);
    }

    public function testRevision4AesV2ExportCanUseExplicitCryptFiltersAndIdentityMetadataOverride(): void
    {
        if (!function_exists('openssl_encrypt')) {
            $this->markTestSkipped('OpenSSL extension is required for explicit crypt filter writer tests.');
        }

        $bytes = Pdf::new()
            ->metadata(title: 'Explicit Crypt Metadata', author: 'PdfToolkit')
            ->catalogMetadata()
            ->addPage()
            ->text(new TextRun('Explicit crypt page text', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: '',
                ownerPassword: 'owner-secret',
                encryptionRevision: 4,
                encryptionMethod: 'AESV2',
                encryptMetadata: false,
                useExplicitCryptFilters: true,
            ));

        $this->assertStringContainsString('/Filter /Crypt', $bytes);
        $this->assertStringContainsString('/DecodeParms << /Name /StdCF >>', $bytes);
        $this->assertStringContainsString('/NoCrypt << /CFM /None /AuthEvent /DocOpen >>', $bytes);
        $this->assertStringContainsString('/DecodeParms << /Name /NoCrypt >>', $bytes);
        $this->assertStringContainsString('Explicit Crypt Metadata', $bytes);
        $this->assertStringNotContainsString('(Explicit crypt page text) Tj', $bytes);

        $imported = Pdf::loadString($bytes);
        $roundTripped = $imported->save();

        $this->assertStringContainsString('Explicit Crypt Metadata', $roundTripped);
        $this->assertStringContainsString('(Explicit crypt page text) Tj', $roundTripped);
    }

    public function testRevision5AesV3ExportCanUseExplicitCryptFiltersAndIdentityMetadataOverride(): void
    {
        if (!function_exists('openssl_encrypt')) {
            $this->markTestSkipped('OpenSSL extension is required for explicit crypt filter writer tests.');
        }

        $bytes = Pdf::new()
            ->metadata(title: 'Explicit AESV3 Crypt Metadata', author: 'PdfToolkit')
            ->catalogMetadata()
            ->addPage()
            ->text(new TextRun('Explicit AESV3 crypt page text', 72, 720))
            ->endPage()
            ->build()
            ->save(options: new WriteOptions(
                userPassword: '',
                ownerPassword: 'owner-secret',
                encryptionRevision: 5,
                encryptionMethod: 'AESV3',
                encryptMetadata: false,
                useExplicitCryptFilters: true,
            ));

        $this->assertStringContainsString('/Filter /Crypt', $bytes);
        $this->assertStringContainsString('/DecodeParms << /Name /StdCF >>', $bytes);
        $this->assertStringContainsString('/NoCrypt << /CFM /None /AuthEvent /DocOpen >>', $bytes);
        $this->assertStringContainsString('/DecodeParms << /Name /NoCrypt >>', $bytes);
        $this->assertStringContainsString('Explicit AESV3 Crypt Metadata', $bytes);
        $this->assertStringNotContainsString('(Explicit AESV3 crypt page text) Tj', $bytes);

        $imported = Pdf::loadString($bytes);
        $roundTripped = $imported->save();

        $this->assertStringContainsString('Explicit AESV3 Crypt Metadata', $roundTripped);
        $this->assertStringContainsString('(Explicit AESV3 crypt page text) Tj', $roundTripped);
    }

    private function firstFlateDecodedStream(string $bytes): string
    {
        $this->assertMatchesRegularExpression('/<<[^>]*\/Filter \/FlateDecode[^>]*>>\s*stream\s*(.*?)\s*endstream/s', $bytes);
        preg_match('/<<[^>]*\/Filter \/FlateDecode[^>]*>>\s*stream\s*(.*?)\s*endstream/s', $bytes, $matches);

        $decoded = zlib_decode($matches[1]);

        $this->assertNotFalse($decoded);

        return $decoded;
    }

    private function buildPdf(array $objects): string
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

        $body .= "trailer\n<< /Size " . (max(array_keys($objects)) + 1) . " /Root 1 0 R >>\n";
        $body .= "startxref\n" . $xrefOffset . "\n%%EOF";

        return $body;
    }
}
