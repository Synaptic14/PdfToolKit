<?php

declare(strict_types=1);

namespace PdfToolkit\Import;

use PdfToolkit\Core\Document;
use PdfToolkit\Core\ImportedPageSource;
use PdfToolkit\Core\PdfException;
use PdfToolkit\Graphics\Color;
use PdfToolkit\Graphics\Line;
use PdfToolkit\Graphics\Rectangle;
use PdfToolkit\Parser\PdfReference;
use PdfToolkit\Parser\PdfValueSerializer;
use PdfToolkit\Text\TextRun;

final class ImportedAcroFormEditor
{
    private ?PdfValueSerializer $serializer = null;

    public function __construct(
        private Document $document,
        private ImportedDocument $importedDocument,
    ) {
    }

    public function setText(string $name, string $value): self
    {
        $this->updateFieldObject(
            $name,
            static fn (string $object): string => self::replaceOrAppendValue($object, 'V', self::literalString($value))
        );

        return $this;
    }

    public function setCheckbox(string $name, bool $checked, ?string $onName = null): self
    {
        $source = $this->document->importedAcroFormSource();

        if ($source === null) {
            throw new PdfException('The imported document does not contain a preserved AcroForm.');
        }

        $objects = $source->dependentObjects;
        $updated = false;

        foreach ($objects as $objectNumber => $serializedObject) {
            if ($this->qualifiedFieldName($objectNumber, $objects) !== $name) {
                continue;
            }

            $state = $checked
                ? ($onName ?? $this->inferCheckboxOnStateName($objectNumber, $serializedObject, $objects) ?? 'Yes')
                : 'Off';

            $updatedObject = self::replaceOrAppendValue($serializedObject, 'V', '/' . self::name($state));
            $updatedObject = self::replaceOrAppendValue($updatedObject, 'AS', '/' . self::name($state));
            $objects[$objectNumber] = $updatedObject;
            $this->syncUpdatedFieldObjectToImportedPages($objectNumber, $updatedObject);
            $updated = true;
        }

        if (!$updated) {
            throw new PdfException(sprintf('Imported AcroForm field "%s" was not found.', $name));
        }

        $this->document->setImportedAcroFormSource($source->withDependentObjects($objects));

        return $this;
    }

    public function flatten(): self
    {
        $source = $this->document->importedAcroFormSource();

        if ($source === null) {
            throw new PdfException('The imported document does not contain a preserved AcroForm.');
        }

        $fieldsByPage = [];
        $allFieldObjectNumbers = [];

        foreach ($source->dependentObjects as $objectNumber => $serializedObject) {
            $field = $this->parseFlattenableField($objectNumber, $serializedObject);

            if ($field === null) {
                continue;
            }

            $allFieldObjectNumbers[] = $field['objectNumber'];

            $pageIndex = $this->locateFieldPageIndex($objectNumber, $serializedObject);

            if ($pageIndex === null) {
                continue;
            }

            $fieldsByPage[$pageIndex][] = $field;
        }

        foreach ($fieldsByPage as $pageIndex => $fields) {
            $page = $this->document->page($pageIndex);

            foreach ($fields as $field) {
                if ($field['type'] === 'text' && $field['value'] !== '') {
                    $page->addText(new TextRun(
                        $field['value'],
                        $field['rect'][0] + 2.0,
                        $field['rect'][1] + max(10.0, ($field['rect'][3] - $field['rect'][1]) / 2.0),
                        12.0
                    ));
                }

                if ($field['type'] === 'checkbox') {
                    $page->addRectangle(new Rectangle(
                        $field['rect'][0],
                        $field['rect'][1],
                        $field['rect'][2] - $field['rect'][0],
                        $field['rect'][3] - $field['rect'][1],
                        strokeColor: Color::black()
                    ));

                    if ($field['checked']) {
                        $page->addLine(new Line(
                            $field['rect'][0] + 2.0,
                            $field['rect'][1] + (($field['rect'][3] - $field['rect'][1]) / 2.0),
                            $field['rect'][0] + (($field['rect'][2] - $field['rect'][0]) / 2.0),
                            $field['rect'][1] + 2.0,
                            1.0,
                            Color::black()
                        ));
                        $page->addLine(new Line(
                            $field['rect'][0] + (($field['rect'][2] - $field['rect'][0]) / 2.0),
                            $field['rect'][1] + 2.0,
                            $field['rect'][2] - 2.0,
                            $field['rect'][3] - 2.0,
                            1.0,
                            Color::black()
                        ));
                    }
                }
            }

            $this->removeWidgetAnnotationsFromPage($pageIndex, array_column($fields, 'objectNumber'));
        }

        foreach (array_keys($this->document->pages()) as $pageIndex) {
            $this->removeWidgetAnnotationsFromPage($pageIndex, $allFieldObjectNumbers);
        }

        $this->document->setImportedAcroFormSource(null);
        $this->document->setImportedStructTreeSource(null);

        return $this;
    }

