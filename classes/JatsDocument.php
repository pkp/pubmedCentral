<?php

/**
 * @file classes/JatsDocument.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class JatsDocument
 *
 * @brief A JATS document being prepared for deposit to PubMed Central.
 *
 * A document reaches PMC either generated from the submission's metadata by the JATS
 * Template plugin, or uploaded by the depositor, and both are prepared the same way:
 * PMC's requirements are the same whatever the document was written with, and an
 * uploaded document may be derived from OJS's own JATS, saved and edited. Every step either
 * returns an error message in the plugin's [key, detail] form, or leaves the document
 * changed in place; a step whose work is already done leaves it alone.
 */

namespace APP\plugins\generic\pubmedCentral\classes;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class JatsDocument
{
    /**
     * JATS related-article-type values PMC does not accept, mapped to the nearest
     * value its style checker allows.
     */
    protected const PMC_RELATED_ARTICLE_TYPES = [
        'expression-of-concern' => 'object-of-concern',
        'partial-retraction' => 'retracted-article',
    ];

    /**
     * The document-type values the JATS4R peer review recommendation puts on a
     * related-object, mapped to the related-article-type PMC gives the same relationship.
     *
     * @see https://pmc.ncbi.nlm.nih.gov/tagging-guidelines/article/dobs/#dob-peerreviewdocs
     */
    protected const PMC_PEER_REVIEW_RELATED_ARTICLE_TYPES = [
        'peer-reviewed-article' => 'reviewed-article',
        'peer-review-report' => 'reviewer-report',
        'reviewer-report' => 'reviewer-report',
    ];

    /**
     * The elements the JATS content model places after volume in article-meta.
     */
    protected const AFTER_VOLUME = [
        'volume-id', 'volume-series', 'issue', 'issue-id', 'issue-title', 'issue-sponsor',
        'issue-part', 'volume-issue-group', 'isbn', 'supplement', 'fpage', 'lpage', 'page-range',
        'elocation-id', 'email', 'ext-link', 'uri', 'product', 'supplementary-material', 'history',
        'pub-history', 'permissions', 'self-uri', 'related-article', 'related-object', 'abstract',
        'trans-abstract', 'kwd-group', 'funding-group', 'support-group', 'conference', 'counts',
        'custom-meta-group',
    ];

    /**
     * The article-type PMC gives each kind of article a journal section holds, keyed by
     * the section's title in lower case. This is a customization for Open Research
     * Europe, whose sections are the ones listed; a section that is not listed, such as
     * its generic "Articles" and "Research Articles" sections, is exported as a research
     * article. Clinical practice and software tool articles report research and have no
     * PMC type of their own, and PMC keeps "other" for what fits nothing else, which is
     * where it places study protocols and essays.
     *
     * @see https://pmc.ncbi.nlm.nih.gov/tagging-guidelines/article/dobs/#dob-artype
     */
    protected const ORE_SECTION_ARTICLE_TYPES = [
        'brief report' => 'brief-report',
        'case report' => 'case-report',
        'case study' => 'case-study',
        'clinical practice article' => 'research-article',
        'data note' => 'data-paper',
        'essay' => 'other',
        'method article' => 'methods-article',
        'open letter' => 'letter',
        'research article' => 'research-article',
        'review' => 'review-article',
        'software tool article' => 'research-article',
        'study protocol' => 'other',
        'systematic review' => 'systematic-review',
        'retraction' => 'retraction',
    ];

    /**
     * The article-type given to an article whose section is not mapped.
     */
    protected const DEFAULT_ARTICLE_TYPE = 'research-article';

