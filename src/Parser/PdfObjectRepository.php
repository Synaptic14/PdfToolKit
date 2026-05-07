<?php

declare(strict_types=1);

namespace PdfToolkit\Parser;

use PdfToolkit\Core\PdfException;

final class PdfObjectRepository
{
    private const DEFAULT_MAX_DECODED_STREAM_BYTES = 67108864;

    /**
     * @var array<int, int>
     */
    private array $offsets;

    /**
     * @var array<int, PdfIndirectObject>
     */
    private array $cache = [];

    /**
     * @var array<int, array{objectStreamNumber: int, index: int}>
     */
    private array $compressedObjects = [];

    /**
     * @var array<int, array<int, PdfIndirectObject>>
     */
    private array $objectStreamCache = [];

    private ?PdfValueSerializer $serializer = null;

    /**
     * @param array<int, int> $offsets
     * @param array<int, array{objectStreamNumber: int, index: int}> $compressedObjects
     */
    public function __construct(
        private readonly string $contents,
        array $offsets,
        array $compressedObjects = [],
        private readonly ?StandardSecurityHandler $securityHandler = null,
    ) {
        $this->offsets = $offsets;
        $this->compressedObjects = $compressedObjects;
    }

    public function resolve(PdfReference $reference): mixed
    {
        return $this->get($reference)->value;
    }

    public function get(PdfReference $reference): PdfIndirectObject
    {
        $objectNumber = $reference->objectNumber;

        if (isset($this->cache[$objectNumber])) {
            return $this->cache[$objectNumber];
        }

        $offset = $this->offsets[$objectNumber] ?? null;

        if ($offset !== null) {
            return $this->cache[$objectNumber] = $this->parseObjectAt($offset);
        }

        if (isset($this->compressedObjects[$objectNumber])) {
            return $this->cache[$objectNumber] = $this->parseCompressedObject(
                $objectNumber,
                $this->compressedObjects[$objectNumber]['objectStreamNumber'],
                $this->compressedObjects[$objectNumber]['index'],
            );
        }

        throw new PdfException(sprintf('Missing xref entry for object %d.', $objectNumber));
    }

    /**
     * @param array<int, true> $visited
     * @return array<int, string>
     */
    public function collectDependentObjects(mixed $value, array &$visited = []): array
    {
        $objects = [];

        if ($value instanceof PdfReference) {
            if (isset($visited[$value->objectNumber])) {
                return [];
            }

            $visited[$value->objectNumber] = true;
            $object = $this->get($value);
            $objects[$value->objectNumber] = $object->serializedValue;

            foreach ($this->extractChildValues($object->value) as $child) {
                foreach ($this->collectDependentObjects($child, $visited) as $objectNumber => $serializedValue) {
                    $objects[$objectNumber] = $serializedValue;
                }
            }

            return $objects;
        }

        foreach ($this->extractChildValues($value) as $child) {
            foreach ($this->collectDependentObjects($child, $visited) as $objectNumber => $serializedValue) {
                $objects[$objectNumber] = $serializedValue;
            }
        }

        return $objects;
    }

    private function parseObjectAt(int $offset): PdfIndirectObject
    {
        if (!preg_match('/\G\s*(\d+)\s+(\d+)\s+obj\b/As', $this->contents, $matches, 0, $offset)) {
            throw new PdfException(sprintf('Unable to parse indirect object at byte offset %d.', $offset));
        }

        $objectNumber = (int) $matches[1];
        $generationNumber = (int) $matches[2];
        $cursor = $offset + strlen($matches[0]);
        $bodyStart = $cursor;
        $parser = new PdfValueParser($this->contents);
        $value = $parser->parseValue($cursor);
        $parser->skipWhitespaceAndComments($cursor);

        if (is_array($value) && substr($this->contents, $cursor, 6) === 'stream') {
            $value = $this->parseStreamValue($objectNumber, $value, $cursor);
        }

        $parser->skipWhitespaceAndComments($cursor);

        if (!preg_match('/\Gendobj\b/As', $this->contents, $endMatches, 0, $cursor)) {
            throw new PdfException(sprintf('Indirect object %d is missing endobj.', $objectNumber));
        }

        if ($this->securityHandler !== null) {
            $value = $this->securityHandler->decryptObjectValue($value, $objectNumber, $generationNumber);
            $serializedValue = $this->serializer()->serialize($value);
        } else {
            $serializedValue = rtrim(substr($this->contents, $bodyStart, $cursor - $bodyStart));
        }

        return new PdfIndirectObject($objectNumber, $generationNumber, $value, $offset, $serializedValue);
    }