    public function reconnectWidgetsToPages(): self
    {
        $source = $this->document->importedAcroFormSource();

        if ($source === null) {
            throw new PdfException('The imported document does not contain a preserved AcroForm.');
        }

        $acroFormObjects = $source->dependentObjects;

        foreach ($acroFormObjects as $objectNumber => $serializedObject) {
            if (!$this->isWidgetAnnotation($serializedObject)) {
                continue;
            }

            $pageIndex = $this->locateFieldPageIndex($objectNumber, $serializedObject);

            if ($pageIndex === null) {
                continue;
            }

            $page = $this->document->page($pageIndex);
            $pageSource = $page->importedSource();

            if ($pageSource === null) {
                continue;
            }

            $widgetObject = self::replaceOrAppendValue(
                $serializedObject,
                'P',
                sprintf('%d 0 R', $pageSource->objectNumber)
            );
            $acroFormObjects[$objectNumber] = $widgetObject;

            $pageDictionary = $pageSource->pageDictionary;
            $annotations = $pageDictionary['Annots'] ?? [];

            if (!is_array($annotations)) {
                $annotations = [];
            }

            if (!$this->annotationArrayContainsObjectNumber($annotations, $objectNumber)) {
                $annotations[] = new PdfReference($objectNumber, 0);
            }

            $pageDictionary['Annots'] = $annotations;
            $dependentObjects = $this->mergeDependentObjectGraphs(
                $pageSource->dependentObjects,
                $this->collectDependentObjectGraph($acroFormObjects, [$objectNumber])
            );

            $page->setImportedSource(new ImportedPageSource(
                objectNumber: $pageSource->objectNumber,
                pageDictionary: $pageDictionary,
                resourceDictionary: $pageSource->resourceDictionary,
                contentStreams: $pageSource->contentStreams,
                dependentObjects: $dependentObjects,
                warnings: $pageSource->warnings,
            ));
        }

        $this->document->setImportedAcroFormSource($source->withDependentObjects($acroFormObjects));

        return $this;
    }

    public function regenerateAppearances(): self
    {
        $source = $this->document->importedAcroFormSource();

        if ($source === null) {
            throw new PdfException('The imported document does not contain a preserved AcroForm.');
        }

        $objects = $source->dependentObjects;
        $nextObjectNumber = $this->nextAvailableObjectNumber();
        $fontObjectNumber = null;

        foreach (array_keys($objects) as $objectNumber) {
            $serializedObject = $objects[$objectNumber];

            if (!$this->isWidgetAnnotation($serializedObject)) {
                continue;
            }

            $field = $this->parseFlattenableField($objectNumber, $serializedObject);

            if ($field === null) {
                continue;
            }

            if ($field['type'] === 'text') {
                $fontObjectNumber ??= $nextObjectNumber++;
                $objects[$fontObjectNumber] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
                $appearanceObjectNumber = $nextObjectNumber++;
                $objects[$appearanceObjectNumber] = $this->buildTextWidgetAppearanceObject(
                    $field['rect'],
                    $field['value'],
                    $fontObjectNumber
                );
            } else {
                $appearanceObjectNumber = $nextObjectNumber++;
                $objects[$appearanceObjectNumber] = $this->buildCheckboxWidgetAppearanceObject(
                    $field['rect'],
                    $field['checked']
                );
            }

            $updatedWidget = self::replaceOrAppendValue(
                $serializedObject,
                'AP',
                sprintf('<< /N %d 0 R >>', $appearanceObjectNumber)
            );

            $objects[$objectNumber] = $updatedWidget;
            $this->syncUpdatedFieldObjectToImportedPages($objectNumber, $updatedWidget);
            $this->syncObjectGraphToImportedPages([$appearanceObjectNumber => $objects[$appearanceObjectNumber]]);

            if ($fontObjectNumber !== null) {
                $this->syncObjectGraphToImportedPages([$fontObjectNumber => $objects[$fontObjectNumber]]);
            }
        }

        $objects[$source->objectNumber] = self::replaceOrAppendValue($objects[$source->objectNumber], 'NeedAppearances', 'false');
        $this->document->setImportedAcroFormSource($source->withDependentObjects($objects));

        return $this;
    }

    /**
     * @return list<string>
     */
    public function fieldNames(): array
    {
        $source = $this->document->importedAcroFormSource();

        if ($source === null) {
            return [];
        }

        $names = [];

        foreach (array_keys($source->dependentObjects) as $objectNumber) {
            if ($this->resolvedNameValue($objectNumber, $source->dependentObjects, 'FT') === null) {
                continue;
            }

            $name = $this->qualifiedFieldName($objectNumber, $source->dependentObjects);

            if ($name === null || $name === '') {
                continue;
            }

            $names[$name] = true;
        }

        $fieldNames = array_keys($names);
        sort($fieldNames);

        return $fieldNames;
    }