    /**
     * The characters PMC's style checker collapses before deciding whether an element is
     * empty. XPath's own normalize-space() covers only space, tab, CR and LF, so a paragraph
     * holding nothing but a non-breaking space reads as empty to PMC and as content here.
     *
     * @see xsl/stylecheck-named-tests.xsl, the really-normalize-space template
     */
    protected const PMC_SPACE_CHARACTERS = "\u{0020}\u{00A0}\u{1361}\u{1680}"
        . "\u{2002}\u{2003}\u{2004}\u{2005}\u{2006}\u{2007}"
        . "\u{2008}\u{2009}\u{200A}\u{200B}\u{202F}\u{205F}"
        . "\u{2420}\u{3000}\u{303F}\u{FEFF}";

    /**
     * The journal-id-type values the PMC style checker accepts. OJS records its own journal
     * identifiers, and an uploaded document may carry identifiers from wherever it was
     * produced; PMC can resolve neither, and rejects the deposit over them.
     *
     * @see xsl/stylecheck-named-tests.xsl, the journal-id-check template
     */
    protected const PMC_JOURNAL_ID_TYPES = [
        'archive',
        'aggregator',
        'coden',
        'doi',
        'hwp',
        'index',
        'iso-abbrev',
        'issn',
        'nlm-journal-id',
        'nlm-ta',
        'pmc',
        'pubmed-jr-id',
        'publisher-id',
        'sc',
    ];

    /**
     * The XLink namespace, which JATS uses for every reference to a packaged file.
     */
    protected const XLINK_NS = 'http://www.w3.org/1999/xlink';

    /**
     * A reference to somewhere else on the web, rather than to a file of the publication's
     * that can be packaged alongside the article.
     */
    protected const EXTERNAL_REFERENCE = '#^[a-z][a-z0-9+.-]*:#i';

    /**
     * The elements whose @xlink:href points at a file that has to be packaged, mapped
     * to the component PMC's naming scheme gives that kind of file.
     */
    protected const MEDIA_ELEMENT_TYPES = [
        'graphic' => 'g',
        'inline-graphic' => 'i',
        'media' => 's',
        'supplementary-material' => 's',
    ];

    protected DOMDocument $dom;
    protected DOMXPath $xpath;

    /**
     * The media files the document refers to, as [packaged file name => path in the file
     * store]. Filled in while the document is prepared, and read back to decide what goes
     * into the package alongside it.
     */
    protected array $packagedMedia = [];

    /**
     * @param string $jats The document as it was generated or uploaded
     * @param string $articlePdfFilename The name the article PDF is packaged under
     * @param array $mediaFiles The publication's media files, file store paths keyed by file name
     * @param string $mediaBaseName The package's base file name, which the names given to
     *  packaged media files are built from
     */
    public function __construct(
        protected string $jats,
        protected string $articlePdfFilename,
        protected array $mediaFiles = [],
        protected string $mediaBaseName = ''
    ) {
    }

    /**
     * Prepare the document for deposit.
     *
     * @return string|array The prepared document, or an error message
     */
    public function prepare(string $nlmTitle, ?string $collectionYear = null): string|array
    {
        if ($error = $this->open()) {
            return $error;
        }

        if ($error = $this->addPmcJournalId($nlmTitle)) {
            return $error;
        }

        $this->removeUnsupportedJournalIds();

        if ($error = $this->addAbbreviatedJournalTitle($nlmTitle)) {
            return $error;
        }

        if ($error = $this->removeUnsupportedContributors()) {
            return $error;
        }

        $this->unwrapNameAlternatives();
        $this->removeDuplicateCompetingInterests();
        $this->addCollectionDate($collectionYear);
        $this->addVolume();

        if ($error = $this->packageMediaReferences()) {
            return $error;
        }

        // Runs after packaging, which is what decides whether a reference resolves
        $this->removeUnpackagedSupplementaryMaterial();

        if ($error = $this->addSelfUri()) {
            return $error;
        }

        $this->remapRelatedArticleTypes();
        $this->convertPeerReviewRelatedObjects();
        $this->removeEmptyParagraphs();
        $this->setArticleType();

        return $this->dom->saveXML();
    }

