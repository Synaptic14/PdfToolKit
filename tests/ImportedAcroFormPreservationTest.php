<?php

declare(strict_types=1);

namespace PdfToolkit\Tests;

use PdfToolkit\Import\Importer;
use PHPUnit\Framework\TestCase;

final class ImportedAcroFormPreservationTest extends TestCase
{
    public function testImportedAcroFormIsPreservedOnSave(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /AcroForm 5 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Fields [6 0 R] /NeedAppearances true >>',
            6 => '<< /FT /Tx /T (existing_name) /V (Ada) /Rect [72 700 200 724] >>',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $pdf);
        $imported = (new Importer())->load($path);
        unlink($path);

        $bytes = $imported->save();

        $this->assertStringContainsString('/AcroForm', $bytes);
        $this->assertStringContainsString('/Fields [', $bytes);
        $this->assertStringContainsString('/T (existing_name)', $bytes);
        $this->assertStringContainsString('/V (Ada)', $bytes);
    }

    public function testImportedFieldNamesCanBeListed(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /AcroForm 5 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [7 0 R 8 0 R] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Fields [6 0 R 8 0 R] /NeedAppearances true >>',
            6 => '<< /T (billing) /Kids [7 0 R] >>',
            7 => '<< /Type /Annot /Subtype /Widget /Parent 6 0 R /FT /Tx /T (city) /V (London) /P 3 0 R /Rect [72 300 160 324] >>',
            8 => '<< /Type /Annot /Subtype /Widget /FT /Btn /T (accepted) /V /Off /AS /Off /P 3 0 R /Rect [72 250 92 270] >>',
        ]);

        $fieldNames = (new Importer())
            ->loadString($pdf)
            ->form()
            ->fieldNames();

        $this->assertSame(['accepted', 'billing.city'], $fieldNames);
    }

    public function testImportedFieldsCanBeListedWithGeometry(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /AcroForm 5 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [7 0 R 8 0 R] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Fields [6 0 R 8 0 R] /NeedAppearances true >>',
            6 => '<< /T (billing) /Kids [7 0 R] >>',
            7 => '<< /Type /Annot /Subtype /Widget /Parent 6 0 R /FT /Tx /T (city) /TU (Billing city) /V (London) /P 3 0 R /Rect [72 300 160 324] >>',
            8 => '<< /Type /Annot /Subtype /Widget /FT /Btn /T (accepted) /V /Off /AS /Off /P 3 0 R /Rect [72 250 92 270] >>',
        ]);

        $fields = (new Importer())
            ->loadString($pdf)
            ->form()
            ->fields();

        $this->assertCount(2, $fields);
        $this->assertSame('accepted', $fields[0]->name);
        $this->assertSame(1, $fields[0]->pageNumber);
        $this->assertSame([72.0, 250.0, 92.0, 270.0], $fields[0]->rect);
        $this->assertSame('Btn', $fields[0]->type);
        $this->assertNull($fields[0]->tooltip);
        $this->assertSame('billing.city', $fields[1]->name);
        $this->assertSame('Billing city', $fields[1]->tooltip);
        $this->assertSame('Tx', $fields[1]->type);
    }

    public function testImportedEncodedNameObjectFieldNamesCanBeListed(): void
    {
        $encodedName = '/#FE#FF#00f#001#00_#001#00#5B#000#00#5D';

        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /AcroForm 5 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [6 0 R] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Fields [6 0 R] /NeedAppearances true >>',
            6 => "<< /Type /Annot /Subtype /Widget /FT /Tx /T {$encodedName} /P 3 0 R /Rect [72 300 160 324] >>",
        ]);

        $fieldNames = (new Importer())
            ->loadString($pdf)
            ->form()
            ->fieldNames();

        $this->assertSame(['f1_1[0]'], $fieldNames);
    }

    public function testImportedUtf16LiteralStringFieldNamesCanBeListed(): void
    {
        $utf16Literal = '(\\376\\377\\000f\\0001\\000_\\0001\\000[\\0000\\000])';

        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /AcroForm 5 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [6 0 R] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Fields [6 0 R] /NeedAppearances true >>',
            6 => '<< /Type /Annot /Subtype /Widget /FT /Tx /T ' . $utf16Literal . ' /P 3 0 R /Rect [72 300 160 324] >>',
        ]);

        $fieldNames = (new Importer())
            ->loadString($pdf)
            ->form()
            ->fieldNames();

        $this->assertSame(['f1_1[0]'], $fieldNames);
    }

    public function testImportedTextFieldCanBeFilledByName(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /AcroForm 5 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Fields [6 0 R] /NeedAppearances true >>',
            6 => '<< /FT /Tx /T (existing_name) /V (Ada) /Rect [72 700 200 724] >>',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $pdf);
        $imported = (new Importer())->load($path);
        unlink($path);

        $bytes = $imported
            ->form()
            ->setText('existing_name', 'Grace Hopper')
            ->done()
            ->save();

        $this->assertStringContainsString('/T (existing_name)', $bytes);
        $this->assertStringContainsString('/V (Grace Hopper)', $bytes);
        $this->assertStringNotContainsString('/V (Ada)', $bytes);
    }

    public function testImportedCheckboxCanBeSetByName(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /AcroForm 5 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Fields [6 0 R] /NeedAppearances true >>',
            6 => '<< /FT /Btn /T (accepted) /V /Off /AS /Off /Rect [72 700 92 720] >>',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $pdf);
        $imported = (new Importer())->load($path);
        unlink($path);

        $bytes = $imported
            ->form()
            ->setCheckbox('accepted', true)
            ->done()
            ->save();

        $this->assertStringContainsString('/T (accepted)', $bytes);
        $this->assertStringContainsString('/V /Yes', $bytes);
        $this->assertStringContainsString('/AS /Yes', $bytes);
    }

    public function testImportedCheckboxSetterInfersExistingOnStateName(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /AcroForm 5 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [6 0 R] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Fields [6 0 R] /NeedAppearances true >>',
            6 => '<< /Type /Annot /Subtype /Widget /FT /Btn /T (accepted) /V /Off /AS /Off /AP << /N << /1 7 0 R /Off 8 0 R >> >> /P 3 0 R /Rect [72 700 92 720] >>',
            7 => "<< /Length 0 >>\nstream\n\nendstream",
            8 => "<< /Length 0 >>\nstream\n\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->form()
            ->setCheckbox('accepted', true)
            ->done()
            ->save();

        $this->assertStringContainsString('/T (accepted)', $bytes);
        $this->assertStringContainsString('/V /1', $bytes);
        $this->assertStringContainsString('/AS /1', $bytes);
        $this->assertStringNotContainsString('/V /Yes', $bytes);
    }

    public function testImportedCheckboxSetterInfersReferencedAppearanceOnStateName(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /AcroForm 5 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [6 0 R] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Fields [6 0 R] /NeedAppearances true >>',
            6 => '<< /Type /Annot /Subtype /Widget /FT /Btn /T (accepted) /V /Off /AS /Off /AP << /N 7 0 R >> /P 3 0 R /Rect [72 700 92 720] >>',
            7 => '<< /1 8 0 R /Off 9 0 R >>',
            8 => "<< /Length 0 >>\nstream\n\nendstream",
            9 => "<< /Length 0 >>\nstream\n\nendstream",
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->form()
            ->setCheckbox('accepted', true)
            ->done()
            ->save();

        $this->assertStringContainsString('/T (accepted)', $bytes);
        $this->assertStringContainsString('/V /1', $bytes);
        $this->assertStringContainsString('/AS /1', $bytes);
        $this->assertStringNotContainsString('/V /Yes', $bytes);
    }

    public function testImportedTextFieldCanBeFlattened(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /AcroForm 5 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [6 0 R] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Fields [6 0 R] /NeedAppearances true >>',
            6 => '<< /Type /Annot /Subtype /Widget /FT /Tx /T (existing_name) /V (Grace Hopper) /P 3 0 R /Rect [72 300 160 324] >>',
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->form()
            ->flatten()
            ->done()
            ->save();

        $this->assertStringNotContainsString('/AcroForm', $bytes);
        $this->assertStringNotContainsString('/Subtype /Widget', $bytes);
        $this->assertStringNotContainsString('/Annots [', $bytes);
        $this->assertStringContainsString('(Grace Hopper) Tj', $bytes);
    }

    public function testImportedCheckboxCanBeFlattened(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /AcroForm 5 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [6 0 R] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Fields [6 0 R] /NeedAppearances true >>',
            6 => '<< /Type /Annot /Subtype /Widget /FT /Btn /T (accepted) /V /Yes /AS /Yes /P 3 0 R /Rect [72 300 92 320] >>',
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->form()
            ->flatten()
            ->done()
            ->save();

        $this->assertStringNotContainsString('/AcroForm', $bytes);
        $this->assertStringNotContainsString('/Subtype /Widget', $bytes);
        $this->assertStringContainsString('72 80 20 20 re', $bytes);
        $this->assertStringContainsString('74 90 m', $bytes);
    }

    public function testFieldInspectionReturnsEmptyResultsWhenNoAcroFormIsPresent(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
        ]);

        $form = (new Importer())
            ->loadString($pdf)
            ->form();

        $this->assertSame([], $form->fieldNames());
        $this->assertSame([], $form->fields());
    }

    public function testFieldInspectionReturnsEmptyResultsAfterFlattenRemovesAcroForm(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /AcroForm 5 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [6 0 R] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Fields [6 0 R] /NeedAppearances true >>',
            6 => '<< /Type /Annot /Subtype /Widget /FT /Tx /T (existing_name) /V (Grace Hopper) /P 3 0 R /Rect [72 300 160 324] >>',
        ]);

        $flattened = (new Importer())
            ->loadString($pdf)
            ->form()
            ->flatten()
            ->done();

        $this->assertSame([], $flattened->form()->fieldNames());
        $this->assertSame([], $flattened->form()->fields());
    }

    public function testImportedHierarchicalTextFieldCanBeFilledByQualifiedName(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /AcroForm 5 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [7 0 R] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Fields [6 0 R] /NeedAppearances true >>',
            6 => '<< /T (billing) /Kids [7 0 R] >>',
            7 => '<< /Type /Annot /Subtype /Widget /Parent 6 0 R /FT /Tx /T (city) /V (London) /P 3 0 R /Rect [72 300 160 324] >>',
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->form()
            ->setText('billing.city', 'Paris')
            ->done()
            ->save();

        $this->assertStringContainsString('/T (city)', $bytes);
        $this->assertStringContainsString('/V (Paris)', $bytes);
        $this->assertStringNotContainsString('/V (London)', $bytes);
    }

    public function testImportedHierarchicalCheckboxCanBeSetByQualifiedName(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /AcroForm 5 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [7 0 R] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Fields [6 0 R] /NeedAppearances true >>',
            6 => '<< /T (terms) /Kids [7 0 R] >>',
            7 => '<< /Type /Annot /Subtype /Widget /Parent 6 0 R /FT /Btn /T (accepted) /V /Off /AS /Off /P 3 0 R /Rect [72 300 92 320] >>',
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->form()
            ->setCheckbox('terms.accepted', true)
            ->done()
            ->save();

        $this->assertStringContainsString('/AS /Yes', $bytes);
        $this->assertStringContainsString('/V /Yes', $bytes);
    }

    public function testImportedHierarchicalTextFieldCanBeFlattenedUsingInheritedValue(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /AcroForm 5 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [7 0 R] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Fields [6 0 R] /NeedAppearances true >>',
            6 => '<< /FT /Tx /T (customer) /V (Ada Lovelace) /Kids [7 0 R] >>',
            7 => '<< /Type /Annot /Subtype /Widget /Parent 6 0 R /T (name) /P 3 0 R /Rect [72 300 160 324] >>',
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->form()
            ->flatten()
            ->done()
            ->save();

        $this->assertStringNotContainsString('/AcroForm', $bytes);
        $this->assertStringContainsString('(Ada Lovelace) Tj', $bytes);
    }

    public function testImportedWidgetsCanBeReconnectedToPages(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /AcroForm 5 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Fields [6 0 R] /NeedAppearances true >>',
            6 => '<< /Type /Annot /Subtype /Widget /FT /Tx /T (existing_name) /V (Ada) /P 3 0 R /Rect [72 300 160 324] >>',
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->form()
            ->reconnectWidgetsToPages()
            ->done()
            ->save();

        $this->assertStringContainsString('/Annots [', $bytes);
        $this->assertStringContainsString('/Subtype /Widget', $bytes);
        $this->assertStringContainsString('/P ', $bytes);
    }

    public function testImportedTextWidgetAppearancesCanBeRegenerated(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /AcroForm 5 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [6 0 R] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Fields [6 0 R] /NeedAppearances true >>',
            6 => '<< /Type /Annot /Subtype /Widget /FT /Tx /T (existing_name) /V (Ada) /P 3 0 R /Rect [72 300 160 324] >>',
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->form()
            ->setText('existing_name', 'Grace Hopper')
            ->regenerateAppearances()
            ->done()
            ->save();

        $this->assertStringContainsString('/AP << /N ', $bytes);
        $this->assertStringContainsString('/Subtype /Form', $bytes);
        $this->assertStringContainsString('(Grace Hopper) Tj', $bytes);
        $this->assertStringContainsString('/NeedAppearances false', $bytes);
    }

    public function testImportedCheckboxAppearancesCanBeRegenerated(): void
    {
        $pdf = $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R /AcroForm 5 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 200 400] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 4 0 R /Annots [6 0 R] >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
            5 => '<< /Fields [6 0 R] /NeedAppearances true >>',
            6 => '<< /Type /Annot /Subtype /Widget /FT /Btn /T (accepted) /V /Off /AS /Off /P 3 0 R /Rect [72 300 92 320] >>',
        ]);

        $bytes = (new Importer())
            ->loadString($pdf)
            ->form()
            ->setCheckbox('accepted', true)
            ->regenerateAppearances()
            ->done()
            ->save();

        $this->assertStringContainsString('/AP << /N ', $bytes);
        $this->assertStringContainsString('/Subtype /Form', $bytes);
        $this->assertStringContainsString('0 0 20 20 re', $bytes);
        $this->assertStringContainsString('/NeedAppearances false', $bytes);
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