    /**
     * @return list<ImportedFormField>
     */
    public function fields(): array
    {
        $source = $this->document->importedAcroFormSource();

        if ($source === null) {
            return [];
        }

        $fields = [];

        foreach ($source->dependentObjects as $objectNumber => $serializedObject) {
            $fieldType = $this->resolvedNameValue($objectNumber, $source->dependentObjects, 'FT');

            if ($fieldType === null) {
                continue;
            }

            $name = $this->qualifiedFieldName($objectNumber, $source->dependentObjects);
            $rect = $this->matchRect($serializedObject);
            $pageIndex = $rect === null ? null : $this->locateFieldPageIndex($objectNumber, $serializedObject);

            if ($name === null || $name === '' || $rect === null || $pageIndex === null) {
                continue;
            }

            $fields[] = new ImportedFormField(
                name: $name,
                pageNumber: $pageIndex + 1,
                rect: $rect,
                type: $fieldType,
                tooltip: $this->resolvedLiteralStringValue($objectNumber, $source->dependentObjects, 'TU'),
                objectNumber: $objectNumber,
            );
        }

        usort(
            $fields,
            static function (ImportedFormField $left, ImportedFormField $right): int {
                if ($left->pageNumber !== $right->pageNumber) {
                    return $left->pageNumber <=> $right->pageNumber;
                }

                return $left->name <=> $right->name;
            }
        );

        return $fields;
    }

    public function done(): ImportedDocument
    {
        return $this->importedDocument;
    }

    /**
     * @param callable(string): string $callback
     */
    private function updateFieldObject(string $name, callable $callback): void
    {
        $source = $this->document->importedAcroFormSource();

        if ($source === null) {
            throw new PdfException('The imported document does not contain a preserved AcroForm.');
        }

        $objects = $source->dependentObjects;
        $updated = false;

        foreach ($objects as $objectNumber => $serializedObject) {
            if ($this->qualifiedFieldName($objectNumber, $objects) !== $name) {
                continue;
            }

            $updatedObject = $callback($serializedObject);
            $objects[$objectNumber] = $updatedObject;
            $this->syncUpdatedFieldObjectToImportedPages($objectNumber, $updatedObject);
            $updated = true;
        }

        if (!$updated) {
            throw new PdfException(sprintf('Imported AcroForm field "%s" was not found.', $name));
        }

        $this->document->setImportedAcroFormSource($source->withDependentObjects($objects));
    }

    private function syncUpdatedFieldObjectToImportedPages(int $objectNumber, string $serializedObject): void
    {
        foreach ($this->document->pages() as $page) {
            $source = $page->importedSource();

            if ($source === null || !isset($source->dependentObjects[$objectNumber])) {
                continue;
            }

            $dependentObjects = $source->dependentObjects;
            $dependentObjects[$objectNumber] = $serializedObject;

            $page->setImportedSource(new ImportedPageSource(
                objectNumber: $source->objectNumber,
                pageDictionary: $source->pageDictionary,
                resourceDictionary: $source->resourceDictionary,
                contentStreams: $source->contentStreams,
                dependentObjects: $dependentObjects,
                warnings: $source->warnings,
            ));
        }
    }

    /**
     * @param array<int, string> $objects
     */
    private function inferCheckboxOnStateName(int $objectNumber, string $serializedObject, array $objects): ?string
    {
        $appearanceDictionary = $this->matchSerializedValue($serializedObject, 'AP');

        if ($appearanceDictionary !== null) {
            $normalAppearance = $this->matchSerializedValue($appearanceDictionary, 'N');

            if ($normalAppearance !== null) {
                $normalAppearanceObjectNumber = self::matchSerializedReferenceObjectNumber($normalAppearance);

                if (
                    $normalAppearanceObjectNumber !== null
                    && isset($objects[$normalAppearanceObjectNumber])
                ) {
                    $normalAppearance = $objects[$normalAppearanceObjectNumber];
                }

                foreach (self::topLevelDictionaryNames($normalAppearance) as $name) {
                    if ($name !== 'Off') {
                        return $name;
                    }
                }
            }
        }

        $state = $this->matchNameValue($serializedObject, 'AS')
            ?? $this->resolvedNameValue($objectNumber, $objects, 'V');

        if ($state !== null && $state !== 'Off') {
            return $state;
        }

        return null;
    }

    /**
     * @param array<int, string> $objects
     */
    private function syncObjectGraphToImportedPages(array $objects): void
    {
        foreach ($this->document->pages() as $page) {
            $source = $page->importedSource();

            if ($source === null) {
                continue;
            }

            $dependentObjects = $source->dependentObjects;

            foreach ($objects as $objectNumber => $serializedObject) {
                $dependentObjects[$objectNumber] = $serializedObject;
            }

            $page->setImportedSource(new ImportedPageSource(
                objectNumber: $source->objectNumber,
                pageDictionary: $source->pageDictionary,
                resourceDictionary: $source->resourceDictionary,
                contentStreams: $source->contentStreams,
                dependentObjects: $dependentObjects,
                warnings: $source->warnings,
            ));
        }
    }