    /**
     * The media files the prepared document refers to, as [packaged file name => path in
     * the file store].
     */
    public function getPackagedMedia(): array
    {
        return $this->packagedMedia;
    }

    /**
     * Load the document, ready for the steps that follow.
     */
    protected function open(): ?array
    {
        $this->packagedMedia = [];

        $this->dom = new DOMDocument();
        $this->dom->preserveWhiteSpace = false;

        // loadXML() raises a ValueError rather than failing on an empty document
        if ($this->jats === '' || !$this->dom->loadXML($this->jats)) {
            return ['plugins.importexport.pmc.export.failure.loadJats'];
        }

        $this->xpath = new DOMXPath($this->dom);

        return null;
    }

    /**
     * Add the journal's PMC identifier, which every deposit is filed under.
     */
    protected function addPmcJournalId(string $nlmTitle): ?array
    {
        if (!$journalMeta = $this->journalMeta()) {
            return ['plugins.importexport.pmc.export.failure.jatsNodeMissing', 'journal-meta'];
        }

        if ($this->xpath->query("journal-id[@journal-id-type='pmc']", $journalMeta)->length) {
            return null;
        }

        if (!$firstChild = $this->xpath->query('*[1]', $journalMeta)->item(0)) {
            return ['plugins.importexport.pmc.export.failure.jatsNodeMissing', 'journal-meta[1]'];
        }

        $journalId = $this->dom->createElement('journal-id', $nlmTitle);
        $journalId->setAttribute('journal-id-type', 'pmc');
        $journalMeta->insertBefore($journalId, $firstChild);

        return null;
    }

    /**
     * Remove every journal-id whose type the PMC style checker does not accept, including
     * one carrying no journal-id-type at all.
     *
     * The identifier names nothing PMC can resolve, so it is dropped rather than left to
     * stop the deposit. Both the generated and the uploaded document are treated alike:
     * an uploaded one is often OJS's own JATS, saved and edited.
     */
    protected function removeUnsupportedJournalIds(): void
    {
        $supported = implode(' or ', array_map(
            fn (string $type) => "@journal-id-type='{$type}'",
            self::PMC_JOURNAL_ID_TYPES
        ));

        $this->removeNodes("//article/front/journal-meta/journal-id[not({$supported})]");
    }

    /**
     * Add the NLM title abbreviation as the abbreviated journal title.
     */
    protected function addAbbreviatedJournalTitle(string $nlmTitle): ?array
    {
        if (!$titleGroup = $this->xpath->query('//article/front/journal-meta/journal-title-group')->item(0)) {
            return ['plugins.importexport.pmc.export.failure.jatsNodeMissing', 'journal-title-group'];
        }

        if ($this->xpath->query("abbrev-journal-title[@abbrev-type='nlm-ta']", $titleGroup)->length) {
            return null;
        }

        $abbreviated = $this->dom->createElement('abbrev-journal-title');
        $abbreviated->setAttribute('abbrev-type', 'nlm-ta');
        $abbreviated->appendChild($this->dom->createTextNode($nlmTitle));
        $titleGroup->appendChild($abbreviated);

        return null;
    }

