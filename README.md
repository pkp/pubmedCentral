# PubMed Central Plugin for OJS

An OJS plugin for exporting articles to PubMed Central.

## Compatibility

Compatible with OJS 3.6 and later.

## Installation

### For Development

- Copy the plugin files to `plugins/importexport/pubmedCentral/`
- Run the installation tool: `php lib/pkp/tools/installPluginVersion.php plugins/importexport/pubmedCentral/version.xml`

## Using the Plugin

Articles to export to PubMed Central must meet the following requirements:

- Have a compatible JATS XML file in OJS for the article.
- Contain uncompressed image files, if applicable.
- All image and supplementary files must be referenced in the JATS XML.

## License

This plugin is licensed under the GNU General Public License v3.
