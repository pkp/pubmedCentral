<?php

/**
 * @file tests/PubmedCentralExportPluginTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Unit tests for the PubMed Central export plugin.
 */

namespace APP\plugins\generic\pubmedCentral\tests;

use APP\issue\Issue;
use APP\issue\Repository as IssueRepository;
use APP\journal\Journal;
use APP\plugins\generic\pubmedCentral\PubmedCentralExportPlugin;
use APP\plugins\PubObjectsExportPlugin;
use APP\publication\Publication;
use APP\submission\Repository as SubmissionRepository;
use APP\submission\Submission;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PKP\tests\PKPTestCase;
use ReflectionMethod;
use ZipArchive;

#[CoversClass(PubmedCentralExportPlugin::class)]
class PubmedCentralExportPluginTest extends PKPTestCase
{
    /**
     * A minimal JATS document. The xlink namespace is declared here because the
     * jatsTemplate plugin declares it on generated JATS. The journal-meta is
     * populated because modifyDefaultJats() requires it before it reaches
     * article-meta.
     */
    private const JATS = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <article xmlns:xlink="http://www.w3.org/1999/xlink">
            <front>
                <journal-meta>
                    <journal-id journal-id-type="ojs">testjournal</journal-id>
                    <journal-title-group>
                        <journal-title>Journal of Testing</journal-title>
                    </journal-title-group>
                </journal-meta>
                <article-meta>
                    <title-group><article-title>Test article</article-title></title-group>
                    <pub-date publication-format="electronic" date-type="pub"><year>2026</year></pub-date>
                    %s
                    <abstract><p>An abstract.</p></abstract>
                </article-meta>
            </front>
        </article>
        XML;

    protected function tearDown(): void
    {
        // Drop any container binding a test may have replaced so that the next
        // resolution builds a fresh instance.
        app()->forgetInstance(IssueRepository::class);
        app()->forgetInstance(SubmissionRepository::class);
        parent::tearDown();
    }

    //
    // Helpers
    //

    /**
     * Build the plugin with its settings stubbed out.
     *
     * @param array $settings Plugin setting name => value
     */
    private function createPlugin(array $settings = []): PubmedCentralExportPlugin
    {
        $plugin = $this->getMockBuilder(PubmedCentralExportPlugin::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSetting', 'getPluginPath'])
            ->getMock();

        $plugin->method('getSetting')
            ->willReturnCallback(fn ($contextId, $name) => $settings[$name] ?? null);

        // Without the constructor the plugin has no path of its own, and the style
        // checker XSL alongside it cannot be found
        $plugin->method('getPluginPath')->willReturn('plugins/generic/pubmedCentral');

        return $plugin;
    }

    /**
     * Call a protected method on the plugin.
     */
    private function invoke(object $object, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($object, $args);
    }

    private function createJournal(): Journal
    {
        $journal = new Journal();
        $journal->setId(1);
        $journal->setData('primaryLocale', 'en');
        return $journal;
    }

    private function bindIssueRepository(int $volume, int $number, ?string $datePublished = null): void
    {
        $issue = new Issue();
        $issue->setData('volume', $volume);
        $issue->setData('number', $number);
        $issue->setData('datePublished', $datePublished);
        $issueRepository = $this->createMock(IssueRepository::class);
        $issueRepository->method('get')->willReturn($issue);
        app()->instance(IssueRepository::class, $issueRepository);
    }

    /**
     * Bind a submission repository handing back a submission with the given published
     * versions, in the order the collector would return them.
     *
     * @param array $datesPublished One publication date per version
     */
    private function bindSubmissionRepository(array $datesPublished): void
    {
        $publications = [];
        foreach (array_values($datesPublished) as $index => $datePublished) {
            $publication = new Publication();
            $publication->setId($index + 1);
            $publication->setData('status', Submission::STATUS_PUBLISHED);
            $publication->setData('datePublished', $datePublished);
            $publications[] = $publication;
        }

        $submission = new Submission();
        $submission->setData('publications', collect($publications));

        $submissionRepository = $this->createMock(SubmissionRepository::class);
        $submissionRepository->method('get')->willReturn($submission);
        app()->instance(SubmissionRepository::class, $submissionRepository);
    }

    /**
     * Build the JATS fixture, optionally injecting elements before the abstract.
     */
    private function jats(string $extraArticleMeta = ''): string
    {
        return sprintf(self::JATS, $extraArticleMeta);
    }

    /**
     * Run an XPath query against a returned XML string.
     */
    private function xpath(string $xml): DOMXPath
    {
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'Result should be well-formed XML');
        return new DOMXPath($dom);
    }

    /**
     * Call one of the two JATS modifiers, absorbing their differing signatures.
     */
    private function modifyJats(
        string $method,
        string $jats,
        string $articlePdfFilename,
        ?string $collectionYear = null
    ): string|array {
        $args = [$jats, $articlePdfFilename];
        if ($method === 'modifyDefaultJats') {
            $args[] = 'J Test';
            $args[] = $collectionYear;
        }
        return $this->invoke($this->createPlugin(), $method, $args);
    }

    /**
     * modifyDefaultJats() and modifyCustomJats() carry byte-identical self-uri and
     * empty-paragraph handling, so every test of that shared behaviour runs against
     * both. Anything asserted here must hold for generated and uploaded JATS alike.
     */
    public static function jatsModifierProvider(): array
    {
        return [
            'generated JATS' => ['modifyDefaultJats'],
            'uploaded JATS' => ['modifyCustomJats'],
        ];
    }

    //
    // nlmTitle()
    //
    public function testNlmTitleReturnsTheConfiguredSetting(): void
    {
        $plugin = $this->createPlugin(['nlmTitle' => 'J Test']);

        $this->assertSame('J Test', $plugin->nlmTitle($this->createJournal()));
    }