    /**
     * Remove the contributors PMC does not accept: the journal's editorial team,
     * and in the article metadata any contributor but an author or editor.
     */
    protected function removeUnsupportedContributors(): ?array
    {
        if ($journalMeta = $this->journalMeta()) {
            $this->removeNodes('contrib-group', $journalMeta);
        }

        if (!$articleMeta = $this->articleMeta()) {
            return ['plugins.importexport.pmc.export.failure.jatsNodeMissing', 'article-meta'];
        }

        // What only the removed contributors referred to goes with them: an affiliation or
        // competing interests statement left behind would be published for no one listed.
        // Anything the document never linked to a contributor stays as it is.
        $removed = $this->xpath->query(
            "contrib-group/contrib[not(@contrib-type='author' or @contrib-type='editor')]",
            $articleMeta
        );
        $orphanCandidates = $this->referencedIds($removed);
        foreach ($removed as $contrib) {
            $contrib->parentNode->removeChild($contrib);
        }

        $stillReferenced = $this->referencedIds([$this->dom->documentElement]);
        $ownedByContributors = "aff[@id] | contrib-group/aff[@id] | author-notes/fn[@id][translate(@fn-type, 'COISTAEMN', 'coistaemn')='coi-statement']";
        foreach ($this->xpath->query($ownedByContributors, $articleMeta) as $node) { /** @var DOMElement $node */
            $id = $node->getAttribute('id');
            if (isset($orphanCandidates[$id]) && !isset($stillReferenced[$id])) {
                $node->parentNode->removeChild($node);
            }
        }

        // Drop a contrib-group or author-notes the removals emptied
        $this->removeNodes('//contrib-group[not(*) and not(normalize-space())]');
        $this->removeNodes('author-notes[not(*)]', $articleMeta);

        return null;
    }

    /**
     * The ids the xrefs within the given elements point at.
     *
     * @return array<string, true>
     */
    protected function referencedIds(iterable $elements): array
    {
        $ids = [];
        foreach ($elements as $element) {
            foreach ($this->xpath->query('.//xref/@rid', $element) as $rid) {
                foreach (preg_split('/\s+/', trim($rid->value)) as $id) {
                    $ids[$id] = true;
                }
            }
        }
        return $ids;
    }

    /**
     * Unwrap the structured name of every contributor.
     *
     * The jatsTemplate plugin wraps every personal name in name-alternatives, holding the
     * structured <name> and, where one is recorded, a display <string-name>. PMC rejects
     * string-name, and name-alternatives needs more than one child, so drop the display
     * name and unwrap the structured one. @specific-use only distinguished the two, so it
     * goes with the wrapper. Queried document-wide to cover contributors in sub-article
     * front-stubs as well as article-meta.
     */
    protected function unwrapNameAlternatives(): void
    {
        foreach ($this->xpath->query('//contrib-group/contrib/name-alternatives') as $node) {
            /** @var DOMNode $node */
            $this->removeNodes('./string-name', $node);

            $names = $this->xpath->query('./name', $node);
            if ($names->length !== 1) {
                continue;
            }

            $nameNode = $names->item(0); /** @var DOMElement $nameNode */
            $nameNode->removeAttribute('specific-use');
            $node->parentNode->insertBefore($nameNode, $node);
            $node->parentNode->removeChild($node);
        }
    }

    /**
     * Add the date of the collection the article belongs to, which PMC requires alongside
     * the electronic publication date and uses to organize the archive.
     *
     * Only the year is needed: PMC collects by year when a journal has no volumes.
     */
    protected function addCollectionDate(?string $collectionYear): void
    {
        if (!$collectionYear || !($articleMeta = $this->articleMeta())) {
            return;
        }

        // Either spelling counts: JATS 1.2 pairs @date-type with @publication-format, and
        // a document may still use the older @pub-type
        if ($this->xpath->query("pub-date[@date-type='collection' or @pub-type='collection']", $articleMeta)->length) {
            return;
        }

        $pubDate = $this->xpath->query(
            "pub-date[@date-type='pub' and @publication-format='electronic']",
            $articleMeta
        )->item(0);
        $publicationDate = $pubDate ?: $this->xpath->query("pub-date[@pub-type='epub']", $articleMeta)->item(0);
        if (!$publicationDate) {
            return;
        }

        // PMC reads a collection date only alongside an electronic publication date
        // written the same way, so the one the document has decides how this one is
        $collectionDate = $this->dom->createElement('pub-date');
        if ($pubDate) {
            $collectionDate->setAttribute('date-type', 'collection');
            $collectionDate->setAttribute('publication-format', 'electronic');
        } else {
            $collectionDate->setAttribute('pub-type', 'collection');
        }
        $collectionDate->appendChild($this->dom->createElement('year', $collectionYear));

        // Kept alongside the publication date, before the volume and page elements the
        // JATS content model expects to follow it.
        $articleMeta->insertBefore($collectionDate, $publicationDate->nextSibling);
    }

