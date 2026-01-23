<?xml version="1.0" encoding="utf-8"?>

<xsl:stylesheet 
   xmlns:xsl="http://www.w3.org/1999/XSL/Transform" 
   xmlns:xlink="http://www.w3.org/1999/xlink" 
	xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
   xmlns:mml="http://www.w3.org/1998/Math/MathML" 
   xmlns:ali="http://www.niso.org/schemas/ali/1.0/"
   xmlns:pmcvar="http://www.pubmedcentral.gov/variables"
   version="1.0" exclude-result-prefixes="pmcvar">
   

<!-- ######################## HELPER TEMPLATES ############################## -->
<!--
      Templates for 'everything else'.
  -->
<!-- ######################################################################## -->

   
   <!-- ********************************************* -->
   <!-- Template: node() | @* 
        Mode: output
        
        Copy all nodes and attributes to output after
        being checked by special processing rules.    -->
   <!-- ********************************************* -->   
   <xsl:template match="* | @*" mode="output">
      <xsl:copy>
         <xsl:apply-templates select="@*[not(name() = 'xml:lang')]"/>
         <xsl:apply-templates select="@xml:lang"/>
         <xsl:apply-templates />
      </xsl:copy>
   </xsl:template>
	
	<xsl:template match="@xsi:noNamespaceSchemaLocation"/>


   <!-- ********************************************* -->
   <!-- Template: node() | @* 
        
        Copy all nodes and attributes to output that
        do not have special processing rules
     -->
   <!-- ********************************************* -->   
   <xsl:template match="* | @*">
      <xsl:copy>
         <!-- Copy out all attributes -->
         <xsl:apply-templates select="@*"/>
         
         <!-- Copy all children -->
         <xsl:apply-templates />
      </xsl:copy>
   </xsl:template>



   <!-- ********************************************************************* -->
   <!-- Template: make-error
        
        Outputs an error or warning element with the provided
	    type and description. 

        PARAMS:
		   error-type    
		   description   Long text of error message  
		   class         "error": style-check should fail (the default).
		                 "warning": style-check can still pass.
						 other value: the message becomes a "warning",
						 and a note is added warning about the bad value.
		                 This is done to guard against typing mistakes.
     -->
   <!-- ********************************************************************* -->
   <xsl:template name="make-error">
      <xsl:param name="error-type"  select="''"/>
      <xsl:param name="description" select="''"/>
      <xsl:param name="tg-target" select="''"/>
      <xsl:param name="class"       select="'error'"/>
     
      <xsl:variable name="class-type">
         <xsl:choose>
            <xsl:when test="$class = 'error'">
               <xsl:text>error</xsl:text>
            </xsl:when>
            <xsl:otherwise>
               <xsl:text>warning</xsl:text>
            </xsl:otherwise>
         </xsl:choose>
      </xsl:variable>

	<xsl:variable name="errpath">
	<xsl:for-each select="ancestor-or-self::*">
		<xsl:variable name="name" select="name()"/>
    	<xsl:text/>/<xsl:value-of select="name()"/><xsl:text/>
		<xsl:choose>
			<xsl:when test="@id">
				<xsl:text>[</xsl:text>
				<xsl:value-of select="concat('@id=&quot;',@id,'&quot;')"/>
				<xsl:text>]</xsl:text>
				</xsl:when>
				
			<xsl:when test="preceding-sibling::node()[name()=$name]">
				<xsl:text>[</xsl:text>
				<xsl:value-of select="count(preceding-sibling::node()[name()=$name])+1"/>
				<xsl:text>]</xsl:text>
				</xsl:when>
			</xsl:choose>
	</xsl:for-each>
		</xsl:variable>


      <!-- Make sure have all needed values, otherwise don't output -->
      <xsl:if test="string-length($error-type) &gt; 0 and
	                string-length($description) &gt; 0">
         <xsl:element name="{$class-type}">
			<xsl:choose>
				<xsl:when test="$notices='yes'">
					<xsl:attribute name="notice">
					<xsl:value-of select="concat('sc:',translate(normalize-space($error-type),' ','_'))"/>
						</xsl:attribute>
					</xsl:when>
					<xsl:otherwise>
            		<xsl:value-of select="normalize-space($error-type)"/>
            		<xsl:text>: </xsl:text>
						</xsl:otherwise>
					</xsl:choose>
            <xsl:value-of select="$description"/>
				<xsl:if test="$stream='manuscript'">
					<xsl:text> (</xsl:text>
					<xsl:value-of select="$errpath"/>
					<xsl:text>)</xsl:text>
					</xsl:if>
         <xsl:if test="string-length($tg-target) &gt; 0">
				<xsl:call-template name="tglink">
					<xsl:with-param name="tg-target" select="$tg-target"/>
					</xsl:call-template>
				</xsl:if>
         </xsl:element>
         
         <xsl:call-template name="output-message">
            <xsl:with-param name="class" select="$class-type"/>
            <xsl:with-param name="errpath" select="$errpath"/>
            <xsl:with-param name="description">
			   <xsl:value-of select="$description"/>
			   <xsl:if test="$class!='error' and $class!='warning'">
			      <xsl:text>   *** Error class was neither 'error' nor 'warning' ***   </xsl:text>
			   </xsl:if>
			</xsl:with-param>
            <xsl:with-param name="type" select="$error-type"/>
         </xsl:call-template>
      </xsl:if>    
   </xsl:template> 

	<xsl:template name="tglink">
		<xsl:param name="tg-target"/>
		<xsl:variable name="base">
			<xsl:choose>
				<xsl:when test="$stream='book'">
					<xsl:value-of select="'https://pmc.ncbi.nlm.nih.gov/tagging-guidelines/book/'"/>
					</xsl:when>
				<xsl:when test="$stream='manuscript'">
					<xsl:value-of select="'https://pmc.ncbi.nlm.nih.gov/tagging-guidelines/manuscript/'"/>
					</xsl:when>
				<xsl:otherwise>
					<xsl:value-of select="'https://pmc.ncbi.nlm.nih.gov/tagging-guidelines/article/'"/>
					</xsl:otherwise>
				</xsl:choose>
			</xsl:variable>
		<xsl:text> </xsl:text>
		<tlink>
			<xsl:attribute name="target">	
				<xsl:value-of select="concat($base,$tg-target)"/>
				</xsl:attribute>
			<xsl:text>(Tagging Guidelines)</xsl:text>
		</tlink>
		</xsl:template>
				
		

   <!-- ********************************************************************* -->
   <!-- TEMPLATE: output-message
        Takes an error message and outputs it. Does nothing if $messages
	    global is set to false or if the xsl:message element is not available.
        
        PARAMS:
		   class, description, type: (as for make-error)
           -path: path to output the error log (Eh? No such param)
		CALLED:   Only from make-error, above.
     -->
   <!-- ********************************************************************* -->
   <xsl:template name="output-message">
      <xsl:param name="class" select="'error'"/>
      <xsl:param name="description" select="''"/>
      <xsl:param name="errpath" />
      <xsl:param name="type" select="''"/>
      
      <!--<xsl:variable name="descriptor">
         <xsl:choose>
            <xsl:when test="$class='warning'">
               <xsl:text> (warning)</xsl:text>
            </xsl:when>

            <xsl:otherwise>
               <xsl:text></xsl:text>
            </xsl:otherwise>
         </xsl:choose>
      </xsl:variable>
      
      <xsl:if test="element-available('xsl:message') and $messages = 'true'">
         <xsl:message terminate="no">
            <xsl:value-of select="concat($type, $descriptor)"/>
            <xsl:text>: </xsl:text>
            <xsl:value-of select="$description"/>
				<xsl:if test="$errpath!=''">
					<xsl:text> (</xsl:text>
					<xsl:value-of select="$errpath"/>
					<xsl:text>)</xsl:text>
					<!-\-</xsl:if>-\->
            <xsl:text disable-output-escaping="yes">&#10;</xsl:text>
         </xsl:message>
      </xsl:if>-->
   </xsl:template>

		
			
			
   <!-- ********************************************************************* -->
   <!-- Template: text(), and 
                  NAMED check-prohibited-math-characters-outside-math-context

        Scans all text nodes for prohibited characters, outside math context
        
     -->
   <!-- ********************************************************************* -->
   <!-- ********************************************************************* -->
	
	<xsl:template name="check-prohibited-math-characters-outside-math-context">
		<!-- keeping this as the template is called from a importing secondary checker for books -->
	</xsl:template>
	
   <!-- <xsl:template match="text()" name="check-prohibited-math-characters-outside-math-context">

        <!-/- are we in math context ?-/->
   	  <xsl:if test="not(ancestor::node()[local-name() = 'math'
					    or local-name() = 'inline-formula'
					    or local-name() = 'disp-formula'
					    or local-name() = 'tex-math'])">

            <!-/- here you can list using "OR" a banch of contains function calls 
                to check prohibited  characters.  -/->
            <xsl:if test="contains(., '&#xFE37;')">
                
                <xsl:call-template name="make-error">
                  <xsl:with-param name="error-type" select="'math character check'"/>
                  <xsl:with-param name="description">
                     <xsl:text>prohibited character is being used outside of math context in this node.</xsl:text>
                  </xsl:with-param>
                </xsl:call-template>

      	    </xsl:if>

	  </xsl:if>

      <!-/- If we are in the text() node copy its content to the output, 
           otherwise we're in the attribute node, and we do not do output here, 
           because it is done in other place. -/->
      <xsl:if test="(name(.)='')">
         <xsl:copy-of select="."/>
      </xsl:if>
   </xsl:template> -->


   <!-- ********************************************************************* -->
   <!-- <xsl:template match="@*" mode="check-prohibited-math-characters-outside-math-context">
      <xsl:call-template name="check-prohibited-math-characters-outside-math-context"/>
   </xsl:template> -->


	<xsl:template name="capitalize">
		<xsl:param name="str"/>
		<xsl:value-of select="translate($str, 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ')"/>
		</xsl:template>

	<xsl:template name="knockdown">
		<xsl:param name="str"/>
		<xsl:value-of select="translate($str,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')"/>
		</xsl:template>

    <!-- ==================================================================== -->
    <!-- TEMPLATE: replace-substring

   Removes/replaces all occurrences of a 'substring' in the original string.
   If no replacement is specified, then the specified substring
   is removed. If no substring is specified or the substring is
   an empty string, then the template simply returns the original string.
         
   Parameters:
      main-string: main string to operate on
      substring: substring to locate in main string
      replacement: replacement string for the substring 
   -->
    <!-- ==================================================================== -->
    <xsl:template name="replace-substring">
        <xsl:param name="main-string"/>
        <xsl:param name="substring"/>
        <xsl:param name="replacement"/>
       
        <xsl:choose>
           <!-- Error case -->
           <xsl:when test="not($substring)">
              <xsl:value-of select="$main-string"/>
           </xsl:when>
            
           <!-- Base Case: no more substrings to remove -->
           <xsl:when test="not(contains($main-string, $substring))">
              <xsl:value-of select="$main-string"/>
           </xsl:when>
                    
           <!-- Case 1: Substring is in the main string -->
           <xsl:otherwise>
              <xsl:value-of select="substring-before($main-string, $substring )"/>
              <xsl:value-of select="$replacement"/>
              <xsl:call-template name="replace-substring">
                 <xsl:with-param name="main-string"
                    select="substring-after($main-string, $substring)"/>
                 <xsl:with-param name="substring" select="$substring"/>
                 <xsl:with-param name="replacement" select="$replacement"/>
              </xsl:call-template>
           </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

    <!-- ==================================================================== -->
    <!-- TEMPLATE: is-in-list (aka contains-token)
        PARAMS:
           list   String containing list of acceptable items
           token  Token to look for in list
           case   Regard case when matching (default = 0 = ignore)
           delim  Char that separates items in list (default = ' ')
        NOTES:    Return 1 if $token occurs in $list (say, of month-names).
                  Tokens in $list must be separated by spaces, unless
                     a different char or string is specified in $delim.
                  Unless $case is true, case will be ignored.
        WARNING:  If $token = '', returns nil.
        ADDED:    sjd, ~2006-10.
     -->
    <!-- ==================================================================== -->
    <xsl:template name="is-in-list">
      <xsl:param name="list"/>
      <xsl:param name="token"/>
      <xsl:param name="case"  select="0"/>
      <xsl:param name="delim" select="' '"/>

      <!-- Make sure the list of tokens is capped if needed, and has delims -->
      <xsl:variable name="myList">
         <xsl:choose>
            <xsl:when test="$case">
               <xsl:value-of select="concat($delim,$list,$delim)"/>
            </xsl:when>
            <xsl:otherwise>
               <xsl:call-template name="capitalize">
                  <xsl:with-param name="str"
                      select="concat($delim,$list,$delim)"/>
               </xsl:call-template>
            </xsl:otherwise>
         </xsl:choose>
      </xsl:variable>

      <!-- Same for token to look for (exactly one delim at each end) -->
      <xsl:variable name="myToken">
         <xsl:if test="substring($token,1,1)!=$delim">
            <xsl:value-of select="$delim"/>
         </xsl:if>
         <xsl:choose>
            <xsl:when test="$case">
               <xsl:value-of select="$token"/>
            </xsl:when>
            <xsl:otherwise>
               <xsl:call-template name="capitalize">
                  <xsl:with-param name="str" select="$token"/>
               </xsl:call-template>
            </xsl:otherwise>
         </xsl:choose>
         <xsl:if test="substring($token,string-length($token))!=$delim">
            <xsl:value-of select="$delim"/>
         </xsl:if>
      </xsl:variable>

      <!-- Now that we're normalized, the test is easy -->
      <xsl:if test="$myToken!='' and contains($myList,$myToken)">1</xsl:if>
    </xsl:template>


   <!-- Outputs the substring after the last dot in the input string -->
   <xsl:template name="substring-after-last-dot">
      <xsl:param name="str"/>
      <xsl:if test="$str">
         <xsl:choose>
            <xsl:when test="contains($str,'.')">
               <xsl:call-template name="substring-after-last-dot">
                  <xsl:with-param name="str"
                     select="substring-after($str,'.')"/>
               </xsl:call-template>
            </xsl:when>
            <xsl:otherwise>
               <xsl:value-of select="$str"/>
            </xsl:otherwise>
         </xsl:choose>
      </xsl:if>
   </xsl:template>

