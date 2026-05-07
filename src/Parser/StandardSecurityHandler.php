<?php

declare(strict_types=1);

namespace PdfToolkit\Parser;

use PdfToolkit\Core\PdfException;

final class StandardSecurityHandler
{
    private const METHOD_IDENTITY = 'Identity';
    private const METHOD_RC4 = 'RC4';
    private const METHOD_AESV2 = 'AESV2';
    private const METHOD_AESV3 = 'AESV3';

    private const PASSWORD_PADDING = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08"
        . "\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    private function __construct(
        private readonly string $fileKey,
        private readonly int $keyLength,
        private readonly int $encryptObjectNumber,
        private readonly string $authenticatedAs,
        private readonly string $stringMethod,
        private readonly string $streamMethod,
        private readonly bool $encryptMetadata,
        /** @var array<string, string> */
        private readonly array $cryptFilterMethods,
    ) {
    }

    /**
     * @param array<string, mixed> $encryptDictionary
     * @param array<string, mixed> $trailer
     */
    public static function fromTrailer(array $encryptDictionary, array $trailer, ?string $password, int $encryptObjectNumber): self
    {
        $description = self::describeEncryption($encryptDictionary);
        $version = $description->version();
        $revision = $description->revision();

        $owner = self::stringBytes($encryptDictionary['O'] ?? null, 'O');
        $user = self::stringBytes($encryptDictionary['U'] ?? null, 'U');
        $permissions = self::integerValue($encryptDictionary['P'] ?? null, 'P');
        $encryptMetadata = $description->encryptMetadata();
        $keyLength = $revision >= 5
            ? 32
            : ($revision >= 3
            ? max(5, (int) (($encryptDictionary['Length'] ?? 40) / 8))
            : 5);
        $stringFilterName = $description->stringFilterName();
        $streamFilterName = $description->streamFilterName();
        $stringMethod = $description->stringMethod();
        $streamMethod = $description->streamMethod();
        $candidatePassword = $password ?? '';
        $openedWithPassword = $candidatePassword !== '';
        $fileId = '';

        if ($revision < 5) {
            $id = $trailer['ID'] ?? null;

            if (!is_array($id) || $id === []) {
                throw new PdfException('Encrypted PDF is missing trailer ID information.');
            }

            $fileId = self::stringBytes($id[0] ?? null, 'ID');
        }

        if ($revision >= 5) {
            $userEncryption = self::stringBytes($encryptDictionary['UE'] ?? null, 'UE');
            $ownerEncryption = self::stringBytes($encryptDictionary['OE'] ?? null, 'OE');
            $fileKey = self::authenticateUserPasswordAesV3($candidatePassword, $user, $userEncryption);
            $authenticatedAs = 'user';

            if ($fileKey === null) {
                $fileKey = self::authenticateOwnerPasswordAesV3($candidatePassword, $owner, $ownerEncryption, $user);
                $authenticatedAs = 'owner';
            }
        } else {
            $fileKey = self::authenticateUserPassword($candidatePassword, $owner, $user, $permissions, $fileId, $revision, $keyLength, $encryptMetadata);
            $authenticatedAs = 'user';

            if ($fileKey === null) {
                $fileKey = self::authenticateOwnerPassword($candidatePassword, $owner, $user, $permissions, $fileId, $revision, $keyLength, $encryptMetadata);
                $authenticatedAs = 'owner';
            }
        }

        if ($fileKey === null) {
            throw new PdfException('Unable to authenticate encrypted PDF with the provided password.');
        }

        if ($revision >= 5) {
            $perms = self::stringBytes($encryptDictionary['Perms'] ?? null, 'Perms');
            self::verifyAesV3Perms($perms, $fileKey, $permissions, $encryptMetadata);
        }

        return new self(
            $fileKey,
            $keyLength,
            $encryptObjectNumber,
            $authenticatedAs,
            $stringMethod,
            $streamMethod,
            $encryptMetadata,
            self::resolveCryptFilterMethods($encryptDictionary),
        );
    }

    /**
     * @param array<string, mixed> $encryptDictionary
     */
    public static function describeEncryption(array $encryptDictionary): ParsedEncryption
    {
        return self::describeEncryptionWithAuthentication($encryptDictionary);
    }

    /**
     * @param array<string, mixed> $encryptDictionary
     */
    public static function describeEncryptionWithAuthentication(
        array $encryptDictionary,
        ?string $authenticatedAs = null,
        bool $openedWithPassword = false,
    ): ParsedEncryption
    {
        if (($encryptDictionary['Filter'] ?? null) !== 'Standard') {
            throw new PdfException('Only Standard Security encrypted PDFs are supported yet.');
        }

        $version = (int) ($encryptDictionary['V'] ?? 0);
        $revision = (int) ($encryptDictionary['R'] ?? 0);

        if (
            !in_array($version, [1, 2, 4, 5], true)
            || !in_array($revision, [2, 3, 4, 5], true)
            || ($revision === 4 && $version !== 4)
            || ($revision === 5 && $version !== 5)
        ) {
            throw new PdfException('Only Standard Security revision 2/3/4 RC4 or revision 5 AES encrypted PDFs are supported yet.');
        }

        [$stringFilterName, $streamFilterName] = self::resolveFilterNames($encryptDictionary, $version);
        $cryptFilterNames = self::resolveCryptFilterNames($encryptDictionary, $version);
        $cryptFilters = self::resolveCryptFilterMethods($encryptDictionary);
        $cryptFilterAuthEvents = self::resolveCryptFilterAuthEvents($encryptDictionary);
        $cryptFilterKeyLengthBits = self::resolveCryptFilterKeyLengthBits($encryptDictionary);
        $embeddedFileFilterName = self::resolveEmbeddedFileFilterName($encryptDictionary, $version);
        [$stringMethod, $streamMethod] = self::resolveMethods($encryptDictionary, $version);
        $embeddedFileMethod = $embeddedFileFilterName === null
            ? null
            : self::methodForCryptFilterName($encryptDictionary, $embeddedFileFilterName, 'EFF');

        return new ParsedEncryption(
            filter: 'Standard',
            version: $version,
            revision: $revision,
            keyLengthBits: $revision >= 5
                ? 256
                : ($revision >= 3
                ? max(40, (int) ($encryptDictionary['Length'] ?? 40))
                : 40),
            permissions: self::integerValue($encryptDictionary['P'] ?? null, 'P'),
            authenticatedAs: $authenticatedAs,
            openedWithPassword: $openedWithPassword,
            cryptFilterNames: $cryptFilterNames,
            cryptFilters: $cryptFilters,
            cryptFilterAuthEvents: $cryptFilterAuthEvents,
            cryptFilterKeyLengthBits: $cryptFilterKeyLengthBits,
            embeddedFileFilterName: $embeddedFileFilterName,
            stringFilterName: $stringFilterName,
            streamFilterName: $streamFilterName,
            embeddedFileMethod: $embeddedFileMethod,
            stringMethod: $stringMethod,
            streamMethod: $streamMethod,
            encryptMetadata: ($encryptDictionary['EncryptMetadata'] ?? true) !== false,
        );
    }

    /**
     * @param array<string, mixed> $encryptDictionary
     * @return array{0: string, 1: string}
     */
    private static function resolveFilterNames(array $encryptDictionary, int $version): array
    {
        if ($version < 4) {
            return ['Standard', 'Standard'];
        }

        $stringFilterName = $encryptDictionary['StrF'] ?? 'Identity';
        $streamFilterName = $encryptDictionary['StmF'] ?? 'Identity';

        if (!is_string($stringFilterName) || !is_string($streamFilterName)) {
            throw new PdfException('Encrypted PDF has invalid string or stream crypt filter names.');
        }

        return [$stringFilterName, $streamFilterName];
    }

    /**
     * @param array<string, mixed> $encryptDictionary
     * @return list<string>
     */
    private static function resolveCryptFilterNames(array $encryptDictionary, int $version): array
    {
        if ($version < 4) {
            return [];
        }

        $cryptFilters = $encryptDictionary['CF'] ?? null;

        if (!is_array($cryptFilters)) {
            return [];
        }

        $names = [];

        foreach ($cryptFilters as $name => $definition) {
            if (is_string($name) && is_array($definition)) {
                $names[] = $name;
            }
        }

        sort($names);

        return array_values(array_unique($names));
    }

    public function authenticatedAs(): string
    {
        return $this->authenticatedAs;
    }

    public function decryptObjectValue(mixed $value, int $objectNumber, int $generationNumber): mixed
    {
        if ($objectNumber === $this->encryptObjectNumber) {
            return $value;
        }

        if ($value instanceof PdfLiteralString) {
            if ($this->stringMethod === self::METHOD_IDENTITY) {
                return $value;
            }

            return new PdfLiteralString($this->decryptBytes($value->value, $objectNumber, $generationNumber, $this->stringMethod));
        }

        if ($value instanceof PdfStream) {
            $dictionary = $this->decryptObjectValue($value->dictionary, $objectNumber, $generationNumber);
            $stream = new PdfStream(
                is_array($dictionary) ? $dictionary : $value->dictionary,
                $value->contents,
            );
            [$streamMethod, $stream] = $this->prepareStreamForDecryption($stream);
            $contents = $stream->contents;

            if (
                $streamMethod !== self::METHOD_IDENTITY
                && ($this->encryptMetadata || (($stream->dictionary['Type'] ?? null) !== 'Metadata'))
            ) {
                $contents = $this->decryptBytes($contents, $objectNumber, $generationNumber, $streamMethod);
            }

            return new PdfStream(
                $stream->dictionary,
                $contents,
            );
        }

        if (is_array($value)) {
            $updated = [];

            foreach ($value as $key => $item) {
                $updated[$key] = $this->decryptObjectValue($item, $objectNumber, $generationNumber);
            }

            return $updated;
        }

        return $value;
    }

    private function decryptBytes(string $contents, int $objectNumber, int $generationNumber, string $method): string
    {
        $objectKey = $this->objectKey($objectNumber, $generationNumber, $method);

        return match ($method) {
            self::METHOD_RC4 => self::rc4($objectKey, $contents),
            self::METHOD_AESV2 => self::decryptAesV2($objectKey, $contents),
            self::METHOD_AESV3 => self::decryptAesV3($objectKey, $contents),
            default => throw new PdfException(sprintf('Unsupported decryption method: %s', $method)),
        };
    }

    private static function authenticateUserPasswordAesV3(string $password, string $userEntry, string $userEncryption): ?string
    {
        if (strlen($userEntry) < 48 || strlen($userEncryption) < 32) {
            throw new PdfException('Encrypted PDF is missing valid AESV3 user credentials.');
        }

        $password = substr($password, 0, 127);
        $validationSalt = substr($userEntry, 32, 8);
        $keySalt = substr($userEntry, 40, 8);
        $validationHash = hash('sha256', $password . $validationSalt, true);

        if (!hash_equals(substr($userEntry, 0, 32), $validationHash)) {
            return null;
        }

        $intermediateKey = hash('sha256', $password . $keySalt, true);

        return self::decryptAes256ZeroIv($intermediateKey, $userEncryption, 'user');
    }

    private static function authenticateOwnerPasswordAesV3(
        string $password,
        string $ownerEntry,
        string $ownerEncryption,
        string $userEntry,
    ): ?string {
        if (strlen($ownerEntry) < 48 || strlen($ownerEncryption) < 32) {
            throw new PdfException('Encrypted PDF is missing valid AESV3 owner credentials.');
        }

        $password = substr($password, 0, 127);
        $validationSalt = substr($ownerEntry, 32, 8);
        $keySalt = substr($ownerEntry, 40, 8);
        $validationHash = hash('sha256', $password . $validationSalt . $userEntry, true);

        if (!hash_equals(substr($ownerEntry, 0, 32), $validationHash)) {
            return null;
        }

        $intermediateKey = hash('sha256', $password . $keySalt . $userEntry, true);

        return self::decryptAes256ZeroIv($intermediateKey, $ownerEncryption, 'owner');
    }

    private static function authenticateUserPassword(
        string $password,
        string $owner,
        string $user,
        int $permissions,
        string $fileId,
        int $revision,
        int $keyLength,
        bool $encryptMetadata,
    ): ?string {
        $key = self::computeFileKey(
            self::padPassword($password),
            $owner,
            $permissions,
            $fileId,
            $revision,
            $keyLength,
            $encryptMetadata,
        );

        if (self::matchesUserEntry($key, $user, $fileId, $revision)) {
            return $key;
        }

        return null;
    }

    private static function authenticateOwnerPassword(
        string $password,
        string $owner,
        string $user,
        int $permissions,
        string $fileId,
        int $revision,
        int $keyLength,
        bool $encryptMetadata,
    ): ?string {
        $ownerKey = self::ownerKey(self::padPassword($password), $revision, $keyLength);
        $candidateUserPadding = $revision >= 3
            ? self::decryptRc4Iterations($owner, $ownerKey)
            : self::rc4($ownerKey, $owner);
        $key = self::computeFileKey(
            $candidateUserPadding,
            $owner,
            $permissions,
            $fileId,
            $revision,
            $keyLength,
            $encryptMetadata,
        );

        if (self::matchesUserEntry($key, $user, $fileId, $revision)) {
            return $key;
        }

        return null;
    }

    private static function computeFileKey(
        string $paddedPassword,
        string $owner,
        int $permissions,
        string $fileId,
        int $revision,
        int $keyLength,
        bool $encryptMetadata,
    ): string {
        $payload = $paddedPassword
            . $owner
            . pack('V', $permissions < 0 ? $permissions + 0x100000000 : $permissions)
            . $fileId;

        if ($revision >= 4 && !$encryptMetadata) {
            $payload .= "\xFF\xFF\xFF\xFF";
        }

        $digest = md5($payload, true);

        if ($revision >= 3) {
            $digest = substr($digest, 0, $keyLength);

            for ($index = 0; $index < 50; $index++) {
                $digest = md5($digest, true);
                $digest = substr($digest, 0, $keyLength);
            }

            return $digest;
        }

        return substr($digest, 0, 5);
    }

    private static function matchesUserEntry(string $key, string $user, string $fileId, int $revision): bool
    {
        if ($revision === 2) {
            return self::rc4($key, self::PASSWORD_PADDING) === $user;
        }

        $value = md5(self::PASSWORD_PADDING . $fileId, true);
        $value = self::encryptRc4Iterations($value, $key);

        return substr($user, 0, 16) === substr($value, 0, 16);
    }

    private static function ownerKey(string $paddedPassword, int $revision, int $keyLength): string
    {
        $digest = md5($paddedPassword, true);

        if ($revision >= 3) {
            $digest = substr($digest, 0, $keyLength);

            for ($index = 0; $index < 50; $index++) {
                $digest = md5($digest, true);
                $digest = substr($digest, 0, $keyLength);
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

    private static function encryptRc4Iterations(string $value, string $key): string
    {
        $result = self::rc4($key, $value);

        for ($index = 1; $index <= 19; $index++) {
            $result = self::rc4(self::xorKey($key, $index), $result);
        }

        return $result;
    }

    private static function decryptRc4Iterations(string $value, string $key): string
    {
        $result = $value;

        for ($index = 19; $index >= 0; $index--) {
            $result = self::rc4(self::xorKey($key, $index), $result);
        }

        return $result;
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
        $dataLength = strlen($data);

        for ($index = 0; $index < $dataLength; $index++) {
            $i = ($i + 1) & 0xFF;
            $j = ($j + $state[$i]) & 0xFF;
            [$state[$i], $state[$j]] = [$state[$j], $state[$i]];
            $output .= chr(ord($data[$index]) ^ $state[($state[$i] + $state[$j]) & 0xFF]);
        }

        return $output;
    }

    private static function stringBytes(mixed $value, string $field): string
    {
        if ($value instanceof PdfLiteralString) {
            return $value->value;
        }

        throw new PdfException(sprintf('Encrypted PDF is missing a valid %s string.', $field));
    }

    private static function integerValue(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }

        throw new PdfException(sprintf('Encrypted PDF is missing a valid %s integer.', $field));
    }

    /**
     * @param array<string, mixed> $encryptDictionary
     * @return array{0: string, 1: string}
     */
    private static function resolveMethods(array $encryptDictionary, int $version): array
    {
        if ($version < 4) {
            return [self::METHOD_RC4, self::METHOD_RC4];
        }

        return [
            self::resolveMethod($encryptDictionary, 'StrF'),
            self::resolveMethod($encryptDictionary, 'StmF'),
        ];
    }

    /**
     * @param array<string, mixed> $encryptDictionary
     */
    private static function resolveMethod(array $encryptDictionary, string $key): string
    {
        $filterName = $encryptDictionary[$key] ?? 'Identity';

        return self::methodForCryptFilterName($encryptDictionary, $filterName, $key);
    }

    /**
     * @param array<string, mixed> $encryptDictionary
     * @return array<string, string>
     */
    private static function resolveCryptFilterMethods(array $encryptDictionary): array
    {
        $cryptFilters = $encryptDictionary['CF'] ?? null;

        if (!is_array($cryptFilters)) {
            return [];
        }

        $methods = [];

        foreach ($cryptFilters as $name => $definition) {
            if (!is_string($name) || !is_array($definition)) {
                continue;
            }

            $methods[$name] = match ($definition['CFM'] ?? null) {
                'V2' => self::METHOD_RC4,
                'AESV2' => self::METHOD_AESV2,
                'AESV3' => self::METHOD_AESV3,
                'None' => self::METHOD_IDENTITY,
                default => throw new PdfException(sprintf('Unsupported crypt filter method: %s', (string) ($definition['CFM'] ?? null))),
            };
        }

        return $methods;
    }

    /**
     * @param array<string, mixed> $encryptDictionary
     * @return array<string, string>
     */
    private static function resolveCryptFilterAuthEvents(array $encryptDictionary): array
    {
        $cryptFilters = $encryptDictionary['CF'] ?? null;

        if (!is_array($cryptFilters)) {
            return [];
        }

        $events = [];

        foreach ($cryptFilters as $name => $definition) {
            if (!is_string($name) || !is_array($definition)) {
                continue;
            }

            $authEvent = $definition['AuthEvent'] ?? null;

            if (is_string($authEvent)) {
                $events[$name] = $authEvent;
            }
        }

        return $events;
    }

    /**
     * @param array<string, mixed> $encryptDictionary
     * @return array<string, int>
     */
    private static function resolveCryptFilterKeyLengthBits(array $encryptDictionary): array
    {
        $cryptFilters = $encryptDictionary['CF'] ?? null;

        if (!is_array($cryptFilters)) {
            return [];
        }

        $lengths = [];

        foreach ($cryptFilters as $name => $definition) {
            if (!is_string($name) || !is_array($definition)) {
                continue;
            }

            $length = $definition['Length'] ?? null;

            if (is_int($length)) {
                $lengths[$name] = $length;
            }
        }

        return $lengths;
    }

    /**
     * @param array<string, mixed> $encryptDictionary
     */
    private static function resolveEmbeddedFileFilterName(array $encryptDictionary, int $version): ?string
    {
        if ($version < 4) {
            return null;
        }

        $filterName = $encryptDictionary['EFF'] ?? null;

        if ($filterName === null) {
            return null;
        }

        if (!is_string($filterName)) {
            throw new PdfException('Encrypted PDF has an invalid embedded file crypt filter name.');
        }

        return $filterName;
    }

    /**
     * @param array<string, mixed> $encryptDictionary
     */
    private static function methodForCryptFilterName(array $encryptDictionary, mixed $filterName, string $key): string
    {
        if ($filterName === 'Identity') {
            return self::METHOD_IDENTITY;
        }

        $cryptFilters = $encryptDictionary['CF'] ?? null;

        if (!is_array($cryptFilters) || !is_array($cryptFilters[$filterName] ?? null)) {
            throw new PdfException(sprintf('Encrypted PDF is missing a valid %s crypt filter.', $key));
        }

        $method = $cryptFilters[$filterName]['CFM'] ?? null;

        return match ($method) {
            'V2' => self::METHOD_RC4,
            'AESV2' => self::METHOD_AESV2,
            'AESV3' => self::METHOD_AESV3,
            'None' => self::METHOD_IDENTITY,
            default => throw new PdfException(sprintf('Unsupported crypt filter method: %s', (string) $method)),
        };
    }

    private function objectKey(int $objectNumber, int $generationNumber, string $method): string
    {
        if ($method === self::METHOD_AESV3) {
            return $this->fileKey;
        }

        $keyMaterial = $this->fileKey
            . chr($objectNumber & 0xFF)
            . chr(($objectNumber >> 8) & 0xFF)
            . chr(($objectNumber >> 16) & 0xFF)
            . chr($generationNumber & 0xFF)
            . chr(($generationNumber >> 8) & 0xFF);

        if ($method === self::METHOD_AESV2) {
            $keyMaterial .= 'sAlT';
        }

        return substr(md5($keyMaterial, true), 0, min($this->keyLength + 5, 16));
    }

    private static function decryptAesV2(string $key, string $contents): string
    {
        if (!function_exists('openssl_decrypt')) {
            throw new PdfException('OpenSSL extension is required to decode AESV2 encrypted PDFs.');
        }

        if (strlen($contents) < 16) {
            throw new PdfException('AESV2-encrypted data is missing its initialization vector.');
        }

        $iv = substr($contents, 0, 16);
        $ciphertext = substr($contents, 16);
        $plaintext = openssl_decrypt($ciphertext, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if (!is_string($plaintext)) {
            throw new PdfException('Unable to decrypt AESV2-encrypted data.');
        }

        return $plaintext;
    }

    private static function decryptAesV3(string $key, string $contents): string
    {
        if (!function_exists('openssl_decrypt')) {
            throw new PdfException('OpenSSL extension is required to decode AESV3 encrypted PDFs.');
        }

        if (strlen($contents) < 16) {
            throw new PdfException('AESV3-encrypted data is missing its initialization vector.');
        }

        $iv = substr($contents, 0, 16);
        $ciphertext = substr($contents, 16);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if (!is_string($plaintext)) {
            throw new PdfException('Unable to decrypt AESV3-encrypted data.');
        }

        return $plaintext;
    }

    private static function decryptAes256ZeroIv(string $key, string $ciphertext, string $label): string
    {
        if (!function_exists('openssl_decrypt')) {
            throw new PdfException('OpenSSL extension is required to decode AESV3 encrypted PDFs.');
        }

        $plaintext = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, str_repeat("\x00", 16));

        if (!is_string($plaintext)) {
            throw new PdfException(sprintf('Unable to decrypt AESV3 %s encryption key.', $label));
        }

        return $plaintext;
    }

    private static function verifyAesV3Perms(string $perms, string $fileKey, int $permissions, bool $encryptMetadata): void
    {
        if (!function_exists('openssl_decrypt')) {
            throw new PdfException('OpenSSL extension is required to decode AESV3 encrypted PDFs.');
        }

        if (strlen($perms) !== 16) {
            throw new PdfException('Encrypted PDF has an invalid AESV3 permissions block.');
        }

        $plaintext = openssl_decrypt($perms, 'aes-256-ecb', $fileKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);

        if (!is_string($plaintext) || strlen($plaintext) !== 16) {
            throw new PdfException('Unable to decrypt AESV3 permissions block.');
        }

        $decodedPermissions = unpack('V', substr($plaintext, 0, 4));
        $permissionValue = $decodedPermissions[1] ?? null;

        if (!is_int($permissionValue)) {
            throw new PdfException('Encrypted PDF has an invalid AESV3 permissions block.');
        }

        $permissionValue = $permissionValue > 0x7FFFFFFF ? $permissionValue - 0x100000000 : $permissionValue;
        $metadataFlag = $plaintext[8] ?? '';
        $marker = substr($plaintext, 9, 3);

        if (
            $permissionValue !== $permissions
            || $metadataFlag !== ($encryptMetadata ? 'T' : 'F')
            || $marker !== 'adb'
        ) {
            throw new PdfException('Encrypted PDF has a tampered AESV3 permissions block.');
        }
    }

    /**
     * @return array{0: string, 1: PdfStream}
     */
    private function prepareStreamForDecryption(PdfStream $stream): array
    {
        $filters = $stream->dictionary['Filter'] ?? null;

        if ($filters === null) {
            return [$this->streamMethod, $stream];
        }

        $filterList = is_array($filters) ? $filters : [$filters];
        $decodeParams = $stream->dictionary['DecodeParms'] ?? null;
        $decodeParamList = is_array($decodeParams) && array_is_list($decodeParams)
            ? $decodeParams
            : array_fill(0, count($filterList), $decodeParams);
        $streamMethod = $this->streamMethod;
        $filtered = [];
        $filteredParams = [];
        $removedCrypt = false;

        foreach ($filterList as $index => $filter) {
            if ($filter !== 'Crypt') {
                $filtered[] = $filter;
                $filteredParams[] = $decodeParamList[$index] ?? null;
                continue;
            }

            $removedCrypt = true;
            $params = $decodeParamList[$index] ?? null;
            $cryptName = is_array($params) ? ($params['Name'] ?? null) : null;

            if ($cryptName === null) {
                $streamMethod = $this->streamMethod;
                continue;
            }

            if ($cryptName === 'Identity') {
                $streamMethod = self::METHOD_IDENTITY;
                continue;
            }

            if (!is_string($cryptName) || !isset($this->cryptFilterMethods[$cryptName])) {
                throw new PdfException(sprintf('Encrypted PDF references an unknown crypt filter: %s', (string) $cryptName));
            }

            $streamMethod = $this->cryptFilterMethods[$cryptName];
        }

        if (!$removedCrypt) {
            return [$this->streamMethod, $stream];
        }

        $dictionary = $stream->dictionary;

        if ($filtered === []) {
            unset($dictionary['Filter'], $dictionary['DecodeParms']);
        } else {
            $dictionary['Filter'] = count($filtered) === 1 ? $filtered[0] : $filtered;

            if (array_filter($filteredParams, static fn (mixed $value): bool => $value !== null) === []) {
                unset($dictionary['DecodeParms']);
            } else {
                $dictionary['DecodeParms'] = count($filtered) === 1 ? $filteredParams[0] : $filteredParams;
            }
        }

        return [$streamMethod, new PdfStream($dictionary, $stream->contents)];
    }
}