    /**
     * Remove the supplementary material that still points somewhere else on the web.
     *
     * The jatsTemplate plugin points supplementary-material at the galley's OJS download
     * URL, which is not a packaged file, and the style check rejects an @xlink:href with
     * no file extension outright, so drop these rather than ship a reference that cannot
     * resolve. What packaging resolved to a media file stays, and is deposited with the
     * article.
     *
     * @todo Point a generated document's supplementary galleys at packaged files, so that
     * they can be deposited rather than dropped.
     */
    protected function removeUnpackagedSupplementaryMaterial(): void
    {
        foreach ($this->xpath->query('//supplementary-material') as $node) { /** @var DOMElement $node */
            $href = $node->getAttributeNS(self::XLINK_NS, 'href') ?: $node->getAttribute('xlink:href');
            if ($href !== '' && preg_match(self::EXTERNAL_REFERENCE, $href)) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    /**
     * Point the document at the PDF packaged alongside it, replacing any PDF link it
     * already carries. createZip() cannot produce a package without one.
     */
    protected function addSelfUri(): ?array
    {
        if (!$articleMeta = $this->articleMeta()) {
            return ['plugins.importexport.pmc.export.failure.jatsNodeMissing', 'article-meta'];
        }

        $this->removeNodes(
            "self-uri[@content-type='pdf' or @content-type='application/pdf']",
            $articleMeta
        );

        $selfUri = $this->dom->createElement('self-uri');
        $selfUri->setAttribute('content-type', 'pdf');
        $selfUri->setAttribute('xlink:href', $this->articlePdfFilename);

        if ($existing = $this->xpath->query('self-uri', $articleMeta)->item(0)) {
            $existing->parentNode->insertBefore($selfUri, $existing);
            return null;
        }

        if (!$abstract = $this->xpath->query('abstract', $articleMeta)->item(0)) {
            return ['plugins.importexport.pmc.export.failure.jatsNodeMissing', 'abstract'];
        }
        $articleMeta->insertBefore($selfUri, $abstract);

        return null;
    }

    /**
     * Map the related-article-type values PMC rejects onto its nearest supported ones: it
     * accepts a narrower vocabulary than JATS.
     */
    protected function remapRelatedArticleTypes(): void
    {
        foreach (self::PMC_RELATED_ARTICLE_TYPES as $jatsType => $pmcType) {
            foreach ($this->xpath->query("//related-article[@related-article-type='{$jatsType}']") as $node) {
                /** @var DOMElement $node */
                $node->setAttribute('related-article-type', $pmcType);
            }
        }
    }

    /**
     * Turn the related-object elements that link peer review materials into the
     * related-article elements PMC reads them from, naming the related material by DOI.
     * OJS follows the JATS4R recommendation to use related-object, but PMC uses related-article.
     */
    protected function convertPeerReviewRelatedObjects(): void
    {
        foreach (self::PMC_PEER_REVIEW_RELATED_ARTICLE_TYPES as $documentType => $relatedArticleType) {
            $query = "//related-object[@document-type='{$documentType}' and @document-id-type='doi' and @document-id]";
            foreach ($this->xpath->query($query) as $node) { /** @var DOMElement $node */
                $relatedArticle = $this->dom->createElement('related-article');
                if ($node->hasAttribute('id')) {
                    $relatedArticle->setAttribute('id', $node->getAttribute('id'));
                }
                $relatedArticle->setAttribute('related-article-type', $relatedArticleType);
                $relatedArticle->setAttribute('ext-link-type', 'doi');
                $relatedArticle->setAttributeNS(self::XLINK_NS, 'xlink:href', $node->getAttribute('document-id'));
                $node->parentNode->replaceChild($relatedArticle, $node);
            }
        }
    }

    /**
     * Keep a single copy of each competing interests statement, ensuring none are duplicated
     * (e.g. "No competing interests were disclosed".)
     *
     * OJS records competing interests for each author, so a statement several authors
     * share is repeated once for each of them, while the article publishes it once. Each
     * author's reference is pointed at the copy that is kept.
     */
    protected function removeDuplicateCompetingInterests(): void
    {
        foreach ($this->xpath->query('//author-notes') as $authorNotes) {
            $kept = [];
            foreach ($this->xpath->query('fn', $authorNotes) as $fn) { /** @var DOMElement $fn */
                if (strtolower($fn->getAttribute('fn-type')) !== 'coi-statement') {
                    continue;
                }
                $statement = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $fn->textContent));
                if (!isset($kept[$statement])) {
                    $kept[$statement] = $fn->getAttribute('id');
                    continue;
                }

                $id = $fn->getAttribute('id');
                if ($id !== '' && $kept[$statement] !== '') {
                    foreach ($this->xpath->query("//xref[@ref-type='author-notes' and @rid='{$id}']") as $xref) {
                        /** @var DOMElement $xref */
                        $xref->setAttribute('rid', $kept[$statement]);
                        // An author referring to both copies needs only the one
                        $siblings = $this->xpath->query(
                            "preceding-sibling::xref[@ref-type='author-notes' and @rid='{$kept[$statement]}']",
                            $xref
                        );
                        if ($siblings->length) {
                            $xref->parentNode->removeChild($xref);
                        }
                    }
                }
                $fn->parentNode->removeChild($fn);
            }
        }
    }

