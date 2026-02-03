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

## License

This plugin is licensed under the GNU General Public License v3.
