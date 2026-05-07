<?php

declare(strict_types=1);

namespace PdfToolkit\Writer;

use PdfToolkit\Parser\PdfLiteralString;
use PdfToolkit\Parser\PdfStream;
use PdfToolkit\Parser\PdfValueParser;
use PdfToolkit\Parser\PdfValueSerializer;

final class StandardSecurityWriter
{
    private const METHOD_RC4 = 'RC4';
    private const METHOD_AESV2 = 'AESV2';
    private const METHOD_AESV3 = 'AESV3';
    private const EXPLICIT_NO_CRYPT_FILTER = 'NoCrypt';
    private const EMBEDDED_STD_CRYPT_FILTER = 'EmbeddedStdCF';
    private const EMBEDDED_NO_CRYPT_FILTER = 'EmbeddedNoCrypt';

    private const PASSWORD_PADDING = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08"
        . "\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    private ?PdfValueSerializer $serializer = null;

    private function __construct(
        private readonly int $permissions,
        private readonly int $revision,
        private readonly int $version,
        private readonly int $keyLength,
        private readonly string $method,
        private readonly bool $encryptMetadata,
        private readonly bool $useExplicitCryptFilters,
        private readonly bool $encryptStrings,
        private readonly bool $encryptStreams,
        private readonly ?string $embeddedFileFilterName,
        private readonly ?string $embeddedFileAuthEvent,
        private readonly string $fileId,
        private readonly string $ownerEntry,
        private readonly string $userEntry,
        private readonly ?string $ownerEncryption,
        private readonly ?string $userEncryption,
        private readonly string $fileKey,
    ) {
    }

    public static function fromWriteOptions(WriteOptions $options): ?self
    {
        if ($options->userPassword === null && $options->ownerPassword === null) {
            return null;
        }

        $userPassword = $options->userPassword ?? '';
        $ownerPassword = $options->ownerPassword ?? $userPassword;
        $permissions = $options->permissions;
        $revision = $options->encryptionRevision;
        $method = $options->encryptionMethod;

        if (!in_array($revision, [2, 3, 4, 5], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported writer encryption revision: %d', $revision));
        }

        if (!in_array($method, [self::METHOD_RC4, self::METHOD_AESV2, self::METHOD_AESV3], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported writer encryption method: %s', $method));
        }

        if ($revision < 4 && $method !== self::METHOD_RC4) {
            throw new \InvalidArgumentException('AESV2 writer encryption requires revision 4.');
        }

        if ($revision === 4 && $method === self::METHOD_AESV3) {
            throw new \InvalidArgumentException('AESV3 writer encryption requires revision 5.');
        }

        if ($revision === 5 && $method !== self::METHOD_AESV3) {
            throw new \InvalidArgumentException('Revision 5 writer encryption requires AESV3.');
        }

        if ($revision < 4 && !$options->encryptMetadata) {
            throw new \InvalidArgumentException('encryptMetadata=false requires revision 4 writer encryption.');
        }

        if ($revision < 4 && $options->useExplicitCryptFilters) {
            throw new \InvalidArgumentException('Explicit crypt filters require revision 4 writer encryption.');
        }

        if ($revision < 4 && !$options->encryptStrings) {
            throw new \InvalidArgumentException('encryptStrings=false requires revision 4 writer encryption.');
        }

        if ($revision < 4 && !$options->encryptStreams) {
            throw new \InvalidArgumentException('encryptStreams=false requires revision 4 writer encryption.');
        }

        if ($revision < 4 && $options->embeddedFileFilterName !== null) {
            throw new \InvalidArgumentException('embeddedFileFilterName requires revision 4 writer encryption.');
        }

        if ($revision < 4 && $options->embeddedFileAuthEvent !== null) {
            throw new \InvalidArgumentException('embeddedFileAuthEvent requires revision 4 writer encryption.');
        }

        if ($options->embeddedFileFilterName !== null
            && !in_array($options->embeddedFileFilterName, ['StdCF', self::EXPLICIT_NO_CRYPT_FILTER], true)
        ) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported embedded file crypt filter name: %s',
                $options->embeddedFileFilterName,
            ));
        }