    /**
     * Add the volume, which PMC requires for every article. If the journal publishes no
     * volume numbers, PMC takes the collection year in its place.
     */
    protected function addVolume(): void
    {
        if (!($articleMeta = $this->articleMeta()) || $this->xpath->query('volume', $articleMeta)->length) {
            return;
        }

        $year = trim($this->xpath->evaluate(
            "string(pub-date[@date-type='collection' or @pub-type='collection'][1]/year)",
            $articleMeta
        ));
        if ($year === '') {
            return;
        }

        $following = null;
        foreach ($articleMeta->childNodes as $child) {
            if ($child instanceof DOMElement && in_array($child->nodeName, self::AFTER_VOLUME, true)) {
                $following = $child;
                break;
            }
        }
        $articleMeta->insertBefore($this->dom->createElement('volume', $year), $following);
    }

    /**
     * Remove every paragraph PMC would read as empty, such as the ones a rich-text editor
     * leaves behind for a line break.
     *
     * A paragraph holding a child element is content to PMC whatever its text, so only a
     * childless one is a candidate, and its text is measured the way PMC measures it.
     */
    protected function removeEmptyParagraphs(): void
    {
        $spaces = self::PMC_SPACE_CHARACTERS;
        $blanks = str_repeat(' ', mb_strlen($spaces));

        $this->removeNodes("//p[not(*) and not(normalize-space(translate(., '{$spaces}', '{$blanks}')))]");
    }

    /**
     * Set the article-type, from the section the article is in.
     *
     * The jatsTemplate plugin writes the section title as the heading subject, so the
     * section is read back from there and looked up in the Open Research Europe mapping.
     * A document that declares an article-type of its own keeps it: only a generated one
     * arrives without.
     */
    protected function setArticleType(): void
    {
        if ($this->dom->documentElement?->hasAttribute('article-type')) {
            return;
        }

        $section = $this->xpath->evaluate(
            "string(//article/front/article-meta/article-categories/subj-group[@subj-group-type='heading']/subject)"
        );

        $this->dom->documentElement?->setAttribute(
            'article-type',
            self::ORE_SECTION_ARTICLE_TYPES[mb_strtolower(trim($section))] ?? self::DEFAULT_ARTICLE_TYPE
        );
    }