    private function parseCompressedObject(int $objectNumber, int $objectStreamNumber, int $index): PdfIndirectObject
    {
        $objects = $this->loadObjectStream($objectStreamNumber);

        if (!isset($objects[$index])) {
            throw new PdfException(sprintf(
                'Object stream %d does not contain index %d for object %d.',
                $objectStreamNumber,
                $index,
                $objectNumber
            ));
        }

        return $objects[$index];
    }

    /**
     * @param array<string, mixed> $dictionary
     */
    private function parseStreamValue(int $objectNumber, array $dictionary, int &$cursor): PdfStream
    {
        $cursor += 6;

        if (substr($this->contents, $cursor, 2) === "\r\n") {
            $cursor += 2;
        } elseif (($this->contents[$cursor] ?? '') === "\n" || ($this->contents[$cursor] ?? '') === "\r") {
            $cursor++;
        }

        $length = $dictionary['Length'] ?? null;

        if ($length instanceof PdfReference) {
            $length = $this->resolve($length);
        }

        if (!is_int($length) && !is_float($length)) {
            throw new PdfException('Stream dictionary is missing a concrete Length value.');
        }

        $length = (int) $length;
        $contents = substr($this->contents, $cursor, $length);

        if ($contents === false) {
            throw new PdfException('Unable to read stream contents.');
        }

        $expectedEnd = $cursor + $length;

        if (preg_match('/\G\s*endstream\b/As', $this->contents, $matches, 0, $expectedEnd)) {
            $cursor = $expectedEnd + strlen($matches[0]);

            return new PdfStream($dictionary, $contents);
        }

        $fallbackEnd = strpos($this->contents, 'endstream', $cursor);

        if ($fallbackEnd === false) {
            throw new PdfException('Stream is missing endstream marker.');
        }

        $contents = rtrim(substr($this->contents, $cursor, $fallbackEnd - $cursor), "\r\n");
        $cursor = $fallbackEnd + strlen('endstream');
        $dictionary['__warnings'] = [
            sprintf(
                'Stream length mismatch for object %d. Used endstream marker fallback.',
                $objectNumber
            ),
        ];

        return new PdfStream($dictionary, $contents);
    }

    /**
     * @return array<int, PdfIndirectObject>
     */
    private function loadObjectStream(int $objectStreamNumber): array
    {
        if (isset($this->objectStreamCache[$objectStreamNumber])) {
            return $this->objectStreamCache[$objectStreamNumber];
        }

        $objectStream = $this->get(new PdfReference($objectStreamNumber, 0));

        if (!$objectStream->value instanceof PdfStream) {
            throw new PdfException(sprintf('Object %d is not an object stream.', $objectStreamNumber));
        }

        $dictionary = $objectStream->value->dictionary;

        if (($dictionary['Type'] ?? null) !== 'ObjStm') {
            throw new PdfException(sprintf('Object %d is not an /ObjStm stream.', $objectStreamNumber));
        }

        $decoded = $this->decodeStream($objectStream->value);
        $objectCount = (int) ($dictionary['N'] ?? 0);
        $firstOffset = (int) ($dictionary['First'] ?? -1);

        if ($objectCount <= 0 || $firstOffset < 0) {
            throw new PdfException(sprintf('Object stream %d is missing N/First metadata.', $objectStreamNumber));
        }

        $header = substr($decoded, 0, $firstOffset);
        $body = substr($decoded, $firstOffset);

        if ($header === false || $body === false) {
            throw new PdfException(sprintf('Unable to split object stream %d.', $objectStreamNumber));
        }

        $numbers = preg_split('/\s+/', trim($header)) ?: [];

        if (count($numbers) < $objectCount * 2) {
            throw new PdfException(sprintf('Object stream %d header is incomplete.', $objectStreamNumber));
        }

        $objects = [];

        for ($i = 0; $i < $objectCount; $i++) {
            $compressedObjectNumber = (int) $numbers[$i * 2];
            $relativeOffset = (int) $numbers[$i * 2 + 1];
            $cursor = $relativeOffset;
            $parser = new PdfValueParser($body);
            $value = $parser->parseValue($cursor);

            $objects[$i] = new PdfIndirectObject(
                objectNumber: $compressedObjectNumber,
                generationNumber: 0,
                value: $value,
                offset: -1,
                serializedValue: $this->serializer()->serialize($value),
            );
        }

        return $this->objectStreamCache[$objectStreamNumber] = $objects;
    }

