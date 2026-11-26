<?php

/**
 * @file classes/DeliveryHistoryImporter.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class DeliveryHistoryImporter
 *
 * @brief Imports the history of articles indexed in PMC that another platform delivered, so that
 *  deliveries from OJS continue their package names, version numbers and revisions.
 *
 * The file is a CSV with one row per version indexed in PMC or sent to it:
 *
 *  doi             The version's DOI, which finds its publication
 *  package_prefix  The name its packages begin with, e.g. "openreseurope-4-openreseurope.4-83"
 *  version         The version number it is indexed under in PMC
 *  last_revision   The highest revision delivered of it, 0 for none, blank if unknown
 *  file_stem       The stem its files were named from, e.g. "openreseurope-4-27633", blank if unknown
 *  in_pmc          Y where it is indexed in PMC, N where its packages were all rejected
 *
 * A file may be imported again, with more complete or more recent rows: a value
 * recorded by a delivery from OJS is never replaced, and a revision never goes back.
 */

namespace APP\plugins\generic\pubmedCentral\classes;

use APP\facades\Repo;
use APP\plugins\generic\pubmedCentral\PubmedCentralExportPlugin;
use APP\plugins\PubObjectsExportPlugin;
use APP\publication\Publication;
use APP\submission\Submission;
use Illuminate\Support\Facades\DB;
use PKP\context\Context;

class DeliveryHistoryImporter
{
    public const COLUMNS = ['doi', 'package_prefix', 'version', 'last_revision', 'file_stem', 'in_pmc'];

    /** Package names and file stems as PMC accepts them, which have no spaces or special characters */
    protected const NAME_PATTERN = '/^[a-z0-9][a-z0-9.-]*$/i';

    /**
     * What the import found, by kind: each entry an array of line, doi, message key and params.
     *
     * @var array<string, array<array{line: int, doi: string, key: ?string, params: array}>>
     */
    protected array $report = [];

    /** @var array<int, Submission> */
    protected array $submissions = [];

    /** @var array<string, ?array{Submission, Publication[]}> Publications found, by lowercased DOI */
    protected array $found = [];

    public function __construct(
        protected PubmedCentralExportPlugin $plugin,
        protected Context $context
    ) {
    }

    /**
     * Import a file, or with $dryRun, only report what importing it would do.
     *
     * @return array{report: array, publications: int, submissions: int, refused: ?string}
     *  refused is the message key for why nothing could be imported, if so
     */
    public function import(string $path, bool $dryRun = false): array
    {
        $this->report = [];
        $this->submissions = [];

        // Without DOI versioning every version of an article shares one DOI, which cannot
        // say which version a row is, and deposit statuses are kept on submissions rather
        // than the publications the history is recorded on
        if (!$this->context->getData(Context::SETTING_DOI_VERSIONING)) {
            return [
                'report' => [],
                'publications' => 0,
                'submissions' => 0,
                'refused' => 'plugins.importexport.pmc.cli.importHistory.doiVersioningRequired',
            ];
        }

        $rows = $this->readRows($path);

        // Every version imported counts towards the article's highest version indexed in
        // PMC, including those with no publication here, such as versions under review
        // that were not migrated. Their package prefix places them with their article.
        $articleRows = [];
        $prefixes = [];
        $publicationParams = [];

        // An article's package prefix only has to be given in one of its rows
        $prefixed = [];
        foreach ($rows as $row) {
            [$submission] = $this->findPublications($row['doi']) ?? [null];
            if ($submission && ($row['package_prefix'] !== '' || ImportedHistory::packagePrefix($submission))) {
                $prefixed[$submission->getId()] = true;
            }
        }

        foreach ($rows as $row) {
            [$submission, $publications] = $this->findPublications($row['doi']) ?? [null, []];
            if (!$publications) {
                continue;
            }
            $this->submissions[$submission->getId()] = $submission;
            if ($row['package_prefix'] !== '') {
                $prefixes[strtolower($row['package_prefix'])] = $submission->getId();
            }

            // Versions deposited before DOI versioning was enabled can share one DOI
            $majorVersions = array_unique(array_map(fn (Publication $publication) => (int) $publication->getData('versionMajor'), $publications));
            if (count($majorVersions) > 1) {
                sort($majorVersions);
                $this->addToReport('conflicts', $row, 'plugins.importexport.pmc.cli.importHistory.conflict.sharedDoi', [
                    'versions' => implode(', ', $majorVersions),
                ]);
                continue;
            }

            // A row skipped or in conflict is not imported for any of its publications
            $rowParams = [];
            foreach ($publications as $publication) {
                $params = $this->publicationParams($row, $publication, isset($prefixed[$submission->getId()]));
                if ($params === null) {
                    continue 2;
                }
                if ($params) {
                    $rowParams[] = [$publication, $params];
                }
            }
            array_push($publicationParams, ...$rowParams);
            $articleRows[$submission->getId()][] = $row;
        }

        foreach ($rows as $row) {
            if ($this->findPublications($row['doi'])) {
                continue;
            }
            $submissionId = $prefixes[strtolower($row['package_prefix'])] ?? null;
            if ($submissionId) {
                $articleRows[$submissionId][] = $row;
            } else {
                $this->addToReport('notFound', $row);
            }
        }

        $submissionParams = [];
        foreach ($articleRows as $submissionId => $submissionRows) {
            if ($params = $this->submissionParams($submissionRows, $this->submissions[$submissionId])) {
                $submissionParams[] = [$this->submissions[$submissionId], $params];
            }
        }

        if (!$dryRun) {
            foreach ([...$publicationParams, ...$submissionParams] as [$object, $params]) {
                $this->save($object, $params);
            }
        }

        return [
            'report' => $this->report,
            'publications' => count($publicationParams),
            'submissions' => count($submissionParams),
            'refused' => null,
        ];
    }