    private function fieldName(string $object): ?string
    {
        if (preg_match('/\/T\s*\(/', $object, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $offset = $matches[0][1] + strlen($matches[0][0]) - 1;

            $value = $this->readLiteralString($object, $offset);

            return $value === null ? null : $this->decodePossiblyUtf16String($value);
        }

        if (preg_match('/\/T\s*\//', $object, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $offset = $matches[0][1] + strlen($matches[0][0]) - 1;

        return $this->readNameString($object, $offset);
    }

    /**
     * @param array<int, string> $objects
     * @param array<int, bool> $visited
     */
    private function qualifiedFieldName(int $objectNumber, array $objects, array &$visited = []): ?string
    {
        if (isset($visited[$objectNumber]) || !isset($objects[$objectNumber])) {
            return null;
        }

        $visited[$objectNumber] = true;
        $name = $this->fieldName($objects[$objectNumber]);
        $parentObjectNumber = $this->matchReferenceObjectNumber($objects[$objectNumber], 'Parent');

        if ($parentObjectNumber === null) {
            return $name;
        }

        $parentName = $this->qualifiedFieldName($parentObjectNumber, $objects, $visited);

        if ($parentName === null || $parentName === '') {
            return $name;
        }

        if ($name === null || $name === '') {
            return $parentName;
        }

        return $parentName . '.' . $name;
    }

    private function readLiteralString(string $source, int $offset): ?string
    {
        if (($source[$offset] ?? null) !== '(') {
            return null;
        }

        $offset++;
        $depth = 1;
        $value = '';
        $length = strlen($source);

        while ($offset < $length) {
            $char = $source[$offset++];

            if ($char === '\\') {
                if ($offset >= $length) {
                    break;
                }

                $escaped = $source[$offset++];

                if ($escaped >= '0' && $escaped <= '7') {
                    $octal = $escaped;

                    for ($i = 0; $i < 2 && $offset < $length; $i++) {
                        $next = $source[$offset];

                        if ($next < '0' || $next > '7') {
                            break;
                        }

                        $octal .= $next;
                        $offset++;
                    }

                    $value .= chr(octdec($octal));
                    continue;
                }

                if ($escaped === "\n" || $escaped === "\r") {
                    if ($escaped === "\r" && $offset < $length && $source[$offset] === "\n") {
                        $offset++;
                    }

                    continue;
                }

                $value .= match ($escaped) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'b' => "\x08",
                    'f' => "\x0C",
                    '(', ')', '\\' => $escaped,
                    default => $escaped,
                };
                continue;
            }

            if ($char === '(') {
                $depth++;
                $value .= $char;
                continue;
            }

            if ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    return $value;
                }

                $value .= $char;
                continue;
            }

            $value .= $char;
        }

        return null;
    }

    private function readNameString(string $source, int $offset): ?string
    {
        if (($source[$offset] ?? null) !== '/') {
            return null;
        }

        $offset++;
        $length = strlen($source);
        $token = '';

        while ($offset < $length) {
            $char = $source[$offset];

            if (
                ctype_space($char)
                || str_contains('<>[](){}/%', $char)
            ) {
                break;
            }

            if ($char === '#' && $offset + 2 < $length && ctype_xdigit($source[$offset + 1]) && ctype_xdigit($source[$offset + 2])) {
                $token .= chr(hexdec(substr($source, $offset + 1, 2)));
                $offset += 3;
                continue;
            }

            $token .= $char;
            $offset++;
        }

        if ($token === '') {
            return null;
        }

        return $this->decodePossiblyUtf16String($token);
    }

    private function decodePossiblyUtf16String(string $value): string
    {
        if (str_starts_with($value, "\xFE\xFF")) {
            $decoded = $this->decodeUtf16Be(substr($value, 2));

            if ($decoded !== null) {
                return $decoded;
            }
        }

        if (strlen($value) >= 2 && (strlen($value) % 2) === 0) {
            $oddBytesAreZero = true;

            for ($i = 1; $i < strlen($value); $i += 2) {
                if ($value[$i] !== "\0") {
                    $oddBytesAreZero = false;
                    break;
                }
            }

            if ($oddBytesAreZero) {
                $decoded = $this->decodeUtf16Le($value);

                if ($decoded !== null) {
                    return $decoded;
                }
            }

            $evenBytesAreZero = true;

            for ($i = 0; $i < strlen($value); $i += 2) {
                if ($value[$i] !== "\0") {
                    $evenBytesAreZero = false;
                    break;
                }
            }

            if ($evenBytesAreZero) {
                $decoded = $this->decodeUtf16Be($value);

                if ($decoded !== null) {
                    return $decoded;
                }
            }
        }

        return $value;
    }

    private function decodeUtf16Be(string $value): ?string
    {
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($value, 'UTF-8', 'UTF-16BE');
        }

        if (function_exists('iconv')) {
            $decoded = iconv('UTF-16BE', 'UTF-8//IGNORE', $value);

            return $decoded === false ? null : $decoded;
        }