    /**
     * The setting is required by the settings form, but getSetting() returns null
     * when it has never been saved. Returning that straight from a string-typed
     * method would raise a TypeError.
     */
    public function testNlmTitleIsAnEmptyStringWhenUnset(): void
    {
        $plugin = $this->createPlugin();

        $this->assertSame('', $plugin->nlmTitle($this->createJournal()));
    }

    //
    // jatsImportedOnly()
    //

    /**
     * The setting is stored with type 'bool', but a loose comparison against 1 is
     * what decides whether generated JATS is allowed as a fallback.
     *
     * @param mixed $setting The stored setting value
     */
    #[DataProvider('jatsImportedProvider')]
    public function testJatsImportedOnly(mixed $setting, bool $expected): void
    {
        $plugin = $this->createPlugin(['jatsImported' => $setting]);

        $this->assertSame($expected, $plugin->jatsImportedOnly($this->createJournal()));
    }

    public static function jatsImportedProvider(): array
    {
        return [
            'enabled' => [true, true],
            'stored as a legacy string' => ['1', true],
            'disabled' => [false, false],
            'never saved' => [null, false],
        ];
    }

    //
    // getExportActions()
    //
    public function testDepositActionOfferedWhenCredentialsAreComplete(): void
    {
        $plugin = $this->createPlugin([
            'host' => 'ftp.example.org',
            'username' => 'user',
            'password' => 'secret',
        ]);

        $this->assertSame([
            PubObjectsExportPlugin::EXPORT_ACTION_DEPOSIT,
            PubObjectsExportPlugin::EXPORT_ACTION_EXPORT,
            PubObjectsExportPlugin::EXPORT_ACTION_MARKREGISTERED,
        ], $plugin->getExportActions($this->createJournal()));
    }

    /**
     * @param array $settings The connection settings that *are* present
     */
    #[DataProvider('incompleteCredentialsProvider')]
    public function testDepositActionWithheldWhenCredentialsAreIncomplete(array $settings): void
    {
        $plugin = $this->createPlugin($settings);

        $this->assertSame([
            PubObjectsExportPlugin::EXPORT_ACTION_EXPORT,
            PubObjectsExportPlugin::EXPORT_ACTION_MARKREGISTERED,
        ], $plugin->getExportActions($this->createJournal()));
    }

    public static function incompleteCredentialsProvider(): array
    {
        return [
            'nothing set' => [[]],
            'host missing' => [['username' => 'user', 'password' => 'secret']],
            'username missing' => [['host' => 'ftp.example.org', 'password' => 'secret']],
            'password missing' => [['host' => 'ftp.example.org', 'username' => 'user']],
            'host empty' => [['host' => '', 'username' => 'user', 'password' => 'secret']],
        ];
    }

    //
    // isAccountComplete()
    //
    #[DataProvider('accountProvider')]
    public function testAccountCompletenessCheck(array $account, bool $complete): void
    {
        $plugin = $this->createPlugin();

        $this->assertSame($complete, $plugin->isAccountComplete($account));
    }

    public static function accountProvider(): array
    {
        $complete = ['host' => 'ftp.example.org', 'username' => 'user', 'password' => 'secret'];

        return [
            'complete' => [$complete, true],
            'nothing set' => [[], false],
            'blank strings' => [['host' => '', 'username' => '', 'password' => ''], false],
            'host only' => [['host' => 'ftp.example.org'], false],
            'password missing' => [array_diff_key($complete, ['password' => null]), false],
        ];
    }

    //
    // buildFileName()
    //
    public function testBuildFileNameWithoutAnObjectIsJustTheNlmTitle(): void
    {
        $plugin = $this->createPlugin();

        $this->assertSame(
            'jtest',
            $this->invoke($plugin, 'buildFileName', ['J Test', $this->createJournal()])
        );
    }

    public function testBuildFileNameAppendsTheExtension(): void
    {
        $plugin = $this->createPlugin();

        $this->assertSame(
            'jtest.zip',
            $this->invoke($plugin, 'buildFileName', ['J Test', $this->createJournal(), null, false, 'zip'])
        );
    }

    /**
     * PMC organizes the archive by volume, and by the collection year in its place
     * where a journal carries no volumes, so the year sits between the journal
     * abbreviation and the article number.
     */
    public function testBuildFileNameUsesTheArticleNumberScheme(): void
    {
        $plugin = $this->createPlugin(['namingType' => 'articleNumber']);
        $this->bindIssueRepository(12, 3, '2025-03-01');

        $publication = new Publication();
        $publication->setData('issueId', 7);
        $publication->setData('articleNumber', 'e12345');

        $this->assertSame(
            'jtest-2025-e12345.xml',
            $this->invoke($plugin, 'buildFileName', ['J Test', $this->createJournal(), $publication, false, 'xml'])
        );
    }

    /**
     * A publication with no collection year is stopped by validateNamingMetadata()
     * before it is named, but the name itself should still not carry an empty part.
     */
    public function testBuildFileNameOmitsAnUnknownCollectionYear(): void
    {
        $plugin = $this->createPlugin(['namingType' => 'articleNumber']);
        $publication = new Publication();
        $publication->setData('articleNumber', 'e12345');

        $this->assertSame(
            'jtest-e12345.xml',
            $this->invoke($plugin, 'buildFileName', ['J Test', $this->createJournal(), $publication, false, 'xml'])
        );
    }

    public function testBuildFileNameUsesTheVolumeIssueScheme(): void
    {
        $plugin = $this->createPlugin(['namingType' => 'volumeIssue']);
        $this->bindIssueRepository(12, 3);

        $publication = new Publication();
        $publication->setData('issueId', 7);
        $publication->setData('pages', '45-52');

        $this->assertSame(
            'jtest-12-3-45.xml',
            $this->invoke($plugin, 'buildFileName', ['J Test', $this->createJournal(), $publication, false, 'xml'])
        );
    }