    /**
     * Read and check the file's rows, reporting any that cannot be imported.
     *
     * @return array<array{line: int, doi: string, package_prefix: string, version: int, last_revision: string, file_stem: string, in_pmc: bool}>
     */
    protected function readRows(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = array_map(fn ($column) => strtolower(trim((string) $column)), fgetcsv($handle, escape: '') ?: []);
        // A spreadsheet program can begin the file with a byte order mark
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0] ?? '');
        if ($missing = array_diff(self::COLUMNS, $header)) {
            fclose($handle);
            $this->addToReport('invalid', ['line' => 1, 'doi' => ''], 'plugins.importexport.pmc.cli.importHistory.invalidHeader', [
                'columns' => implode(', ', $missing),
            ]);
            return [];
        }

        $rows = [];
        $line = 1;
        while (($values = fgetcsv($handle, escape: '')) !== false) {
            $line++;
            if ($values === [null]) {
                continue;
            }
            $row = ['line' => $line] + array_map('trim', array_combine($header, array_slice(array_pad($values, count($header), ''), 0, count($header))));
            if ($column = $this->validateRow($row)) {
                $this->addToReport('invalid', $row, 'plugins.importexport.pmc.cli.importHistory.invalid.value', ['column' => $column]);
                continue;
            }
            $row['version'] = (int) $row['version'];
            $row['in_pmc'] = strtoupper($row['in_pmc']) === 'Y';
            $rows[] = $row;
        }
        fclose($handle);

        // One row per package, as PMC's reports list them, would overwrite each other
        $counts = array_count_values(array_map(fn ($row) => strtolower($row['doi']), $rows));
        return array_values(array_filter($rows, function ($row) use ($counts) {
            if ($counts[strtolower($row['doi'])] > 1) {
                $this->addToReport('invalid', $row, 'plugins.importexport.pmc.cli.importHistory.invalid.duplicate');
                return false;
            }
            return true;
        }));
    }

    /**
     * @return ?string The first column the row has a missing or invalid value in, if any
     */
    protected function validateRow(array $row): ?string
    {
        return match (true) {
            $row['doi'] === '' => 'doi',
            !ctype_digit($row['version']) || (int) $row['version'] < 1 => 'version',
            $row['last_revision'] !== '' && !ctype_digit($row['last_revision']) => 'last_revision',
            $row['package_prefix'] !== '' && !preg_match(self::NAME_PATTERN, $row['package_prefix']) => 'package_prefix',
            $row['file_stem'] !== '' && !preg_match(self::NAME_PATTERN, $row['file_stem']) => 'file_stem',
            !in_array(strtoupper($row['in_pmc']), ['Y', 'N']) => 'in_pmc',
            default => null,
        };
    }

    /**
     * The settings to save on a version's publication, or null if the row is not imported.
     */
    protected function publicationParams(array $row, Publication $publication, bool $articleHasPrefix): ?array
    {
        $plugin = $this->plugin;
        $existingVersion = ImportedHistory::version($publication);
        $existingStem = (string) $publication->getData(ImportedHistory::SETTING_FILE_STEM);
        $existingRevision = $publication->getData(ImportedHistory::SETTING_REVISION) ?? '';

        // Delivered from here since the last import, so what it records is newer
        if ($existingRevision !== '' && !$existingVersion) {
            $this->addToReport('skipped', $row, 'plugins.importexport.pmc.cli.importHistory.deliveredFromOjs');
            return null;
        }
        if ($existingVersion && $existingVersion !== $row['version']) {
            $this->addToReport('conflicts', $row, 'plugins.importexport.pmc.cli.importHistory.conflict.version', [
                'existing' => $existingVersion,
            ]);
            return null;
        }
        if ($existingStem !== '' && $row['file_stem'] !== '' && strcasecmp($existingStem, $row['file_stem']) !== 0) {
            $this->addToReport('conflicts', $row, 'plugins.importexport.pmc.cli.importHistory.conflict.stem', [
                'existing' => $existingStem,
            ]);
            return null;
        }

        $params = [ImportedHistory::SETTING_VERSION => (string) $row['version']];
        if ($existingStem === '' && $row['file_stem'] !== '') {
            $params[ImportedHistory::SETTING_FILE_STEM] = strtolower($row['file_stem']);
        }
        if ($row['last_revision'] !== '') {
            $params[ImportedHistory::SETTING_REVISION] = (string) max((int) $row['last_revision'], (int) $existingRevision);
        }

        // Left as it is where a publication has since been marked stale, to be delivered again
        $status = $publication->getData($plugin->getDepositStatusSettingName());
        if ($row['in_pmc'] && in_array($status, [null, '', PubObjectsExportPlugin::EXPORT_STATUS_NOT_DEPOSITED])) {
            $params[$plugin->getDepositStatusSettingName()] = PubObjectsExportPlugin::EXPORT_STATUS_MARKEDREGISTERED;
        }

        if (!$row['in_pmc']) {
            $this->addToReport('notInPmc', $row);
        }
        $missing = array_keys(array_filter([
            'file_stem' => ($params[ImportedHistory::SETTING_FILE_STEM] ?? $existingStem) === '',
            'last_revision' => ($params[ImportedHistory::SETTING_REVISION] ?? $existingRevision) === '',
            'package_prefix' => !$articleHasPrefix,
        ]));
        if ($missing) {
            $this->addToReport('incomplete', $row, 'plugins.importexport.pmc.cli.importHistory.missing', [
                'columns' => implode(', ', $missing),
            ]);
        }

        // Nothing to save where an earlier import already recorded all of it
        return array_filter(
            $params,
            fn ($value, $name) => (string) $publication->getData($name) !== $value,
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * The settings to save on an article's submission, from the rows of all its versions.
     *
     * @param array[] $rows
     */
    protected function submissionParams(array $rows, Submission $submission): array
    {
        // Compared without case, but saved as written, so later packages match the earlier ones.
        $prefixes = [];
        foreach ($rows as $row) {
            if ($row['package_prefix'] !== '') {
                $prefixes[strtolower($row['package_prefix'])] ??= $row['package_prefix'];
            }
        }
        $existingPrefix = (string) $submission->getData(ImportedHistory::SETTING_PACKAGE_PREFIX);
        $params = [];

        if (count($prefixes) > 1 || ($existingPrefix !== '' && $prefixes && array_key_first($prefixes) !== strtolower($existingPrefix))) {
            $this->addToReport('conflicts', $rows[0], 'plugins.importexport.pmc.cli.importHistory.conflict.prefix', [
                'prefixes' => implode(', ', array_unique(array_filter([$existingPrefix, ...array_values($prefixes)]))),
            ]);
        } elseif ($prefixes && $existingPrefix === '') {
            $params[ImportedHistory::SETTING_PACKAGE_PREFIX] = reset($prefixes);
        }

        $highest = max(array_map(fn ($row) => $row['version'], $rows));
        if ($highest > (int) $submission->getData(ImportedHistory::SETTING_LAST_VERSION)) {
            $params[ImportedHistory::SETTING_LAST_VERSION] = (string) $highest;
        }

        return $params;
    }

    /**
     * The publications a version's DOI belongs to in the journal, with their submission.
     * A version's minor versions share its DOI, and correct it, so each takes its history.
     *
     * @return ?array{Submission, Publication[]}
     */
    protected function findPublications(string $doi): ?array
    {
        $key = strtolower($doi);
        if (!array_key_exists($key, $this->found)) {
            $submission = $this->findSubmission($doi);
            $publications = $submission?->getData('publications')
                ->filter(fn (Publication $publication) => strcasecmp((string) $publication->getDoi(), $doi) === 0)
                ->values()
                ->all();
            $this->found[$key] = $publications ? [$submission, $publications] : null;
        }

        return $this->found[$key];
    }

    /**
     * The journal's article with a version that has this DOI, ignoring letter case as DOIs do.
     */
    protected function findSubmission(string $doi): ?Submission
    {
        $submissionId = DB::table('publications AS p')
            ->join('submissions AS s', 's.submission_id', '=', 'p.submission_id')
            ->join('dois AS d', 'd.doi_id', '=', 'p.doi_id')
            ->where('s.context_id', '=', $this->context->getId())
            ->whereRaw('LOWER(d.doi) = LOWER(?)', [$doi])
            ->value('p.submission_id');

        return $submissionId ? Repo::submission()->get((int) $submissionId) : null;
    }

    protected function save(Submission|Publication $object, array $params): void
    {
        $object instanceof Publication
            ? Repo::publication()->edit($object, $params)
            : Repo::submission()->edit($object, $params);
    }

    /**
     * Report something about a row, once however many publications share its DOI.
     */
    protected function addToReport(string $kind, array $row, ?string $key = null, array $params = []): void
    {
        $entry = ['line' => $row['line'], 'doi' => $row['doi'], 'key' => $key, 'params' => $params];
        if (!in_array($entry, $this->report[$kind] ?? [])) {
            $this->report[$kind][] = $entry;
        }
    }
}
