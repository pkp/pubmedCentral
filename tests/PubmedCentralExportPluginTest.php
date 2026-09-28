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
use APP\plugins\generic\pubmedCentral\classes\JatsDocument;
use APP\plugins\generic\pubmedCentral\PubmedCentralExportPlugin;
use APP\plugins\PubObjectsExportPlugin;
use APP\publication\enums\VersionStage;
use APP\publication\Publication;
use APP\submission\Repository as SubmissionRepository;
use APP\submission\Submission;
use APP\submissionFile\Repository as SubmissionFileRepository;
use PKP\db\DAORegistry;
use PKP\galley\Galley;
use PKP\jats\JatsFile;
use PKP\jats\Repository as JatsRepository;
use PKP\submission\Genre;
use PKP\submission\GenreDAO;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\LazyCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PKP\submissionFile\Collector as SubmissionFileCollector;
use PKP\submissionFile\enums\MediaVariantType;
use PKP\submissionFile\SubmissionFile;
use PKP\tests\PKPTestCase;
use ReflectionMethod;
use ZipArchive;

#[CoversClass(PubmedCentralExportPlugin::class)]
class PubmedCentralExportPluginTest extends PKPTestCase
{
    /**
     * A minimal JATS document. The xlink namespace is declared here because the
     * jatsTemplate plugin declares it on generated JATS. The journal-meta is
     * populated because prepareGenerated() requires it before it reaches
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
        app()->forgetInstance(SubmissionFileRepository::class);
        app()->forgetInstance(JatsRepository::class);
        app()->forgetInstance('file');
        parent::tearDown();
    }

    /**
     * @copydoc PKPTestCase::getMockedDAOs()
     */
    protected function getMockedDAOs(): array
    {
        return ['GenreDAO'];
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
     * Bind a submission file repository handing back the given media files, and a file
     * service resolving each file id to a path.
     *
     * @param array $paths [file id => path in the file store]
     */
    private function bindMediaFiles(array $mediaFiles, array $paths): MockObject
    {
        $collector = $this->createMock(SubmissionFileCollector::class);
        foreach (['filterBySubmissionIds', 'filterByFileStages', 'filterByAssoc'] as $filter) {
            $collector->method($filter)->willReturnSelf();
        }
        $collector->method('getMany')->willReturn(LazyCollection::make($mediaFiles));

        $submissionFileRepository = $this->createMock(SubmissionFileRepository::class);
        $submissionFileRepository->method('getCollector')->willReturn($collector);
        app()->instance(SubmissionFileRepository::class, $submissionFileRepository);

        // Stands in for the file store: resolves a file id to its path, and reads the
        // bytes that get packaged
        $fileService = new class ($paths) {
            public object $fs;

            public function __construct(private array $paths)
            {
                $this->fs = new class {
                    public function read(string $path): string
                    {
                        return "contents of {$path}";
                    }
                };
            }

            public function get(int $fileId): object
            {
                return (object) ['path' => $this->paths[$fileId]];
            }
        };
        app()->instance('file', $fileService);

        return $submissionFileRepository;
    }

    /**
     * Build a media file as the media files panel stores one.
     */
    private function createMediaFile(
        int $id,
        int $fileId,
        string $name,
        ?int $variantGroupId = null,
        ?MediaVariantType $variantType = null
    ): SubmissionFile {
        $mediaFile = new SubmissionFile();
        $mediaFile->setId($id);
        $mediaFile->setData('fileId', $fileId);
        $mediaFile->setData('name', ['en' => $name]);
        $mediaFile->setData('variantGroupId', $variantGroupId);
        $mediaFile->setData('variantType', $variantType?->value);
        return $mediaFile;
    }

    /**
     * Prepare a document that refers to media files, returning the result alongside the
     * media it recorded for packaging.
     *
     * @return array [result, packagedMedia]
     */
    private function prepareJatsWithMedia(string $jats, array $mediaFiles): array
    {
        $document = new JatsDocument($jats, 'jtest.pdf', $mediaFiles, 'jtest-2025-82');

        return [$document->prepare('J Test'), $document->getPackagedMedia()];
    }

    /**
     * Build the JATS fixture with a body, for the figures that only uploaded JATS carries.
     */
    private function jatsWithBody(string $body): string
    {
        return str_replace('</article>', "<body>{$body}</body></article>", $this->jats());
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
     * Prepare a document.
     */
    private function prepareJats(
        string $jats,
        string $articlePdfFilename,
        ?string $collectionYear = null
    ): string|array {
        return (new JatsDocument($jats, $articlePdfFilename))->prepare('J Test', $collectionYear);
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
            'host' => 'sftp.example.org',
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
            'username missing' => [['host' => 'sftp.example.org', 'password' => 'secret']],
            'password missing' => [['host' => 'sftp.example.org', 'username' => 'user']],
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
        $complete = ['host' => 'sftp.example.org', 'username' => 'user', 'password' => 'secret'];

        return [
            'complete' => [$complete, true],
            'nothing set' => [[], false],
            'blank strings' => [['host' => '', 'username' => '', 'password' => ''], false],
            'host only' => [['host' => 'sftp.example.org'], false],
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
     * Every name is derived from the publication, so without the version two versions of an
     * article would produce the same package name and the same names inside it. PMC reads the
     * uid as one part of the name, so the version goes inside it: "e12345.v2", not "e12345-v2".
     */
    public function testBuildFileNameCarriesTheVersion(): void
    {
        $plugin = $this->createPlugin(['namingType' => 'articleNumber']);
        $this->bindIssueRepository(12, 3, '2025-03-01');

        $publication = new Publication();
        $publication->setData('issueId', 7);
        $publication->setData('articleNumber', 'e12345');
        $publication->setData('versionMajor', 2);

        $this->assertSame(
            'jtest-2025-e12345.v2.xml',
            $this->invoke($plugin, 'buildFileName', ['J Test', $this->createJournal(), $publication, false, 'xml'])
        );
    }

    /**
     * The version separates the volume/issue scheme's names for the same reason.
     */
    public function testBuildFileNameCarriesTheVersionUnderTheVolumeIssueScheme(): void
    {
        $plugin = $this->createPlugin(['namingType' => 'volumeIssue']);
        $this->bindIssueRepository(12, 3);

        $publication = new Publication();
        $publication->setData('issueId', 7);
        $publication->setData('pages', '45-52');
        $publication->setData('versionMajor', 3);

        $this->assertSame(
            'jtest-12-3-45.v3.xml',
            $this->invoke($plugin, 'buildFileName', ['J Test', $this->createJournal(), $publication, false, 'xml'])
        );
    }

    /**
     * The version sits before the timestamp, so a package name stays sortable by article.
     */
    public function testBuildFileNamePlacesTheVersionBeforeTheTimestamp(): void
    {
        $plugin = $this->createPlugin(['namingType' => 'articleNumber']);
        $this->bindIssueRepository(12, 3, '2025-03-01');

        $publication = new Publication();
        $publication->setData('issueId', 7);
        $publication->setData('articleNumber', 'e12345');
        $publication->setData('versionMajor', 2);

        $this->assertMatchesRegularExpression(
            '/^jtest-2025-e12345\.v2-\d{14}\.zip$/',
            $this->invoke($plugin, 'buildFileName', ['J Test', $this->createJournal(), $publication, true, 'zip'])
        );
    }

    /**
     * A publication carrying no version number is named the way it always was.
     */
    public function testBuildFileNameOmitsAnUnknownVersion(): void
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
     * A dot is not one of them - PMC's own uids carry one - so it is kept.
     */
    public function testBuildFileNameStripsNonAlphanumericCharactersAndLowercases(): void
    {
        $plugin = $this->createPlugin(['namingType' => 'articleNumber']);
        $publication = new Publication();
        $publication->setData('articleNumber', 'e 12/345');

        $this->assertSame(
            'j.pubknowledge1-e12345',
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

    /**
     * Package an uploaded document referring to the given media files, skipping validation.
     *
     * @param SubmissionFile[] $mediaFiles
     * @param array $mediaPaths [file id => path in the file store] for the media files; ids
     *  12 and 13 are the PDF galley and the JATS
     *
     * @return array [plugin, package]
     */
    private function packageWithMedia(array $mediaFiles, array $mediaPaths, string $body): array
    {
        $plugin = $this->createPlugin(['nlmTitle' => 'J Test', 'namingType' => 'articleNumber']);

        $submissionFileRepository = $this->bindMediaFiles(
            $mediaFiles,
            [12 => 'journals/1/galley.pdf', 13 => 'journals/1/article.xml'] + $mediaPaths
        );

        // The PDF galley, and the uploaded JATS the document is read from
        $galleyFile = new SubmissionFile();
        $galleyFile->setData('mimetype', 'application/pdf');
        $galleyFile->setData('genreId', 4);
        $galleyFile->setData('fileId', 12);
        $submissionFileRepository->method('get')->willReturn($galleyFile);

        $jatsSubmissionFile = new SubmissionFile();
        $jatsSubmissionFile->setData('fileId', 13);
        $submissionFileRepository->method('getSubmissionFileContent')->willReturn($this->jatsWithBody($body));

        $jatsRepository = $this->createMock(JatsRepository::class);
        $jatsRepository->method('getJatsFile')->willReturn(new JatsFile(3, 5, $jatsSubmissionFile));
        app()->instance(JatsRepository::class, $jatsRepository);

        $genre = new Genre();
        $genre->setData('category', Genre::GENRE_CATEGORY_DOCUMENT);
        $genre->setData('supplementary', false);
        $genre->setData('dependent', false);
        $genreDao = $this->createMock(GenreDAO::class);
        $genreDao->method('getEnabledByContextId')->willReturn(collect([]));
        $genreDao->method('getById')->willReturn($genre);
        DAORegistry::registerDAO('GenreDAO', $genreDao);

        // The collection year, which the package name is built from
        $this->bindSubmissionRepository(['2025-03-01']);

        $galley = new Galley();
        $galley->setData('locale', 'en');
        $galley->setData('submissionFileId', 21);

        $publication = new Publication();
        $publication->setId(3);
        $publication->setData('submissionId', 5);
        $publication->setData('locale', 'en');
        $publication->setData('articleNumber', 'e12345');
        $publication->setData('galleys', [$galley]);

        // Validation is exercised by the validateJats() tests; running the style checker
        // here would only make the packaging slow to test
        return [$plugin, $plugin->createZip($publication, $this->createJournal(), true)];
    }

    //
    // createZip()
    //

    /**
     * PMC supports a single level of decompression: every file sits at the top of the
     * package, named alike, and nothing is nested in a directory.
     *
     * @see https://pmc.ncbi.nlm.nih.gov/pub/filespec-delivery/
     */
    public function testCreateZipPackagesTheXmlPdfAndMediaSideBySide(): void
    {
        [, $package] = $this->packageWithMedia(
            [$this->createMediaFile(1, 11, 'figure1.tif')],
            [11 => 'journals/1/figure1.tif'],
            '<fig id="f1"><graphic xlink:href="figure1.tif"/></fig>'
        );

        $this->assertArrayNotHasKey('error', $package);

        // The package itself carries a timestamp, which PMC reads as the revision
        $this->assertMatchesRegularExpression('/^jtest-2025-e12345-\d{14}$/', $package['filename']);

        $zip = new ZipArchive();
        $zip->open($package['path']);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        sort($names);

        $this->assertSame([
            'jtest-2025-e12345-g001.tif',
            'jtest-2025-e12345.pdf',
            'jtest-2025-e12345.xml',
        ], $names);

        unlink($package['path']);
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
    // JatsDocument - handling shared by both kinds of document
    //
    public function testSelfUriIsInsertedBeforeTheAbstract(): void
    {
        $result = $this->prepareJats($this->jats(), 'jtest-12-3-45.pdf');

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

    public function testSelfUriIsInsertedBeforeAnExistingSelfUri(): void
    {
        $jats = $this->jats('<self-uri content-type="html" xlink:href="article.html"/>');

        $result = $this->prepareJats($jats, 'jtest-12-3-45.pdf');

        $xpath = $this->xpath($result);
        $selfUris = $xpath->query('//article-meta/self-uri');

        $this->assertSame(2, $selfUris->length);
        $this->assertSame('pdf', $selfUris->item(0)->getAttribute('content-type'));
        $this->assertSame('html', $selfUris->item(1)->getAttribute('content-type'));
    }

    public function testExistingPdfSelfUrisAreReplaced(): void
    {
        $jats = $this->jats(
            '<self-uri content-type="pdf" xlink:href="old.pdf"/>' .
            '<self-uri content-type="application/pdf" xlink:href="older.pdf"/>'
        );

        $result = $this->prepareJats($jats, 'new.pdf');

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

    public function testEmptyParagraphsAreRemoved(): void
    {
        $jats = $this->jats('<self-uri content-type="html" xlink:href="a.html"/><p>   </p>');

        $result = $this->prepareJats($jats, 'jtest.pdf');

        $xpath = $this->xpath($result);
        // Only the abstract's non-empty paragraph should survive.
        $this->assertSame(1, $xpath->query('//p')->length);
        $this->assertSame('An abstract.', $xpath->query('//p')->item(0)->textContent);
    }

    /**
     * A rich-text editor writes a blank line as a paragraph holding a non-breaking space.
     * XPath's normalize-space() leaves that standing, but PMC counts it as empty and rejects
     * the paragraph, so emptiness has to be measured the way PMC measures it.
     */
    public function testParagraphsHoldingOnlyNonBreakingSpaceAreRemoved(): void
    {
        $jats = $this->jats("<p>\u{00A0}</p><p>\u{2003}\u{200B}</p><p>Real content.</p>");

        $result = $this->prepareJats($jats, 'jtest.pdf');

        $xpath = $this->xpath($result);
        $paragraphs = [];
        foreach ($xpath->query('//p') as $paragraph) {
            $paragraphs[] = $paragraph->textContent;
        }

        $this->assertSame(['Real content.', 'An abstract.'], $paragraphs);
    }

    /**
     * A paragraph is content to PMC as soon as it holds a child element, whatever its text.
     */
    public function testParagraphsHoldingAnElementAreKept(): void
    {
        $jats = $this->jats("<p>\u{00A0}<italic>x</italic></p>");

        $result = $this->prepareJats($jats, 'jtest.pdf');

        $this->assertSame(1, $this->xpath($result)->query('//p/italic')->length);
    }

    /**
     * The PMC style checker restricts journal-id-type to a fixed set of values, so an
     * identifier carrying any other type - or none - stops the deposit. OJS records its own,
     * and an uploaded document may carry identifiers from wherever it was produced.
     */
    public function testUnsupportedJournalIdsAreRemoved(): void
    {
        $jats = str_replace(
            '<journal-id journal-id-type="ojs">testjournal</journal-id>',
            '<journal-id journal-id-type="ojs">testjournal</journal-id>'
            . '<journal-id journal-id-type="publisher">Journal of Testing</journal-id>'
            . '<journal-id>untyped</journal-id>'
            . '<journal-id journal-id-type="publisher-id">jtest</journal-id>',
            $this->jats()
        );

        $result = $this->prepareJats($jats, 'jtest.pdf');

        $this->assertIsString($result);
        $xpath = $this->xpath($result);

        $this->assertSame(
            0,
            $xpath->query(
                '//journal-meta/journal-id[not(@journal-id-type)'
                . " or @journal-id-type='ojs' or @journal-id-type='publisher']"
            )->length,
            'Journal identifiers PMC does not accept should have been removed'
        );

        $supported = $xpath->query("//journal-meta/journal-id[@journal-id-type='publisher-id']");
        $this->assertSame(1, $supported->length, 'A supported journal-id should have been kept');
        $this->assertSame('jtest', $supported->item(0)->textContent);
    }

    public function testMissingArticleMetaReturnsAnError(): void
    {
        $jats = '<?xml version="1.0"?><article><front><journal-meta>'
            . '<journal-id journal-id-type="ojs">testjournal</journal-id>'
            . '<journal-title-group><journal-title>J</journal-title></journal-title-group>'
            . '</journal-meta></front></article>';

        $this->assertSame(
            ['plugins.importexport.pmc.export.failure.jatsNodeMissing', 'article-meta'],
            $this->prepareJats($jats, 'jtest.pdf')
        );
    }

    public function testMissingAbstractReturnsAnError(): void
    {
        $jats = '<?xml version="1.0"?><article><front><journal-meta>'
            . '<journal-id journal-id-type="ojs">testjournal</journal-id>'
            . '<journal-title-group><journal-title>J</journal-title></journal-title-group>'
            . '</journal-meta><article-meta>'
            . '<title-group><article-title>T</article-title></title-group>'
            . '</article-meta></front></article>';

        $this->assertSame(
            ['plugins.importexport.pmc.export.failure.jatsNodeMissing', 'abstract'],
            $this->prepareJats($jats, 'jtest.pdf')
        );
    }

    public function testMalformedXmlReturnsAnError(): void
    {
        // The methods rely on the caller having enabled internal error handling;
        // exportXML() does this before calling them.
        $previous = libxml_use_internal_errors(true);
        try {
            $result = $this->prepareJats('<article><front>', 'jtest.pdf');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $this->assertSame(['plugins.importexport.pmc.export.failure.loadJats'], $result);
    }

    public function testAnEmptyDocumentReturnsAnError(): void
    {
        $this->assertSame(
            ['plugins.importexport.pmc.export.failure.loadJats'],
            $this->prepareJats('', 'jtest.pdf')
        );
    }

    //
    // JatsDocument::prepareUploaded()
    //

    /**
     * Uploaded JATS is re-modified whenever a submission is re-exported, and the
     * result has to converge. prepareGenerated() has no equivalent test because it
     * always re-adds the pmc journal-id and abbrev-journal-title; it is only ever
     * handed freshly generated JATS.
     */
    public function testModifyCustomJatsIsIdempotent(): void
    {
        $once = $this->prepareJats($this->jats(), 'jtest.pdf');
        $twice = $this->prepareJats($once, 'jtest.pdf');

        $this->assertSame($once, $twice);
    }

    /**
     * PMC names a figure graphic -g###, an inline graphic -i### and anything else -s###,
     * all built from the package's own name, and every file in a package has to be
     * referenced from the XML -- so the document decides what is packaged.
     */
    public function testCustomJatsPointsMediaReferencesAtPackagedFiles(): void
    {
        $body = <<<'XML'
            <p>Text with an <inline-graphic xlink:href="logo.png"/> in it.</p>
            <fig id="f1"><graphic xlink:href="figure1.jpg"/></fig>
            <fig id="f2"><graphic xlink:href="figure2.jpg"/></fig>
            <supplementary-material xlink:href="dataset.csv"/>
            XML;

        [$result, $packaged] = $this->prepareJatsWithMedia($this->jatsWithBody($body), [
            'logo.png' => 'journals/1/logo-hi.tif',
            'figure1.jpg' => 'journals/1/figure1-hi.tif',
            'figure2.jpg' => 'journals/1/figure2-hi.tif',
            'dataset.csv' => 'journals/1/dataset.csv',
        ]);

        $this->assertIsString($result);
        $xpath = $this->xpath($result);

        $this->assertSame(
            'jtest-2025-82-i001.tif',
            $xpath->evaluate('string(//inline-graphic/@xlink:href)')
        );
        $this->assertSame(
            'jtest-2025-82-g001.tif',
            $xpath->evaluate('string(//fig[@id="f1"]/graphic/@xlink:href)')
        );
        $this->assertSame(
            'jtest-2025-82-g002.tif',
            $xpath->evaluate('string(//fig[@id="f2"]/graphic/@xlink:href)')
        );
        $this->assertSame(
            'jtest-2025-82-s001.csv',
            $xpath->evaluate('string(//supplementary-material/@xlink:href)')
        );

        $this->assertSame([
            'jtest-2025-82-i001.tif' => 'journals/1/logo-hi.tif',
            'jtest-2025-82-g001.tif' => 'journals/1/figure1-hi.tif',
            'jtest-2025-82-g002.tif' => 'journals/1/figure2-hi.tif',
            'jtest-2025-82-s001.csv' => 'journals/1/dataset.csv',
        ], $packaged);
    }

    /**
     * A file name in the document is matched however the depositor wrote it, and a file
     * referred to twice is packaged once under one name.
     */
    public function testCustomJatsPackagesAFileReferencedTwiceOnce(): void
    {
        $body = <<<'XML'
            <fig id="f1"><graphic xlink:href="Figure1.JPG"/></fig>
            <fig id="f2"><graphic xlink:href="images/figure1.jpg"/></fig>
            XML;

        [$result, $packaged] = $this->prepareJatsWithMedia($this->jatsWithBody($body), [
            'figure1.jpg' => 'journals/1/figure1.jpg',
        ]);

        $xpath = $this->xpath($result);
        $this->assertSame(
            ['jtest-2025-82-g001.jpg', 'jtest-2025-82-g001.jpg'],
            array_map(
                fn ($node) => $node->getAttribute('xlink:href'),
                iterator_to_array($xpath->query('//graphic'))
            )
        );
        $this->assertSame(['jtest-2025-82-g001.jpg' => 'journals/1/figure1.jpg'], $packaged);
    }

    /**
     * PMC rejects a package whose XML points at a file that is not in it, so an export
     * that cannot resolve a reference is stopped with the file named.
     */
    public function testCustomJatsReportsAReferenceWithNoMediaFile(): void
    {
        $body = '<fig id="f1"><graphic xlink:href="figure1.jpg"/></fig>';

        [$result, $packaged] = $this->prepareJatsWithMedia($this->jatsWithBody($body), []);

        $this->assertSame('plugins.importexport.pmc.export.failure.missingMediaFile', $result[0]);
        $this->assertSame(
            __('plugins.importexport.pmc.export.failure.missingMediaFile.none', ['reference' => 'figure1.jpg']),
            $result[1]
        );
        $this->assertSame([], $packaged);
    }

    /**
     * The mismatch is usually a media file whose name has drifted from the one the
     * document was written against, so the names that are available are reported too.
     */
    public function testCustomJatsNamesTheAvailableMediaFilesWhenAReferenceIsUnmatched(): void
    {
        $body = '<fig id="f1"><graphic xlink:href="figure1.jpg"/></fig>';

        [$result] = $this->prepareJatsWithMedia($this->jatsWithBody($body), [
            'xyz_figure1.tif' => 'journals/1/aaa.tif',
            'dataset.csv' => 'journals/1/bbb.csv',
        ]);

        $this->assertSame('plugins.importexport.pmc.export.failure.missingMediaFile', $result[0]);
        $this->assertSame(
            __('plugins.importexport.pmc.export.failure.missingMediaFile.available', [
                'reference' => 'figure1.jpg',
                'files' => 'xyz_figure1.tif, dataset.csv',
            ]),
            $result[1]
        );
    }

    /**
     * A document is often written against a file whose extension has since changed, or
     * which was uploaded in another format, so the name without its extension is matched
     * too. The packaged file keeps the extension of the file that was actually uploaded.
     */
    public function testCustomJatsMatchesAReferenceOnTheNameWithoutItsExtension(): void
    {
        $body = '<fig id="f1"><graphic xlink:href="figure1.tif"/></fig>';

        [$result, $packaged] = $this->prepareJatsWithMedia($this->jatsWithBody($body), [
            'figure1.jpg' => 'journals/1/figure1.jpg',
        ]);

        $this->assertIsString($result);
        $this->assertSame(
            'jtest-2025-82-g001.jpg',
            $this->xpath($result)->evaluate('string(//graphic/@xlink:href)')
        );
        $this->assertSame(['jtest-2025-82-g001.jpg' => 'journals/1/figure1.jpg'], $packaged);
    }

    /**
     * Packaging the wrong image is worse than stopping the export, so a name that could
     * be answered with either of two files is no match at all.
     */
    public function testCustomJatsWillNotGuessBetweenMediaFilesSharingAName(): void
    {
        $body = '<fig id="f1"><graphic xlink:href="figure1.png"/></fig>';

        [$result, $packaged] = $this->prepareJatsWithMedia($this->jatsWithBody($body), [
            'figure1.jpg' => 'journals/1/figure1.jpg',
            'figure1.tif' => 'journals/1/figure1.tif',
        ]);

        $this->assertSame('plugins.importexport.pmc.export.failure.missingMediaFile', $result[0]);
        $this->assertSame([], $packaged);
    }

    /**
     * A reference to somewhere else on the web is the depositor's to keep: there is no
     * file of ours behind it to package.
     */
    public function testCustomJatsLeavesExternalReferencesAlone(): void
    {
        $body = '<fig id="f1"><graphic xlink:href="https://example.org/figure1.jpg"/></fig>';

        [$result, $packaged] = $this->prepareJatsWithMedia($this->jatsWithBody($body), []);

        $this->assertIsString($result);
        $this->assertSame(
            'https://example.org/figure1.jpg',
            $this->xpath($result)->evaluate('string(//graphic/@xlink:href)')
        );
        $this->assertSame([], $packaged);
    }

    /**
     * Every file in a PMC package has to be referenced from the XML, so a media file the
     * document never mentions is not packaged.
     */
    public function testCustomJatsPackagesOnlyReferencedMediaFiles(): void
    {
        [$result, $packaged] = $this->prepareJatsWithMedia($this->jats(), [
            'figure1.jpg' => 'journals/1/figure1.jpg',
        ]);

        $this->assertIsString($result);
        $this->assertSame([], $packaged);
    }

    //
    // getMediaFiles()
    //

    /**
     * PMC asks for the highest resolution available, so a document referring to the web
     * version of an image is answered with the high-resolution file linked to it. Both
     * names resolve to it, because a document may refer to either.
     */
    public function testMediaFilesResolveToTheHighResolutionVariant(): void
    {
        $this->bindMediaFiles(
            [
                $this->createMediaFile(1, 11, 'figure1.jpg', 7, MediaVariantType::WEB),
                $this->createMediaFile(2, 12, 'figure1.tif', 7, MediaVariantType::HIGH_RESOLUTION),
            ],
            [11 => 'journals/1/aaa.jpg', 12 => 'journals/1/bbb.tif']
        );

        $publication = new Publication();
        $publication->setId(3);
        $publication->setData('submissionId', 5);

        $mediaFiles = $this->invoke($this->createPlugin(), 'getMediaFiles', [$publication]);

        $this->assertSame([
            'figure1.jpg' => 'journals/1/bbb.tif',
            'figure1.tif' => 'journals/1/bbb.tif',
        ], $mediaFiles);
    }

    /**
     * A high-resolution file that was never linked to a counterpart stands for itself.
     * Grouping the unlinked files together would key them all alike, and every one of
     * them would resolve to whichever high-resolution file was uploaded.
     */
    public function testUnlinkedMediaFilesDoNotResolveToAnUnlinkedHighResolutionFile(): void
    {
        $this->bindMediaFiles(
            [
                $this->createMediaFile(1, 11, 'figure1.tif', null, MediaVariantType::HIGH_RESOLUTION),
                $this->createMediaFile(2, 12, 'logo.png'),
            ],
            [11 => 'journals/1/aaa.tif', 12 => 'journals/1/bbb.png']
        );

        $publication = new Publication();
        $publication->setId(3);
        $publication->setData('submissionId', 5);

        $mediaFiles = $this->invoke($this->createPlugin(), 'getMediaFiles', [$publication]);

        $this->assertSame([
            'figure1.tif' => 'journals/1/aaa.tif',
            'logo.png' => 'journals/1/bbb.png',
        ], $mediaFiles);
    }

    /**
     * A media file with no high-resolution counterpart is packaged as it is.
     */
    public function testMediaFilesWithoutAVariantArePackagedAsUploaded(): void
    {
        $this->bindMediaFiles(
            [$this->createMediaFile(1, 11, 'Figure1.PNG')],
            [11 => 'journals/1/aaa.png']
        );

        $publication = new Publication();
        $publication->setId(3);
        $publication->setData('submissionId', 5);

        $mediaFiles = $this->invoke($this->createPlugin(), 'getMediaFiles', [$publication]);

        // Indexed in lower case: the document may name the file however it likes
        $this->assertSame(
            ['figure1.png' => 'journals/1/aaa.png'],
            $mediaFiles
        );
    }

    //
    // JatsDocument::prepareGenerated() - PMC-specific transforms
    //
    public function testDefaultJatsAddsThePmcJournalIdAsTheFirstChild(): void
    {
        $result = $this->prepareJats($this->jats(), 'jtest.pdf');

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
        $result = $this->prepareJats($this->jats(), 'jtest.pdf');

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
        $result = $this->prepareJats($this->jats(), 'jtest.pdf', '2025');

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
        $result = $this->prepareJats($this->jats(), 'jtest.pdf');

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

        $result = $this->prepareJats($jats, 'jtest.pdf', '2025');

        $this->assertIsString($result);
        $this->assertSame(0, $this->xpath($result)->query('//pub-date')->length);
    }

    public function testDefaultJatsSetsTheArticleType(): void
    {
        $result = $this->prepareJats($this->jats(), 'jtest.pdf');

        $xpath = $this->xpath($result);
        $this->assertSame(
            'research-article',
            $xpath->query('/article')->item(0)->getAttribute('article-type')
        );
    }

    /**
     * The article-type follows the section the article was published in, using the
     * Open Research Europe section names; anything else is a research article.
     */
    #[DataProvider('sectionArticleTypeProvider')]
    public function testDefaultJatsMapsTheSectionToThePmcArticleType(string $section, string $articleType): void
    {
        $categories = sprintf(
            '<article-categories><subj-group subj-group-type="heading"><subject>%s</subject></subj-group></article-categories>',
            $section
        );

        $result = $this->prepareJats($this->jats($categories), 'jtest.pdf');

        $this->assertSame(
            $articleType,
            $this->xpath($result)->query('/article')->item(0)->getAttribute('article-type')
        );
    }

    public static function sectionArticleTypeProvider(): array
    {
        return [
            'open letter' => ['Open Letter', 'letter'],
            'review' => ['Review', 'review-article'],
            'study protocol' => ['Study Protocol', 'other'],
            'systematic review' => ['Systematic Review', 'systematic-review'],
            'data note' => ['Data Note', 'data-paper'],
            'method article' => ['Method Article', 'methods-article'],
            'brief report' => ['Brief Report', 'brief-report'],
            'retraction' => ['Retraction', 'retraction'],
            'case differs' => ['OPEN LETTER', 'letter'],
            'surrounding space' => [' Review ', 'review-article'],
            'generic articles section' => ['Articles', 'research-article'],
            'unknown section' => ['Poetry Corner', 'research-article'],
        ];
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

        $result = $this->prepareJats($this->jats($contribGroup), 'jtest.pdf');

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

        $result = $this->prepareJats($jats, 'jtest.pdf');

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

        $result = $this->prepareJats($jats, 'jtest.pdf');

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

        $result = $this->prepareJats($jats, 'jtest.pdf');

        $xpath = $this->xpath($result);
        $this->assertSame(
            'updated-article',
            $xpath->query('//article-meta/related-article')->item(0)->getAttribute('related-article-type')
        );
    }

    public function testPeerReviewRelatedObjectsAreRewrittenForPmc(): void
    {
        $subArticles = <<<'XML'
            <sub-article id="rr1" article-type="reviewer-report">
                <front-stub>
                    <related-object id="ro1" document-id="10.1234/test.1" document-id-type="doi"
                        document-type="peer-reviewed-article"/>
                </front-stub>
            </sub-article>
            <sub-article id="ar1" article-type="author-comment">
                <front-stub>
                    <related-object id="ro2" document-id="10.1234/test.r1" document-id-type="doi"
                        document-type="reviewer-report"/>
                </front-stub>
            </sub-article>
            XML;

        $result = $this->prepareJats(
            str_replace('</article>', $subArticles . '</article>', $this->jats()),
            'jtest.pdf'
        );

        $this->assertIsString($result);
        $xpath = $this->xpath($result);

        $reviewedArticle = $xpath->query('//sub-article[@id="rr1"]/front-stub/related-object')->item(0);
        $this->assertSame('article', $reviewedArticle->getAttribute('document-type'));
        $this->assertSame('peer-reviewed-article', $reviewedArticle->getAttribute('link-type'));
        $this->assertSame('10.1234/test.1', $reviewedArticle->getAttribute('document-id'), 'The target is kept');

        $reviewerReport = $xpath->query('//sub-article[@id="ar1"]/front-stub/related-object')->item(0);
        $this->assertSame('article', $reviewerReport->getAttribute('document-type'));
        $this->assertSame('peer-review', $reviewerReport->getAttribute('link-type'));
    }

    public function testRelatedObjectsNamingOtherThingsAreLeftAlone(): void
    {
        $subArticle = <<<'XML'
            <sub-article id="rr1" article-type="reviewer-report">
                <front-stub>
                    <related-object id="ro1" document-id="10.1234/book" document-id-type="doi"
                        document-type="chapter"/>
                </front-stub>
            </sub-article>
            XML;

        $result = $this->prepareJats(
            str_replace('</article>', $subArticle . '</article>', $this->jats()),
            'jtest.pdf'
        );

        $relatedObject = $this->xpath($result)->query('//related-object')->item(0);
        $this->assertSame('chapter', $relatedObject->getAttribute('document-type'));
        $this->assertFalse($relatedObject->hasAttribute('link-type'));
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

        $result = $this->prepareJats($this->jats($contribGroup), 'jtest.pdf');

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

        $result = $this->prepareJats(
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

    /**
     * Body text may refer to the publication's media files the same way an uploaded
     * document does, and those references are packaged and renamed alike.
     */
    public function testDefaultJatsPointsMediaReferencesAtPackagedFiles(): void
    {
        $body = <<<'XML'
            <p>Text with an <inline-graphic xlink:href="logo.png"/> in it.</p>
            <fig id="f1"><graphic xlink:href="figure1.jpg"/></fig>
            XML;

        [$result, $packaged] = $this->prepareJatsWithMedia(
            $this->jatsWithBody($body),
            [
                'logo.png' => 'journals/1/logo-hi.tif',
                'figure1.jpg' => 'journals/1/figure1-hi.tif',
            ]
        );

        $this->assertIsString($result);
        $xpath = $this->xpath($result);

        $this->assertSame(
            'jtest-2025-82-i001.tif',
            $xpath->evaluate('string(//inline-graphic/@xlink:href)')
        );
        $this->assertSame(
            'jtest-2025-82-g001.tif',
            $xpath->evaluate('string(//fig[@id="f1"]/graphic/@xlink:href)')
        );
        $this->assertSame([
            'jtest-2025-82-i001.tif' => 'journals/1/logo-hi.tif',
            'jtest-2025-82-g001.tif' => 'journals/1/figure1-hi.tif',
        ], $packaged);
    }

    public function testDefaultJatsReportsAReferenceWithNoMediaFile(): void
    {
        $body = '<fig id="f1"><graphic xlink:href="figure1.jpg"/></fig>';

        [$result, $packaged] = $this->prepareJatsWithMedia(
            $this->jatsWithBody($body),
            []
        );

        $this->assertSame('plugins.importexport.pmc.export.failure.missingMediaFile', $result[0]);
        $this->assertSame([], $packaged);
    }

    /**
     * Supplementary material naming one of the publication's media files is deposited
     * with the article, whether the document was generated or uploaded.
     */
    public function testSupplementaryMaterialResolvedToAMediaFileIsKept(): void
    {
        $body = '<p>Text.</p><supplementary-material xlink:href="dataset.csv"/>';

        [$result, $packaged] = $this->prepareJatsWithMedia(
            $this->jatsWithBody($body),
            ['dataset.csv' => 'journals/1/dataset.csv']
        );

        $this->assertIsString($result);
        $this->assertSame(
            'jtest-2025-82-s001.csv',
            $this->xpath($result)->evaluate('string(//supplementary-material/@xlink:href)')
        );
        $this->assertSame(['jtest-2025-82-s001.csv' => 'journals/1/dataset.csv'], $packaged);
    }

    /**
     * Supplementary material left pointing at the web is dropped, and nothing is packaged
     * for it: PMC rejects a reference that cannot resolve inside the package.
     */
    public function testSupplementaryMaterialPointingAtTheWebIsDropped(): void
    {
        $body = '<p>Text.</p><supplementary-material xlink:href="https://example.org/download/1/2/3"/>';

        [$result, $packaged] = $this->prepareJatsWithMedia($this->jatsWithBody($body), []);

        $this->assertIsString($result);
        $this->assertSame(0, $this->xpath($result)->query('//supplementary-material')->length);
        $this->assertSame([], $packaged);
    }

    //
    // JatsDocument::prepare() - work a document already carries
    //

    /**
     * A document prepared once and uploaded again is prepared again, so every step that
     * adds something has to leave a document that already has it alone.
     */
    public function testThePmcJournalIdIsNotAddedTwice(): void
    {
        $jats = str_replace(
            '<journal-id journal-id-type="ojs">testjournal</journal-id>',
            '<journal-id journal-id-type="pmc">J Test</journal-id>',
            $this->jats()
        );

        $result = $this->prepareJats($jats, 'jtest.pdf');

        $this->assertIsString($result);
        $this->assertSame(
            1,
            $this->xpath($result)->query("//journal-meta/journal-id[@journal-id-type='pmc']")->length
        );
    }

    public function testTheAbbreviatedJournalTitleIsNotAddedTwice(): void
    {
        $jats = str_replace(
            '<journal-title>Journal of Testing</journal-title>',
            '<journal-title>Journal of Testing</journal-title>'
                . '<abbrev-journal-title abbrev-type="nlm-ta">J Test</abbrev-journal-title>',
            $this->jats()
        );

        $result = $this->prepareJats($jats, 'jtest.pdf');

        $this->assertIsString($result);
        $this->assertSame(
            1,
            $this->xpath($result)->query("//journal-title-group/abbrev-journal-title[@abbrev-type='nlm-ta']")->length
        );
    }

    /**
     * PMC reads a collection date only alongside an electronic publication date written
     * the same way, so a document using the older @pub-type gets one to match.
     */
    public function testTheCollectionDateFollowsThePublicationDateSpelling(): void
    {
        $jats = str_replace(
            '<pub-date publication-format="electronic" date-type="pub"><year>2026</year></pub-date>',
            '<pub-date pub-type="epub"><day>8</day><month>1</month><year>2026</year></pub-date>',
            $this->jats()
        );

        $result = $this->prepareJats($jats, 'jtest.pdf', '2025');

        $this->assertIsString($result);
        $xpath = $this->xpath($result);
        $this->assertSame(
            '2025',
            $xpath->evaluate("string(//pub-date[@pub-type='collection']/year)")
        );
        $this->assertSame(
            0,
            $xpath->query("//pub-date[@date-type='collection']")->length,
            'The two spellings should not be mixed in one document'
        );
    }

    public function testACollectionDateInTheOlderSpellingIsNotDuplicated(): void
    {
        $jats = $this->jats('<pub-date pub-type="collection"><year>2024</year></pub-date>');

        $result = $this->prepareJats($jats, 'jtest.pdf', '2025');

        $this->assertIsString($result);
        $this->assertSame(1, $this->xpath($result)->query("//pub-date[@pub-type='collection']")->length);
        $this->assertSame(
            0,
            $this->xpath($result)->query("//pub-date[@date-type='collection']")->length
        );
    }

    public function testTheCollectionDateIsNotAddedTwice(): void
    {
        $jats = $this->jats(
            '<pub-date date-type="collection" publication-format="electronic"><year>2024</year></pub-date>'
        );

        $result = $this->prepareJats($jats, 'jtest.pdf', '2025');

        $this->assertIsString($result);
        $xpath = $this->xpath($result);
        $this->assertSame(1, $xpath->query("//pub-date[@date-type='collection']")->length);
        $this->assertSame(
            '2024',
            $xpath->evaluate("string(//pub-date[@date-type='collection']/year)"),
            "The document's own collection date is left as it was written"
        );
    }

    /**
     * The section mapping fills in an article-type a generated document arrives without;
     * a document that declares one keeps it.
     */
    public function testAnArticleTypeTheDocumentDeclaresIsKept(): void
    {
        $jats = str_replace('<article ', '<article article-type="case-report" ', $this->jats());

        $result = $this->prepareJats($jats, 'jtest.pdf');

        $this->assertIsString($result);
        $this->assertSame(
            'case-report',
            $this->xpath($result)->query('/article')->item(0)->getAttribute('article-type')
        );
    }

    public function testDefaultJatsMissingJournalMetaReturnsAnError(): void
    {
        $jats = '<?xml version="1.0"?><article><front><article-meta>'
            . '<abstract><p>An abstract.</p></abstract>'
            . '</article-meta></front></article>';

        $this->assertSame(
            ['plugins.importexport.pmc.export.failure.jatsNodeMissing', 'journal-meta'],
            $this->prepareJats($jats, 'jtest.pdf')
        );
    }

    //
    // getExportableVersionStages()
    //
    public function testOnlyVersionsOfRecordAreListedForDeposit(): void
    {
        $this->assertSame([VersionStage::VERSION_OF_RECORD], $this->createPlugin()->getExportableVersionStages());
    }
}
