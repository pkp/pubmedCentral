<?php

/**
 * @file classes/ImportedHistory.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ImportedHistory
 *
 * @brief The history of articles indexed in PMC that another platform delivered, as
 *  imported by the importHistory command (see DeliveryHistoryImporter).
 *
 * For articles with imported history only. Only the importer, and deliveries of the
 * articles it imported, set these settings, so for a journal that has not imported a
 * history they are never set.
 */

namespace APP\plugins\generic\pubmedCentral\classes;

use APP\publication\enums\VersionStage;
use APP\publication\Publication;
use APP\submission\Submission;

class ImportedHistory
{
    /** Of a submission: the name its packages begin with, which every later version continues */
    public const SETTING_PACKAGE_PREFIX = 'pubmedCentral::packagePrefix';

    /** Of a submission: the highest version number indexed in PMC, including versions with no publication here */
    public const SETTING_LAST_VERSION = 'pubmedCentral::lastVersion';

    /** Of a publication: the version number the other platform delivered it as, which marks it as imported */
    public const SETTING_VERSION = 'pubmedCentral::version';

    /** Of a publication: the stem the other platform named the files of its version from */
    public const SETTING_FILE_STEM = 'pubmedCentral::fileStem';

    /** Of a publication: the highest revision delivered of its version, 0 for its first package */
    public const SETTING_REVISION = 'pubmedCentral::revision';

    public const SETTINGS = [
        self::SETTING_PACKAGE_PREFIX,
        self::SETTING_LAST_VERSION,
        self::SETTING_VERSION,
        self::SETTING_FILE_STEM,
        self::SETTING_REVISION,
    ];

    /**
     * The name an article's packages begin with, if the other platform established one.
     */
    public static function packagePrefix(?Submission $submission): ?string
    {
        return $submission?->getData(self::SETTING_PACKAGE_PREFIX) ?: null;
    }

    /**
     * The version number another platform delivered a publication as, or 0 if it was not imported.
     */
    public static function version(Publication $publication): int
    {
        return (int) $publication->getData(self::SETTING_VERSION);
    }

    /**
     * The stem the other platform named a version's files from, if it was imported with one.
     */
    public static function fileStem(Publication $publication): ?string
    {
        return $publication->getData(self::SETTING_FILE_STEM) ?: null;
    }

    /**
     * How far an article's version numbers in PMC run ahead of its major versions: the
     * highest version indexed in PMC, less the highest major version of record imported.
     * The other platform can have numbered versions that are not here, and versions under
     * review in the same series, which OJS numbers separately.
     */
    public static function versionOffset(?Submission $submission): int
    {
        if (!$lastVersion = (int) $submission?->getData(self::SETTING_LAST_VERSION)) {
            return 0;
        }

        $highestImported = 0;
        foreach ($submission->getData('publications') ?? [] as $publication) { /** @var Publication $publication */
            if (
                self::version($publication) &&
                $publication->getData('versionStage') === VersionStage::VERSION_OF_RECORD->value
            ) {
                $highestImported = max($highestImported, (int) $publication->getData('versionMajor'));
            }
        }

        return $lastVersion - $highestImported;
    }

    /**
     * Whether a version the other platform delivered is missing what a revision of it
     * must carry over: its file stem, or the last revision PMC received of it.
     */
    public static function isIncomplete(Publication $publication): bool
    {
        if (!self::version($publication)) {
            return false;
        }

        return !self::fileStem($publication) || ($publication->getData(self::SETTING_REVISION) ?? '') === '';
    }
}