    public function testBuildFileNameDefaultsToTheVolumeIssueSchemeWhenUnset(): void
    {
        $plugin = $this->createPlugin();
        $this->bindIssueRepository(12, 3);

        $publication = new Publication();
        $publication->setData('issueId', 7);
        $publication->setData('pages', '45-52');

        $this->assertSame(
            'jtest-12-3-45',
            $this->invoke($plugin, 'buildFileName', ['J Test', $this->createJournal(), $publication])
        );
    }

    public function testBuildFileNameResolvesTheCurrentPublicationOfASubmission(): void
    {
        $plugin = $this->createPlugin(['namingType' => 'articleNumber']);

        $publication = new Publication();
        $publication->setData('articleNumber', 'e999');

        $submission = $this->getMockBuilder(Submission::class)
            ->onlyMethods(['getCurrentPublication'])
            ->getMock();
        $submission->method('getCurrentPublication')->willReturn($publication);

        $this->assertSame(
            'jtest-e999',
            $this->invoke($plugin, 'buildFileName', ['J Test', $this->createJournal(), $submission])
        );
    }

    /**
     * PMC file names cannot contain spaces or special characters such as ?, %, #, / or :.
     */
    public function testBuildFileNameStripsNonAlphanumericCharactersAndLowercases(): void
    {
        $plugin = $this->createPlugin(['namingType' => 'articleNumber']);
        $publication = new Publication();
        $publication->setData('articleNumber', 'e 12/345');

        $this->assertSame(
            'jpubknowledge1-e12345',
            $this->invoke(
                $plugin,
                'buildFileName',
                ['J. Pub/Knowledge: #1', $this->createJournal(), $publication]
            )
        );
    }

    public function testBuildFileNameAppendsATimestamp(): void
    {
        $plugin = $this->createPlugin(['namingType' => 'articleNumber']);
        $publication = new Publication();
        $publication->setData('articleNumber', 'e12345');

        $filename = $this->invoke(
            $plugin,
            'buildFileName',
            ['J Test', $this->createJournal(), $publication, true, 'zip']
        );

        $this->assertMatchesRegularExpression('/^jtest-e12345-\d{14}\.zip$/', $filename);
    }

    //
    // collectionYear()
    //

    /**
     * PMC keeps an article in the collection it was first published in, so the year
     * comes from the issue rather than from the version being deposited.
     */
    public function testCollectionYearComesFromTheIssue(): void
    {
        $plugin = $this->createPlugin();
        $this->bindIssueRepository(14, 1, '2025-11-30');

        $publication = new Publication();
        $publication->setData('issueId', 7);
        $publication->setData('datePublished', '2026-06-01');

        $this->assertSame('2025', $this->invoke($plugin, 'collectionYear', [$publication]));
    }

    /**
     * A journal publishing by article number need not assign its publications to an
     * issue, in which case the first published version fixes the collection. A second
     * version published in a later year has to keep the first version's year, or its
     * revised files would no longer match the names PMC already holds.
     */
    public function testCollectionYearFallsBackToTheFirstPublishedVersion(): void
    {
        $plugin = $this->createPlugin();
        $this->bindSubmissionRepository(['2025-12-01', '2026-06-01']);

        $publication = new Publication();
        $publication->setId(2);
        $publication->setData('submissionId', 5);
        $publication->setData('datePublished', '2026-06-01');

        $this->assertSame('2025', $this->invoke($plugin, 'collectionYear', [$publication]));
    }

    public function testCollectionYearOfASubmissionUsesItsOwnPublications(): void
    {
        $plugin = $this->createPlugin();

        $publications = [];
        foreach (['2025-12-01', '2026-06-01'] as $index => $datePublished) {
            $publication = new Publication();
            $publication->setId($index + 1);
            $publication->setData('status', Submission::STATUS_PUBLISHED);
            $publication->setData('datePublished', $datePublished);
            $publications[] = $publication;
        }
        $submission = new Submission();
        $submission->setData('publications', collect($publications));
        $submission->setData('currentPublicationId', 2);

        $this->assertSame('2025', $this->invoke($plugin, 'collectionYear', [$submission]));
    }

    /**
     * A publication that is in no issue and has no earlier version falls back to its
     * own publication date.
     */
    public function testCollectionYearFallsBackToThePublicationDate(): void
    {
        $plugin = $this->createPlugin();

        $publication = new Publication();
        $publication->setData('datePublished', '2026-06-01');

        $this->assertSame('2026', $this->invoke($plugin, 'collectionYear', [$publication]));
    }

    public function testCollectionYearIsNullWhenNoDateIsRecorded(): void
    {
        $plugin = $this->createPlugin();

        $this->assertNull($this->invoke($plugin, 'collectionYear', [new Publication()]));
        $this->assertNull($this->invoke($plugin, 'collectionYear', [null]));
    }

    //
    // validateNamingMetadata()
    //
    public function testNamingMetadataIsValidWhenEverythingIsPresent(): void
    {
        $plugin = $this->createPlugin(['nlmTitle' => 'J Test', 'namingType' => 'articleNumber']);
        $publication = new Publication();
        $publication->setData('articleNumber', 'e12345');
        $publication->setData('datePublished', '2025-03-01');

        $this->assertNull(
            $this->invoke($plugin, 'validateNamingMetadata', [$publication, $this->createJournal()])
        );
    }

    /**
     * The NLM title abbreviation is the leading part of every package and file
     * name, so an export cannot proceed without it.
     */
    public function testMissingNlmTitleIsReportedAsMissingMetadata(): void
    {
        $plugin = $this->createPlugin(['namingType' => 'articleNumber']);
        $publication = new Publication();
        $publication->setData('articleNumber', 'e12345');

        $result = $this->invoke($plugin, 'validateNamingMetadata', [$publication, $this->createJournal()]);

        $this->assertIsArray($result);
        $this->assertSame('plugins.importexport.pmc.export.failure.missingMetadata', $result[0]);
        $this->assertStringContainsString(__('plugins.importexport.pmc.settings.form.nlmTitle'), $result[1]);
    }