    public function decodeStream(PdfStream $stream): string
    {
        $filters = $stream->dictionary['Filter'] ?? null;

        if ($filters === null) {
            return $stream->contents;
        }

        $filterList = $filters;

        if (!is_array($filterList)) {
            $filterList = [$filterList];
        }

        $decoded = $stream->contents;

        $decodeParams = $stream->dictionary['DecodeParms'] ?? null;
        $decodeParamList = is_array($decodeParams) && array_is_list($decodeParams)
            ? $decodeParams
            : [$decodeParams];

        foreach ($filterList as $index => $filter) {
            $params = $decodeParamList[$index] ?? null;
            $decoded = match ($this->canonicalFilterName($filter)) {
                'FlateDecode' => $this->decodeFlate($decoded),
                'ASCIIHexDecode' => $this->decodeAsciiHex($decoded),
                'ASCII85Decode' => $this->decodeAscii85($decoded),
                'LZWDecode' => $this->decodeLzw($decoded, is_array($params) ? $params : []),
                'RunLengthDecode' => $this->decodeRunLength($decoded),
                default => throw new PdfException(sprintf('Unsupported stream filter: %s', (string) $filter)),
            };
            $this->assertDecodedStreamSize($decoded);

            if (is_array($params)) {
                $decoded = $this->applyPredictor($decoded, $params);
                $this->assertDecodedStreamSize($decoded);
            }
        }

        return $decoded;
    }

    private function serializer(): PdfValueSerializer
    {
        return $this->serializer ??= new PdfValueSerializer();
    }

    private function maxDecodedStreamBytes(): int
    {
        $configured = $this->envValue('PDFTOOLKIT_MAX_DECODED_STREAM_BYTES', 'PDFTOOLBOX_MAX_DECODED_STREAM_BYTES');

        if (!is_string($configured) || $configured === '') {
            return self::DEFAULT_MAX_DECODED_STREAM_BYTES;
        }

        $value = (int) $configured;

        return $value > 0 ? $value : self::DEFAULT_MAX_DECODED_STREAM_BYTES;
    }

    private function assertDecodedStreamSize(string $decoded): void
    {
        if (strlen($decoded) > $this->maxDecodedStreamBytes()) {
            throw new PdfException(sprintf(
                'Decoded stream exceeds the configured safety limit of %d bytes.',
                $this->maxDecodedStreamBytes()
            ));
        }
    }

    private function envValue(string $name, ?string $legacyName = null): string|false
    {
        $value = getenv($name);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        if ($legacyName !== null) {
            return getenv($legacyName);
        }

        return $value;
    }

    private function canonicalFilterName(mixed $filter): string
    {
        return match ($filter) {
            'Fl' => 'FlateDecode',
            'AHx' => 'ASCIIHexDecode',
            'A85' => 'ASCII85Decode',
            'LZW' => 'LZWDecode',
            'RL' => 'RunLengthDecode',
            default => (string) $filter,
        };
    }

    private function decodeFlate(string $contents): string
    {
        if (!function_exists('zlib_decode')) {
            throw new PdfException('zlib extension is required to decode FlateDecode streams.');
        }

        set_error_handler(static fn (): bool => true);

        try {
            $inflated = zlib_decode($contents, $this->maxDecodedStreamBytes() + 1);
        } finally {
            restore_error_handler();
        }

        if ($inflated === false) {
            throw new PdfException('Unable to decode FlateDecode stream or decoded data exceeded the configured safety limit.');
        }

        return $inflated;
    }