        return null;
    }

    private function decodeUtf16Le(string $value): ?string
    {
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($value, 'UTF-8', 'UTF-16LE');
        }

        if (function_exists('iconv')) {
            $decoded = iconv('UTF-16LE', 'UTF-8//IGNORE', $value);

            return $decoded === false ? null : $decoded;
        }

        return null;
    }

    private static function replaceOrAppendValue(string $object, string $key, string $value): string
    {
        $replacement = sprintf('/%s %s', $key, $value);

        $range = self::topLevelDictionaryEntryRange($object, $key);

        if ($range !== null) {
            return substr($object, 0, $range['start'])
                . $replacement
                . substr($object, $range['end']);
        }

        return preg_replace('/\s*>>\s*$/', ' ' . $replacement . ' >>', $object, 1) ?? $object;
    }

    /**
     * @return array{start: int, end: int}|null
     */
    private static function topLevelDictionaryEntryRange(string $object, string $key): ?array
    {
        $offset = strpos($object, '<<');

        if ($offset === false) {
            return null;
        }

        $offset += 2;
        $length = strlen($object);

        while ($offset < $length) {
            self::skipSerializedWhitespaceAndComments($object, $offset);

            if ($offset >= $length) {
                break;
            }

            if (substr($object, $offset, 2) === '>>') {
                break;
            }

            if ($object[$offset] !== '/') {
                break;
            }

            $entryStart = $offset;
            $name = self::readSerializedName($object, $offset);

            if ($name === null) {
                break;
            }

            self::skipSerializedWhitespaceAndComments($object, $offset);
            $valueStart = $offset;
            self::advanceSerializedValue($object, $offset);

            if ($name === $key) {
                return ['start' => $entryStart, 'end' => $offset];
            }

            if ($offset === $valueStart) {
                break;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function topLevelDictionaryNames(string $object): array
    {
        $offset = strpos($object, '<<');

        if ($offset === false) {
            return [];
        }

        $offset += 2;
        $length = strlen($object);
        $names = [];

        while ($offset < $length) {
            self::skipSerializedWhitespaceAndComments($object, $offset);

            if ($offset >= $length || substr($object, $offset, 2) === '>>') {
                break;
            }

            if ($object[$offset] !== '/') {
                break;
            }

            $name = self::readSerializedName($object, $offset);

            if ($name === null) {
                break;
            }

            $names[] = $name;
            self::skipSerializedWhitespaceAndComments($object, $offset);
            self::advanceSerializedValue($object, $offset);
        }

        return $names;
    }

    private static function skipSerializedWhitespaceAndComments(string $source, int &$offset): void
    {
        $length = strlen($source);

        while ($offset < $length) {
            $char = $source[$offset];

            if (ctype_space($char)) {
                $offset++;
                continue;
            }

            if ($char === '%') {
                while ($offset < $length && !in_array($source[$offset], ["\r", "\n"], true)) {
                    $offset++;
                }

                continue;
            }

            break;
        }
    }

    private static function readSerializedName(string $source, int &$offset): ?string
    {
        if (($source[$offset] ?? null) !== '/') {
            return null;
        }

        $offset++;
        $length = strlen($source);
        $token = '';

        while ($offset < $length) {
            $char = $source[$offset];

            if (ctype_space($char) || str_contains('<>[](){}/%', $char)) {
                break;
            }

            if (
                $char === '#'
                && $offset + 2 < $length
                && ctype_xdigit($source[$offset + 1])
                && ctype_xdigit($source[$offset + 2])
            ) {
                $token .= chr(hexdec(substr($source, $offset + 1, 2)));
                $offset += 3;
                continue;
            }

            $token .= $char;
            $offset++;
        }

        return $token === '' ? null : $token;
    }

    private static function advanceSerializedValue(string $source, int &$offset): void
    {
        self::skipSerializedWhitespaceAndComments($source, $offset);

        if ($offset >= strlen($source)) {
            return;
        }

        if (substr($source, $offset, 2) === '<<') {
            self::advanceSerializedDictionary($source, $offset);

            return;
        }

        if ($source[$offset] === '[') {
            self::advanceSerializedArray($source, $offset);

            return;
        }

        if ($source[$offset] === '(') {
            self::advanceSerializedLiteralString($source, $offset);

            return;
        }

        if ($source[$offset] === '<') {
            self::advanceSerializedHexString($source, $offset);

            return;
        }

        if ($source[$offset] === '/') {
            self::readSerializedName($source, $offset);

            return;
        }

        $length = strlen($source);
        $start = $offset;

        while ($offset < $length) {
            $char = $source[$offset];

            if (ctype_space($char) || str_contains('<>[](){}/%', $char)) {
                break;
            }

            $offset++;
        }

        $token = substr($source, $start, $offset - $start);
        self::skipSerializedWhitespaceAndComments($source, $offset);

        if (
            preg_match('/-?\d+(?:\.\d+)?$/', $token) === 1
            && preg_match('/\G(-?\d+)\s+R\b/A', $source, $matches, 0, $offset) === 1
        ) {
            $offset += strlen($matches[0]);
        }
    }

    private static function advanceSerializedDictionary(string $source, int &$offset): void
    {
        $offset += 2;

        while ($offset < strlen($source)) {
            self::skipSerializedWhitespaceAndComments($source, $offset);

            if (substr($source, $offset, 2) === '>>') {
                $offset += 2;

                return;
            }

            self::readSerializedName($source, $offset);
            self::advanceSerializedValue($source, $offset);
        }
    }

    private static function advanceSerializedArray(string $source, int &$offset): void
    {
        $offset++;

        while ($offset < strlen($source)) {
            self::skipSerializedWhitespaceAndComments($source, $offset);

            if (($source[$offset] ?? null) === ']') {
                $offset++;

                return;
            }

            self::advanceSerializedValue($source, $offset);
        }
    }

    private static function advanceSerializedLiteralString(string $source, int &$offset): void
    {
        $offset++;
        $depth = 1;
        $length = strlen($source);

        while ($offset < $length) {
            $char = $source[$offset++];

            if ($char === '\\') {
                if ($offset < $length) {
                    $offset++;
                }

                continue;
            }

            if ($char === '(') {
                $depth++;
                continue;
            }

            if ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    return;
                }
            }
        }
    }

    private static function advanceSerializedHexString(string $source, int &$offset): void
    {
        $offset++;
        $length = strlen($source);

        while ($offset < $length) {
            if ($source[$offset] === '>') {
                $offset++;

                return;
            }

            $offset++;
        }
    }

    private static function literalString(string $value): string
    {
        return '(' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value) . ')';
    }

    private static function name(string $value): string
    {
        return preg_replace_callback(
            '/[^A-Za-z0-9_.-]/',
            static fn (array $matches): string => sprintf('#%02X', ord($matches[0])),
            $value
        ) ?? $value;
    }

    /**
     * @return array{objectNumber: int, type: 'text'|'checkbox', rect: list<float>, value: string, checked: bool}|null
     */
    private function parseFlattenableField(int $objectNumber, string $serializedObject): ?array
    {
        $source = $this->document->importedAcroFormSource();
        $objects = $source?->dependentObjects ?? [];
        $fieldType = $this->resolvedNameValue($objectNumber, $objects, 'FT');
        $rect = $this->matchRect($serializedObject);

        if ($fieldType === null || $rect === null) {
            return null;
        }

        if ($fieldType === 'Tx') {
            return [
                'objectNumber' => $objectNumber,
                'type' => 'text',
                'rect' => $rect,
                'value' => $this->resolvedLiteralStringValue($objectNumber, $objects, 'V') ?? '',
                'checked' => false,
            ];
        }

        if ($fieldType === 'Btn') {
            $state = $this->matchNameValue($serializedObject, 'AS')
                ?? $this->resolvedNameValue($objectNumber, $objects, 'V')
                ?? 'Off';

            return [
                'objectNumber' => $objectNumber,
                'type' => 'checkbox',
                'rect' => $rect,
                'value' => '',
                'checked' => $state !== 'Off',
            ];
        }

        return null;
    }

    private function locateFieldPageIndex(int $objectNumber, string $serializedObject): ?int
    {
        $pageObjectNumber = $this->matchReferenceObjectNumber($serializedObject, 'P');

        foreach ($this->document->pages() as $pageIndex => $page) {
            $source = $page->importedSource();

            if ($source === null) {
                continue;
            }

            if ($pageObjectNumber !== null && $source->objectNumber === $pageObjectNumber) {
                return $pageIndex;
            }

            if ($this->pageHasAnnotationReference($source, $objectNumber)) {
                return $pageIndex;
            }
        }

        return null;
    }

    /**
     * @param list<int> $objectNumbers
     */
    private function removeWidgetAnnotationsFromPage(int $pageIndex, array $objectNumbers): void
    {
        $page = $this->document->page($pageIndex);
        $source = $page->importedSource();

        if ($source === null) {
            return;
        }

        $pageDictionary = $source->pageDictionary;
        $annotations = $pageDictionary['Annots'] ?? null;
        $retained = [];

        if (is_array($annotations)) {
            foreach ($annotations as $annotation) {
                $annotationObjectNumber = $annotation instanceof PdfReference ? $annotation->objectNumber : null;

                if ($annotationObjectNumber !== null && in_array($annotationObjectNumber, $objectNumbers, true)) {
                    continue;
                }

                $retained[] = $annotation;
            }
        }

        if (is_array($annotations) && $retained === []) {
            unset($pageDictionary['Annots']);
        } elseif (is_array($annotations)) {
            $pageDictionary['Annots'] = $retained;
        }

        $page->setImportedSource(new ImportedPageSource(
            objectNumber: $source->objectNumber,
            pageDictionary: $pageDictionary,
            resourceDictionary: $source->resourceDictionary,
            contentStreams: $source->contentStreams,
            dependentObjects: $this->removeDependentObjectGraph($source->dependentObjects, $objectNumbers),
            warnings: $source->warnings,
        ));
    }

    private function pageHasAnnotationReference(ImportedPageSource $source, int $objectNumber): bool
    {
        $annotations = $source->pageDictionary['Annots'] ?? null;

        if (!is_array($annotations)) {
            return false;
        }

        foreach ($annotations as $annotation) {
            if ($annotation instanceof PdfReference && $annotation->objectNumber === $objectNumber) {
                return true;
            }
        }

        return false;
    }

    private function annotationArrayContainsObjectNumber(array $annotations, int $objectNumber): bool
    {
        foreach ($annotations as $annotation) {
            if ($annotation instanceof PdfReference && $annotation->objectNumber === $objectNumber) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $dependentObjects
     * @param list<int> $rootObjectNumbers
     * @return array<int, string>
     */
    private function removeDependentObjectGraph(array $dependentObjects, array $rootObjectNumbers): array
    {
        $remove = [];
        $queue = $rootObjectNumbers;
        $queueIndex = 0;

        while (isset($queue[$queueIndex])) {
            $current = $queue[$queueIndex++];

            if (isset($remove[$current])) {
                continue;
            }

            $remove[$current] = true;

            foreach ($this->referencedObjectNumbersInSerializedValue($dependentObjects[$current] ?? '') as $referencedObjectNumber) {
                if (!isset($remove[$referencedObjectNumber])) {
                    $queue[] = $referencedObjectNumber;
                }
            }
        }

        foreach (array_keys($remove) as $objectNumber) {
            unset($dependentObjects[$objectNumber]);
        }

        return $dependentObjects;
    }

    /**
     * @return list<int>
     */
    private function referencedObjectNumbersInSerializedValue(string $value): array
    {
        if (preg_match_all('/\b(\d+)\s+\d+\s+R\b/', $value, $matches) !== 1) {
            return [];
        }

        return array_values(array_unique(array_map(static fn (string $objectNumber): int => (int) $objectNumber, $matches[1])));
    }

    /**
     * @param array<int, string> $dependentObjects
     * @param list<int> $rootObjectNumbers
     * @return array<int, string>
     */
    private function collectDependentObjectGraph(array $dependentObjects, array $rootObjectNumbers): array
    {
        $collected = [];
        $queue = $rootObjectNumbers;
        $queueIndex = 0;

        while (isset($queue[$queueIndex])) {
            $current = $queue[$queueIndex++];

            if (isset($collected[$current]) || !isset($dependentObjects[$current])) {
                continue;
            }

            $collected[$current] = $dependentObjects[$current];

            foreach ($this->referencedObjectNumbersInSerializedValue($dependentObjects[$current]) as $referencedObjectNumber) {
                if (!isset($collected[$referencedObjectNumber])) {
                    $queue[] = $referencedObjectNumber;
                }
            }
        }

        return $collected;
    }

    /**
     * @param array<int, string> $left
     * @param array<int, string> $right
     * @return array<int, string>
     */
    private function mergeDependentObjectGraphs(array $left, array $right): array
    {
        foreach ($right as $objectNumber => $serializedObject) {
            $left[$objectNumber] = $serializedObject;
        }

        return $left;
    }

    private function nextAvailableObjectNumber(): int
    {
        $max = 0;

        $acroFormSource = $this->document->importedAcroFormSource();

        if ($acroFormSource !== null) {
            $max = max($max, $acroFormSource->objectNumber);

            if ($acroFormSource->dependentObjects !== []) {
                $max = max($max, max(array_keys($acroFormSource->dependentObjects)));
            }
        }

        foreach ($this->document->pages() as $page) {
            $source = $page->importedSource();

            if ($source === null) {
                continue;
            }

            $max = max($max, $source->objectNumber);

            if ($source->dependentObjects !== []) {
                $max = max($max, max(array_keys($source->dependentObjects)));
            }
        }

        foreach ([
            $this->document->importedOutlineSource(),
            $this->document->importedNameTreeSource(),
            $this->document->importedCatalogMetadataSource(),
            $this->document->importedPageLabelsSource(),
            $this->document->importedViewerPreferencesSource(),
            $this->document->importedOutputIntentsSource(),
            $this->document->importedStructTreeSource(),
        ] as $source) {
            if ($source === null) {
                continue;
            }

            $max = max($max, $source->objectNumber);

            if ($source->dependentObjects !== []) {
                $max = max($max, max(array_keys($source->dependentObjects)));
            }
        }

        return $max + 1;
    }

    /**
     * @return list<float>|null
     */
    private function matchRect(string $serializedObject): ?array
    {
        if (preg_match('/\/Rect\s*\[\s*(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)\s*\]/', $serializedObject, $matches) !== 1) {
            return null;
        }

        return [(float) $matches[1], (float) $matches[2], (float) $matches[3], (float) $matches[4]];
    }

    private function matchLiteralStringValue(string $serializedObject, string $key): ?string
    {
        if (preg_match(sprintf('/\/%s\s*\(/', preg_quote($key, '/')), $serializedObject, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $offset = $matches[0][1] + strlen($matches[0][0]) - 1;

        return $this->readLiteralString($serializedObject, $offset);
    }

    private function matchSerializedValue(string $serializedObject, string $key): ?string
    {
        $range = self::topLevelDictionaryEntryRange($serializedObject, $key);

        if ($range === null) {
            return null;
        }

        $offset = $range['start'];
        self::readSerializedName($serializedObject, $offset);
        self::skipSerializedWhitespaceAndComments($serializedObject, $offset);
        $valueStart = $offset;
        self::advanceSerializedValue($serializedObject, $offset);

        return substr($serializedObject, $valueStart, $offset - $valueStart);
    }

    private static function matchSerializedReferenceObjectNumber(string $value): ?int
    {
        return preg_match('/^\s*(\d+)\s+\d+\s+R\s*$/', $value, $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    private function matchNameValue(string $serializedObject, string $key): ?string
    {
        if (preg_match(sprintf('/\/%s\s*\/([^\s<>\[\]\(\)\/%%]+)/', preg_quote($key, '/')), $serializedObject, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function matchReferenceObjectNumber(string $serializedObject, string $key): ?int
    {
        if (preg_match(sprintf('/\/%s\s*(\d+)\s+\d+\s+R\b/', preg_quote($key, '/')), $serializedObject, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function isWidgetAnnotation(string $serializedObject): bool
    {
        return str_contains($serializedObject, '/Subtype /Widget');
    }

    /**
     * @param list<float> $rect
     */
    private function buildTextWidgetAppearanceObject(array $rect, string $value, int $fontObjectNumber): string
    {
        $width = max(1.0, $rect[2] - $rect[0]);
        $height = max(1.0, $rect[3] - $rect[1]);
        $contents = implode("\n", [
            'q',
            '0 0 0 RG',
            '1 w',
            sprintf('0 0 %s %s re', $this->formatNumber($width), $this->formatNumber($height)),
            'S',
            'BT',
            '/F1 12 Tf',
            '0 0 0 rg',
            sprintf('1 0 0 1 2 %s Tm', $this->formatNumber(max(10.0, $height / 2.0))),
            '(' . $this->escapeLiteral($value) . ') Tj',
            'ET',
            'Q',
        ]);

        return $this->buildAppearanceStreamObject(
            width: $width,
            height: $height,
            contents: $contents,
            resources: [
                'Font' => [
                    'F1' => new PdfReference($fontObjectNumber, 0),
                ],
            ],
        );
    }

    /**
     * @param list<float> $rect
     */
    private function buildCheckboxWidgetAppearanceObject(array $rect, bool $checked): string
    {
        $width = max(1.0, $rect[2] - $rect[0]);
        $height = max(1.0, $rect[3] - $rect[1]);
        $lines = [
            'q',
            '0 0 0 RG',
            '1 w',
            sprintf('0 0 %s %s re', $this->formatNumber($width), $this->formatNumber($height)),
            'S',
        ];

        if ($checked) {
            $lines[] = sprintf(
                '%s %s m',
                $this->formatNumber(2.0),
                $this->formatNumber($height / 2.0)
            );
            $lines[] = sprintf(
                '%s %s l',
                $this->formatNumber($width / 2.0),
                $this->formatNumber(2.0)
            );
            $lines[] = 'S';
            $lines[] = sprintf(
                '%s %s m',
                $this->formatNumber($width / 2.0),
                $this->formatNumber(2.0)
            );
            $lines[] = sprintf(
                '%s %s l',
                $this->formatNumber($width - 2.0),
                $this->formatNumber($height - 2.0)
            );
            $lines[] = 'S';
        }

        $lines[] = 'Q';

        return $this->buildAppearanceStreamObject(
            width: $width,
            height: $height,
            contents: implode("\n", $lines),
        );
    }

    /**
     * @param array<string, mixed> $resources
     */
    private function buildAppearanceStreamObject(float $width, float $height, string $contents, array $resources = []): string
    {
        $dictionary = [
            'Type' => 'XObject',
            'Subtype' => 'Form',
            'BBox' => [0, 0, $width, $height],
            'Length' => strlen($contents),
        ];

        if ($resources !== []) {
            $dictionary['Resources'] = $resources;
        }

        return $this->serializePdfValue($dictionary) . "\nstream\n" . $contents . "\nendstream";
    }

    private function serializePdfValue(mixed $value): string
    {
        return $this->serializer()->serialize($value);
    }

    private function serializer(): PdfValueSerializer
    {
        return $this->serializer ??= new PdfValueSerializer();
    }

    private function formatNumber(float $value): string
    {
        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
    }

    private function escapeLiteral(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }

    /**
     * @param array<int, string> $objects
     * @param array<int, bool> $visited
     */
    private function resolvedNameValue(int $objectNumber, array $objects, string $key, array &$visited = []): ?string
    {
        if (isset($visited[$objectNumber]) || !isset($objects[$objectNumber])) {
            return null;
        }

        $visited[$objectNumber] = true;
        $value = $this->matchNameValue($objects[$objectNumber], $key);

        if ($value !== null) {
            return $value;
        }

        $parentObjectNumber = $this->matchReferenceObjectNumber($objects[$objectNumber], 'Parent');

        return $parentObjectNumber === null ? null : $this->resolvedNameValue($parentObjectNumber, $objects, $key, $visited);
    }

    /**
     * @param array<int, string> $objects
     * @param array<int, bool> $visited
     */
    private function resolvedLiteralStringValue(int $objectNumber, array $objects, string $key, array &$visited = []): ?string
    {
        if (isset($visited[$objectNumber]) || !isset($objects[$objectNumber])) {
            return null;
        }

        $visited[$objectNumber] = true;
        $value = $this->matchLiteralStringValue($objects[$objectNumber], $key);

        if ($value !== null) {
            return $this->decodePossiblyUtf16String($value);
        }

        $parentObjectNumber = $this->matchReferenceObjectNumber($objects[$objectNumber], 'Parent');

        return $parentObjectNumber === null ? null : $this->resolvedLiteralStringValue($parentObjectNumber, $objects, $key, $visited);
    }
}
