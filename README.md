# PubMed Central Plugin for OJS

An OJS plugin for exporting articles to [PubMed Central](https://pmc.ncbi.nlm.nih.gov/).

## Compatibility

Compatible with OJS 3.6 and later.

Deposits are dispatched as queued jobs, so the installation needs a way of running them:
either OJS's scheduled `ProcessQueueJobs` task (which runs with the rest of the scheduler)
or a dedicated worker, `php lib/pkp/tools/jobs.php work`.

## Installation

### For Development

- Copy the plugin files to `plugins/generic/pubmedCentral/`
- Run the installation tool: `php lib/pkp/tools/installPluginVersion.php plugins/generic/pubmedCentral/version.xml`

## Using the Plugin

Before using this plugin, your journal should be approved for deposit by PubMed Central.

To use the plugin, ensure that your journal has entered a publisher and at least one ISSN in the journal settings.

Within the plugin settings, you will need to enter the PubMed Central SFTP connection details and your journal's
NLM Title Abbreviation.

Articles to export to PubMed Central should meet the following requirements:

- Have a valid JATS XML file uploaded to the publication. OJS-generated JATS is not exported.
- Contain high-resolution image files, if applicable.
- Contain all required metadata (see below).

All JATS XML will be validated against its DTD and
[the PubMed Central Style Checker](https://pmc.ncbi.nlm.nih.gov/tools/stylechecker/) prior to export. It is not recommended
to disable validation for packages being sent to PubMed Central.

Exported packages will include a PDF galley of the article if one is available in the submission's primary language.
The plugin will add a link to the PDF in the JATS XML prior to export.

### Media Files

JATS XML may reference figures and other files, for example
`<graphic xlink:href="figure1.jpg"/>`. Those files should be uploaded to the publication's media files, under the same
file names the JATS XML refers to. The plugin packages each referenced file alongside the article and renames it to
a numbered name (`-g0000` for the first figure graphic, `-i0000` for an inline graphic and `-s0000` for
supplementary material), updating the reference in the XML to match.

If a media file is linked to a high-resolution version, the high-resolution file is the one packaged, since PubMed
Central asks for the highest resolution available. Media files the XML does not reference are not packaged, because
PubMed Central requires every file in a package to be referenced from the XML.

A reference is matched to a media file by name, ignoring case and any folder in the reference, so
`<graphic xlink:href="images/Figure1.JPG"/>` finds a media file named `figure1.jpg`. If no media file carries that
name, the name without its extension is matched instead, so a reference to `figure1.tif` still finds a media file
named `figure1.jpg` — but only where one media file carries that name, since packaging the wrong image is worse than
stopping the export. Note that the name matched is the media file's name in OJS, which can be edited after upload: if
it no longer matches the reference, the export stops, naming the reference and the media files that are available.

Supplementary material is the one reference that may be removed rather than packaged: OJS-generated JATS points
`<supplementary-material>` at a galley's download URL, which is not a file the plugin can package, so a reference still
pointing at a URL once the media files are resolved is dropped. Supplementary material naming a media file is packaged
and deposited like any other reference.

For more details about PubMed Central requirements, refer to the
[PubMed Central minimum data requirements](https://pmc.ncbi.nlm.nih.gov/pub/min_requirements/) and the
[PubMed Central Tagging Guidelines](https://pmc.ncbi.nlm.nih.gov/tagging-guidelines/article/style/).

### Naming Scheme

Journals may choose to use either volume/issue/page or the article number for naming the packages and files.

If the volume/issue/page naming scheme is used, then publications must be assigned to an issue and have volume, issue, and page numbers.

If article numbering is used, then publications must have an article number, and the four-digit collection year is
included in the name (e.g. `jtest-2025-e12345.zip`), since PubMed Central organizes its archive by volume and uses the
collection year in its place for journals without volume numbers. Article number metadata can be enabled in
the settings under Settings → Distribution → Metadata → Article Number.

### Collection Date

PubMed Central requires the article's electronic publication date to be accompanied by the date of the collection it
belongs to. The plugin adds a collection year to the JATS XML it generates, taken from the issue's publication date,
or from the first published version of the article when it is not assigned to an issue. An article stays in the
collection it was first published in, so publishing a new version in a later year does not move it, and the file names
of a revised package continue to match the ones already deposited.

A document that already carries a collection date keeps the one it has, in whichever form it uses: JATS 1.2 pairs
`@date-type` with `@publication-format`, and a document may still use the older `@pub-type`. A collection date the
plugin adds is written in the same form as the document's own electronic publication date, since PubMed Central reads
the two together.

### DOI Versioning

If DOI versioning is enabled in OJS, then the user can deposit each major version of an article to PubMed Central.

Without DOI versioning, an article's deposit status is recorded on the article rather than on its
versions. Enabling DOI versioning after articles have been deposited therefore leaves their versions
with no deposit status, so they appear not deposited and automatic deposits will send them again.
Before enabling it, turn off automatic deposits, and once it is enabled, mark the versions already
in PubMed Central registered before turning them back on.

### Earlier Versions

Only versions of record are listed for deposit, and each is deposited on its own. Earlier versions of other stages,
such as a published manuscript under review, are not sent to PubMed Central.

### Deposits

Deposits are queued: clicking Deposit (or the automatic deposit task running) dispatches one job per
object, which builds that object's package, validates it, and uploads it. The request returns as soon
as the jobs are queued, so a slow SFTP endpoint never blocks the browser, and each object's outcome is
recorded against it individually. An object waiting on its job shows the Submitted status; when the
job runs it becomes Deposited, or Failed with the error message.

The SFTP account is optional -- Export can be used to download packages and deliver them manually --
but partially filling it in is not: either all of host, username, and password, or none. Automatic
deposit requires a complete account. Deposit actions only appear once all three are set.

### Versions

Each version of an article is numbered by its major version, and its package and files carry that
number (e.g. `jtest-2025-e12345.v2`), as do its minor versions. The package name also carries a
timestamp, which tells PubMed Central one delivery of a version from the next, so a correction to a
version is delivered as a newer package of it.

### Articles Delivered to PMC by Another Platform

This section only applies to a journal that has imported a history with the command-line tool below;
without one, nothing in it changes how packages are named. The tool requires DOI versioning, since
each version is found by its own DOI.

A journal moving to OJS from another platform can import the history of its articles indexed in
PubMed Central, so that deliveries from OJS continue them. An imported version keeps the version
number, file stem and package names it was delivered with, and a correction to it is sent as its next
revision. A new version is numbered by its major version, offset to continue after the article's
highest version indexed in PubMed Central: if PubMed Central indexes v1 to v4 and the imported
versions are majors 1 to 3, major 4 is sent as v5. Its package and files are named from the article's
package prefix, as written, with no timestamp (e.g. `openreseurope-4-openreseurope.4-83.v5.zip`), and
a correction to it is sent as its next revision (`openreseurope-4-openreseurope.4-83.v5.r1.zip`). A
version marked registered counts as delivered once. This suits articles whose earlier packages were
named `{prefix}.vN` and `{prefix}.vN.rM`, as F1000's were, but not a platform that named its versions
or revisions differently.

The history is imported from a CSV file with one row per version:

```
doi,package_prefix,version,last_revision,file_stem,in_pmc
10.12688/openreseurope.17317.4,openreseurope-4-openreseurope.4-83,4,0,openreseurope-4-27633,Y
```

| Column           | Contents                                                                                                         |
|------------------|------------------------------------------------------------------------------------------------------------------|
| `doi`            | The version's DOI, which finds its publication. Required.                                                        |
| `package_prefix` | The part of the article's package names before `.vN`. The same for every version of an article.                  |
| `version`        | The version number it is indexed under in PubMed Central. Required.                                              |
| `last_revision`  | The highest revision delivered of it (`0` if none), counting packages PubMed Central rejected. Blank if unknown. |
| `file_stem`      | The name its XML and PDF were delivered under, without the extension. Blank if unknown.                          |
| `in_pmc`         | `Y` if it is indexed in PubMed Central, `N` if every package of it was rejected. Required.                       |

Include a row for every version indexed in PubMed Central, even one with no publication in OJS, such
as a version under review that was not migrated: it still counts towards the article's highest
version, and is placed with its article by its package prefix. Minor versions share their version's
DOI and take its history. A row whose DOI is shared by more than one major version, as versions
published before DOI versioning was enabled can be, is reported as a conflict and not imported. A
DOI in more than one row is also reported, and none of its rows are imported.

The data can be retrieved from the PubMed Central Deposit Tracking System (DTS). Its package report
lists each package delivered, with its file name, DOI and status: the file name gives the package
prefix, version and revision (e.g. `openreseurope-4-openreseurope.4-139.v2.r3.tgz`), and a rejected
package still counts towards the last revision. Its article details report gives the XML file name of
each version indexed in PubMed Central, and so its file stem (e.g. `openreseurope-4-27633.xml`). Join
the two on the package each version was last delivered in. A report can stop short of every row, so
check that each version indexed in PubMed Central is included before importing.

```bash
php tools/importExport.php PubmedCentralExportPlugin importHistory [journal_path] [csvFile] --dry-run
php tools/importExport.php PubmedCentralExportPlugin importHistory [journal_path] [csvFile]
```

Versions indexed in PubMed Central are marked registered unless they already have a deposit status.
Correcting an imported version from OJS needs JATS XML uploaded to its publication, as any deposit
does, and its file stem and last revision: a version missing either is reported as incomplete. The
tool also reports rows it could not read, DOIs with no publication in the journal, rows that disagree
with what is recorded, versions delivered from OJS since an earlier import, and versions not indexed
in PubMed Central. Run it with `--dry-run` first to see the report without saving anything. The file
can be imported again with more complete rows: a revision never goes back, and what a delivery from
OJS has recorded is never replaced.

## What the Plugin Changes in Your JATS

Uploaded and OJS-generated JATS are prepared the same way. PubMed Central's requirements do not depend on where a
document came from, and an uploaded document is often OJS's own JATS, saved and edited: a journal may export the
generated JATS, add a body element, and upload the result. Every step below leaves a document that already meets the
requirement alone, so a document prepared once and uploaded again is not changed twice.

| Step                      | What it does                                                                                                                                                                                                                                           |
|---------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| PMC journal identifier    | Adds `<journal-id journal-id-type="pmc">` with the NLM title abbreviation, which the deposit is filed under.                                                                                                                                           |
| Journal identifiers       | Removes every `journal-id` whose type the StyleChecker does not accept, such as the OJS and publisher identifiers.                                                                                                                                     |
| Translated journal title  | Removes a journal `trans-title-group` in the article's language, which the StyleChecker rejects.                                                                                                                                                       |
| Abbreviated journal title | Adds `<abbrev-journal-title abbrev-type="nlm-ta">` with the NLM title abbreviation.                                                                                                                                                                    |
| Contributors              | Removes the journal's editorial team from the journal metadata, which PubMed Central asks to be left out, and keeps only authors and editors in the article metadata. PubMed Central accepts no other contributor type.                                |
| Contributor names         | Removes `<string-name>` and unwraps `<name-alternatives>`, which PubMed Central rejects.                                                                                                                                                               |
| Competing interests       | Keeps one copy of each competing interests statement, pointing every author who declared it at that copy. OJS records the statement for each author, so a statement several authors share would otherwise be repeated.                                 |
| Collection date           | Adds the collection year, as described under Collection Date above.                                                                                                                                                                                    |
| Volume                    | Adds `<volume>` with the collection year, unless the document has a volume of its own. PubMed Central requires a volume of every article, and takes the collection year in its place where a journal publishes no volume numbers.                      |
| Media files               | Renames each referenced media file to PubMed Central's scheme and repoints the reference at it, as described under Media Files above.                                                                                                                  |
| Supplementary material    | Removes `<supplementary-material>` still pointing at a URL after the media files are resolved, since PubMed Central cannot resolve a reference outside the package.                                                                                    |
| PDF link                  | Replaces any PDF `<self-uri>` with one naming the PDF packaged alongside the XML.                                                                                                                                                                      |
| Related articles          | Maps the `related-article-type` values PubMed Central rejects onto the nearest ones it accepts.                                                                                                                                                        |
| Peer review relationships | Turns the peer review `<related-object>` elements into the `<related-article>` elements PubMed Central reads: a reviewer report names the article it reviews (`reviewed-article`), and an author's response the report it answers (`reviewer-report`). |
| Empty paragraphs          | Removes paragraphs PubMed Central reads as empty, including those holding only a non-breaking space.                                                                                                                                                   |
| Article type              | Sets `article-type` from the journal section, unless the document declares one of its own.                                                                                                                                                             |

Every document is then validated against the JATS DTD and the PubMed Central StyleChecker. A document declaring a JATS
version other than 1.2 skips DTD validation, with a warning, and is style checked as usual.

## Uploaded JATS XML

Only publications with an uploaded JATS XML file can be exported. OJS-generated JATS has no usable body or media
files, so a publication without an uploaded file fails to export.

Each version is exported from the JATS XML uploaded to that publication. A new version starts with a copy of the
uploaded file from the version it was made from, so replace it with the new version's JATS XML before depositing:
otherwise the new version is sent with the earlier version's content.

## Troubleshooting

If you are receiving validation errors, ensure you have entered the required metadata in your journal settings and/or
the publication. If you are using your own JATS XML documents, then you may need to modify the metadata in the JATS
XML to meet the requirements of PubMed Central.

## PubMed Central StyleChecker Updates

The StyleChecker validation files are periodically updated by PubMed Central. Updated file packages can be downloaded from
[the Downloadable StyleChecker page](https://pmc.ncbi.nlm.nih.gov/pub/stylechecker-info/) and placed in the `xsl` directory
(`style-reporter.xsl` is not used and can be omitted).

## License

This plugin is licensed under the GNU General Public License v3.