    private function decodeAsciiHex(string $contents): string
    {
        $hex = preg_replace('/\s+/', '', $contents) ?? $contents;
        $terminator = strpos($hex, '>');

        if ($terminator !== false) {
            $hex = substr($hex, 0, $terminator);
        }

        if ($hex === '') {
            return '';
        }

        if (preg_match('/[^0-9A-Fa-f]/', $hex) === 1) {
            throw new PdfException('ASCIIHexDecode stream contains non-hex characters.');
        }

        if (strlen($hex) % 2 === 1) {
            $hex .= '0';
        }

        $decoded = hex2bin($hex);

        if ($decoded === false) {
            throw new PdfException('Unable to decode ASCIIHexDecode stream.');
        }

        return $decoded;
    }

    private function decodeAscii85(string $contents): string
    {
        $data = preg_replace('/\s+/', '', $contents) ?? $contents;

        if (str_starts_with($data, '<~')) {
            $data = substr($data, 2);
        }

        $terminator = strpos($data, '~>');

        if ($terminator !== false) {
            $data = substr($data, 0, $terminator);
        }

        $decoded = '';
        $group = '';
        $length = strlen($data);

        for ($i = 0; $i < $length; $i++) {
            $char = $data[$i];

            if ($char === 'z') {
                if ($group !== '') {
                    throw new PdfException('ASCII85Decode z marker appeared inside a partial group.');
                }

                $decoded .= "\0\0\0\0";
                continue;
            }

            if ($char < '!' || $char > 'u') {
                throw new PdfException('ASCII85Decode stream contains invalid characters.');
            }

            $group .= $char;

            if (strlen($group) === 5) {
                $decoded .= $this->decodeAscii85Group($group, 4);
                $group = '';
            }
        }

        if ($group !== '') {
            $outputBytes = strlen($group) - 1;
            $group = str_pad($group, 5, 'u');
            $decoded .= $this->decodeAscii85Group($group, $outputBytes);
        }

        return $decoded;
    }

    private function decodeAscii85Group(string $group, int $outputBytes): string
    {
        $value = 0;

        for ($i = 0; $i < 5; $i++) {
            $value = ($value * 85) + (ord($group[$i]) - 33);
        }

        $bytes = pack('N', $value);

        return substr($bytes, 0, $outputBytes);
    }

