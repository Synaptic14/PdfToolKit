<?php

declare(strict_types=1);

namespace PdfToolkit\Import;

use PdfToolkit\Writer\StandardPermissions;

final readonly class ImportSecurityInfo
{
    public function __construct(
        public string $filter,
        public int $version,
        public int $revision,
        public int $keyLengthBits,
        public int $permissions,
        public ?string $authenticatedAs,
        public bool $openedWithPassword,
        /** @var list<string> */
        public array $cryptFilterNames,
        /** @var array<string, string> */
        public array $cryptFilters,
        /** @var array<string, string> */
        public array $cryptFilterAuthEvents,
        /** @var array<string, int> */
        public array $cryptFilterKeyLengthBits,
        public ?string $embeddedFileFilterName,
        public string $stringFilterName,
        public string $streamFilterName,
        public ?string $embeddedFileMethod,
        public string $stringMethod,
        public string $streamMethod,
        public bool $encryptMetadata,
    ) {
    }

    public function authenticatedAsUser(): bool
    {
        return $this->authenticatedAs === 'user';
    }

    public function authenticatedAsOwner(): bool
    {
        return $this->authenticatedAs === 'owner';
    }

    public function openedWithoutPassword(): bool
    {
        return !$this->openedWithPassword;
    }

    public function stringsEncrypted(): bool
    {
        return $this->stringMethod !== 'Identity';
    }

    public function streamsEncrypted(): bool
    {
        return $this->streamMethod !== 'Identity';
    }

    public function usesIdentityStringFilter(): bool
    {
        return $this->stringFilterName === 'Identity';
    }

    public function usesIdentityStreamFilter(): bool
    {
        return $this->streamFilterName === 'Identity';
    }

    public function usesNoOpStringFilter(): bool
    {
        return !$this->stringsEncrypted();
    }

    public function usesNoOpStreamFilter(): bool
    {
        return !$this->streamsEncrypted();
    }

    public function usesNamedNoOpStringFilter(): bool
    {
        return $this->usesNoOpStringFilter() && !$this->usesIdentityStringFilter();
    }

    public function usesNamedNoOpStreamFilter(): bool
    {
        return $this->usesNoOpStreamFilter() && !$this->usesIdentityStreamFilter();
    }

    public function usesNoOpFilters(): bool
    {
        return $this->usesNoOpStringFilter() || $this->usesNoOpStreamFilter();
    }

    public function usesNamedNoOpFilters(): bool
    {
        return $this->usesNamedNoOpStringFilter() || $this->usesNamedNoOpStreamFilter();
    }

    public function usesCryptFilters(): bool
    {
        return $this->cryptFilterNames !== [];
    }

    public function usesCustomNamedCryptFilters(): bool
    {
        foreach ($this->cryptFilterNames as $name) {
            if ($name !== 'StdCF') {
                return true;
            }
        }

        return false;
    }

    public function definesCryptFilter(string $name): bool
    {
        return array_key_exists($name, $this->cryptFilters);
    }

    public function cryptFilterMethod(string $name): ?string
    {
        return $this->cryptFilters[$name] ?? null;
    }

    public function cryptFilterAuthEvent(string $name): ?string
    {
        return $this->cryptFilterAuthEvents[$name] ?? null;
    }

    public function usesDocOpenAuthEvent(string $name): bool
    {
        return $this->cryptFilterAuthEvent($name) === 'DocOpen';
    }

    public function usesEfOpenAuthEvent(string $name): bool
    {
        return $this->cryptFilterAuthEvent($name) === 'EFOpen';
    }

    public function cryptFilterKeyLengthBits(string $name): ?int
    {
        return $this->cryptFilterKeyLengthBits[$name] ?? null;
    }

    public function usesEmbeddedFileCryptFilter(): bool
    {
        return $this->embeddedFileFilterName !== null;
    }

    public function usesExplicitEmbeddedFileCryptFilter(): bool
    {
        return $this->embeddedFileFilterName !== null;
    }

    public function usesInheritedEmbeddedFileFilter(): bool
    {
        return $this->embeddedFileFilterName === null;
    }

    public function effectiveEmbeddedFileFilterName(): string
    {
        return $this->embeddedFileFilterName ?? $this->streamFilterName;
    }

    public function effectiveEmbeddedFileMethod(): string
    {
        return $this->embeddedFileMethod ?? $this->streamMethod;
    }

    public function usesIdentityEmbeddedFileFilter(): bool
    {
        return $this->effectiveEmbeddedFileFilterName() === 'Identity';
    }

    public function usesDefaultEmbeddedFileCryptFilter(): bool
    {
        return $this->effectiveEmbeddedFileFilterName() === 'StdCF'
            && $this->effectiveEmbeddedFileMethod() !== 'Identity';
    }

    public function usesInheritedDefaultEmbeddedFileCryptFilter(): bool
    {
        return $this->usesInheritedEmbeddedFileFilter() && $this->usesDefaultEmbeddedFileCryptFilter();
    }

    public function usesExplicitDefaultEmbeddedFileCryptFilter(): bool
    {
        return $this->embeddedFileFilterName === 'StdCF'
            && $this->embeddedFileMethod !== 'Identity';
    }

    public function usesDefaultRevision4EmbeddedFileCryptFilter(): bool
    {
        return $this->revision === 4 && $this->usesDefaultEmbeddedFileCryptFilter();
    }

    public function usesDefaultRevision5EmbeddedFileCryptFilter(): bool
    {
        return $this->revision === 5 && $this->usesDefaultEmbeddedFileCryptFilter();
    }

    public function embeddedFilesEncrypted(): bool
    {
        return $this->effectiveEmbeddedFileMethod() !== 'Identity';
    }

    public function usesNoOpEmbeddedFileFilter(): bool
    {
        return $this->effectiveEmbeddedFileMethod() === 'Identity';
    }

    public function usesNamedNoOpEmbeddedFileFilter(): bool
    {
        return $this->usesNoOpEmbeddedFileFilter() && $this->effectiveEmbeddedFileFilterName() !== 'Identity';
    }

    public function embeddedFileAuthEvent(): ?string
    {
        $name = $this->embeddedFileFilterName ?? $this->streamFilterName;

        if ($name === 'Identity') {
            return null;
        }

        return $this->cryptFilterAuthEvent($name);
    }

    public function usesEmbeddedFileDocOpenAuthEvent(): bool
    {
        return $this->embeddedFileAuthEvent() === 'DocOpen';
    }

    public function usesEmbeddedFileEfOpenAuthEvent(): bool
    {
        return $this->embeddedFileAuthEvent() === 'EFOpen';
    }

    public function embeddedFileKeyLengthBits(): ?int
    {
        $name = $this->embeddedFileFilterName ?? $this->streamFilterName;

        if ($name === 'Identity') {
            return null;
        }

        return $this->cryptFilterKeyLengthBits($name);
    }

    public function embeddedFileFilterMatchesStringFilter(): bool
    {
        return $this->effectiveEmbeddedFileFilterName() === $this->stringFilterName;
    }

    public function embeddedFileFilterMatchesStreamFilter(): bool
    {
        return $this->effectiveEmbeddedFileFilterName() === $this->streamFilterName;
    }

    public function hasDistinctEmbeddedFileCryptFilter(): bool
    {
        return !$this->embeddedFileFilterMatchesStringFilter()
            && !$this->embeddedFileFilterMatchesStreamFilter();
    }

    public function embeddedFileAlgorithm(): ?string
    {
        return $this->effectiveEmbeddedFileMethod();
    }

    public function embeddedFileAlgorithmSummary(): string
    {
        if ($this->usesInheritedEmbeddedFileFilter()) {
            return 'Inherited ' . $this->effectiveEmbeddedFileMethod();
        }

        if ($this->embeddedFileMethod === null) {
            return 'Unknown';
        }

        return $this->embeddedFileMethod;
    }

    public function usesLegacyStandardFilters(): bool
    {
        return $this->cryptFilterNames === []
            && $this->stringFilterName === 'Standard'
            && $this->streamFilterName === 'Standard';
    }

    public function usesDefaultStandardCryptFilters(): bool
    {
        return $this->cryptFilterNames === ['StdCF']
            && $this->stringFilterName === 'StdCF'
            && $this->streamFilterName === 'StdCF';
    }

    public function usesDefaultRevision4CryptFilters(): bool
    {
        return $this->revision === 4 && $this->usesDefaultStandardCryptFilters();
    }

    public function usesDefaultRevision5CryptFilters(): bool
    {
        return $this->revision === 5 && $this->usesDefaultStandardCryptFilters();
    }

    public function usesCustomCryptFilterConfiguration(): bool
    {
        return !$this->usesLegacyStandardFilters() && !$this->usesDefaultStandardCryptFilters();
    }

    public function hasMixedCryptFilters(): bool
    {
        return $this->stringFilterName !== $this->streamFilterName;
    }

    public function hasMixedCryptMethods(): bool
    {
        return $this->stringMethod !== $this->streamMethod;
    }

    public function isFullyNoOpEncrypted(): bool
    {
        return $this->usesNoOpStringFilter() && $this->usesNoOpStreamFilter();
    }

    public function usesRc4(): bool
    {
        return $this->stringMethod === 'RC4' || $this->streamMethod === 'RC4';
    }

    public function usesAes(): bool
    {
        return in_array($this->stringMethod, ['AESV2', 'AESV3'], true)
            || in_array($this->streamMethod, ['AESV2', 'AESV3'], true);
    }

    public function algorithm(): string
    {
        if ($this->stringMethod === 'AESV3' || $this->streamMethod === 'AESV3') {
            return 'AESV3';
        }

        if ($this->usesAes()) {
            return 'AESV2';
        }

        if ($this->usesRc4()) {
            return 'RC4';
        }

        return 'Identity';
    }

    public function algorithmSummary(): string
    {
        if ($this->hasMixedCryptMethods()) {
            return 'Mixed';
        }

        return $this->algorithm();
    }

    public function isPasswordProtected(): bool
    {
        return $this->filter === 'Standard';
    }

    public function isEffectivelyEncrypted(): bool
    {
        return $this->stringsEncrypted() || $this->streamsEncrypted();
    }

    public function isLegacy40Bit(): bool
    {
        return $this->keyLengthBits <= 40;
    }

    public function uses128BitKeys(): bool
    {
        return $this->keyLengthBits >= 128;
    }

    public function usesIdentityFilters(): bool
    {
        return $this->usesIdentityStringFilter() || $this->usesIdentityStreamFilter();
    }

    public function hasMixedStringAndStreamEncryption(): bool
    {
        return $this->stringsEncrypted() !== $this->streamsEncrypted();
    }

    public function allowsAllPermissions(): bool
    {
        if ($this->revision >= 3) {
            return $this->permissions === StandardPermissions::all(4);
        }

        return $this->permissions === StandardPermissions::all(2);
    }

    public function hasRestrictedPermissions(): bool
    {
        return !$this->allowsAllPermissions();
    }

    public function allowsPrint(): bool
    {
        return $this->allows(StandardPermissions::PRINT);
    }

    public function allowsModify(): bool
    {
        return $this->allows(StandardPermissions::MODIFY);
    }

    public function allowsCopy(): bool
    {
        return $this->allows(StandardPermissions::COPY);
    }

    public function allowsAnnotate(): bool
    {
        return $this->allows(StandardPermissions::ANNOTATE);
    }

    public function allowsFillForms(): bool
    {
        return $this->revision >= 3 && $this->allows(StandardPermissions::FILL_FORMS);
    }

    public function allowsAccessibility(): bool
    {
        return $this->revision >= 3 && $this->allows(StandardPermissions::ACCESSIBILITY);
    }

    public function allowsAssemble(): bool
    {
        return $this->revision >= 3 && $this->allows(StandardPermissions::ASSEMBLE);
    }

    public function allowsHighQualityPrint(): bool
    {
        return $this->revision >= 3 && $this->allows(StandardPermissions::HIGH_QUALITY_PRINT);
    }

    private function allows(int $permission): bool
    {
        $mask = $this->permissions < 0 ? $this->permissions + 0x100000000 : $this->permissions;

        return ($mask & $permission) === $permission;
    }
}
