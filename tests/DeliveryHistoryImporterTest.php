<?php

/**
 * @file tests/DeliveryHistoryImporterTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Unit tests for importing the history of articles indexed in PMC that another platform delivered.
 */

namespace APP\plugins\generic\pubmedCentral\tests;

use APP\journal\Journal;
use APP\plugins\generic\pubmedCentral\classes\DeliveryHistoryImporter;
use APP\plugins\generic\pubmedCentral\classes\ImportedHistory;
use APP\plugins\generic\pubmedCentral\PubmedCentralExportPlugin;
use APP\plugins\PubObjectsExportPlugin;
use APP\publication\Publication;
use APP\submission\Submission;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\context\Context;
use PKP\tests\PKPTestCase;

#[CoversClass(DeliveryHistoryImporter::class)]
class DeliveryHistoryImporterTest extends PKPTestCase
{
    private const HEADER = "doi,package_prefix,version,last_revision,file_stem,in_pmc\n";
    private const PREFIX = 'openreseurope-4-openreseurope.4-83';

    private array $paths = [];

    protected function tearDown(): void
    {
        array_map('unlink', $this->paths);
        parent::tearDown();
    }

    private function csv(string $rows, string $header = self::HEADER): string
    {
        $path = tempnam(sys_get_temp_dir(), 'PmcHistoryTest_');
        file_put_contents($path, $header . $rows);
        $this->paths[] = $path;
        return $path;
    }

    private function createVersion(int $id, string $doi, array $data = []): Publication
    {
        $publication = new Publication();
        $publication->setId($id);
        $publication->setData('submissionId', 5);
        $publication->setData('pmcTestDoi', $doi);
        foreach ($data as $name => $value) {
            $publication->setData($name, $value);
        }
        return $publication;
    }

    private function createArticle(array $data = []): Submission
    {
        $submission = new Submission();
        $submission->setAllData($data + ['id' => 5]);
        return $submission;
    }