    private function decodeRunLength(string $contents): string
    {
        $decoded = '';
        $offset = 0;
        $length = strlen($contents);

        while ($offset < $length) {
            $lengthByte = ord($contents[$offset++]);

            if ($lengthByte === 128) {
                return $decoded;
            }

            if ($lengthByte <= 127) {
                $count = $lengthByte + 1;
                $decoded .= substr($contents, $offset, $count);
                $offset += $count;
                continue;
            }

            if ($offset >= $length) {
                throw new PdfException('RunLengthDecode repeat packet is missing its data byte.');
            }

            $decoded .= str_repeat($contents[$offset++], 257 - $lengthByte);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function applyPredictor(string $contents, array $params): string
    {
        $predictor = (int) ($params['Predictor'] ?? 1);

        if ($predictor === 1) {
            return $contents;
        }

        $colors = max(1, (int) ($params['Colors'] ?? 1));
        $bitsPerComponent = (int) ($params['BitsPerComponent'] ?? 8);
        $columns = max(1, (int) ($params['Columns'] ?? 1));

        if ($bitsPerComponent !== 8) {
            throw new PdfException('Only 8-bit stream predictors are supported yet.');
        }

        $bytesPerPixel = max(1, $colors);
        $rowLength = $columns * $colors;

        if ($predictor === 2) {
            return $this->decodeTiffPredictor($contents, $rowLength, $bytesPerPixel);
        }

        if ($predictor >= 10 && $predictor <= 15) {
            return $this->decodePngPredictor($contents, $rowLength, $bytesPerPixel);
        }

        throw new PdfException(sprintf('Unsupported stream predictor: %d', $predictor));
    }

    private function decodeTiffPredictor(string $contents, int $rowLength, int $bytesPerPixel): string
    {
        $decoded = '';

        for ($offset = 0, $length = strlen($contents); $offset < $length; $offset += $rowLength) {
            $row = substr($contents, $offset, $rowLength);

            for ($i = $bytesPerPixel; $i < strlen($row); $i++) {
                $row[$i] = chr((ord($row[$i]) + ord($row[$i - $bytesPerPixel])) & 0xFF);
            }

            $decoded .= $row;
        }

        return $decoded;
    }

    private function decodePngPredictor(string $contents, int $rowLength, int $bytesPerPixel): string
    {
        $decoded = '';
        $previousRow = str_repeat("\0", $rowLength);
        $stride = $rowLength + 1;

        for ($offset = 0, $length = strlen($contents); $offset < $length; $offset += $stride) {
            $filter = ord($contents[$offset] ?? "\0");
            $row = substr($contents, $offset + 1, $rowLength);

            if (strlen($row) < $rowLength) {
                throw new PdfException('PNG predictor row is shorter than expected.');
            }

            for ($i = 0; $i < $rowLength; $i++) {
                $left = $i >= $bytesPerPixel ? ord($row[$i - $bytesPerPixel]) : 0;
                $up = ord($previousRow[$i]);
                $upperLeft = $i >= $bytesPerPixel ? ord($previousRow[$i - $bytesPerPixel]) : 0;
                $value = ord($row[$i]);

                $row[$i] = chr(match ($filter) {
                    0 => $value,
                    1 => ($value + $left) & 0xFF,
                    2 => ($value + $up) & 0xFF,
                    3 => ($value + intdiv($left + $up, 2)) & 0xFF,
                    4 => ($value + $this->paeth($left, $up, $upperLeft)) & 0xFF,
                    default => throw new PdfException(sprintf('Unsupported PNG predictor filter: %d', $filter)),
                });
            }

            $decoded .= $row;
            $previousRow = $row;
        }

        return $decoded;
    }

    private function paeth(int $left, int $up, int $upperLeft): int
    {
        $estimate = $left + $up - $upperLeft;
        $leftDistance = abs($estimate - $left);
        $upDistance = abs($estimate - $up);
        $upperLeftDistance = abs($estimate - $upperLeft);

        if ($leftDistance <= $upDistance && $leftDistance <= $upperLeftDistance) {
            return $left;
        }

        return $upDistance <= $upperLeftDistance ? $up : $upperLeft;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function decodeLzw(string $contents, array $params): string
    {
        $earlyChange = (int) ($params['EarlyChange'] ?? 1);
        $reader = new class ($contents) {
            private int $offset = 0;

            public function __construct(private readonly string $data)
            {
            }

            public function read(int $width): ?int
            {
                if ($this->offset + $width > strlen($this->data) * 8) {
                    return null;
                }

                $value = 0;

                for ($i = 0; $i < $width; $i++) {
                    $byte = ord($this->data[intdiv($this->offset, 8)]);
                    $bit = 7 - ($this->offset % 8);
                    $value = ($value << 1) | (($byte >> $bit) & 1);
                    $this->offset++;
                }

                return $value;
            }
        };

        $reset = static function () use (&$dictionary, &$nextCode, &$codeSize): void {
            $dictionary = [];

            for ($i = 0; $i < 256; $i++) {
                $dictionary[$i] = chr($i);
            }

            $nextCode = 258;
            $codeSize = 9;
        };

        $dictionary = [];
        $nextCode = 258;
        $codeSize = 9;
        $reset();
        $previous = null;
        $decoded = '';

        while (($code = $reader->read($codeSize)) !== null) {
            if ($code === 256) {
                $reset();
                $previous = null;
                continue;
            }

            if ($code === 257) {
                return $decoded;
            }

            if (isset($dictionary[$code])) {
                $entry = $dictionary[$code];
            } elseif ($previous !== null && $code === $nextCode) {
                $entry = $previous . $previous[0];
            } else {
                throw new PdfException('Invalid LZWDecode code encountered.');
            }

            $decoded .= $entry;

            if ($previous !== null && $nextCode < 4096) {
                $dictionary[$nextCode++] = $previous . $entry[0];

                if ($codeSize < 12 && $nextCode + $earlyChange >= (1 << $codeSize)) {
                    $codeSize++;
                }
            }

            $previous = $entry;
        }

        return $decoded;
    }

    /**
     * @return list<mixed>
     */
    private function extractChildValues(mixed $value): array
    {
        if ($value instanceof PdfStream) {
            return array_values($value->dictionary);
        }

        if (is_array($value)) {
            return array_values($value);
        }

        return [];
    }
}