    public function testMissingNlmTitleIsListedAlongsideOtherMissingMetadata(): void
    {
        $plugin = $this->createPlugin(['namingType' => 'articleNumber']);
        $publication = new Publication();

        $result = $this->invoke($plugin, 'validateNamingMetadata', [$publication, $this->createJournal()]);

        $this->assertSame(
            __('plugins.importexport.pmc.settings.form.nlmTitle') . ', '
                . __('submission.articleNumber') . ', '
                . __('plugins.importexport.pmc.export.collectionYear'),
            $result[1]
        );
    }

    public function testMissingArticleNumberIsReported(): void
    {
        $plugin = $this->createPlugin(['nlmTitle' => 'J Test', 'namingType' => 'articleNumber']);
        $publication = new Publication();
        $publication->setData('datePublished', '2025-03-01');

        $result = $this->invoke($plugin, 'validateNamingMetadata', [$publication, $this->createJournal()]);

        $this->assertSame(__('submission.articleNumber'), $result[1]);
    }

    /**
     * The article number scheme names packages by the collection year, so an article
     * whose year cannot be determined cannot be named.
     */
    public function testMissingCollectionYearIsReportedForTheArticleNumberScheme(): void
    {
        $plugin = $this->createPlugin(['nlmTitle' => 'J Test', 'namingType' => 'articleNumber']);
        $publication = new Publication();
        $publication->setData('articleNumber', 'e12345');

        $result = $this->invoke($plugin, 'validateNamingMetadata', [$publication, $this->createJournal()]);

        $this->assertSame(__('plugins.importexport.pmc.export.collectionYear'), $result[1]);
    }

    public function testMissingIssueIsReportedForTheVolumeIssueScheme(): void
    {
        $plugin = $this->createPlugin(['nlmTitle' => 'J Test', 'namingType' => 'volumeIssue']);
        $publication = new Publication();
        $publication->setData('pages', '45-52');

        $result = $this->invoke($plugin, 'validateNamingMetadata', [$publication, $this->createJournal()]);

        $this->assertSame(__('issue.issue'), $result[1]);
    }

    /**
     * The volume/issue fallback has to match the one in buildFileName(), or an
     * export would validate one scheme's metadata and then name files by the other.
     */
    public function testValidateNamingMetadataDefaultsToTheVolumeIssueScheme(): void
    {
        $plugin = $this->createPlugin(['nlmTitle' => 'J Test']);
        $publication = new Publication();
        $publication->setData('pages', '45-52');

        $result = $this->invoke($plugin, 'validateNamingMetadata', [$publication, $this->createJournal()]);

        $this->assertSame(__('issue.issue'), $result[1]);
    }

    public function testMissingVolumeNumberAndPagesAreAllReported(): void
    {
        $plugin = $this->createPlugin(['nlmTitle' => 'J Test', 'namingType' => 'volumeIssue']);
        $this->bindIssueRepository(0, 0);

        $publication = new Publication();
        $publication->setData('issueId', 7);

        $result = $this->invoke($plugin, 'validateNamingMetadata', [$publication, $this->createJournal()]);

        $this->assertSame(
            __('issue.volume') . ', ' . __('issue.number') . ', ' . __('editor.issues.pages'),
            $result[1]
        );
    }

    //
    // convertErrorMessage()
    //
    public function testConvertErrorMessageWithoutAParam(): void
    {
        $plugin = $this->createPlugin();

        $this->assertSame(
            __('plugins.importexport.pmc.export.failure.loadJats'),
            $this->invoke(
                $plugin,
                'convertErrorMessage',
                [['plugins.importexport.pmc.export.failure.loadJats']]
            )
        );
    }

    /**
     * Every error the JATS modifiers return is a [key, detail] pair, and the detail
     * is only reachable from the locale strings under the name 'param'.
     */
    public function testConvertErrorMessagePassesTheDetailAsParam(): void
    {
        $plugin = $this->createPlugin();

        $this->assertSame(
            __('plugins.importexport.pmc.export.failure.jatsNodeMissing', ['param' => 'article-meta']),
            $this->invoke(
                $plugin,
                'convertErrorMessage',
                [['plugins.importexport.pmc.export.failure.jatsNodeMissing', 'article-meta']]
            )
        );
    }

    //
    // createZipCollection()
    //