    /**
     * Build an importer finding the given publications by DOI and recording each save in $saved.
     *
     * @param Publication[] $publications
     */
    private function createImporter(
        Submission $submission,
        array $publications,
        array &$saved = [],
        bool $doiVersioning = true
    ): DeliveryHistoryImporter {
        $plugin = $this->getMockBuilder(PubmedCentralExportPlugin::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $journal = new Journal();
        $journal->setId(1);
        $journal->setData(Context::SETTING_DOI_VERSIONING, $doiVersioning);

        return new class ($plugin, $journal, $submission, $publications, $saved) extends DeliveryHistoryImporter {
            public function __construct($plugin, $context, private Submission $submission, private array $publications, private array &$saved)
            {
                parent::__construct($plugin, $context);
            }

            protected function findPublications(string $doi): ?array
            {
                $found = array_values(array_filter(
                    $this->publications,
                    fn (Publication $publication) => $publication->getData('pmcTestDoi') === $doi
                ));
                return $found ? [$this->submission, $found] : null;
            }

            protected function save(Submission|Publication $object, array $params): void
            {
                $this->saved[] = [$object, $params];
            }
        };
    }

    /**
     * @return array<string, string[]> The DOIs reported, by kind
     */
    private function reported(array $result): array
    {
        return array_map(fn ($entries) => array_column($entries, 'doi'), $result['report']);
    }

    public function testImportRecordsWhatIsIndexedInPmc(): void
    {
        $v1 = $this->createVersion(1, '10.1/a.1');
        $v2 = $this->createVersion(2, '10.1/a.2');
        $submission = $this->createArticle();
        $saved = [];
        $importer = $this->createImporter($submission, [$v1, $v2], $saved);

        $result = $importer->import($this->csv(
            '10.1/a.1,' . self::PREFIX . ",1,0,openreseurope-4-18594,Y\n" .
            '10.1/a.2,' . self::PREFIX . ",2,3,openreseurope-4-20243,Y\n"
        ));

        $this->assertSame([], $result['report']);
        $this->assertSame(2, $result['publications']);
        $this->assertSame(1, $result['submissions']);
        $this->assertSame([
            [$v1, [
                ImportedHistory::SETTING_VERSION => '1',
                ImportedHistory::SETTING_FILE_STEM => 'openreseurope-4-18594',
                ImportedHistory::SETTING_REVISION => '0',
                'pubmedCentral::status' => PubObjectsExportPlugin::EXPORT_STATUS_MARKEDREGISTERED,
            ]],
            [$v2, [
                ImportedHistory::SETTING_VERSION => '2',
                ImportedHistory::SETTING_FILE_STEM => 'openreseurope-4-20243',
                ImportedHistory::SETTING_REVISION => '3',
                'pubmedCentral::status' => PubObjectsExportPlugin::EXPORT_STATUS_MARKEDREGISTERED,
            ]],
            [$submission, [
                ImportedHistory::SETTING_PACKAGE_PREFIX => self::PREFIX,
                ImportedHistory::SETTING_LAST_VERSION => '2',
            ]],
        ], $saved);
    }

    /**
     * A version with no publication here, such as one under review that was not migrated,
     * still counts towards the article's highest version through its package prefix.
     */
    public function testImportCountsVersionsWithNoPublicationByTheirPrefix(): void
    {
        $v2 = $this->createVersion(2, '10.1/a.2');
        $submission = $this->createArticle();
        $saved = [];
        $importer = $this->createImporter($submission, [$v2], $saved);

        $result = $importer->import($this->csv(
            '10.1/a.1,' . self::PREFIX . ",1,0,openreseurope-4-18594,Y\n" .
            '10.1/a.2,' . self::PREFIX . ",2,0,openreseurope-4-20243,Y\n" .
            '10.1/a.3,' . self::PREFIX . ",3,0,openreseurope-4-21000,Y\n" .
            "10.1/b.1,openreseurope-4-openreseurope.4-99,1,0,openreseurope-4-30000,Y\n"
        ));

        $this->assertSame(['notFound' => ['10.1/b.1']], $this->reported($result));
        $this->assertSame([
            ImportedHistory::SETTING_PACKAGE_PREFIX => self::PREFIX,
            ImportedHistory::SETTING_LAST_VERSION => '3',
        ], end($saved)[1]);
    }

    /**
     * A prefix is matched without case, but saved as written, so that later packages
     * match the earlier ones.
     */
    public function testImportKeepsThePrefixAsWritten(): void
    {
        $prefix = 'Jtest-4-Jtest.4-83';
        $v1 = $this->createVersion(1, '10.1/a.1');
        $submission = $this->createArticle();
        $saved = [];
        $importer = $this->createImporter($submission, [$v1], $saved);

        $result = $importer->import($this->csv(
            "10.1/a.1,{$prefix},1,0,jtest-4-18594,Y\n" .
            '10.1/a.2,' . strtolower($prefix) . ",2,0,jtest-4-20243,Y\n"
        ));

        $this->assertSame([], $this->reported($result));
        $this->assertSame($prefix, end($saved)[1][ImportedHistory::SETTING_PACKAGE_PREFIX]);
    }

    /**
     * Importing a file again only adds what is new: a revision never goes back, and a
     * publication delivered from OJS since keeps what that delivery recorded.
     */
    public function testImportAgainOnlyAddsWhatIsNew(): void
    {
        $recorded = [
            ImportedHistory::SETTING_VERSION => '1',
            ImportedHistory::SETTING_FILE_STEM => 'openreseurope-4-18594',
            ImportedHistory::SETTING_REVISION => '4',
            'pubmedCentral::status' => PubObjectsExportPlugin::EXPORT_STATUS_REGISTERED,
        ];
        $v1 = $this->createVersion(1, '10.1/a.1', $recorded);
        $v2 = $this->createVersion(2, '10.1/a.2', [ImportedHistory::SETTING_REVISION => '0']);
        $submission = $this->createArticle([
            ImportedHistory::SETTING_PACKAGE_PREFIX => self::PREFIX,
            ImportedHistory::SETTING_LAST_VERSION => '2',
        ]);
        $saved = [];
        $importer = $this->createImporter($submission, [$v1, $v2], $saved);

        $result = $importer->import($this->csv(
            '10.1/a.1,' . self::PREFIX . ",1,2,openreseurope-4-18594,Y\n" .
            '10.1/a.2,' . self::PREFIX . ",2,1,openreseurope-4-20243,Y\n"
        ));

        $this->assertSame(['skipped' => ['10.1/a.2']], $this->reported($result));
        $this->assertSame([], $saved);
    }

    /**
     * Versions published before DOI versioning was enabled can share one DOI, and a row
     * cannot say which of them it describes. Its version still counts towards the article.
     */
    public function testImportReportsADoiSharedByMoreThanOneVersion(): void
    {
        $v1 = $this->createVersion(1, '10.1/a', ['versionMajor' => 1]);
        $v1Minor = $this->createVersion(2, '10.1/a', ['versionMajor' => 1, 'versionMinor' => 1]);
        $v2 = $this->createVersion(3, '10.1/a', ['versionMajor' => 2]);
        $v3 = $this->createVersion(4, '10.1/a.3', ['versionMajor' => 3]);
        $saved = [];
        $importer = $this->createImporter($this->createArticle(), [$v1, $v1Minor, $v2, $v3], $saved);

        $result = $importer->import($this->csv(
            '10.1/a,' . self::PREFIX . ",2,0,openreseurope-4-20243,Y\n" .
            '10.1/a.3,' . self::PREFIX . ",3,0,openreseurope-4-21000,Y\n"
        ));

        $this->assertSame(['conflicts' => ['10.1/a']], $this->reported($result));
        $this->assertSame(['versions' => '1, 2'], $result['report']['conflicts'][0]['params']);
        $this->assertSame([$v3], array_values(array_filter(
            array_column($saved, 0),
            fn ($object) => $object instanceof Publication
        )));
        $this->assertSame(self::PREFIX, end($saved)[1][ImportedHistory::SETTING_PACKAGE_PREFIX]);
    }

    /**
     * A version delivered from OJS since an earlier import is numbered from the import, so
     * its row in a later file does not move the article's numbering.
     */
    public function testImportDoesNotCountVersionsDeliveredFromOjs(): void
    {
        $v1 = $this->createVersion(1, '10.1/a.1', [
            ImportedHistory::SETTING_REVISION => '0',
            'pubmedCentral::status' => PubObjectsExportPlugin::EXPORT_STATUS_MARKEDREGISTERED,
            ImportedHistory::SETTING_VERSION => '1',
            ImportedHistory::SETTING_FILE_STEM => 'openreseurope-4-18594',
        ]);
        $v3 = $this->createVersion(3, '10.1/a.3', [ImportedHistory::SETTING_REVISION => '0']);
        $saved = [];
        $importer = $this->createImporter($this->createArticle([
            ImportedHistory::SETTING_PACKAGE_PREFIX => self::PREFIX,
            ImportedHistory::SETTING_LAST_VERSION => '2',
        ]), [$v1, $v3], $saved);

        $result = $importer->import($this->csv(
            '10.1/a.1,' . self::PREFIX . ",1,0,openreseurope-4-18594,Y\n" .
            '10.1/a.3,' . self::PREFIX . ",3,0,jtest-2025-e83.v3,Y\n"
        ));

        $this->assertSame(['skipped' => ['10.1/a.3']], $this->reported($result));
        $this->assertSame([], $saved);
    }

    /**
     * Marking a version registered records nothing of its names, so its history can still
     * be imported afterwards.
     */
    public function testImportRecordsAVersionMarkedRegistered(): void
    {
        $v1 = $this->createVersion(1, '10.1/a.1', [
            'pubmedCentral::status' => PubObjectsExportPlugin::EXPORT_STATUS_MARKEDREGISTERED,
        ]);
        $submission = $this->createArticle();
        $saved = [];
        $importer = $this->createImporter($submission, [$v1], $saved);

        $result = $importer->import($this->csv('10.1/a.1,' . self::PREFIX . ",1,2,openreseurope-4-18594,Y\n"));

        $this->assertSame([], $this->reported($result));
        $this->assertSame([
            [$v1, [
                ImportedHistory::SETTING_VERSION => '1',
                ImportedHistory::SETTING_FILE_STEM => 'openreseurope-4-18594',
                ImportedHistory::SETTING_REVISION => '2',
            ]],
            [$submission, [
                ImportedHistory::SETTING_PACKAGE_PREFIX => self::PREFIX,
                ImportedHistory::SETTING_LAST_VERSION => '1',
            ]],
        ], $saved);
    }

    /**
     * A file with a row per package, as PMC's reports list them, would save one row over
     * another, so a DOI in more than one row is not imported.
     */
    public function testImportReportsADoiInMoreThanOneRow(): void
    {
        $saved = [];
        $importer = $this->createImporter($this->createArticle(), [$this->createVersion(1, '10.1/a.1')], $saved);

        $result = $importer->import($this->csv(
            '10.1/a.1,' . self::PREFIX . ",1,3,openreseurope-4-18594,Y\n" .
            '10.1/A.1,' . self::PREFIX . ",1,1,openreseurope-4-18594,Y\n"
        ));

        $this->assertSame(['invalid' => ['10.1/a.1', '10.1/A.1']], $this->reported($result));
        $this->assertSame('plugins.importexport.pmc.cli.importHistory.invalid.duplicate', $result['report']['invalid'][0]['key']);
        $this->assertSame([], $saved);
    }

    /**
     * Spreadsheet programs can save a CSV file beginning with a byte order mark.
     */
    public function testImportReadsAFileBeginningWithAByteOrderMark(): void
    {
        $saved = [];
        $importer = $this->createImporter($this->createArticle(), [$this->createVersion(1, '10.1/a.1')], $saved);

        $result = $importer->import($this->csv(
            '10.1/a.1,' . self::PREFIX . ",1,0,openreseurope-4-18594,Y\n",
            "\xEF\xBB\xBF" . self::HEADER
        ));

        $this->assertSame([], $this->reported($result));
        $this->assertSame(1, $result['publications']);
    }

    /**
     * Rows that disagree with what is recorded are not imported, and do not count towards
     * the article's highest version.
     */
    public function testImportReportsRowsThatDisagreeWithWhatIsRecorded(): void
    {
        $v1 = $this->createVersion(1, '10.1/a.1', [
            ImportedHistory::SETTING_VERSION => '1',
            ImportedHistory::SETTING_FILE_STEM => 'openreseurope-4-18594',
        ]);
        $v2 = $this->createVersion(2, '10.1/a.2', [ImportedHistory::SETTING_VERSION => '3']);
        $v3 = $this->createVersion(3, '10.1/a.3');
        $submission = $this->createArticle([
            ImportedHistory::SETTING_PACKAGE_PREFIX => 'openreseurope-4-openreseurope.4-84',
        ]);
        $saved = [];
        $importer = $this->createImporter($submission, [$v1, $v2, $v3], $saved);

        $result = $importer->import($this->csv(
            '10.1/a.1,' . self::PREFIX . ",1,0,openreseurope-4-99999,Y\n" .
            '10.1/a.2,' . self::PREFIX . ",5,0,openreseurope-4-20243,Y\n" .
            '10.1/a.3,' . self::PREFIX . ",2,0,openreseurope-4-21000,Y\n"
        ));

        $this->assertSame(['conflicts' => ['10.1/a.1', '10.1/a.2', '10.1/a.3']], $this->reported($result));
        $this->assertSame(
            ['plugins.importexport.pmc.cli.importHistory.conflict.stem', 'plugins.importexport.pmc.cli.importHistory.conflict.version', 'plugins.importexport.pmc.cli.importHistory.conflict.prefix'],
            array_column($result['report']['conflicts'], 'key')
        );
        $this->assertSame([$v3, $submission], array_column($saved, 0));
        $this->assertSame([ImportedHistory::SETTING_LAST_VERSION => '2'], end($saved)[1]);
    }

    /**
     * A version not indexed in PMC is recorded without being marked registered, and one
     * missing what a revision needs is reported as incomplete.
     */
    public function testImportReportsVersionsNotInPmcAndIncompleteHistories(): void
    {
        $v1 = $this->createVersion(1, '10.1/a.1');
        $v2 = $this->createVersion(2, '10.1/a.2');
        $saved = [];
        $importer = $this->createImporter($this->createArticle(), [$v1, $v2], $saved);

        $result = $importer->import($this->csv(
            '10.1/a.1,' . self::PREFIX . ",1,,openreseurope-4-18594,Y\n" .
            '10.1/a.2,' . self::PREFIX . ",2,0,,N\n"
        ));

        $this->assertSame(['incomplete' => ['10.1/a.1', '10.1/a.2'], 'notInPmc' => ['10.1/a.2']], $this->reported($result));
        $this->assertSame(['columns' => 'last_revision'], $result['report']['incomplete'][0]['params']);
        $this->assertArrayNotHasKey('pubmedCentral::status', $saved[1][1]);
    }

    /**
     * An article's package prefix is only reported missing when none of its rows gives it.
     */
    public function testImportReportsAMissingPrefixOnlyWhenTheArticleHasNone(): void
    {
        $v1 = $this->createVersion(1, '10.1/a.1');
        $v2 = $this->createVersion(2, '10.1/a.2');
        $importer = $this->createImporter($this->createArticle(), [$v1, $v2]);

        $result = $importer->import($this->csv(
            '10.1/a.1,' . self::PREFIX . ",1,0,openreseurope-4-18594,Y\n" .
            "10.1/a.2,,2,0,openreseurope-4-20243,Y\n"
        ));
        $this->assertSame([], $this->reported($result));

        $result = $importer->import($this->csv("10.1/a.2,,2,0,openreseurope-4-20243,Y\n"));
        $this->assertSame(['incomplete' => ['10.1/a.2']], $this->reported($result));
        $this->assertSame(['columns' => 'package_prefix'], $result['report']['incomplete'][0]['params']);
    }

    /**
     * A minor version shares its version's DOI and corrects it, so it takes the same history.
     */
    public function testImportRecordsTheHistoryOnEveryPublicationOfAVersion(): void
    {
        $v1 = $this->createVersion(1, '10.1/a.1');
        $v11 = $this->createVersion(2, '10.1/a.1');
        $saved = [];
        $importer = $this->createImporter($this->createArticle(), [$v1, $v11], $saved);

        $result = $importer->import($this->csv('10.1/a.1,' . self::PREFIX . ",1,,openreseurope-4-18594,Y\n"));

        $this->assertSame([$v1, $v11], array_slice(array_column($saved, 0), 0, 2));
        $this->assertCount(1, $result['report']['incomplete']);
    }

    public function testImportReportsRowsThatCannotBeRead(): void
    {
        $importer = $this->createImporter($this->createArticle(), []);

        $result = $importer->import($this->csv(
            ",x,1,0,stem,Y\n" .
            "10.1/a.1,x,one,0,stem,Y\n" .
            "10.1/a.2,x,1,-1,stem,Y\n" .
            "10.1/a.3,x y,1,0,stem,Y\n" .
            "10.1/a.4,x,1,0,stem,maybe\n"
        ));

        $this->assertSame(
            ['doi', 'version', 'last_revision', 'package_prefix', 'in_pmc'],
            array_column(array_column($result['report']['invalid'], 'params'), 'column')
        );
        $this->assertSame([2, 3, 4, 5, 6], array_column($result['report']['invalid'], 'line'));
    }

    public function testImportRequiresEveryColumn(): void
    {
        $importer = $this->createImporter($this->createArticle(), []);

        $result = $importer->import($this->csv("10.1/a.1,1\n", "doi,version\n"));

        $this->assertSame('plugins.importexport.pmc.cli.importHistory.invalidHeader', $result['report']['invalid'][0]['key']);
        $this->assertSame(['columns' => 'package_prefix, last_revision, file_stem, in_pmc'], $result['report']['invalid'][0]['params']);
    }

    /**
     * Without DOI versioning a row's DOI would find every version of its article, so
     * nothing is read or saved.
     */
    public function testImportRequiresDoiVersioning(): void
    {
        $saved = [];
        $importer = $this->createImporter($this->createArticle(), [$this->createVersion(1, '10.1/a.1')], $saved, false);

        $result = $importer->import($this->csv('10.1/a.1,' . self::PREFIX . ",1,0,openreseurope-4-18594,Y\n"));

        $this->assertSame('plugins.importexport.pmc.cli.importHistory.doiVersioningRequired', $result['refused']);
        $this->assertSame([], $result['report']);
        $this->assertSame(0, $result['publications']);
        $this->assertSame([], $saved);
    }

    public function testDryRunSavesNothing(): void
    {
        $saved = [];
        $importer = $this->createImporter($this->createArticle(), [$this->createVersion(1, '10.1/a.1')], $saved);

        $result = $importer->import($this->csv('10.1/a.1,' . self::PREFIX . ",1,0,openreseurope-4-18594,Y\n"), true);

        $this->assertSame(1, $result['publications']);
        $this->assertSame(1, $result['submissions']);
        $this->assertSame([], $saved);
    }
}