    /**
     * Point every reference to a media file at the name that file is packaged under,
     * and record what has to go into the package.
     *
     * A document refers to its figures by the file names the depositor works with, whether
     * it was uploaded or generated from the submission. PMC needs those names to follow its
     * own scheme, and every file in a package has to be referenced from the XML, so the
     * document decides what is packaged: a media file nothing refers to is left out, and a
     * reference that matches no media file stops the export.
     */
    protected function packageMediaReferences(): ?array
    {
        // Also indexed by name without its extension: a document is often written against
        // a file whose extension has since changed, or which was uploaded in another
        // format. A stem naming more than one file is dropped, because a reference that
        // could be answered with either of two files is no match at all.
        $stems = [];
        foreach ($this->mediaFiles as $name => $path) {
            $stem = pathinfo($name, PATHINFO_FILENAME);
            $stems[$stem] = array_key_exists($stem, $stems) && $stems[$stem] !== $path ? null : $path;
        }
        $stems = array_filter($stems);

        $elements = implode(' | ', array_map(fn ($name) => '//' . $name, array_keys(self::MEDIA_ELEMENT_TYPES)));
        $counts = [];
        $packagedNames = [];

        foreach ($this->xpath->query($elements) as $node) { /** @var DOMElement $node */
            // Held as the attribute node so that the reference is read and rewritten the
            // same way, whether the document declares the xlink namespace
            $href = $node->getAttributeNodeNS(self::XLINK_NS, 'href') ?: $node->getAttributeNode('xlink:href');

            // An element with no reference, or one pointing somewhere else on the web,
            // has no file of ours behind it to package
            if (!$href || $href->value === '' || preg_match(self::EXTERNAL_REFERENCE, $href->value)) {
                continue;
            }

            $name = strtolower(basename($href->value));
            $path = $this->mediaFiles[$name] ?? $stems[pathinfo($name, PATHINFO_FILENAME)] ?? null;

            // Naming what the publication does have: the mismatch is usually a media file
            // whose name has drifted from the one the document was written against
            if (!$path) {
                return [
                    'plugins.importexport.pmc.export.failure.missingMediaFile',
                    $this->mediaFiles
                        ? __('plugins.importexport.pmc.export.failure.missingMediaFile.available', [
                            'reference' => $href->value,
                            'files' => implode(', ', array_keys($this->mediaFiles)),
                        ])
                        : __('plugins.importexport.pmc.export.failure.missingMediaFile.none', [
                            'reference' => $href->value,
                        ]),
                ];
            }

            // Keyed by the file rather than the reference, so that a file named two ways
            // is still packaged once
            if (!isset($packagedNames[$path])) {
                $type = self::MEDIA_ELEMENT_TYPES[$node->nodeName];
                $counts[$type] = ($counts[$type] ?? 0) + 1;
                $sequence = str_pad((string) $counts[$type], 3, '0', STR_PAD_LEFT);
                $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $packagedNames[$path] = $this->mediaBaseName . '-' . $type . $sequence . '.' . $extension;
                $this->packagedMedia[$packagedNames[$path]] = $path;
            }

            $href->value = $packagedNames[$path];
        }

        return null;
    }

    protected function journalMeta(): ?DOMElement
    {
        return $this->xpath->query('//article/front/journal-meta')->item(0);
    }

    protected function articleMeta(): ?DOMElement
    {
        return $this->xpath->query('//article/front/article-meta')->item(0);
    }

    /**
     * Remove every node an XPath query matches.
     */
    protected function removeNodes(string $query, ?DOMNode $context = null): void
    {
        foreach ($this->xpath->query($query, $context) as $node) { /** @var DOMNode $node */
            $node->parentNode->removeChild($node);
        }
    }
}