    /**
     * Build the plugin with createZip() stubbed to hand back ready-made packages,
     * so the collection logic can be exercised without a submission or its files.
     *
     * @param array $packages One createZip() return value per call, in order.
     */
    private function createPluginWithPackages(array $packages): PubmedCentralExportPlugin
    {
        $plugin = $this->getMockBuilder(PubmedCentralExportPlugin::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSetting', 'getPluginPath', 'createZip'])
            ->getMock();

        $plugin->method('getSetting')->willReturn(null);
        $plugin->method('getPluginPath')->willReturn('plugins/generic/pubmedCentral');
        $plugin->method('createZip')->willReturnOnConsecutiveCalls(...$packages);

        return $plugin;
    }

    /**
     * Write a stand-in article package and return it in createZip()'s shape.
     */
    private function buildPackage(string $filename): array
    {
        $path = tempnam(sys_get_temp_dir(), 'PmcTest_');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString($filename . '/' . $filename . '.xml', '<article/>');
        $zip->close();

        return ['filename' => $filename, 'path' => $path];
    }

    public function testASingleObjectIsDownloadedAsItsOwnPackage(): void
    {
        $package = $this->buildPackage('jtest-1-1-1');
        $plugin = $this->createPluginWithPackages([$package]);

        $result = $this->invoke($plugin, 'createZipCollection', [[new Submission()], $this->createJournal()]);

        $this->assertSame($package['path'], $result['path'], 'The package itself is the download');

        // Guards the regression this replaced: a lone article wrapped in a collection zip
        $zip = new ZipArchive();
        $zip->open($result['path']);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        $this->assertSame(['jtest-1-1-1/jtest-1-1-1.xml'], $names);

        unlink($package['path']);
    }

    public function testSeveralObjectsAreGatheredIntoACollection(): void
    {
        $packages = [$this->buildPackage('jtest-1-1-1'), $this->buildPackage('jtest-1-1-9')];
        $plugin = $this->createPluginWithPackages($packages);

        $result = $this->invoke(
            $plugin,
            'createZipCollection',
            [[new Submission(), new Submission()], $this->createJournal()]
        );

        $zip = new ZipArchive();
        $zip->open($result['path']);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        $this->assertSame(['jtest-1-1-1.zip', 'jtest-1-1-9.zip'], $names);

        // The per-article packages are cleaned up once the collection is closed
        foreach ($packages as $package) {
            $this->assertFileDoesNotExist($package['path']);
        }

        unlink($result['path']);
    }

    public function testASingleObjectPassesItsErrorStraightThrough(): void
    {
        $plugin = $this->createPluginWithPackages([['error' => ['plugins.importexport.pmc.export.failure.loadJats']]]);

        $result = $this->invoke($plugin, 'createZipCollection', [[new Submission()], $this->createJournal()]);

        $this->assertSame(['error' => ['plugins.importexport.pmc.export.failure.loadJats']], $result);
    }

    //
    // discardZip() / deleteTempFile()
    //
    public function testDiscardZipRemovesTheArchiveAndReturnsTheError(): void
    {
        $plugin = $this->createPlugin();

        $zipPath = tempnam(sys_get_temp_dir(), 'PmcTest_');
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('a/b.xml', '<x/>');

        $result = $this->invoke($plugin, 'discardZip', [$zip, $zipPath, ['some.error.key', 'detail']]);

        $this->assertSame(['error' => ['some.error.key', 'detail']], $result);
        $this->assertFileDoesNotExist($zipPath);
    }

    public function testDiscardZipAlsoRemovesCollectedPackages(): void
    {
        $plugin = $this->createPlugin();

        $zipPath = tempnam(sys_get_temp_dir(), 'PmcTest_');
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('a/b.xml', '<x/>');

        $collected = [
            tempnam(sys_get_temp_dir(), 'PmcTest_'),
            tempnam(sys_get_temp_dir(), 'PmcTest_'),
        ];

        $this->invoke($plugin, 'discardZip', [$zip, $zipPath, ['some.error.key'], $collected]);

        $this->assertFileDoesNotExist($zipPath);
        foreach ($collected as $path) {
            $this->assertFileDoesNotExist($path);
        }
    }

    public function testDeleteTempFileRemovesTheFile(): void
    {
        $plugin = $this->createPlugin();

        $path = tempnam(sys_get_temp_dir(), 'PmcTest_');

        $this->invoke($plugin, 'deleteTempFile', [$path]);

        $this->assertFileDoesNotExist($path);
    }

    /**
     * Packages are cleaned up on paths where some of them were never created, so
     * the file_exists() guard has to keep unlink() from warning on a missing file.
     */
    public function testDeleteTempFileToleratesAMissingFile(): void
    {
        $plugin = $this->createPlugin();

        $path = tempnam(sys_get_temp_dir(), 'PmcTest_');
        unlink($path);

        $raised = [];
        set_error_handler(
            function (int $errno, string $message) use (&$raised): bool {
                $raised[] = $message;
                return true;
            },
            E_WARNING | E_NOTICE
        );

        try {
            $this->invoke($plugin, 'deleteTempFile', [$path]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $raised, 'unlink() should not be attempted on a missing file');
        $this->assertFileDoesNotExist($path);
    }

    //
    // validateJats()
    //
    public static function jatsEntityProvider(): array
    {
        return [
            'JATS 1.2 public identifier' => [
                '-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.2 20190208//EN',
                'http://example.org/wherever.dtd',
                true,
            ],
            'JATS 1.2 system identifier over https' => [
                null,
                'https://jats.nlm.nih.gov/publishing/1.2/JATS-journalpublishing1.dtd',
                true,
            ],
            'another JATS version' => [
                null,
                'http://jats.nlm.nih.gov/publishing/1.1/JATS-journalpublishing1.dtd',
                false,
            ],
            'a module the DTD pulls in' => [
                null,
                '/somewhere/dtd/jats/1.2/JATS-common1.ent',
                false,
            ],
        ];
    }

    #[DataProvider('jatsEntityProvider')]
    public function testResolveJatsEntityPrefersTheBundledDtd(?string $publicId, string $systemId, bool $bundled): void
    {
        $resolved = $this->invoke($this->createPlugin(), 'resolveJatsEntity', [$publicId, $systemId]);

        if (!$bundled) {
            $this->assertSame($systemId, $resolved, 'Anything else should be left to libxml');
            return;
        }

        $this->assertStringEndsWith('/dtd/jats/1.2/JATS-journalpublishing1.dtd', $resolved);
        $this->assertFileExists($resolved);
    }

    public function testValidateJatsReportsDtdErrors(): void
    {
        // The fixture's journal-meta has no issn, which the DTD requires
        $doctype = '<!DOCTYPE article PUBLIC '
            . '"-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.2 20190208//EN" '
            . '"http://jats.nlm.nih.gov/publishing/1.2/JATS-journalpublishing1.dtd">';
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML(str_replace('<article ', $doctype . "\n" . '<article ', $this->jats())));

        $result = $this->invoke($this->createPlugin(), 'validateJats', [$dom]);

        $this->assertIsString($result, 'An invalid document should be reported, not accepted');
        $this->assertStringContainsString('DTD Error', $result);
        $this->assertStringContainsString('journal-meta', $result);
    }

    public function testValidateJatsSkipsTheDtdForAnotherJatsVersion(): void
    {
        $doctype = '<!DOCTYPE article PUBLIC '
            . '"-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.1 20151215//EN" '
            . '"http://jats.nlm.nih.gov/publishing/1.1/JATS-journalpublishing1.dtd">';
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML(str_replace('<article ', $doctype . "\n" . '<article ', $this->jats())));
        $plugin = $this->createPlugin();

        $result = $this->invoke($plugin, 'validateJats', [$dom]);

        // The same fixture reports DTD errors when it declares JATS 1.2, but the style
        // check still applies
        $this->assertIsString($result);
        $this->assertStringNotContainsString('DTD Error', $result);
        $this->assertStringContainsString('PMC Style Check Error', $result);
        $this->assertSame(
            ['plugins.importexport.pmc.export.warning.jatsVersionUnsupported'],
            $this->invoke($plugin, 'getValidationWarnings')
        );
    }

