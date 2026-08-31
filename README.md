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

Within the plugin settings, you will need to enter the PubMed Central FTP connection details and your journal's
NLM Title Abbreviation.

Articles to export to PubMed Central should meet the following requirements:

- Have a valid JATS XML file in OJS, or generate valid JATS (1.2) in OJS via the JATS Template plugin.
- Contain high-resolution image files, if applicable.
- Contain all required metadata (see below).

All JATS XML will be validated against its DTD and
[the PubMed Central Style Checker](https://pmc.ncbi.nlm.nih.gov/tools/stylechecker/) prior to export. It is not recommended
to disable validation for packages being sent to PubMed Central.

Exported packages will include a PDF galley of the article if one is available in the submission's primary language.
The plugin will add a link to the PDF in the JATS XML prior to export.

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

Uploaded JATS XML is not modified, so those files should carry their own collection date.

### DOI Versioning

If DOI versioning is enabled in OJS, then the user can deposit each major version of an article to PubMed Central.

### Deposits

Deposits are queued: clicking Deposit (or the automatic deposit task running) dispatches one job per
object, which builds that object's package, validates it, and uploads it. The request returns as soon
as the jobs are queued, so a slow FTP endpoint never blocks the browser, and each object's outcome is
recorded against it individually. An object waiting on its job shows the Submitted status; when the
job runs it becomes Deposited, or Failed with the error message.

The FTP account is optional -- Export can be used to download packages and deliver them manually --
but partially filling it in is not: either all of host, username, and password, or none. Automatic
deposit requires a complete account. Deposit actions only appear once all three are set.

## Uploaded JATS XML

If a publication has an uploaded JATS XML file, then that will be exported in the plugin instead of the OJS-generated JATS.
Uploaded JATS XML files will also be validated against the DTD and StyleChecker.

## Troubleshooting

If you are receiving validation errors, ensure you have entered the required metadata in your journal settings and/or
the publication. If you are using your own JATS XML documents, then you may need to modify the metadata in the JATS
XML to meet the requirements of PubMed Central.

## PubMed Central StyleChecker Updates

The StyleChecker validation files are periodically updated by PubMed Central. Updated file packages can be downloaded from
[the Downloadable StyleChecker page](https://pmc.ncbi.nlm.nih.gov/pub/stylechecker-info/) and placed in the `xsl` directory,
(`style-reporter.xsl` is not used and can be omitted).

## License

This plugin is licensed under the GNU General Public License v3.