<xsl:template name="get-context">
	<xsl:text>(context: </xsl:text>
	<xsl:choose>
		<xsl:when test="@id">
			<xsl:value-of select="name()"/>
			<xsl:text>[@id="</xsl:text>
			<xsl:value-of select="@id"/>
			<xsl:text>"]</xsl:text>
			</xsl:when>
		<xsl:otherwise>
			<xsl:call-template name="nodePath"/>
			</xsl:otherwise>
		</xsl:choose>
	<xsl:text> )</xsl:text>
	</xsl:template>

	<xsl:template name="nodePath">
		<xsl:for-each select="ancestor-or-self::*">
			<xsl:variable name="nm" select="name()"/>
			<xsl:variable name="pos" select="count(preceding-sibling::node()[name() = $nm])"/>
			<xsl:variable name="more" select="count(following-sibling::node()[name() = $nm])"/>
			<xsl:variable name="poslabel">
				<xsl:if test="($pos + 1 &gt; 1) or ($more &gt; 0)">
					<xsl:text>[</xsl:text><xsl:value-of select="$pos + 1"/><xsl:text>]</xsl:text>
					</xsl:if>
				</xsl:variable>

            <xsl:choose>
               <xsl:when test="name() = 'warning'">
                  <xsl:text>/error</xsl:text>
               </xsl:when>
               
               <xsl:otherwise>
                  <xsl:value-of select="concat('/',name(),$poslabel)"/>               
               </xsl:otherwise>
            </xsl:choose>
		</xsl:for-each>
		</xsl:template>
	
	<xsl:template name="canonical-cc-license-urls">
		<!-- list of canonical cc license urls as provided by cc with publicdomain/mark/1.0 added  -->
		<xsl:value-of
			select="
				concat(
				' creativecommons.org/licenses/by-nc-nd/4.0',
				' creativecommons.org/licenses/by-nc-sa/4.0',
				' creativecommons.org/licenses/by-nc/4.0',
				' creativecommons.org/licenses/by-nd/4.0',
				' creativecommons.org/licenses/by-sa/4.0',
				' creativecommons.org/licenses/by/4.0',
				
				' creativecommons.org/licenses/by-nc-nd/3.0',
				' creativecommons.org/licenses/by-nc-nd/3.0/am',
				' creativecommons.org/licenses/by-nc-nd/3.0/at',
				' creativecommons.org/licenses/by-nc-nd/3.0/au',
				' creativecommons.org/licenses/by-nc-nd/3.0/az',
				' creativecommons.org/licenses/by-nc-nd/3.0/br',
				' creativecommons.org/licenses/by-nc-nd/3.0/ca',
				' creativecommons.org/licenses/by-nc-nd/3.0/ch',
				' creativecommons.org/licenses/by-nc-nd/3.0/cl',
				' creativecommons.org/licenses/by-nc-nd/3.0/cn',
				' creativecommons.org/licenses/by-nc-nd/3.0/cr',
				' creativecommons.org/licenses/by-nc-nd/3.0/cz',
				' creativecommons.org/licenses/by-nc-nd/3.0/de',
				' creativecommons.org/licenses/by-nc-nd/3.0/ec',
				' creativecommons.org/licenses/by-nc-nd/3.0/ee',
				' creativecommons.org/licenses/by-nc-nd/3.0/eg',
				' creativecommons.org/licenses/by-nc-nd/3.0/es',
				' creativecommons.org/licenses/by-nc-nd/3.0/fr',
				' creativecommons.org/licenses/by-nc-nd/3.0/ge',
				' creativecommons.org/licenses/by-nc-nd/3.0/gr',
				' creativecommons.org/licenses/by-nc-nd/3.0/gt',
				' creativecommons.org/licenses/by-nc-nd/3.0/hk',
				' creativecommons.org/licenses/by-nc-nd/3.0/hr',
				' creativecommons.org/licenses/by-nc-nd/3.0/ie',
				' creativecommons.org/licenses/by-nc-nd/3.0/igo',
				' creativecommons.org/licenses/by-nc-nd/3.0/it',
				' creativecommons.org/licenses/by-nc-nd/3.0/lu',
				' creativecommons.org/licenses/by-nc-nd/3.0/nl',
				' creativecommons.org/licenses/by-nc-nd/3.0/no',
				' creativecommons.org/licenses/by-nc-nd/3.0/nz',
				' creativecommons.org/licenses/by-nc-nd/3.0/ph',
				' creativecommons.org/licenses/by-nc-nd/3.0/pl',
				' creativecommons.org/licenses/by-nc-nd/3.0/pr',
				' creativecommons.org/licenses/by-nc-nd/3.0/pt',
				' creativecommons.org/licenses/by-nc-nd/3.0/ro',
				' creativecommons.org/licenses/by-nc-nd/3.0/rs',
				' creativecommons.org/licenses/by-nc-nd/3.0/sg',
				' creativecommons.org/licenses/by-nc-nd/3.0/th',
				' creativecommons.org/licenses/by-nc-nd/3.0/tw',
				' creativecommons.org/licenses/by-nc-nd/3.0/ug',
				' creativecommons.org/licenses/by-nc-nd/3.0/us',
				' creativecommons.org/licenses/by-nc-nd/3.0/ve',
				' creativecommons.org/licenses/by-nc-nd/3.0/vn',
				' creativecommons.org/licenses/by-nc-nd/3.0/za',
				
				' creativecommons.org/licenses/by-nc-sa/3.0',
				' creativecommons.org/licenses/by-nc-sa/3.0/am',
				' creativecommons.org/licenses/by-nc-sa/3.0/at',
				' creativecommons.org/licenses/by-nc-sa/3.0/au',
				' creativecommons.org/licenses/by-nc-sa/3.0/az',
				' creativecommons.org/licenses/by-nc-sa/3.0/br',
				' creativecommons.org/licenses/by-nc-sa/3.0/ca',
				' creativecommons.org/licenses/by-nc-sa/3.0/ch',
				' creativecommons.org/licenses/by-nc-sa/3.0/cl',
				' creativecommons.org/licenses/by-nc-sa/3.0/cn',
				' creativecommons.org/licenses/by-nc-sa/3.0/cr',
				' creativecommons.org/licenses/by-nc-sa/3.0/cz',
				' creativecommons.org/licenses/by-nc-sa/3.0/de',
				' creativecommons.org/licenses/by-nc-sa/3.0/ec',
				' creativecommons.org/licenses/by-nc-sa/3.0/ee',
				' creativecommons.org/licenses/by-nc-sa/3.0/eg',
				' creativecommons.org/licenses/by-nc-sa/3.0/es',
				' creativecommons.org/licenses/by-nc-sa/3.0/fr',
				' creativecommons.org/licenses/by-nc-sa/3.0/ge',
				' creativecommons.org/licenses/by-nc-sa/3.0/gr',
				' creativecommons.org/licenses/by-nc-sa/3.0/gt',
				' creativecommons.org/licenses/by-nc-sa/3.0/hk',
				' creativecommons.org/licenses/by-nc-sa/3.0/hr',
				' creativecommons.org/licenses/by-nc-sa/3.0/ie',
				' creativecommons.org/licenses/by-nc-sa/3.0/igo',
				' creativecommons.org/licenses/by-nc-sa/3.0/it',
				' creativecommons.org/licenses/by-nc-sa/3.0/lu',
				' creativecommons.org/licenses/by-nc-sa/3.0/nl',
				' creativecommons.org/licenses/by-nc-sa/3.0/no',
				' creativecommons.org/licenses/by-nc-sa/3.0/nz',
				' creativecommons.org/licenses/by-nc-sa/3.0/ph',
				' creativecommons.org/licenses/by-nc-sa/3.0/pl',
				' creativecommons.org/licenses/by-nc-sa/3.0/pr',
				' creativecommons.org/licenses/by-nc-sa/3.0/pt',
				' creativecommons.org/licenses/by-nc-sa/3.0/ro',
				' creativecommons.org/licenses/by-nc-sa/3.0/rs',
				' creativecommons.org/licenses/by-nc-sa/3.0/sg',
				' creativecommons.org/licenses/by-nc-sa/3.0/th',
				' creativecommons.org/licenses/by-nc-sa/3.0/tw',
				' creativecommons.org/licenses/by-nc-sa/3.0/ug',
				' creativecommons.org/licenses/by-nc-sa/3.0/us',
				' creativecommons.org/licenses/by-nc-sa/3.0/ve',
				' creativecommons.org/licenses/by-nc-sa/3.0/vn',
				' creativecommons.org/licenses/by-nc-sa/3.0/za',
				
				' creativecommons.org/licenses/by-nc/3.0',
				' creativecommons.org/licenses/by-nc/3.0/am',
				' creativecommons.org/licenses/by-nc/3.0/at',
				' creativecommons.org/licenses/by-nc/3.0/au',
				' creativecommons.org/licenses/by-nc/3.0/az',
				' creativecommons.org/licenses/by-nc/3.0/br',
				' creativecommons.org/licenses/by-nc/3.0/ca',
				' creativecommons.org/licenses/by-nc/3.0/ch',
				' creativecommons.org/licenses/by-nc/3.0/cl',
				' creativecommons.org/licenses/by-nc/3.0/cn',
				' creativecommons.org/licenses/by-nc/3.0/cr',
				' creativecommons.org/licenses/by-nc/3.0/cz',
				' creativecommons.org/licenses/by-nc/3.0/de',
				' creativecommons.org/licenses/by-nc/3.0/ec',
				' creativecommons.org/licenses/by-nc/3.0/ee',
				' creativecommons.org/licenses/by-nc/3.0/eg',
				' creativecommons.org/licenses/by-nc/3.0/es',
				' creativecommons.org/licenses/by-nc/3.0/fr',
				' creativecommons.org/licenses/by-nc/3.0/ge',
				' creativecommons.org/licenses/by-nc/3.0/gr',
				' creativecommons.org/licenses/by-nc/3.0/gt',
				' creativecommons.org/licenses/by-nc/3.0/hk',
				' creativecommons.org/licenses/by-nc/3.0/hr',
				' creativecommons.org/licenses/by-nc/3.0/ie',
				' creativecommons.org/licenses/by-nc/3.0/igo',
				' creativecommons.org/licenses/by-nc/3.0/it',
				' creativecommons.org/licenses/by-nc/3.0/lu',
				' creativecommons.org/licenses/by-nc/3.0/nl',
				' creativecommons.org/licenses/by-nc/3.0/no',
				' creativecommons.org/licenses/by-nc/3.0/nz',
				' creativecommons.org/licenses/by-nc/3.0/ph',
				' creativecommons.org/licenses/by-nc/3.0/pl',
				' creativecommons.org/licenses/by-nc/3.0/pr',
				' creativecommons.org/licenses/by-nc/3.0/pt',
				' creativecommons.org/licenses/by-nc/3.0/ro',
				' creativecommons.org/licenses/by-nc/3.0/rs',
				' creativecommons.org/licenses/by-nc/3.0/sg',
				' creativecommons.org/licenses/by-nc/3.0/th',
				' creativecommons.org/licenses/by-nc/3.0/tw',
				' creativecommons.org/licenses/by-nc/3.0/ug',
				' creativecommons.org/licenses/by-nc/3.0/us',
				' creativecommons.org/licenses/by-nc/3.0/ve',
				' creativecommons.org/licenses/by-nc/3.0/vn',
				' creativecommons.org/licenses/by-nc/3.0/za',
				
				' creativecommons.org/licenses/by-nd/3.0',
				' creativecommons.org/licenses/by-nd/3.0/am',
				' creativecommons.org/licenses/by-nd/3.0/at',
				' creativecommons.org/licenses/by-nd/3.0/au',
				' creativecommons.org/licenses/by-nd/3.0/az',
				' creativecommons.org/licenses/by-nd/3.0/br',
				' creativecommons.org/licenses/by-nd/3.0/ca',
				' creativecommons.org/licenses/by-nd/3.0/ch',
				' creativecommons.org/licenses/by-nd/3.0/cl',
				' creativecommons.org/licenses/by-nd/3.0/cn',
				' creativecommons.org/licenses/by-nd/3.0/cr',
				' creativecommons.org/licenses/by-nd/3.0/cz',
				' creativecommons.org/licenses/by-nd/3.0/de',
				' creativecommons.org/licenses/by-nd/3.0/ec',
				' creativecommons.org/licenses/by-nd/3.0/ee',
				' creativecommons.org/licenses/by-nd/3.0/eg',
				' creativecommons.org/licenses/by-nd/3.0/es',
				' creativecommons.org/licenses/by-nd/3.0/fr',
				' creativecommons.org/licenses/by-nd/3.0/ge',
				' creativecommons.org/licenses/by-nd/3.0/gr',
				' creativecommons.org/licenses/by-nd/3.0/gt',
				' creativecommons.org/licenses/by-nd/3.0/hk',
				' creativecommons.org/licenses/by-nd/3.0/hr',
				' creativecommons.org/licenses/by-nd/3.0/ie',
				' creativecommons.org/licenses/by-nd/3.0/igo',
				' creativecommons.org/licenses/by-nd/3.0/it',
				' creativecommons.org/licenses/by-nd/3.0/lu',
				' creativecommons.org/licenses/by-nd/3.0/nl',
				' creativecommons.org/licenses/by-nd/3.0/no',
				' creativecommons.org/licenses/by-nd/3.0/nz',
				' creativecommons.org/licenses/by-nd/3.0/ph',
				' creativecommons.org/licenses/by-nd/3.0/pl',
				' creativecommons.org/licenses/by-nd/3.0/pr',
				' creativecommons.org/licenses/by-nd/3.0/pt',
				' creativecommons.org/licenses/by-nd/3.0/ro',
				' creativecommons.org/licenses/by-nd/3.0/rs',
				' creativecommons.org/licenses/by-nd/3.0/sg',
				' creativecommons.org/licenses/by-nd/3.0/th',
				' creativecommons.org/licenses/by-nd/3.0/tw',
				' creativecommons.org/licenses/by-nd/3.0/ug',
				' creativecommons.org/licenses/by-nd/3.0/us',
				' creativecommons.org/licenses/by-nd/3.0/ve',
				' creativecommons.org/licenses/by-nd/3.0/vn',
				' creativecommons.org/licenses/by-nd/3.0/za',
				
				' creativecommons.org/licenses/by-sa/3.0',
				' creativecommons.org/licenses/by-sa/3.0/am',
				' creativecommons.org/licenses/by-sa/3.0/at',
				' creativecommons.org/licenses/by-sa/3.0/au',
				' creativecommons.org/licenses/by-sa/3.0/az',
				' creativecommons.org/licenses/by-sa/3.0/br',
				' creativecommons.org/licenses/by-sa/3.0/ca',
				' creativecommons.org/licenses/by-sa/3.0/ch',
				' creativecommons.org/licenses/by-sa/3.0/cl',
				' creativecommons.org/licenses/by-sa/3.0/cn',
				' creativecommons.org/licenses/by-sa/3.0/cr',
				' creativecommons.org/licenses/by-sa/3.0/cz',
				' creativecommons.org/licenses/by-sa/3.0/de',
				' creativecommons.org/licenses/by-sa/3.0/ec',
				' creativecommons.org/licenses/by-sa/3.0/ee',
				' creativecommons.org/licenses/by-sa/3.0/eg',
				' creativecommons.org/licenses/by-sa/3.0/es',
				' creativecommons.org/licenses/by-sa/3.0/fr',
				' creativecommons.org/licenses/by-sa/3.0/ge',
				' creativecommons.org/licenses/by-sa/3.0/gr',
				' creativecommons.org/licenses/by-sa/3.0/gt',
				' creativecommons.org/licenses/by-sa/3.0/hk',
				' creativecommons.org/licenses/by-sa/3.0/hr',
				' creativecommons.org/licenses/by-sa/3.0/ie',
				' creativecommons.org/licenses/by-sa/3.0/igo',
				' creativecommons.org/licenses/by-sa/3.0/it',
				' creativecommons.org/licenses/by-sa/3.0/lu',
				' creativecommons.org/licenses/by-sa/3.0/nl',
				' creativecommons.org/licenses/by-sa/3.0/no',
				' creativecommons.org/licenses/by-sa/3.0/nz',
				' creativecommons.org/licenses/by-sa/3.0/ph',
				' creativecommons.org/licenses/by-sa/3.0/pl',
				' creativecommons.org/licenses/by-sa/3.0/pr',
				' creativecommons.org/licenses/by-sa/3.0/pt',
				' creativecommons.org/licenses/by-sa/3.0/ro',
				' creativecommons.org/licenses/by-sa/3.0/rs',
				' creativecommons.org/licenses/by-sa/3.0/sg',
				' creativecommons.org/licenses/by-sa/3.0/th',
				' creativecommons.org/licenses/by-sa/3.0/tw',
				' creativecommons.org/licenses/by-sa/3.0/ug',
				' creativecommons.org/licenses/by-sa/3.0/us',
				' creativecommons.org/licenses/by-sa/3.0/ve',
				' creativecommons.org/licenses/by-sa/3.0/vn',
				' creativecommons.org/licenses/by-sa/3.0/za',
				
				' creativecommons.org/licenses/by/3.0',
				' creativecommons.org/licenses/by/3.0/am',
				' creativecommons.org/licenses/by/3.0/at',
				' creativecommons.org/licenses/by/3.0/au',
				' creativecommons.org/licenses/by/3.0/az',
				' creativecommons.org/licenses/by/3.0/br',
				' creativecommons.org/licenses/by/3.0/ca',
				' creativecommons.org/licenses/by/3.0/ch',
				' creativecommons.org/licenses/by/3.0/cl',
				' creativecommons.org/licenses/by/3.0/cn',
				' creativecommons.org/licenses/by/3.0/cr',
				' creativecommons.org/licenses/by/3.0/cz',
				' creativecommons.org/licenses/by/3.0/de',
				' creativecommons.org/licenses/by/3.0/ec',
				' creativecommons.org/licenses/by/3.0/ee',
				' creativecommons.org/licenses/by/3.0/eg',
				' creativecommons.org/licenses/by/3.0/es',
				' creativecommons.org/licenses/by/3.0/fr',
				' creativecommons.org/licenses/by/3.0/ge',
				' creativecommons.org/licenses/by/3.0/gr',
				' creativecommons.org/licenses/by/3.0/gt',
				' creativecommons.org/licenses/by/3.0/hk',
				' creativecommons.org/licenses/by/3.0/hr',
				' creativecommons.org/licenses/by/3.0/ie',
				' creativecommons.org/licenses/by/3.0/igo',
				' creativecommons.org/licenses/by/3.0/it',
				' creativecommons.org/licenses/by/3.0/lu',
				' creativecommons.org/licenses/by/3.0/nl',
				' creativecommons.org/licenses/by/3.0/no',
				' creativecommons.org/licenses/by/3.0/nz',
				' creativecommons.org/licenses/by/3.0/ph',
				' creativecommons.org/licenses/by/3.0/pl',
				' creativecommons.org/licenses/by/3.0/pr',
				' creativecommons.org/licenses/by/3.0/pt',
				' creativecommons.org/licenses/by/3.0/ro',
				' creativecommons.org/licenses/by/3.0/rs',
				' creativecommons.org/licenses/by/3.0/sg',
				' creativecommons.org/licenses/by/3.0/th',
				' creativecommons.org/licenses/by/3.0/tw',
				' creativecommons.org/licenses/by/3.0/ug',
				' creativecommons.org/licenses/by/3.0/us',
				' creativecommons.org/licenses/by/3.0/ve',
				' creativecommons.org/licenses/by/3.0/vn',
				' creativecommons.org/licenses/by/3.0/za',
				
				' creativecommons.org/licenses/by-nc-nd/2.5',
				' creativecommons.org/licenses/by-nc-nd/2.5/ar',
				' creativecommons.org/licenses/by-nc-nd/2.5/au',
				' creativecommons.org/licenses/by-nc-nd/2.5/bg',
				' creativecommons.org/licenses/by-nc-nd/2.5/br',
				' creativecommons.org/licenses/by-nc-nd/2.5/ca',
				' creativecommons.org/licenses/by-nc-nd/2.5/ch',
				' creativecommons.org/licenses/by-nc-nd/2.5/cn',
				' creativecommons.org/licenses/by-nc-nd/2.5/co',
				' creativecommons.org/licenses/by-nc-nd/2.5/dk',
				' creativecommons.org/licenses/by-nc-nd/2.5/es',
				' creativecommons.org/licenses/by-nc-nd/2.5/hr',
				' creativecommons.org/licenses/by-nc-nd/2.5/hu',
				' creativecommons.org/licenses/by-nc-nd/2.5/il',
				' creativecommons.org/licenses/by-nc-nd/2.5/in',
				' creativecommons.org/licenses/by-nc-nd/2.5/it',
				' creativecommons.org/licenses/by-nc-nd/2.5/mk',
				' creativecommons.org/licenses/by-nc-nd/2.5/mt',
				' creativecommons.org/licenses/by-nc-nd/2.5/mx',
				' creativecommons.org/licenses/by-nc-nd/2.5/my',
				' creativecommons.org/licenses/by-nc-nd/2.5/nl',
				' creativecommons.org/licenses/by-nc-nd/2.5/pe',
				' creativecommons.org/licenses/by-nc-nd/2.5/pl',
				' creativecommons.org/licenses/by-nc-nd/2.5/pt',
				' creativecommons.org/licenses/by-nc-nd/2.5/scotland',
				' creativecommons.org/licenses/by-nc-nd/2.5/se',
				' creativecommons.org/licenses/by-nc-nd/2.5/si',
				' creativecommons.org/licenses/by-nc-nd/2.5/tw',
				' creativecommons.org/licenses/by-nc-nd/2.5/za',
				
				' creativecommons.org/licenses/by-nc-sa/2.5',
				' creativecommons.org/licenses/by-nc-sa/2.5/ar',
				' creativecommons.org/licenses/by-nc-sa/2.5/au',
				' creativecommons.org/licenses/by-nc-sa/2.5/bg',
				' creativecommons.org/licenses/by-nc-sa/2.5/br',
				' creativecommons.org/licenses/by-nc-sa/2.5/ca',
				' creativecommons.org/licenses/by-nc-sa/2.5/ch',
				' creativecommons.org/licenses/by-nc-sa/2.5/cn',
				' creativecommons.org/licenses/by-nc-sa/2.5/co',
				' creativecommons.org/licenses/by-nc-sa/2.5/dk',
				' creativecommons.org/licenses/by-nc-sa/2.5/es',
				' creativecommons.org/licenses/by-nc-sa/2.5/hr',
				' creativecommons.org/licenses/by-nc-sa/2.5/hu',
				' creativecommons.org/licenses/by-nc-sa/2.5/il',
				' creativecommons.org/licenses/by-nc-sa/2.5/in',
				' creativecommons.org/licenses/by-nc-sa/2.5/it',
				' creativecommons.org/licenses/by-nc-sa/2.5/mk',
				' creativecommons.org/licenses/by-nc-sa/2.5/mt',
				' creativecommons.org/licenses/by-nc-sa/2.5/mx',
				' creativecommons.org/licenses/by-nc-sa/2.5/my',
				' creativecommons.org/licenses/by-nc-sa/2.5/nl',
				' creativecommons.org/licenses/by-nc-sa/2.5/pe',
				' creativecommons.org/licenses/by-nc-sa/2.5/pl',
				' creativecommons.org/licenses/by-nc-sa/2.5/pt',
				' creativecommons.org/licenses/by-nc-sa/2.5/scotland',
				' creativecommons.org/licenses/by-nc-sa/2.5/se',
				' creativecommons.org/licenses/by-nc-sa/2.5/si',
				' creativecommons.org/licenses/by-nc-sa/2.5/tw',
				' creativecommons.org/licenses/by-nc-sa/2.5/za',
				
				' creativecommons.org/licenses/by-nc/2.5',
				' creativecommons.org/licenses/by-nc/2.5/ar',
				' creativecommons.org/licenses/by-nc/2.5/au',
				' creativecommons.org/licenses/by-nc/2.5/bg',
				' creativecommons.org/licenses/by-nc/2.5/br',
				' creativecommons.org/licenses/by-nc/2.5/ca',
				' creativecommons.org/licenses/by-nc/2.5/ch',
				' creativecommons.org/licenses/by-nc/2.5/cn',
				' creativecommons.org/licenses/by-nc/2.5/co',
				' creativecommons.org/licenses/by-nc/2.5/dk',
				' creativecommons.org/licenses/by-nc/2.5/es',
				' creativecommons.org/licenses/by-nc/2.5/hr',
				' creativecommons.org/licenses/by-nc/2.5/hu',
				' creativecommons.org/licenses/by-nc/2.5/il',
				' creativecommons.org/licenses/by-nc/2.5/in',
				' creativecommons.org/licenses/by-nc/2.5/it',
				' creativecommons.org/licenses/by-nc/2.5/mk',
				' creativecommons.org/licenses/by-nc/2.5/mt',
				' creativecommons.org/licenses/by-nc/2.5/mx',
				' creativecommons.org/licenses/by-nc/2.5/my',
				' creativecommons.org/licenses/by-nc/2.5/nl',
				' creativecommons.org/licenses/by-nc/2.5/pe',
				' creativecommons.org/licenses/by-nc/2.5/pl',
				' creativecommons.org/licenses/by-nc/2.5/pt',
				' creativecommons.org/licenses/by-nc/2.5/scotland',
				' creativecommons.org/licenses/by-nc/2.5/se',
				' creativecommons.org/licenses/by-nc/2.5/si',
				' creativecommons.org/licenses/by-nc/2.5/tw',
				' creativecommons.org/licenses/by-nc/2.5/za',
				
				' creativecommons.org/licenses/by-nd/2.5',
				' creativecommons.org/licenses/by-nd/2.5/ar',
				' creativecommons.org/licenses/by-nd/2.5/au',
				' creativecommons.org/licenses/by-nd/2.5/bg',
				' creativecommons.org/licenses/by-nd/2.5/br',
				' creativecommons.org/licenses/by-nd/2.5/ca',
				' creativecommons.org/licenses/by-nd/2.5/ch',
				' creativecommons.org/licenses/by-nd/2.5/cn',
				' creativecommons.org/licenses/by-nd/2.5/co',
				' creativecommons.org/licenses/by-nd/2.5/dk',
				' creativecommons.org/licenses/by-nd/2.5/es',
				' creativecommons.org/licenses/by-nd/2.5/hr',
				' creativecommons.org/licenses/by-nd/2.5/hu',
				' creativecommons.org/licenses/by-nd/2.5/il',
				' creativecommons.org/licenses/by-nd/2.5/in',
				' creativecommons.org/licenses/by-nd/2.5/it',
				' creativecommons.org/licenses/by-nd/2.5/mk',
				' creativecommons.org/licenses/by-nd/2.5/mt',
				' creativecommons.org/licenses/by-nd/2.5/mx',
				' creativecommons.org/licenses/by-nd/2.5/my',
				' creativecommons.org/licenses/by-nd/2.5/nl',
				' creativecommons.org/licenses/by-nd/2.5/pe',
				' creativecommons.org/licenses/by-nd/2.5/pl',
				' creativecommons.org/licenses/by-nd/2.5/pt',
				' creativecommons.org/licenses/by-nd/2.5/scotland',
				' creativecommons.org/licenses/by-nd/2.5/se',
				' creativecommons.org/licenses/by-nd/2.5/si',
				' creativecommons.org/licenses/by-nd/2.5/tw',
				' creativecommons.org/licenses/by-nd/2.5/za',
				
				' creativecommons.org/licenses/by-sa/2.5',
				' creativecommons.org/licenses/by-sa/2.5/ar',
				' creativecommons.org/licenses/by-sa/2.5/au',
				' creativecommons.org/licenses/by-sa/2.5/bg',
				' creativecommons.org/licenses/by-sa/2.5/br',
				' creativecommons.org/licenses/by-sa/2.5/ca',
				' creativecommons.org/licenses/by-sa/2.5/ch',
				' creativecommons.org/licenses/by-sa/2.5/cn',
				' creativecommons.org/licenses/by-sa/2.5/co',
				' creativecommons.org/licenses/by-sa/2.5/dk',
				' creativecommons.org/licenses/by-sa/2.5/es',
				' creativecommons.org/licenses/by-sa/2.5/hr',
				' creativecommons.org/licenses/by-sa/2.5/hu',
				' creativecommons.org/licenses/by-sa/2.5/il',
				' creativecommons.org/licenses/by-sa/2.5/in',
				' creativecommons.org/licenses/by-sa/2.5/it',
				' creativecommons.org/licenses/by-sa/2.5/mk',
				' creativecommons.org/licenses/by-sa/2.5/mt',
				' creativecommons.org/licenses/by-sa/2.5/mx',
				' creativecommons.org/licenses/by-sa/2.5/my',
				' creativecommons.org/licenses/by-sa/2.5/nl',
				' creativecommons.org/licenses/by-sa/2.5/pe',
				' creativecommons.org/licenses/by-sa/2.5/pl',
				' creativecommons.org/licenses/by-sa/2.5/pt',
				' creativecommons.org/licenses/by-sa/2.5/scotland',
				' creativecommons.org/licenses/by-sa/2.5/se',
				' creativecommons.org/licenses/by-sa/2.5/si',
				' creativecommons.org/licenses/by-sa/2.5/tw',
				' creativecommons.org/licenses/by-sa/2.5/za',
				
				' creativecommons.org/licenses/by/2.5',
				' creativecommons.org/licenses/by/2.5/ar',
				' creativecommons.org/licenses/by/2.5/au',
				' creativecommons.org/licenses/by/2.5/bg',
				' creativecommons.org/licenses/by/2.5/br',
				' creativecommons.org/licenses/by/2.5/ca',
				' creativecommons.org/licenses/by/2.5/ch',
				' creativecommons.org/licenses/by/2.5/cn',
				' creativecommons.org/licenses/by/2.5/co',
				' creativecommons.org/licenses/by/2.5/dk',
				' creativecommons.org/licenses/by/2.5/es',
				' creativecommons.org/licenses/by/2.5/hr',
				' creativecommons.org/licenses/by/2.5/hu',
				' creativecommons.org/licenses/by/2.5/il',
				' creativecommons.org/licenses/by/2.5/in',
				' creativecommons.org/licenses/by/2.5/it',
				' creativecommons.org/licenses/by/2.5/mk',
				' creativecommons.org/licenses/by/2.5/mt',
				' creativecommons.org/licenses/by/2.5/mx',
				' creativecommons.org/licenses/by/2.5/my',
				' creativecommons.org/licenses/by/2.5/nl',
				' creativecommons.org/licenses/by/2.5/pe',
				' creativecommons.org/licenses/by/2.5/pl',
				' creativecommons.org/licenses/by/2.5/pt',
				' creativecommons.org/licenses/by/2.5/scotland',
				' creativecommons.org/licenses/by/2.5/se',
				' creativecommons.org/licenses/by/2.5/si',
				' creativecommons.org/licenses/by/2.5/tw',
				' creativecommons.org/licenses/by/2.5/za',
				
				' creativecommons.org/licenses/by-nc-nd/2.1/au',
				' creativecommons.org/licenses/by-nc-nd/2.1/ca',
				' creativecommons.org/licenses/by-nc-nd/2.1/es',
				' creativecommons.org/licenses/by-nc-nd/2.1/jp',
				
				' creativecommons.org/licenses/by-nc-sa/2.1/au',
				' creativecommons.org/licenses/by-nc-sa/2.1/ca',
				' creativecommons.org/licenses/by-nc-sa/2.1/es',
				' creativecommons.org/licenses/by-nc-sa/2.1/jp',
				
				' creativecommons.org/licenses/by-nc/2.1/au',
				' creativecommons.org/licenses/by-nc/2.1/ca',
				' creativecommons.org/licenses/by-nc/2.1/es',
				' creativecommons.org/licenses/by-nc/2.1/jp',
				
				' creativecommons.org/licenses/by-nd/2.1/au',
				' creativecommons.org/licenses/by-nd/2.1/ca',
				' creativecommons.org/licenses/by-nd/2.1/es',
				' creativecommons.org/licenses/by-nd/2.1/jp',
				
				' creativecommons.org/licenses/by-sa/2.1/au',
				' creativecommons.org/licenses/by-sa/2.1/ca',
				' creativecommons.org/licenses/by-sa/2.1/es',
				' creativecommons.org/licenses/by-sa/2.1/jp',
				
				' creativecommons.org/licenses/by/2.1/au',
				' creativecommons.org/licenses/by/2.1/ca',
				' creativecommons.org/licenses/by/2.1/es',
				' creativecommons.org/licenses/by/2.1/jp',
				
				' creativecommons.org/licenses/by-nc-nd/2.0',
				' creativecommons.org/licenses/by-nc-nd/2.0/at',
				' creativecommons.org/licenses/by-nc-nd/2.0/au',
				' creativecommons.org/licenses/by-nc-nd/2.0/be',
				' creativecommons.org/licenses/by-nc-nd/2.0/br',
				' creativecommons.org/licenses/by-nc-nd/2.0/ca',
				' creativecommons.org/licenses/by-nc-nd/2.0/cl',
				' creativecommons.org/licenses/by-nc-nd/2.0/de',
				' creativecommons.org/licenses/by-nc-nd/2.0/es',
				' creativecommons.org/licenses/by-nc-nd/2.0/fr',
				' creativecommons.org/licenses/by-nc-nd/2.0/hr',
				' creativecommons.org/licenses/by-nc-nd/2.0/it',
				' creativecommons.org/licenses/by-nc-nd/2.0/jp',
				' creativecommons.org/licenses/by-nc-nd/2.0/kr',
				' creativecommons.org/licenses/by-nc-nd/2.0/nl',
				' creativecommons.org/licenses/by-nc-nd/2.0/pl',
				' creativecommons.org/licenses/by-nc-nd/2.0/tw',
				' creativecommons.org/licenses/by-nc-nd/2.0/uk',
				' creativecommons.org/licenses/by-nc-nd/2.0/za',
				
				' creativecommons.org/licenses/by-nc-sa/2.0',
				' creativecommons.org/licenses/by-nc-sa/2.0/at',
				' creativecommons.org/licenses/by-nc-sa/2.0/au',
				' creativecommons.org/licenses/by-nc-sa/2.0/be',
				' creativecommons.org/licenses/by-nc-sa/2.0/br',
				' creativecommons.org/licenses/by-nc-sa/2.0/ca',
				' creativecommons.org/licenses/by-nc-sa/2.0/cl',
				' creativecommons.org/licenses/by-nc-sa/2.0/de',
				' creativecommons.org/licenses/by-nc-sa/2.0/es',
				' creativecommons.org/licenses/by-nc-sa/2.0/fr',
				' creativecommons.org/licenses/by-nc-sa/2.0/hr',
				' creativecommons.org/licenses/by-nc-sa/2.0/it',
				' creativecommons.org/licenses/by-nc-sa/2.0/jp',
				' creativecommons.org/licenses/by-nc-sa/2.0/kr',
				' creativecommons.org/licenses/by-nc-sa/2.0/nl',
				' creativecommons.org/licenses/by-nc-sa/2.0/pl',
				' creativecommons.org/licenses/by-nc-sa/2.0/tw',
				' creativecommons.org/licenses/by-nc-sa/2.0/uk',
				' creativecommons.org/licenses/by-nc-sa/2.0/za',
				
				' creativecommons.org/licenses/by-nc/2.0',
				' creativecommons.org/licenses/by-nc/2.0/at',
				' creativecommons.org/licenses/by-nc/2.0/au',
				' creativecommons.org/licenses/by-nc/2.0/be',
				' creativecommons.org/licenses/by-nc/2.0/br',
				' creativecommons.org/licenses/by-nc/2.0/ca',
				' creativecommons.org/licenses/by-nc/2.0/cl',
				' creativecommons.org/licenses/by-nc/2.0/de',
				' creativecommons.org/licenses/by-nc/2.0/es',
				' creativecommons.org/licenses/by-nc/2.0/fr',
				' creativecommons.org/licenses/by-nc/2.0/hr',
				' creativecommons.org/licenses/by-nc/2.0/it',
				' creativecommons.org/licenses/by-nc/2.0/jp',
				' creativecommons.org/licenses/by-nc/2.0/kr',
				' creativecommons.org/licenses/by-nc/2.0/nl',
				' creativecommons.org/licenses/by-nc/2.0/pl',
				' creativecommons.org/licenses/by-nc/2.0/tw',
				' creativecommons.org/licenses/by-nc/2.0/uk',
				' creativecommons.org/licenses/by-nc/2.0/za',
				
				' creativecommons.org/licenses/by-nd/2.0',
				' creativecommons.org/licenses/by-nd/2.0/at',
				' creativecommons.org/licenses/by-nd/2.0/au',
				' creativecommons.org/licenses/by-nd/2.0/be',
				' creativecommons.org/licenses/by-nd/2.0/br',
				' creativecommons.org/licenses/by-nd/2.0/ca',
				' creativecommons.org/licenses/by-nd/2.0/cl',
				' creativecommons.org/licenses/by-nd/2.0/de',
				' creativecommons.org/licenses/by-nd/2.0/es',
				' creativecommons.org/licenses/by-nd/2.0/fr',
				' creativecommons.org/licenses/by-nd/2.0/hr',
				' creativecommons.org/licenses/by-nd/2.0/it',
				' creativecommons.org/licenses/by-nd/2.0/jp',
				' creativecommons.org/licenses/by-nd/2.0/kr',
				' creativecommons.org/licenses/by-nd/2.0/nl',
				' creativecommons.org/licenses/by-nd/2.0/pl',
				' creativecommons.org/licenses/by-nd/2.0/tw',
				' creativecommons.org/licenses/by-nd/2.0/uk',
				' creativecommons.org/licenses/by-nd/2.0/za',
				
				' creativecommons.org/licenses/by-sa/2.0',
				' creativecommons.org/licenses/by-sa/2.0/at',
				' creativecommons.org/licenses/by-sa/2.0/au',
				' creativecommons.org/licenses/by-sa/2.0/be',
				' creativecommons.org/licenses/by-sa/2.0/br',
				' creativecommons.org/licenses/by-sa/2.0/ca',
				' creativecommons.org/licenses/by-sa/2.0/cl',
				' creativecommons.org/licenses/by-sa/2.0/de',
				' creativecommons.org/licenses/by-sa/2.0/es',
				' creativecommons.org/licenses/by-sa/2.0/fr',
				' creativecommons.org/licenses/by-sa/2.0/hr',
				' creativecommons.org/licenses/by-sa/2.0/it',
				' creativecommons.org/licenses/by-sa/2.0/jp',
				' creativecommons.org/licenses/by-sa/2.0/kr',
				' creativecommons.org/licenses/by-sa/2.0/nl',
				' creativecommons.org/licenses/by-sa/2.0/pl',
				' creativecommons.org/licenses/by-sa/2.0/tw',
				' creativecommons.org/licenses/by-sa/2.0/uk',
				' creativecommons.org/licenses/by-sa/2.0/za',
				
				' creativecommons.org/licenses/by/2.0',
				' creativecommons.org/licenses/by/2.0/at',
				' creativecommons.org/licenses/by/2.0/au',
				' creativecommons.org/licenses/by/2.0/be',
				' creativecommons.org/licenses/by/2.0/br',
				' creativecommons.org/licenses/by/2.0/ca',
				' creativecommons.org/licenses/by/2.0/cl',
				' creativecommons.org/licenses/by/2.0/de',
				' creativecommons.org/licenses/by/2.0/es',
				' creativecommons.org/licenses/by/2.0/fr',
				' creativecommons.org/licenses/by/2.0/hr',
				' creativecommons.org/licenses/by/2.0/it',
				' creativecommons.org/licenses/by/2.0/jp',
				' creativecommons.org/licenses/by/2.0/kr',
				' creativecommons.org/licenses/by/2.0/nl',
				' creativecommons.org/licenses/by/2.0/pl',
				' creativecommons.org/licenses/by/2.0/tw',
				' creativecommons.org/licenses/by/2.0/uk',
				' creativecommons.org/licenses/by/2.0/za',
				
				' creativecommons.org/licenses/nc-sa/2.0/jp',
				
				' creativecommons.org/licenses/nc/2.0/jp',
				
				' creativecommons.org/licenses/nd-nc/2.0/jp',
				
				' creativecommons.org/licenses/nd/2.0/jp',
				
				' creativecommons.org/licenses/sa/2.0/jp',
				
				' creativecommons.org/licenses/by-nc-sa/1.0',
				' creativecommons.org/licenses/by-nc-sa/1.0/fi',
				' creativecommons.org/licenses/by-nc-sa/1.0/il',
				' creativecommons.org/licenses/by-nc-sa/1.0/nl',
				
				' creativecommons.org/licenses/by-nc/1.0',
				' creativecommons.org/licenses/by-nc/1.0/fi',
				' creativecommons.org/licenses/by-nc/1.0/il',
				' creativecommons.org/licenses/by-nc/1.0/nl',
				
				' creativecommons.org/licenses/by-nd-nc/1.0',
				' creativecommons.org/licenses/by-nd-nc/1.0/fi',
				' creativecommons.org/licenses/by-nd-nc/1.0/il',
				' creativecommons.org/licenses/by-nd-nc/1.0/nl',
				
				' creativecommons.org/licenses/by-nd/1.0',
				' creativecommons.org/licenses/by-nd/1.0/fi',
				' creativecommons.org/licenses/by-nd/1.0/il',
				' creativecommons.org/licenses/by-nd/1.0/nl',
				
				' creativecommons.org/licenses/by-sa/1.0',
				' creativecommons.org/licenses/by-sa/1.0/fi',
				' creativecommons.org/licenses/by-sa/1.0/il',
				' creativecommons.org/licenses/by-sa/1.0/nl',
				
				' creativecommons.org/licenses/by/1.0',
				' creativecommons.org/licenses/by/1.0/fi',
				' creativecommons.org/licenses/by/1.0/il',
				' creativecommons.org/licenses/by/1.0/nl',
				
				' creativecommons.org/licenses/nc-sa/1.0',
				' creativecommons.org/licenses/nc-sa/1.0/fi',
				' creativecommons.org/licenses/nc-sa/1.0/nl',
				
				' creativecommons.org/licenses/nc-samplingplus/1.0',
				' creativecommons.org/licenses/nc-samplingplus/1.0/tw',
				
				' creativecommons.org/licenses/nc/1.0',
				' creativecommons.org/licenses/nc/1.0/fi',
				' creativecommons.org/licenses/nc/1.0/nl',
				
				' creativecommons.org/licenses/nd-nc/1.0',
				' creativecommons.org/licenses/nd-nc/1.0/fi',
				' creativecommons.org/licenses/nd-nc/1.0/nl',
				
				' creativecommons.org/licenses/nd/1.0',
				' creativecommons.org/licenses/nd/1.0/fi',
				' creativecommons.org/licenses/nd/1.0/nl',
				
				' creativecommons.org/licenses/sa/1.0',
				' creativecommons.org/licenses/sa/1.0/fi',
				' creativecommons.org/licenses/sa/1.0/nl',
				
				' creativecommons.org/licenses/sampling+/1.0',
				' creativecommons.org/licenses/sampling+/1.0/br',
				' creativecommons.org/licenses/sampling+/1.0/de',
				' creativecommons.org/licenses/sampling+/1.0/tw',
				
				' creativecommons.org/licenses/sampling/1.0',
				' creativecommons.org/licenses/sampling/1.0/br',
				' creativecommons.org/licenses/sampling/1.0/tw',
				
				' creativecommons.org/publicdomain/zero/1.0',
				' creativecommons.org/licenses/devnations/2.0',
				' creativecommons.org/publicdomain/zero-assert/1.0',
				' creativecommons.org/publicdomain/zero-waive/1.0',
				' creativecommons.org/publicdomain/mark/1.0',
				
				' '
			)"
		/>
	</xsl:template>

        <!-- built from http://www.loc.gov/standards/iso639-2/php/code_list.php -->
        <!-- @three contains the values from ISO-639-2                          -->
        <!-- @two contains the values from ISO-639-1                            -->
        <pmcvar:langs>
                <l three="aar" two="aa" n="Afar"/>
                <l three="abk" two="ab" n="Abkhazian"/>
                <l three="ace" two="" n="Achinese"/>
                <l three="ach" two="" n="Acoli"/>
                <l three="ada" two="" n="Adangme"/>
                <l three="ady" two="" n="Adyghe; Adygei"/>
                <l three="afa" two="" n="Afro-Asiatic languages"/>
                <l three="afh" two="" n="Afrihili"/>
                <l three="afr" two="af" n="Afrikaans"/>
                <l three="ain" two="" n="Ainu"/>
                <l three="aka" two="ak" n="Akan"/>
                <l three="akk" two="" n="Akkadian"/>
                <l three="alb" two="sq" n="Albanian"/>
                <l three="ale" two="" n="Aleut"/>
                <l three="alg" two="" n="Algonquian languages"/>
                <l three="alt" two="" n="Southern Altai"/>
                <l three="amh" two="am" n="Amharic"/>
                <l three="ang" two="" n="English, Old (ca.450-1100)"/>
                <l three="anp" two="" n="Angika"/>
                <l three="apa" two="" n="Apache languages"/>
                <l three="ara" two="ar" n="Arabic"/>
                <l three="arc" two="" n="Official Aramaic (700-300 BCE); Imperial Aramaic (700-300 BCE)"/>
                <l three="arg" two="an" n="Aragonese"/>
                <l three="arm" two="hy" n="Armenian"/>
                <l three="arn" two="" n="Mapudungun; Mapuche"/>
                <l three="arp" two="" n="Arapaho"/>
                <l three="art" two="" n="Artificial languages"/>
                <l three="arw" two="" n="Arawak"/>
                <l three="asm" two="as" n="Assamese"/>
                <l three="ast" two="" n="Asturian; Bable; Leonese; Asturleonese"/>
                <l three="ath" two="" n="Athapascan languages"/>
                <l three="aus" two="" n="Australian languages"/>
                <l three="ava" two="av" n="Avaric"/>
                <l three="ave" two="ae" n="Avestan"/>
                <l three="awa" two="" n="Awadhi"/>
                <l three="aym" two="ay" n="Aymara"/>
                <l three="aze" two="az" n="Azerbaijani"/>
                <l three="bad" two="" n="Banda languages"/>
                <l three="bai" two="" n="Bamileke languages"/>
                <l three="bak" two="ba" n="Bashkir"/>
                <l three="bal" two="" n="Baluchi"/>
                <l three="bam" two="bm" n="Bambara"/>
                <l three="ban" two="" n="Balinese"/>
                <l three="baq" two="eu" n="Basque"/>
                <l three="bas" two="" n="Basa"/>
                <l three="bat" two="" n="Baltic languages"/>
                <l three="bej" two="" n="Beja; Bedawiyet"/>
                <l three="bel" two="be" n="Belarusian"/>
                <l three="bem" two="" n="Bemba"/>
                <l three="ben" two="bn" n="Bengali"/>
                <l three="ber" two="" n="Berber languages"/>
                <l three="bho" two="" n="Bhojpuri"/>
                <l three="bih" two="bh" n="Bihari languages"/>
                <l three="bik" two="" n="Bikol"/>
                <l three="bin" two="" n="Bini; Edo"/>
                <l three="bis" two="bi" n="Bislama"/>
                <l three="bla" two="" n="Siksika"/>
                <l three="bnt" two="" n="Bantu languages"/>
                <l three="bos" two="bs" n="Bosnian"/>
                <l three="bra" two="" n="Braj"/>
                <l three="bre" two="br" n="Breton"/>
                <l three="btk" two="" n="Batak languages"/>
                <l three="bua" two="" n="Buriat"/>
                <l three="bug" two="" n="Buginese"/>
                <l three="bul" two="bg" n="Bulgarian"/>
                <l three="bur" two="my" n="Burmese"/>
                <l three="byn" two="" n="Blin; Bilin"/>
                <l three="cad" two="" n="Caddo"/>
                <l three="cai" two="" n="Central American Indian languages"/>
                <l three="car" two="" n="Galibi Carib"/>
                <l three="cat" two="ca" n="Catalan" o="Catalan; Valencian"/>
                <l three="cau" two="" n="Caucasian languages"/>
                <l three="ceb" two="" n="Cebuano"/>
                <l three="cel" two="" n="Celtic languages"/>
                <l three="cha" two="ch" n="Chamorro"/>
                <l three="chb" two="" n="Chibcha"/>
                <l three="che" two="ce" n="Chechen"/>
                <l three="chg" two="" n="Chagatai"/>
                <l three="chi" two="zh" n="Chinese"/>
                <l three="chk" two="" n="Chuukese"/>
                <l three="chm" two="" n="Mari"/>
                <l three="chn" two="" n="Chinook jargon"/>
                <l three="cho" two="" n="Choctaw"/>
                <l three="chp" two="" n="Chipewyan; Dene Suline"/>
                <l three="chr" two="" n="Cherokee"/>
                <l three="chu" two="cu" n="Church Slavic; Old Slavonic; Church Slavonic; Old Bulgarian; Old Church Slavonic"/>
                <l three="chv" two="cv" n="Chuvash"/>
                <l three="chy" two="" n="Cheyenne"/>
                <l three="cmc" two="" n="Chamic languages"/>
                <l three="cop" two="" n="Coptic"/>
                <l three="cor" two="kw" n="Cornish"/>
                <l three="cos" two="co" n="Corsican"/>
                <l three="cpe" two="" n="Creoles and pidgins, English based"/>
                <l three="cpf" two="" n="Creoles and pidgins, French-based"/>
                <l three="cpp" two="" n="Creoles and pidgins, Portuguese-based"/>
                <l three="cre" two="cr" n="Cree"/>
                <l three="crh" two="" n="Crimean Tatar; Crimean Turkish"/>
                <l three="crp" two="" n="Creoles and pidgins"/>
                <l three="csb" two="" n="Kashubian"/>
                <l three="cus" two="" n="Cushitic languages"/>
                <l three="cze" two="cs" n="Czech"/>
                <l three="dak" two="" n="Dakota"/>
                <l three="dan" two="da" n="Danish"/>
                <l three="dar" two="" n="Dargwa"/>
                <l three="day" two="" n="Land Dayak languages"/>
                <l three="del" two="" n="Delaware"/>
                <l three="den" two="" n="Slave (Athapascan)"/>
                <l three="dgr" two="" n="Dogrib"/>
                <l three="din" two="" n="Dinka"/>
                <l three="div" two="dv" n="Divehi; Dhivehi; Maldivian"/>
                <l three="doi" two="" n="Dogri"/>
                <l three="dra" two="" n="Dravidian languages"/>
                <l three="dsb" two="" n="Lower Sorbian"/>
                <l three="dua" two="" n="Duala"/>
                <l three="dum" two="" n="Dutch, Middle (ca.1050-1350)"/>
                <l three="dut" two="nl" n="Dutch" o="Dutch; Flemish"/>
                <l three="dyu" two="" n="Dyula"/>
                <l three="dzo" two="dz" n="Dzongkha"/>
                <l three="efi" two="" n="Efik"/>
                <l three="egy" two="" n="Egyptian (Ancient)"/>
                <l three="eka" two="" n="Ekajuk"/>
                <l three="elx" two="" n="Elamite"/>
                <l three="eng" two="en" n="English"/>
                <l three="enm" two="" n="English, Middle (1100-1500)"/>
                <l three="epo" two="eo" n="Esperanto"/>
                <l three="est" two="et" n="Estonian"/>
                <l three="ewe" two="ee" n="Ewe"/>
                <l three="ewo" two="" n="Ewondo"/>
                <l three="fan" two="" n="Fang"/>
                <l three="fao" two="fo" n="Faroese"/>
                <l three="fat" two="" n="Fanti"/>
                <l three="fij" two="fj" n="Fijian"/>
                <l three="fil" two="" n="Filipino; Pilipino"/>
                <l three="fin" two="fi" n="Finnish"/>
                <l three="fiu" two="" n="Finno-Ugrian languages"/>
                <l three="fon" two="" n="Fon"/>
                <l three="fre" two="fr" n="French"/>
                <l three="frm" two="" n="French, Middle (ca.1400-1600)"/>
                <l three="fro" two="" n="French, Old (842-ca.1400)"/>
                <l three="frr" two="" n="Northern Frisian"/>
                <l three="frs" two="" n="Eastern Frisian"/>
                <l three="fry" two="fy" n="Western Frisian"/>
                <l three="ful" two="ff" n="Fulah"/>
                <l three="fur" two="" n="Friulian"/>
                <l three="gaa" two="" n="Ga"/>
                <l three="gay" two="" n="Gayo"/>
                <l three="gba" two="" n="Gbaya"/>
                <l three="gem" two="" n="Germanic languages"/>
                <l three="geo" two="ka" n="Georgian"/>
                <l three="ger" two="de" n="German"/>
                <l three="gez" two="" n="Geez"/>
                <l three="gil" two="" n="Gilbertese"/>
                <l three="gla" two="gd" n="Scottish Gaelic" o="Gaelic; Scottish Gaelic"/>
                <l three="gle" two="ga" n="Irish"/>
                <l three="glg" two="gl" n="Galician"/>
                <l three="glv" two="gv" n="Manx"/>
                <l three="gmh" two="" n="German, Middle High (ca.1050-1500)"/>
                <l three="goh" two="" n="German, Old High (ca.750-1050)"/>
                <l three="gon" two="" n="Gondi"/>
                <l three="gor" two="" n="Gorontalo"/>
                <l three="got" two="" n="Gothic"/>
                <l three="grb" two="" n="Grebo"/>
                <l three="grc" two="" n="Greek, Ancient (to 1453)"/>
                <l three="gre" two="el" n="Greek, Modern" o="Greek, Modern (1453-)"/>
                <l three="grn" two="gn" n="Guarani"/>
                <l three="gsw" two="" n="Swiss German; Alemannic; Alsatian"/>
                <l three="guj" two="gu" n="Gujarati"/>
                <l three="gwi" two="" n="Gwich'in"/>
                <l three="hai" two="" n="Haida"/>
                <l three="hat" two="ht" n="Haitian; Haitian Creole"/>
                <l three="hau" two="ha" n="Hausa"/>
                <l three="haw" two="" n="Hawaiian"/>
                <l three="heb" two="he" n="Hebrew"/>
                <l three="her" two="hz" n="Herero"/>
                <l three="hil" two="" n="Hiligaynon"/>
                <l three="him" two="" n="Himachali languages; Western Pahari languages"/>
                <l three="hin" two="hi" n="Hindi"/>
                <l three="hit" two="" n="Hittite"/>
                <l three="hmn" two="" n="Hmong; Mong"/>
                <l three="hmo" two="ho" n="Hiri Motu"/>
                <l three="hrv" two="hr" n="Croatian"/>
                <l three="hsb" two="" n="Upper Sorbian"/>
                <l three="hun" two="hu" n="Hungarian"/>
                <l three="hup" two="" n="Hupa"/>
                <l three="iba" two="" n="Iban"/>
                <l three="ibo" two="ig" n="Igbo"/>
                <l three="ice" two="is" n="Icelandic"/>
                <l three="ido" two="io" n="Ido"/>
                <l three="iii" two="ii" n="Sichuan Yi; Nuosu"/>
                <l three="ijo" two="" n="Ijo languages"/>
                <l three="iku" two="iu" n="Inuktitut"/>
                <l three="ile" two="ie" n="Interlingue; Occidental"/>
                <l three="ilo" two="" n="Iloko"/>
                <l three="ina" two="ia" n="Interlingua (International Auxiliary Language Association)"/>
                <l three="inc" two="" n="Indic languages"/>
                <l three="ind" two="id" n="Indonesian"/>
                <l three="ine" two="" n="Indo-European languages"/>
                <l three="inh" two="" n="Ingush"/>
                <l three="ipk" two="ik" n="Inupiaq"/>
                <l three="ira" two="" n="Iranian languages"/>
                <l three="iro" two="" n="Iroquoian languages"/>
                <l three="ita" two="it" n="Italian"/>
                <l three="jav" two="jv" n="Javanese"/>
                <l three="jbo" two="" n="Lojban"/>
                <l three="jpn" two="ja" n="Japanese"/>
                <l three="jpr" two="" n="Judeo-Persian"/>
                <l three="jrb" two="" n="Judeo-Arabic"/>
                <l three="kaa" two="" n="Kara-Kalpak"/>
                <l three="kab" two="" n="Kabyle"/>
                <l three="kac" two="" n="Kachin; Jingpho"/>
                <l three="kal" two="kl" n="Kalaallisut; Greenlandic"/>
                <l three="kam" two="" n="Kamba"/>
                <l three="kan" two="kn" n="Kannada"/>
                <l three="kar" two="" n="Karen languages"/>
                <l three="kas" two="ks" n="Kashmiri"/>
                <l three="kau" two="kr" n="Kanuri"/>
                <l three="kaw" two="" n="Kawi"/>
                <l three="kaz" two="kk" n="Kazakh"/>
                <l three="kbd" two="" n="Kabardian"/>
                <l three="kha" two="" n="Khasi"/>
                <l three="khi" two="" n="Khoisan languages"/>
                <l three="khm" two="km" n="Central Khmer"/>
                <l three="kho" two="" n="Khotanese; Sakan"/>
                <l three="kik" two="ki" n="Kikuyu; Gikuyu"/>
                <l three="kin" two="rw" n="Kinyarwanda"/>
                <l three="kir" two="ky" n="Kirghiz; Kyrgyz"/>
                <l three="kmb" two="" n="Kimbundu"/>
                <l three="kok" two="" n="Konkani"/>
                <l three="kom" two="kv" n="Komi"/>
                <l three="kon" two="kg" n="Kongo"/>
                <l three="kor" two="ko" n="Korean"/>
                <l three="kos" two="" n="Kosraean"/>
                <l three="kpe" two="" n="Kpelle"/>
                <l three="krc" two="" n="Karachay-Balkar"/>
                <l three="krl" two="" n="Karelian"/>
                <l three="kro" two="" n="Kru languages"/>
                <l three="kru" two="" n="Kurukh"/>
                <l three="kua" two="kj" n="Kuanyama; Kwanyama"/>
                <l three="kum" two="" n="Kumyk"/>
                <l three="kur" two="ku" n="Kurdish"/>
                <l three="kut" two="" n="Kutenai"/>
                <l three="lad" two="" n="Ladino"/>
                <l three="lah" two="" n="Lahnda"/>
                <l three="lam" two="" n="Lamba"/>
                <l three="lao" two="lo" n="Lao"/>
                <l three="lat" two="la" n="Latin"/>
                <l three="lav" two="lv" n="Latvian"/>
                <l three="lez" two="" n="Lezghian"/>
                <l three="lim" two="li" n="Limburgan; Limburger; Limburgish"/>
                <l three="lin" two="ln" n="Lingala"/>
                <l three="lit" two="lt" n="Lithuanian"/>
                <l three="lol" two="" n="Mongo"/>
                <l three="loz" two="" n="Lozi"/>
                <l three="ltz" two="lb" n="Luxembourgish; Letzeburgesch"/>
                <l three="lua" two="" n="Luba-Lulua"/>
                <l three="lub" two="lu" n="Luba-Katanga"/>
                <l three="lug" two="lg" n="Ganda"/>
                <l three="lui" two="" n="Luiseno"/>
                <l three="lun" two="" n="Lunda"/>
                <l three="luo" two="" n="Luo (Kenya and Tanzania)"/>
                <l three="lus" two="" n="Lushai"/>
                <l three="mac" two="mk" n="Macedonian"/>
                <l three="mad" two="" n="Madurese"/>
                <l three="mag" two="" n="Magahi"/>
                <l three="mah" two="mh" n="Marshallese"/>
                <l three="mai" two="" n="Maithili"/>
                <l three="mak" two="" n="Makasar"/>
                <l three="mal" two="ml" n="Malayalam"/>
                <l three="man" two="" n="Mandingo"/>
                <l three="mao" two="mi" n="Maori"/>
                <l three="map" two="" n="Austronesian languages"/>
                <l three="mar" two="mr" n="Marathi"/>
                <l three="mas" two="" n="Masai"/>
                <l three="may" two="ms" n="Malay"/>
                <l three="mdf" two="" n="Moksha"/>
                <l three="mdr" two="" n="Mandar"/>
                <l three="men" two="" n="Mende"/>
                <l three="mga" two="" n="Irish, Middle (900-1200)"/>
                <l three="mic" two="" n="Mi'kmaq; Micmac"/>
                <l three="min" two="" n="Minangkabau"/>
                <l three="mis" two="" n="Uncoded languages"/>
                <l three="mkh" two="" n="Mon-Khmer languages"/>
                <l three="mlg" two="mg" n="Malagasy"/>
                <l three="mlt" two="mt" n="Maltese"/>
                <l three="mnc" two="" n="Manchu"/>
                <l three="mni" two="" n="Manipuri"/>
                <l three="mno" two="" n="Manobo languages"/>
                <l three="moh" two="" n="Mohawk"/>
                <l three="mon" two="mn" n="Mongolian"/>
                <l three="mos" two="" n="Mossi"/>
                <l three="mul" two="" n="Multiple languages"/>
                <l three="mun" two="" n="Munda languages"/>
                <l three="mus" two="" n="Creek"/>
                <l three="mwl" two="" n="Mirandese"/>
                <l three="mwr" two="" n="Marwari"/>
                <l three="myn" two="" n="Mayan languages"/>
                <l three="myv" two="" n="Erzya"/>
                <l three="nah" two="" n="Nahuatl languages"/>
                <l three="nai" two="" n="North American Indian languages"/>
                <l three="nap" two="" n="Neapolitan"/>
                <l three="nau" two="na" n="Nauru"/>
                <l three="nav" two="nv" n="Navajo; Navaho"/>
                <l three="nbl" two="nr" n="Ndebele, South; South Ndebele"/>
                <l three="nde" two="nd" n="Ndebele, North; North Ndebele"/>
                <l three="ndo" two="ng" n="Ndonga"/>
                <l three="nds" two="" n="Low German; Low Saxon; German, Low; Saxon, Low"/>
                <l three="nep" two="ne" n="Nepali"/>
                <l three="new" two="" n="Nepal Bhasa; Newari"/>
                <l three="nia" two="" n="Nias"/>
                <l three="nic" two="" n="Niger-Kordofanian languages"/>
                <l three="niu" two="" n="Niuean"/>
                <l three="nno" two="nn" n="Norwegian Nynorsk; Nynorsk, Norwegian"/>
                <l three="nob" two="nb" n="Bokm&#x00E5;l, Norwegian; Norwegian Bokm&#x00E5;l"/>
                <l three="nog" two="" n="Nogai"/>
                <l three="non" two="" n="Norse, Old"/>
                <l three="nor" two="no" n="Norwegian"/>
                <l three="nqo" two="" n="N'Ko"/>
                <l three="nso" two="" n="Pedi; Sepedi; Northern Sotho"/>
                <l three="nub" two="" n="Nubian languages"/>
                <l three="nwc" two="" n="Classical Newari; Old Newari; Classical Nepal Bhasa"/>
                <l three="nya" two="ny" n="Chichewa; Chewa; Nyanja"/>
                <l three="nym" two="" n="Nyamwezi"/>
                <l three="nyn" two="" n="Nyankole"/>
                <l three="nyo" two="" n="Nyoro"/>
                <l three="nzi" two="" n="Nzima"/>
                <l three="oci" two="oc" n="Occitan (post 1500)"/>
                <l three="oji" two="oj" n="Ojibwa"/>
                <l three="ori" two="or" n="Oriya"/>
                <l three="orm" two="om" n="Oromo"/>
                <l three="osa" two="" n="Osage"/>
                <l three="oss" two="os" n="Ossetian; Ossetic"/>
                <l three="ota" two="" n="Turkish, Ottoman (1500-1928)"/>
                <l three="oto" two="" n="Otomian languages"/>
                <l three="paa" two="" n="Papuan languages"/>
                <l three="pag" two="" n="Pangasinan"/>
                <l three="pal" two="" n="Pahlavi"/>
                <l three="pam" two="" n="Pampanga; Kapampangan"/>
                <l three="pan" two="pa" n="Panjabi; Punjabi"/>
                <l three="pap" two="" n="Papiamento"/>
                <l three="pau" two="" n="Palauan"/>
                <l three="peo" two="" n="Persian, Old (ca.600-400 B.C.)"/>
                <l three="per" two="fa" n="Persian"/>
                <l three="phi" two="" n="Philippine languages"/>
                <l three="phn" two="" n="Phoenician"/>
                <l three="pli" two="pi" n="Pali"/>
                <l three="pol" two="pl" n="Polish"/>
                <l three="pon" two="" n="Pohnpeian"/>
                <l three="por" two="pt" n="Portuguese"/>
                <l three="pra" two="" n="Prakrit languages"/>
                <l three="pro" two="" n="Proven&#x00E7;al, Old (to 1500);Occitan, Old (to 1500)"/>
                <l three="pus" two="ps" n="Pushto" o="Pushto; Pashto"/>
                <l three="que" two="qu" n="Quechua"/>
                <l three="raj" two="" n="Rajasthani"/>
                <l three="rap" two="" n="Rapanui"/>
                <l three="rar" two="" n="Rarotongan; Cook Islands Maori"/>
                <l three="roa" two="" n="Romance languages"/>
                <l three="roh" two="rm" n="Romansh"/>
                <l three="rom" two="" n="Romany"/>
                <l three="rum" two="ro" n="Romanian" o="Romanian; Moldavian; Moldovan"/>
                <l three="run" two="rn" n="Rundi"/>
                <l three="rup" two="" n="Aromanian; Arumanian; Macedo-Romanian"/>
                <l three="rus" two="ru" n="Russian"/>
                <l three="sad" two="" n="Sandawe"/>
                <l three="sag" two="sg" n="Sango"/>
                <l three="sah" two="" n="Yakut"/>
                <l three="sai" two="" n="South American Indian languages"/>
                <l three="sal" two="" n="Salishan languages"/>
                <l three="sam" two="" n="Samaritan Aramaic"/>
                <l three="san" two="sa" n="Sanskrit"/>
                <l three="sas" two="" n="Sasak"/>
                <l three="sat" two="" n="Santali"/>
                <l three="scn" two="" n="Sicilian"/>
                <l three="sco" two="" n="Scots"/>
                <l three="sel" two="" n="Selkup"/>
                <l three="sem" two="" n="Semitic languages"/>
                <l three="sga" two="" n="Irish, Old (to 900)"/>
                <l three="sgn" two="" n="Sign Languages"/>
                <l three="shn" two="" n="Shan"/>
                <l three="sid" two="" n="Sidamo"/>
                <l three="sin" two="si" n="Sinhala; Sinhalese"/>
                <l three="sio" two="" n="Siouan languages"/>
                <l three="sit" two="" n="Sino-Tibetan languages"/>
                <l three="sla" two="" n="Slavic languages"/>
                <l three="slo" two="sk" n="Slovak"/>
                <l three="slv" two="sl" n="Slovenian"/>
                <l three="sma" two="" n="Southern Sami"/>
                <l three="sme" two="se" n="Northern Sami"/>
                <l three="smi" two="" n="Sami languages"/>
                <l three="smj" two="" n="Lule Sami"/>
                <l three="smn" two="" n="Inari Sami"/>
                <l three="smo" two="sm" n="Samoan"/>
                <l three="sms" two="" n="Skolt Sami"/>
                <l three="sna" two="sn" n="Shona"/>
                <l three="snd" two="sd" n="Sindhi"/>
                <l three="snk" two="" n="Soninke"/>
                <l three="sog" two="" n="Sogdian"/>
                <l three="som" two="so" n="Somali"/>
                <l three="son" two="" n="Songhai languages"/>
                <l three="sot" two="st" n="Sotho, Southern"/>
                <l three="spa" two="es" n="Spanish" o="Spanish; Castilian"/>
                <l three="srd" two="sc" n="Sardinian"/>
                <l three="srn" two="" n="Sranan Tongo"/>
                <l three="srp" two="sr" n="Serbian"/>
                <l three="srr" two="" n="Serer"/>
                <l three="ssa" two="" n="Nilo-Saharan languages"/>
                <l three="ssw" two="ss" n="Swati"/>
                <l three="suk" two="" n="Sukuma"/>
                <l three="sun" two="su" n="Sundanese"/>
                <l three="sus" two="" n="Susu"/>
                <l three="sux" two="" n="Sumerian"/>
                <l three="swa" two="sw" n="Swahili"/>
                <l three="swe" two="sv" n="Swedish"/>
                <l three="syc" two="" n="Classical Syriac"/>
                <l three="syr" two="" n="Syriac"/>
                <l three="tah" two="ty" n="Tahitian"/>
                <l three="tai" two="" n="Tai languages"/>
                <l three="tam" two="ta" n="Tamil"/>
                <l three="tat" two="tt" n="Tatar"/>
                <l three="tel" two="te" n="Telugu"/>
                <l three="tem" two="" n="Timne"/>
                <l three="ter" two="" n="Tereno"/>
                <l three="tet" two="" n="Tetum"/>
                <l three="tgk" two="tg" n="Tajik"/>
                <l three="tgl" two="tl" n="Tagalog"/>
                <l three="tha" two="th" n="Thai"/>
                <l three="tib" two="bo" n="Tibetan"/>
                <l three="tig" two="" n="Tigre"/>
                <l three="tir" two="ti" n="Tigrinya"/>
                <l three="tiv" two="" n="Tiv"/>
                <l three="tkl" two="" n="Tokelau"/>
                <l three="tlh" two="" n="Klingon; tlhIngan-Hol"/>
                <l three="tli" two="" n="Tlingit"/>
                <l three="tmh" two="" n="Tamashek"/>
                <l three="tog" two="" n="Tonga (Nyasa)"/>
                <l three="ton" two="to" n="Tonga (Tonga Islands)"/>
                <l three="tpi" two="" n="Tok Pisin"/>
                <l three="tsi" two="" n="Tsimshian"/>
                <l three="tsn" two="tn" n="Tswana"/>
                <l three="tso" two="ts" n="Tsonga"/>
                <l three="tuk" two="tk" n="Turkmen"/>
                <l three="tum" two="" n="Tumbuka"/>
                <l three="tup" two="" n="Tupi languages"/>
                <l three="tur" two="tr" n="Turkish"/>
                <l three="tut" two="" n="Altaic languages"/>
                <l three="tvl" two="" n="Tuvalu"/>
                <l three="twi" two="tw" n="Twi"/>
                <l three="tyv" two="" n="Tuvinian"/>
                <l three="udm" two="" n="Udmurt"/>
                <l three="uga" two="" n="Ugaritic"/>
                <l three="uig" two="ug" n="Uighur; Uyghur"/>
                <l three="ukr" two="uk" n="Ukrainian"/>
                <l three="umb" two="" n="Umbundu"/>
                <l three="und" two="" n="Undetermined"/>
                <l three="urd" two="ur" n="Urdu"/>
                <l three="uzb" two="uz" n="Uzbek"/>
                <l three="vai" two="" n="Vai"/>
                <l three="ven" two="ve" n="Venda"/>
                <l three="vie" two="vi" n="Vietnamese"/>
                <l three="vol" two="vo" n="Volap&#x00FC;k"/>
                <l three="vot" two="" n="Votic"/>
                <l three="wak" two="" n="Wakashan languages"/>
                <l three="wal" two="" n="Wolaitta; Wolaytta"/>
                <l three="war" two="" n="Waray"/>
                <l three="was" two="" n="Washo"/>
                <l three="wel" two="cy" n="Welsh"/>
                <l three="wen" two="" n="Sorbian languages"/>
                <l three="wln" two="wa" n="Walloon"/>
                <l three="wol" two="wo" n="Wolof"/>
                <l three="xal" two="" n="Kalmyk; Oirat"/>
                <l three="xho" two="xh" n="Xhosa"/>
                <l three="yao" two="" n="Yao"/>
                <l three="yap" two="" n="Yapese"/>
                <l three="yid" two="yi" n="Yiddish"/>
                <l three="yor" two="yo" n="Yoruba"/>
                <l three="ypk" two="" n="Yupik languages"/>
                <l three="zap" two="" n="Zapotec"/>
                <l three="zbl" two="" n="Blissymbols; Blissymbolics; Bliss"/>
                <l three="zen" two="" n="Zenaga"/>
                <l three="zgh" two="" n="Standard Moroccan Tamazight"/>
                <l three="zha" two="za" n="Zhuang; Chuang"/>
                <l three="znd" two="" n="Zande languages"/>
                <l three="zul" two="zu" n="Zulu"/>
                <l three="zun" two="" n="Zuni"/>
                <l three="zxx" two="" n="No linguistic content; Not applicable"/>
                <l three="zza" two="" n="Zaza; Dimili; Dimli; Kirdki; Kirmanjki; Zazaki"/>
        </pmcvar:langs>

</xsl:stylesheet>
