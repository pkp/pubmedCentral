{**
 * templates/settingsForm.tpl
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * PubMed Central plugin settings.
 *
 *}
<script type="text/javascript">
	$(function() {ldelim}
		// Attach the form handler.
		$('#pmcSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim})
</script>
<div class="legacyDefaults">
	<form class="pkp_form" method="post" id="pmcSettingsForm" action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" plugin="PubmedCentralExportPlugin" category="importexport" verb="save"}">
		{csrf}
		{include file="controllers/notification/inPlaceNotification.tpl" notificationId="pmcSettingsFormNotification"}
		{fbvFormArea id="pmcSettingsFormArea"}
			<p class="pkp_help">
				{translate key="plugins.importexport.pmc.description"}
			</p>
			<br/>
			{fbvFormSection list="true"}
				{fbvElement type="checkbox" id="jatsImported" label="plugins.importexport.pmc.settings.form.jatsImportedOnly" checked=$jatsImported|compare:true}
			{/fbvFormSection}

			{fbvFormSection list="true"}
				{fbvElement type="checkbox" id="automaticRegistration" label="plugins.importexport.pmc.settings.form.automaticRegistration.description" checked=$automaticRegistration|compare:true}
			{/fbvFormSection}

			{fbvFormSection}
				<span class="instruct">{translate key="plugins.importexport.pmc.settings.form.nlmTitle.description"}</span>
				<br/>
				{fbvElement type="text" required=true id="nlmTitle" value=$nlmTitle label="plugins.importexport.pmc.settings.form.nlmTitle" maxlength="100" size=$fbvStyles.size.MEDIUM}
			{/fbvFormSection}

			{capture assign="sectionTitle"}{translate key="plugins.importexport.pmc.endpoint"}{/capture}
			{fbvFormSection id="formSection" title=$sectionTitle translate=false class="endpointContainer"}
				{fbvElement type="text" id="host" value=$host label="plugins.importexport.pmc.host" maxlength="120" size=$fbvStyles.size.MEDIUM}
				{fbvElement type="text" id="port" value=$port label="plugins.importexport.pmc.port" maxlength="5" size=$fbvStyles.size.MEDIUM}
				{fbvElement type="text" id="path" value=$path label="plugins.importexport.pmc.path" maxlength="120" size=$fbvStyles.size.MEDIUM}
				{fbvElement type="text" id="username" value=$username label="plugins.importexport.pmc.username" maxlength="120" size=$fbvStyles.size.MEDIUM}
				{fbvElement type="text" password=true id="password" value=$password label="plugins.importexport.pmc.password" maxlength="120" size=$fbvStyles.size.MEDIUM}
			{/fbvFormSection}
		{/fbvFormArea}
		{fbvFormButtons submitText="common.save" hideCancel="true"}
		<p>
			<span class="formRequired">{translate key="common.requiredField"}</span>
		</p>
	</form>
</div>