        if ($options->embeddedFileAuthEvent !== null
            && !in_array($options->embeddedFileAuthEvent, ['DocOpen', 'EFOpen'], true)
        ) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported embedded file auth event: %s',
                $options->embeddedFileAuthEvent,
            ));
        }

        if ($options->embeddedFileAuthEvent === 'EFOpen' && $options->embeddedFileFilterName === null) {
            throw new \InvalidArgumentException('embeddedFileAuthEvent=EFOpen requires an embeddedFileFilterName.');
        }

        $version = match ($revision) {
            2 => 1,
            3 => 2,
            5 => 5,
            default => 4,
        };
        $keyLength = match (true) {
            $revision >= 5 => 32,
            $revision >= 3 => 16,
            default => 5,
        };
        $encryptMetadata = $revision >= 4 ? $options->encryptMetadata : true;
        $useExplicitCryptFilters = $revision >= 4 ? $options->useExplicitCryptFilters : false;
        $encryptStrings = $revision >= 4 ? $options->encryptStrings : true;
        $encryptStreams = $revision >= 4 ? $options->encryptStreams : true;
        $embeddedFileFilterName = $revision >= 4 ? $options->embeddedFileFilterName : null;
        $embeddedFileAuthEvent = $revision >= 4 ? $options->embeddedFileAuthEvent : null;
        $fileId = random_bytes(16);
        $fileKey = random_bytes($keyLength);
        $ownerEncryption = null;
        $userEncryption = null;

        if ($revision >= 5) {
            $userEntry = self::buildRevision5UserEntry($userPassword);
            $ownerEntry = self::buildRevision5OwnerEntry($ownerPassword, $userEntry);
            $userEncryption = self::buildRevision5UserEncryption($fileKey, $userPassword, $userEntry);
            $ownerEncryption = self::buildRevision5OwnerEncryption($fileKey, $ownerPassword, $ownerEntry, $userEntry);
        } else {
            $ownerEntry = self::buildOwnerEntry($ownerPassword, $userPassword, $revision, $keyLength);
            $fileKey = self::buildFileKey($userPassword, $ownerEntry, $permissions, $fileId, $revision, $keyLength, $encryptMetadata);
            $userEntry = $revision >= 3
                ? self::buildRevision3UserEntry($fileKey, $fileId)
                : self::rc4($fileKey, self::PASSWORD_PADDING);
        }

        return new self(
            $permissions,
            $revision,
            $version,
            $keyLength,
            $method,
            $encryptMetadata,
            $useExplicitCryptFilters,
            $encryptStrings,
            $encryptStreams,
            $embeddedFileFilterName,
            $embeddedFileAuthEvent,
            $fileId,
            $ownerEntry,
            $userEntry,
            $ownerEncryption,
            $userEncryption,
            $fileKey,
        );
    }

    /**
     * @param array<int, string> $objects
     * @return array{objects: array<int, string>, encryptObject: string, trailerSuffix: string}
     */
    public function encryptObjects(array $objects, int $encryptObjectNumber): array
    {
        $encryptedObjects = [];

        foreach ($objects as $objectNumber => $serializedValue) {
            $encryptedObjects[$objectNumber] = $this->encryptSerializedObject($serializedValue, $objectNumber);
        }

        return [
            'objects' => $encryptedObjects,
            'encryptObject' => $this->buildEncryptObject(),
            'trailerSuffix' => sprintf(
                ' /Encrypt %d 0 R /ID [<%s> <%s>]',
                $encryptObjectNumber,
                bin2hex($this->fileId),
                bin2hex($this->fileId),
            ),
        ];
    }

    private function encryptSerializedObject(string $serializedValue, int $objectNumber): string
    {
        $value = $this->parseSerializedValue($serializedValue);
        $encrypted = $this->encryptValue($value, $objectNumber);

        return $this->serializer()->serialize($encrypted);
    }

    private function parseSerializedValue(string $serializedValue): mixed
    {
        $offset = 0;
        $parser = new PdfValueParser($serializedValue);
        $value = $parser->parseValue($offset);
        $parser->skipWhitespaceAndComments($offset);

        if (!is_array($value) || substr($serializedValue, $offset, 6) !== 'stream') {
            return $value;
        }

        $offset += 6;

        if (substr($serializedValue, $offset, 2) === "\r\n") {
            $offset += 2;
        } elseif (($serializedValue[$offset] ?? '') === "\n" || ($serializedValue[$offset] ?? '') === "\r") {
            $offset++;
        }

        $length = $value['Length'] ?? null;

        if (!is_int($length) && !is_float($length)) {
            return $value;
        }

        $length = (int) $length;
        $contents = substr($serializedValue, $offset, $length);

        if ($contents === false) {
            $contents = '';
        }

        return new PdfStream($value, $contents);
    }

    private function encryptValue(mixed $value, int $objectNumber, int $generationNumber = 0): mixed
    {
        if ($value instanceof PdfLiteralString) {
            if (!$this->encryptStrings) {
                return $value;
            }

            return new PdfLiteralString($this->encryptBytes($value->value, $objectNumber, $generationNumber));
        }

        if ($value instanceof PdfStream) {
            $dictionary = $this->encryptValue($value->dictionary, $objectNumber, $generationNumber);
            $resolvedDictionary = is_array($dictionary) ? $dictionary : $value->dictionary;
            $contents = $value->contents;
            $encryptStream = $this->encryptStreams
                && ($this->encryptMetadata || (($value->dictionary['Type'] ?? null) !== 'Metadata'));

            if ($encryptStream) {
                $contents = $this->encryptBytes($contents, $objectNumber, $generationNumber);
            }

            if ($this->useExplicitCryptFilters) {
                $resolvedDictionary = $this->withExplicitCryptFilter(
                    $resolvedDictionary,
                    $encryptStream ? 'StdCF' : self::EXPLICIT_NO_CRYPT_FILTER,
                );
            }

            return new PdfStream(
                $resolvedDictionary,
                $contents,
            );
        }

        if (is_array($value)) {
            $updated = [];

            foreach ($value as $key => $item) {
                $updated[$key] = $this->encryptValue($item, $objectNumber, $generationNumber);
            }

            return $updated;
        }

        return $value;
    }

    private function serializer(): PdfValueSerializer
    {
        return $this->serializer ??= new PdfValueSerializer();
    }

    private function encryptBytes(string $contents, int $objectNumber, int $generationNumber): string
    {
        if ($this->method === self::METHOD_AESV3) {
            return self::encryptAesV3($this->fileKey, $contents);
        }

        $keyMaterial = $this->fileKey
            . chr($objectNumber & 0xFF)
            . chr(($objectNumber >> 8) & 0xFF)
            . chr(($objectNumber >> 16) & 0xFF)
            . chr($generationNumber & 0xFF)
            . chr(($generationNumber >> 8) & 0xFF);

        if ($this->method === self::METHOD_AESV2) {
            $keyMaterial .= 'sAlT';
        }

        $objectKey = substr(md5($keyMaterial, true), 0, min($this->keyLength + 5, 16));

        if ($this->method === self::METHOD_AESV2) {
            return self::encryptAesV2($objectKey, $contents);
        }

        return self::rc4($objectKey, $contents);
    }

    private function buildEncryptObject(): string
    {
        $embeddedFileDictionaryName = $this->dictionaryEmbeddedFileFilterName();
        $embeddedFileAuthEvent = $this->effectiveEmbeddedFileAuthEvent();

        if ($this->revision === 2) {
            return sprintf(
                '<< /Filter /Standard /V 1 /R 2 /O <%s> /U <%s> /P %d >>',
                bin2hex($this->ownerEntry),
                bin2hex($this->userEntry),
                $this->permissions,
            );
        }

        if ($this->revision === 3) {
            return sprintf(
                '<< /Filter /Standard /V 2 /R 3 /Length %d /O <%s> /U <%s> /P %d >>',
                $this->keyLength * 8,
                bin2hex($this->ownerEntry),
                bin2hex($this->userEntry),
                $this->permissions,
            );
        }

        if ($this->revision === 5) {
            $cryptFilters = sprintf(
                '/StdCF << /Length %d /CFM /AESV3 /AuthEvent /DocOpen >>',
                $this->keyLength * 8,
            );

            if (
                ($this->useExplicitCryptFilters && (!$this->encryptStrings || !$this->encryptStreams || !$this->encryptMetadata))
                || $this->embeddedFileFilterName === self::EXPLICIT_NO_CRYPT_FILTER
            ) {
                $cryptFilters .= sprintf(' /%s << /CFM /None /AuthEvent /DocOpen >>', self::EXPLICIT_NO_CRYPT_FILTER);
            }

            if ($embeddedFileDictionaryName === self::EMBEDDED_STD_CRYPT_FILTER) {
                $cryptFilters .= sprintf(
                    ' /%s << /Length %d /CFM /AESV3 /AuthEvent /%s >>',
                    self::EMBEDDED_STD_CRYPT_FILTER,
                    $this->keyLength * 8,
                    $embeddedFileAuthEvent,
                );
            } elseif ($embeddedFileDictionaryName === self::EMBEDDED_NO_CRYPT_FILTER) {
                $cryptFilters .= sprintf(
                    ' /%s << /CFM /None /AuthEvent /%s >>',
                    self::EMBEDDED_NO_CRYPT_FILTER,
                    $embeddedFileAuthEvent,
                );
            }

            $streamFilterName = $this->encryptStreams
                ? 'StdCF'
                : ($this->useExplicitCryptFilters ? self::EXPLICIT_NO_CRYPT_FILTER : 'Identity');
            $stringFilterName = $this->encryptStrings
                ? 'StdCF'
                : ($this->useExplicitCryptFilters ? self::EXPLICIT_NO_CRYPT_FILTER : 'Identity');
            $perms = self::buildRevision5Perms($this->permissions, $this->encryptMetadata, $this->fileKey);
            $dictionary = sprintf(
                '<< /Filter /Standard /V 5 /R 5 /Length %d /EncryptMetadata %s /O <%s> /U <%s> /OE <%s> /UE <%s> /Perms <%s> /P %d /CF << %s >> /StmF /%s /StrF /%s',
                $this->keyLength * 8,
                $this->encryptMetadata ? 'true' : 'false',
                bin2hex($this->ownerEntry),
                bin2hex($this->userEntry),
                bin2hex($this->ownerEncryption ?? ''),
                bin2hex($this->userEncryption ?? ''),
                bin2hex($perms),
                $this->permissions,
                $cryptFilters,
                $streamFilterName,
                $stringFilterName,
            );

            if ($embeddedFileDictionaryName !== null) {
                $dictionary .= ' /EFF /' . $embeddedFileDictionaryName;
            }

            return $dictionary . ' >>';
        }

        $cryptFilters = sprintf(
            '/StdCF << /Length %d /CFM /%s /AuthEvent /DocOpen >>',
            $this->keyLength * 8,
            $this->method === self::METHOD_AESV2 ? 'AESV2' : 'V2',
        );

        if (
            ($this->useExplicitCryptFilters && (!$this->encryptStrings || !$this->encryptStreams || !$this->encryptMetadata))
            || $this->embeddedFileFilterName === self::EXPLICIT_NO_CRYPT_FILTER
        ) {
            $cryptFilters .= sprintf(' /%s << /CFM /None /AuthEvent /DocOpen >>', self::EXPLICIT_NO_CRYPT_FILTER);
        }

        if ($embeddedFileDictionaryName === self::EMBEDDED_STD_CRYPT_FILTER) {
            $cryptFilters .= sprintf(
                ' /%s << /Length %d /CFM /%s /AuthEvent /%s >>',
                self::EMBEDDED_STD_CRYPT_FILTER,
                $this->keyLength * 8,
                $this->method === self::METHOD_AESV2 ? 'AESV2' : 'V2',
                $embeddedFileAuthEvent,
            );
        } elseif ($embeddedFileDictionaryName === self::EMBEDDED_NO_CRYPT_FILTER) {
            $cryptFilters .= sprintf(
                ' /%s << /CFM /None /AuthEvent /%s >>',
                self::EMBEDDED_NO_CRYPT_FILTER,
                $embeddedFileAuthEvent,
            );
        }

        $streamFilterName = $this->encryptStreams
            ? 'StdCF'
            : ($this->useExplicitCryptFilters ? self::EXPLICIT_NO_CRYPT_FILTER : 'Identity');
        $stringFilterName = $this->encryptStrings
            ? 'StdCF'
            : ($this->useExplicitCryptFilters ? self::EXPLICIT_NO_CRYPT_FILTER : 'Identity');

        $dictionary = sprintf(
            '<< /Filter /Standard /V 4 /R 4 /Length %d /EncryptMetadata %s /O <%s> /U <%s> /P %d /CF << %s >> /StmF /%s /StrF /%s',
            $this->keyLength * 8,
            $this->encryptMetadata ? 'true' : 'false',
            bin2hex($this->ownerEntry),
            bin2hex($this->userEntry),
            $this->permissions,
            $cryptFilters,
            $streamFilterName,
            $stringFilterName,
        );

        if ($embeddedFileDictionaryName !== null) {
            $dictionary .= ' /EFF /' . $embeddedFileDictionaryName;
        }

        return $dictionary . ' >>';
    }

    private function effectiveEmbeddedFileAuthEvent(): string
    {
        return $this->embeddedFileAuthEvent ?? 'DocOpen';
    }

    private function dictionaryEmbeddedFileFilterName(): ?string
    {
        if ($this->embeddedFileFilterName === null) {
            return null;
        }

        if ($this->effectiveEmbeddedFileAuthEvent() !== 'EFOpen') {
            return $this->embeddedFileFilterName;
        }

        return $this->embeddedFileFilterName === 'StdCF'
            ? self::EMBEDDED_STD_CRYPT_FILTER
            : self::EMBEDDED_NO_CRYPT_FILTER;
    }

    private static function buildOwnerEntry(string $ownerPassword, string $userPassword, int $revision, int $keyLength): string
    {
        $ownerKey = self::ownerKey(self::padPassword($ownerPassword), $revision, $keyLength);
        $value = self::padPassword($userPassword);

        if ($revision >= 3) {
            $value = self::rc4($ownerKey, $value);

            for ($index = 1; $index <= 19; $index++) {
                $value = self::rc4(self::xorKey($ownerKey, $index), $value);
            }

            return $value;
        }

        return self::rc4($ownerKey, $value);
    }

    private static function buildFileKey(
        string $userPassword,
        string $ownerEntry,
        int $permissions,
        string $fileId,
        int $revision,
        int $keyLength,
        bool $encryptMetadata,
    ): string
    {
        $payload = self::padPassword($userPassword)
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

    private static function padPassword(string $password): string
    {
        $password = substr($password, 0, 32);

        return str_pad($password, 32, self::PASSWORD_PADDING);
    }

    private static function ownerKey(string $paddedPassword, int $revision, int $keyLength): string
    {
        $digest = md5($paddedPassword, true);

        if ($revision >= 3) {
            $digest = substr($digest, 0, $keyLength);

            for ($index = 0; $index < 50; $index++) {
                $digest = substr(md5($digest, true), 0, $keyLength);
            }

            return $digest;
        }

        return substr($digest, 0, 5);
    }

    private static function buildRevision3UserEntry(string $fileKey, string $fileId): string
    {
        $value = md5(self::PASSWORD_PADDING . $fileId, true);
        $value = self::rc4($fileKey, $value);

        for ($index = 1; $index <= 19; $index++) {
            $value = self::rc4(self::xorKey($fileKey, $index), $value);
        }

        return $value . str_repeat("\x00", 16);
    }

    private static function buildRevision5UserEntry(string $userPassword): string
    {
        $password = substr($userPassword, 0, 127);
        $validationSalt = random_bytes(8);
        $keySalt = random_bytes(8);

        return hash('sha256', $password . $validationSalt, true) . $validationSalt . $keySalt;
    }

    private static function buildRevision5OwnerEntry(string $ownerPassword, string $userEntry): string
    {
        $password = substr($ownerPassword, 0, 127);
        $validationSalt = random_bytes(8);
        $keySalt = random_bytes(8);

        return hash('sha256', $password . $validationSalt . $userEntry, true) . $validationSalt . $keySalt;
    }

    private static function buildRevision5UserEncryption(string $fileKey, string $userPassword, string $userEntry): string
    {
        $passwordSalt = substr($userEntry, 40, 8);
        $intermediateKey = hash('sha256', substr($userPassword, 0, 127) . $passwordSalt, true);

        return self::encryptAes256ZeroIv($intermediateKey, $fileKey, 'revision 5 user');
    }

    private static function buildRevision5OwnerEncryption(string $fileKey, string $ownerPassword, string $ownerEntry, string $userEntry): string
    {
        $passwordSalt = substr($ownerEntry, 40, 8);
        $intermediateKey = hash('sha256', substr($ownerPassword, 0, 127) . $passwordSalt . $userEntry, true);

        return self::encryptAes256ZeroIv($intermediateKey, $fileKey, 'revision 5 owner');
    }

    private static function buildRevision5Perms(int $permissions, bool $encryptMetadata, string $fileKey): string
    {
        $block = pack('V', $permissions < 0 ? $permissions + 0x100000000 : $permissions)
            . "\xFF\xFF\xFF\xFF"
            . ($encryptMetadata ? 'T' : 'F')
            . 'adb'
            . "\x00\x00\x00\x00";

        if (!function_exists('openssl_encrypt')) {
            throw new \RuntimeException('OpenSSL extension is required for AESV3 writer encryption.');
        }

        $ciphertext = openssl_encrypt($block, 'aes-256-ecb', $fileKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);

        if (!is_string($ciphertext)) {
            throw new \RuntimeException('Unable to encrypt AESV3 permissions block.');
        }

        return $ciphertext;
    }

    private static function xorKey(string $key, int $value): string
    {
        $output = '';
        $length = strlen($key);

        for ($index = 0; $index < $length; $index++) {
            $output .= chr(ord($key[$index]) ^ $value);
        }

        return $output;
    }

    private static function rc4(string $key, string $data): string
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
        $length = strlen($data);

        for ($index = 0; $index < $length; $index++) {
            $i = ($i + 1) & 0xFF;
            $j = ($j + $state[$i]) & 0xFF;
            [$state[$i], $state[$j]] = [$state[$j], $state[$i]];
            $output .= chr(ord($data[$index]) ^ $state[($state[$i] + $state[$j]) & 0xFF]);
        }

        return $output;
    }

    private static function encryptAesV2(string $key, string $contents): string
    {
        if (!function_exists('openssl_encrypt')) {
            throw new \RuntimeException('OpenSSL extension is required for AESV2 writer encryption.');
        }

        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($contents, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if (!is_string($ciphertext)) {
            throw new \RuntimeException('Unable to encrypt AESV2 object contents.');
        }

        return $iv . $ciphertext;
    }

    private static function encryptAesV3(string $key, string $contents): string
    {
        if (!function_exists('openssl_encrypt')) {
            throw new \RuntimeException('OpenSSL extension is required for AESV3 writer encryption.');
        }

        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($contents, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if (!is_string($ciphertext)) {
            throw new \RuntimeException('Unable to encrypt AESV3 object contents.');
        }

        return $iv . $ciphertext;
    }

    private static function encryptAes256ZeroIv(string $key, string $contents, string $label): string
    {
        if (!function_exists('openssl_encrypt')) {
            throw new \RuntimeException('OpenSSL extension is required for AESV3 writer encryption.');
        }

        $ciphertext = openssl_encrypt($contents, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, str_repeat("\x00", 16));

        if (!is_string($ciphertext)) {
            throw new \RuntimeException(sprintf('Unable to encrypt %s data.', $label));
        }

        return $ciphertext;
    }

    /**
     * @param array<string, mixed> $dictionary
     * @return array<string, mixed>
     */
    private function withExplicitCryptFilter(array $dictionary, string $cryptName): array
    {
        $existingFilter = $dictionary['Filter'] ?? null;
        $existingDecodeParms = $dictionary['DecodeParms'] ?? null;

        if ($existingFilter === null) {
            $dictionary['Filter'] = 'Crypt';
            $dictionary['DecodeParms'] = ['Name' => $cryptName];

            return $dictionary;
        }

        $filters = is_array($existingFilter) ? $existingFilter : [$existingFilter];
        array_unshift($filters, 'Crypt');
        $dictionary['Filter'] = $filters;

        $decodeParms = is_array($existingDecodeParms) && array_is_list($existingDecodeParms)
            ? $existingDecodeParms
            : ($existingDecodeParms === null ? [] : [$existingDecodeParms]);
        array_unshift($decodeParms, ['Name' => $cryptName]);
        $dictionary['DecodeParms'] = $decodeParms;

        return $dictionary;
    }
}
