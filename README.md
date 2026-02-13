# PubMed Central Plugin for OJS

An OJS plugin for exporting articles to [PubMed Central](https://pmc.ncbi.nlm.nih.gov/).

## Compatibility

Compatible with OJS 3.6 and later.

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

All JATS XML will be validated against its DTD and
[the PubMed Central Style Checker](https://pmc.ncbi.nlm.nih.gov/tools/stylechecker/) prior to export.

Exported packages will also include a PDF galley of the article if one is available in the submission's primary language.
The plugin will add a link to the PDF in the JATS XML prior to export.

### DOI Versioning

If DOI versioning is enabled in OJS, then the user can deposit each major version of an article to PubMed Central.

## License

This plugin is licensed under the GNU General Public License v3.