    public function testValidateJatsSkipsTheDtdWhenNoDoctypeIsDeclared(): void
    {
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($this->jats()));
        $plugin = $this->createPlugin();

        $result = $this->invoke($plugin, 'validateJats', [$dom]);

        $this->assertIsString($result);
        $this->assertStringNotContainsString('DTD Error', $result);
        $this->assertStringContainsString('PMC Style Check Error', $result);
        $this->assertSame(
            ['plugins.importexport.pmc.export.warning.jatsVersionUnsupported'],
            $this->invoke($plugin, 'getValidationWarnings')
        );
    }

    public function testValidationWarningsAreReportedOncePerExport(): void
    {
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($this->jats()));
        $plugin = $this->createPlugin();

        // An export covers many articles, each of which may raise the same warning
        $this->invoke($plugin, 'validateJats', [$dom]);
        $this->invoke($plugin, 'validateJats', [$dom]);

        $this->assertCount(1, $this->invoke($plugin, 'getValidationWarnings'));
    }

    //
    // modifyDefaultJats() / modifyCustomJats() - shared handling
    //
    #[DataProvider('jatsModifierProvider')]
    public function testSelfUriIsInsertedBeforeTheAbstract(string $method): void
    {
        $result = $this->modifyJats($method, $this->jats(), 'jtest-12-3-45.pdf');

        $this->assertIsString($result);
        $xpath = $this->xpath($result);

        $selfUris = $xpath->query('//article-meta/self-uri');
        $this->assertSame(1, $selfUris->length);
        $this->assertSame('pdf', $selfUris->item(0)->getAttribute('content-type'));
        $this->assertSame('jtest-12-3-45.pdf', $selfUris->item(0)->getAttribute('xlink:href'));

        $this->assertSame(
            'abstract',
            $selfUris->item(0)->nextSibling->nodeName,
            'self-uri should be inserted immediately before the abstract'
        );
    }

    #[DataProvider('jatsModifierProvider')]
    public function testSelfUriIsInsertedBeforeAnExistingSelfUri(string $method): void
    {
        $jats = $this->jats('<self-uri content-type="html" xlink:href="article.html"/>');

        $result = $this->modifyJats($method, $jats, 'jtest-12-3-45.pdf');

        $xpath = $this->xpath($result);
        $selfUris = $xpath->query('//article-meta/self-uri');

        $this->assertSame(2, $selfUris->length);
        $this->assertSame('pdf', $selfUris->item(0)->getAttribute('content-type'));
        $this->assertSame('html', $selfUris->item(1)->getAttribute('content-type'));
    }

    #[DataProvider('jatsModifierProvider')]
    public function testExistingPdfSelfUrisAreReplaced(string $method): void
    {
        $jats = $this->jats(
            '<self-uri content-type="pdf" xlink:href="old.pdf"/>' .
            '<self-uri content-type="application/pdf" xlink:href="older.pdf"/>'
        );

        $result = $this->modifyJats($method, $jats, 'new.pdf');

        $xpath = $this->xpath($result);
        $selfUris = $xpath->query('//article-meta/self-uri');

        $this->assertSame(1, $selfUris->length);
        $this->assertSame('new.pdf', $selfUris->item(0)->getAttribute('xlink:href'));
    }

    /**
     * PMC requires a PDF corresponding to each article XML file, so exportXML()
     * refuses before any JATS is fetched. This also guarantees the modifiers a
     * non-null filename.
     */
    public function testExportingWithoutAPackagedPdfReturnsAnError(): void
    {
        $result = $this->createPlugin()->exportXML(null, null, $this->createJournal());

        $this->assertSame(
            ['plugins.importexport.pmc.export.failure.missingArticleFile'],
            $result
        );
    }

    #[DataProvider('jatsModifierProvider')]
    public function testEmptyParagraphsAreRemoved(string $method): void
    {
        $jats = $this->jats('<self-uri content-type="html" xlink:href="a.html"/><p>   </p>');

        $result = $this->modifyJats($method, $jats, 'jtest.pdf');

        $xpath = $this->xpath($result);
        // Only the abstract's non-empty paragraph should survive.
        $this->assertSame(1, $xpath->query('//p')->length);
        $this->assertSame('An abstract.', $xpath->query('//p')->item(0)->textContent);
    }

    #[DataProvider('jatsModifierProvider')]
    public function testMissingArticleMetaReturnsAnError(string $method): void
    {
        $jats = '<?xml version="1.0"?><article><front><journal-meta>'
            . '<journal-id journal-id-type="ojs">testjournal</journal-id>'
            . '<journal-title-group><journal-title>J</journal-title></journal-title-group>'
            . '</journal-meta></front></article>';

        $this->assertSame(
            ['plugins.importexport.pmc.export.failure.jatsNodeMissing', 'article-meta'],
            $this->modifyJats($method, $jats, 'jtest.pdf')
        );
    }

    #[DataProvider('jatsModifierProvider')]
    public function testMissingAbstractReturnsAnError(string $method): void
    {
        $jats = '<?xml version="1.0"?><article><front><journal-meta>'
            . '<journal-id journal-id-type="ojs">testjournal</journal-id>'
            . '<journal-title-group><journal-title>J</journal-title></journal-title-group>'
            . '</journal-meta><article-meta>'
            . '<title-group><article-title>T</article-title></title-group>'
            . '</article-meta></front></article>';

        $this->assertSame(
            ['plugins.importexport.pmc.export.failure.jatsNodeMissing', 'abstract'],
            $this->modifyJats($method, $jats, 'jtest.pdf')
        );
    }

    #[DataProvider('jatsModifierProvider')]
    public function testMalformedXmlReturnsAnError(string $method): void
    {
        // The methods rely on the caller having enabled internal error handling;
        // exportXML() does this before calling them.
        $previous = libxml_use_internal_errors(true);
        try {
            $result = $this->modifyJats($method, '<article><front>', 'jtest.pdf');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $this->assertSame(['plugins.importexport.pmc.export.failure.loadJats'], $result);
    }

    //
    // modifyCustomJats() - uploaded JATS
    //

    /**
     * Uploaded JATS is re-modified whenever a submission is re-exported, and the
     * result has to converge. modifyDefaultJats() has no equivalent test because it
     * always re-adds the pmc journal-id and abbrev-journal-title; it is only ever
     * handed freshly generated JATS.
     */
    public function testModifyCustomJatsIsIdempotent(): void
    {
        $once = $this->modifyJats('modifyCustomJats', $this->jats(), 'jtest.pdf');
        $twice = $this->modifyJats('modifyCustomJats', $once, 'jtest.pdf');

        $this->assertSame($once, $twice);
    }

    //
    // modifyDefaultJats() - generated JATS, PMC-specific transforms
    //
    public function testDefaultJatsAddsThePmcJournalIdAsTheFirstChild(): void
    {
        $result = $this->modifyJats('modifyDefaultJats', $this->jats(), 'jtest.pdf');

        $this->assertIsString($result);
        $xpath = $this->xpath($result);

        $journalIds = $xpath->query('//journal-meta/journal-id');
        $this->assertSame(1, $journalIds->length, 'The OJS journal-id should have been removed');
        $this->assertSame('pmc', $journalIds->item(0)->getAttribute('journal-id-type'));
        $this->assertSame('J Test', $journalIds->item(0)->textContent);

        $firstChild = $xpath->query('//journal-meta/*[1]')->item(0);
        $this->assertSame('journal-id', $firstChild->nodeName);
    }

    public function testDefaultJatsAddsTheNlmAbbrevJournalTitle(): void
    {
        $result = $this->modifyJats('modifyDefaultJats', $this->jats(), 'jtest.pdf');

        $xpath = $this->xpath($result);
        $abbrev = $xpath->query('//journal-title-group/abbrev-journal-title');

        $this->assertSame(1, $abbrev->length);
        $this->assertSame('nlm-ta', $abbrev->item(0)->getAttribute('abbrev-type'));
        $this->assertSame('J Test', $abbrev->item(0)->textContent);
    }

    /**
     * PMC requires the electronic publication date to be accompanied by the date of
     * the collection the article belongs to. Only the year is needed, and it has to
     * stay among the pub-date elements, which the JATS content model places before
     * the volume and page elements.
     */
    public function testDefaultJatsAddsTheCollectionDate(): void
    {
        $result = $this->modifyJats('modifyDefaultJats', $this->jats(), 'jtest.pdf', '2025');

        $this->assertIsString($result);
        $xpath = $this->xpath($result);

        $collectionDates = $xpath->query("//article-meta/pub-date[@date-type='collection']");
        $this->assertSame(1, $collectionDates->length);
        $this->assertSame('electronic', $collectionDates->item(0)->getAttribute('publication-format'));
        $this->assertSame('2025', $xpath->evaluate("string(//pub-date[@date-type='collection']/year)"));

        $pubDates = $xpath->query('//article-meta/pub-date');
        $this->assertSame(2, $pubDates->length);
        $this->assertSame('pub', $pubDates->item(0)->getAttribute('date-type'));
        $this->assertSame('collection', $pubDates->item(1)->getAttribute('date-type'));
    }

    public function testDefaultJatsAddsNoCollectionDateWithoutACollectionYear(): void
    {
        $result = $this->modifyJats('modifyDefaultJats', $this->jats(), 'jtest.pdf');

        $this->assertIsString($result);
        $this->assertSame(
            0,
            $this->xpath($result)->query("//pub-date[@date-type='collection']")->length
        );
    }

    /**
     * PMC only accepts a collection date on an article that also carries an electronic
     * publication date, so an unpublished article gets neither.
     */
    public function testDefaultJatsAddsNoCollectionDateWithoutAPublicationDate(): void
    {
        $jats = preg_replace('|<pub-date.*?</pub-date>|', '', $this->jats());

        $result = $this->modifyJats('modifyDefaultJats', $jats, 'jtest.pdf', '2025');

        $this->assertIsString($result);
        $this->assertSame(0, $this->xpath($result)->query('//pub-date')->length);
    }

    public function testDefaultJatsSetsTheArticleType(): void
    {
        $result = $this->modifyJats('modifyDefaultJats', $this->jats(), 'jtest.pdf');

        $xpath = $this->xpath($result);
        $this->assertSame(
            'research-article',
            $xpath->query('/article')->item(0)->getAttribute('article-type')
        );
    }

    public function testDefaultJatsKeepsOnlyAuthorAndEditorContributors(): void
    {
        $contribGroup = <<<'XML'
            <contrib-group>
                <contrib contrib-type="author"><name><surname>Author</surname></name></contrib>
                <contrib contrib-type="editor"><name><surname>Editor</surname></name></contrib>
                <contrib contrib-type="translator"><name><surname>Translator</surname></name></contrib>
                <contrib contrib-type="review_assistant"><name><surname>Assistant</surname></name></contrib>
            </contrib-group>
            XML;

        $result = $this->modifyJats('modifyDefaultJats', $this->jats($contribGroup), 'jtest.pdf');

        $this->assertIsString($result);
        $xpath = $this->xpath($result);

        $types = [];
        foreach ($xpath->query('//article-meta/contrib-group/contrib') as $contrib) {
            $types[] = $contrib->getAttribute('contrib-type');
        }
        $this->assertSame(['author', 'editor'], $types);
    }

    public function testDefaultJatsRemovesSupplementaryMaterial(): void
    {
        $jats = $this->jats(
            '<supplementary-material xlink:href="https://example.org/index.php/j/article/download/1/2/3"'
            . ' xlink:title="Data set" mimetype="text/csv"/>'
        );

        $result = $this->modifyJats('modifyDefaultJats', $jats, 'jtest.pdf');

        $this->assertIsString($result);
        $xpath = $this->xpath($result);
        $this->assertSame(
            0,
            $xpath->query('//supplementary-material')->length,
            'Supplementary files are not packaged, so nothing may reference them'
        );
    }

    #[DataProvider('rejectedRelatedArticleTypeProvider')]
    public function testDefaultJatsRemapsRelatedArticleTypesPmcRejects(string $jatsType, string $pmcType): void
    {
        $jats = $this->jats(sprintf('<related-article related-article-type="%s" id="ra1"/>', $jatsType));

        $result = $this->modifyJats('modifyDefaultJats', $jats, 'jtest.pdf');

        $xpath = $this->xpath($result);
        $this->assertSame(
            $pmcType,
            $xpath->query('//article-meta/related-article')->item(0)->getAttribute('related-article-type')
        );
    }

    public static function rejectedRelatedArticleTypeProvider(): array
    {
        return [
            'expression of concern' => ['expression-of-concern', 'object-of-concern'],
            'partial retraction' => ['partial-retraction', 'retracted-article'],
        ];
    }

    public function testDefaultJatsLeavesSupportedRelatedArticleTypesAlone(): void
    {
        $jats = $this->jats('<related-article related-article-type="updated-article" id="ra1"/>');

        $result = $this->modifyJats('modifyDefaultJats', $jats, 'jtest.pdf');

        $xpath = $this->xpath($result);
        $this->assertSame(
            'updated-article',
            $xpath->query('//article-meta/related-article')->item(0)->getAttribute('related-article-type')
        );
    }

    public function testDefaultJatsUnwrapsNameAlternatives(): void
    {
        $contribGroup = <<<'XML'
            <contrib-group>
                <contrib contrib-type="author">
                    <name-alternatives>
                        <string-name specific-use="display">A. Author</string-name>
                        <name name-style="western" specific-use="primary">
                            <surname>Author</surname><given-names>Anne</given-names>
                        </name>
                    </name-alternatives>
                </contrib>
            </contrib-group>
            XML;

        $result = $this->modifyJats('modifyDefaultJats', $this->jats($contribGroup), 'jtest.pdf');

        $this->assertIsString($result);
        $xpath = $this->xpath($result);

        $this->assertSame(0, $xpath->query('//name-alternatives')->length);
        $this->assertSame(0, $xpath->query('//string-name')->length, 'PMC rejects string-name');

        $names = $xpath->query('//article-meta/contrib-group/contrib/name');
        $this->assertSame(1, $names->length);
        $this->assertSame('western', $names->item(0)->getAttribute('name-style'));
        $this->assertFalse(
            $names->item(0)->hasAttribute('specific-use'),
            'specific-use only distinguished the alternatives, so it goes with the wrapper'
        );
    }

    public function testDefaultJatsUnwrapsSingleChildNameAlternativesInSubArticles(): void
    {
        $subArticle = <<<'XML'
            <sub-article id="rr1" article-type="reviewer-report">
                <front-stub>
                    <contrib-group>
                        <contrib contrib-type="author">
                            <name-alternatives>
                                <name name-style="western" specific-use="primary">
                                    <surname>Reviewer</surname><given-names>A</given-names>
                                </name>
                            </name-alternatives>
                        </contrib>
                    </contrib-group>
                </front-stub>
            </sub-article>
            XML;

        $result = $this->modifyJats(
            'modifyDefaultJats',
            str_replace('</article>', $subArticle . '</article>', $this->jats()),
            'jtest.pdf'
        );

        $this->assertIsString($result);
        $xpath = $this->xpath($result);

        $this->assertSame(
            0,
            $xpath->query('//sub-article//name-alternatives')->length,
            'A lone name should not stay wrapped in name-alternatives'
        );
        $this->assertSame(
            1,
            $xpath->query('//sub-article/front-stub/contrib-group/contrib/name')->length
        );
    }

    public function testDefaultJatsMissingJournalMetaReturnsAnError(): void
    {
        $jats = '<?xml version="1.0"?><article><front><article-meta>'
            . '<abstract><p>An abstract.</p></abstract>'
            . '</article-meta></front></article>';

        $this->assertSame(
            ['plugins.importexport.pmc.export.failure.jatsNodeMissing', 'journal-meta'],
            $this->modifyJats('modifyDefaultJats', $jats, 'jtest.pdf')
        );
    }
}
