<?php

/**
 * Static class for hooks handled by the Semantic MediaWiki extension.
 *
 * @since 1.7
 *
 * @file SemanticMediaWiki.hooks.php
 * @ingroup SMW
 *
 * @licence GNU GPL v2+
 * @author Jeroen De Dauw < jeroendedauw@gmail.com >
 */
final class SMWHooks {

	/**
	 * Schema update to set up the needed database tables.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/LoadExtensionSchemaUpdates
	 *
	 * @since 1.7
	 *
	 * @param DatabaseUpdater $updater|null
	 *
	 * @return boolean
	 */
	public static function onSchemaUpdate( DatabaseUpdater $updater = null ) {
		$updater->addExtensionUpdate( array( 'SMWStore::setupStore' ) );

		return true;
	}

	/**
	 * TODO
	 *
	 * @since 1.7
	 *
	 * @return boolean
	 */
	public static function onPageSchemasRegistration() {
		$GLOBALS['wgPageSchemasHandlerClasses'][] = 'SMWPageSchemas';
		return true;
	}

	/**
	 * Adds links to Admin Links page.
	 *
	 * @since 1.7
	 *
	 * @param ALTree $admin_links_tree
	 *
	 * @return boolean
	 */
	public static function addToAdminLinks( ALTree &$admin_links_tree ) {
		$data_structure_section = new ALSection( wfMsg( 'smw_adminlinks_datastructure' ) );

		$smw_row = new ALRow( 'smw' );
		$smw_row->addItem( ALItem::newFromSpecialPage( 'Categories' ) );
		$smw_row->addItem( ALItem::newFromSpecialPage( 'Properties' ) );
		$smw_row->addItem( ALItem::newFromSpecialPage( 'UnusedProperties' ) );
		$smw_row->addItem( ALItem::newFromSpecialPage( 'SemanticStatistics' ) );

		$data_structure_section->addRow( $smw_row );
		$smw_admin_row = new ALRow( 'smw_admin' );
		$smw_admin_row->addItem( ALItem::newFromSpecialPage( 'SMWAdmin' ) );

		$data_structure_section->addRow( $smw_admin_row );
		$smw_docu_row = new ALRow( 'smw_docu' );
		$smw_name = wfMsg( 'specialpages-group-smw_group' );
		$smw_docu_label = wfMsg( 'adminlinks_documentation', $smw_name );
		$smw_docu_row->addItem( AlItem::newFromExternalLink( 'http://semantic-mediawiki.org/wiki/Help:User_manual', $smw_docu_label ) );

		$data_structure_section->addRow( $smw_docu_row );
		$admin_links_tree->addSection( $data_structure_section, wfMsg( 'adminlinks_browsesearch' ) );
		$smw_row = new ALRow( 'smw' );
		$displaying_data_section = new ALSection( wfMsg( 'smw_adminlinks_displayingdata' ) );
		$smw_row->addItem( AlItem::newFromExternalLink( 'http://semantic-mediawiki.org/wiki/Help:Inline_queries', wfMsg( 'smw_adminlinks_inlinequerieshelp' ) ) );

		$displaying_data_section->addRow( $smw_row );
		$admin_links_tree->addSection( $displaying_data_section, wfMsg( 'adminlinks_browsesearch' ) );
		$browse_search_section = $admin_links_tree->getSection( wfMsg( 'adminlinks_browsesearch' ) );

		$smw_row = new ALRow( 'smw' );
		$smw_row->addItem( ALItem::newFromSpecialPage( 'Browse' ) );
		$smw_row->addItem( ALItem::newFromSpecialPage( 'Ask' ) );
		$smw_row->addItem( ALItem::newFromSpecialPage( 'SearchByProperty' ) );
		$browse_search_section->addRow( $smw_row );

		return true;
	}


	/**
	 * Register special classes for displaying semantic content on Property and
	 * Concept pages.
	 *
	 * @since 1.7
	 *
	 * @param $title Title
	 * @param $article Article or null
	 *
	 * @return boolean
	 */
	public static function onArticleFromTitle( Title &$title, /* Article */ &$article ) {
		if ( $title->getNamespace() == SMW_NS_PROPERTY ) {
			$article = new SMWPropertyPage( $title );
		} elseif ( $title->getNamespace() == SMW_NS_CONCEPT ) {
			$article = new SMWConceptPage( $title );
		}

		return true;
	}

	/**
	 * This hook registers parser functions and hooks to the given parser. It is
	 * called during SMW initialisation. Note that parser hooks are something different
	 * than MW hooks in general, which explains the two-level registration.
	 *
	 * @since 1.7
	 *
	 * @param Parser $parser
	 *
	 * @return boolean
	 */
	public static function onParserFirstCallInit( Parser &$parser ) {
		$parser->setFunctionHook( 'ask', array( 'SMWAsk', 'render' ) );
		$parser->setFunctionHook( 'show', array( 'SMWShow', 'render' ) );
		$parser->setFunctionHook( 'subobject', array( 'SMWSubobject', 'render' ) );
		$parser->setFunctionHook( 'concept', array( 'SMWConcept', 'render' ) );
		$parser->setFunctionHook( 'set', array( 'SMWSet', 'render' ) );
		$parser->setFunctionHook( 'set_recurring_event', array( 'SMWSetRecurringEvent', 'render' ) );
		$parser->setFunctionHook( 'declare', array( 'SMWDeclare', 'render' ), SFH_OBJECT_ARGS );

		return true;
	}

	/**
	 * Adds the 'Powered by Semantic MediaWiki' button right next to the default
	 * 'Powered by MediaWiki' button at the bottom of every page. This works
	 * only with MediaWiki 1.17+.
	 * It might make sense to make this configurable via a variable, if some
	 * admins don't want it.
	 *
	 * @since 1.7
	 *
	 * @param string $text
	 * @param Skin $skin
	 *
	 * @return boolean
	 */
	public static function addPoweredBySMW( &$text, $skin ) {
		global $smwgScriptPath;
		$url = htmlspecialchars( "$smwgScriptPath/skins/images/smw_button.png" );
		$text .= ' <a href="http://www.semantic-mediawiki.org/wiki/Semantic_MediaWiki"><img src="' . $url . '" alt="Powered by Semantic MediaWiki" /></a>';

		return true;
	}

	/**
	 * Adds the 'semantic' extension type to the type list.
	 *
	 * @since 1.7.1
	 *
	 * @param $aExtensionTypes Array
	 *
	 * @return boolean
	 */
	public static function addSemanticExtensionType( array &$aExtensionTypes ) {
		$aExtensionTypes = array_merge( array( 'semantic' => wfMsg( 'version-semantic' ) ), $aExtensionTypes );
		return true;
	}

	/**
	 * Register tables to be added to temporary tables for parser tests.
	 * @todo Hard-coding this thwarts the modularity/exchangability of the SMW
	 * storage backend. The actual list of required tables depends on the backend
	 * implementation and cannot really be fixed here.
	 *
	 * @since 1.7.1
	 *
	 * @param array $tables
	 *
	 * @return boolean
	 */
	public static function onParserTestTables( array &$tables ) {
		$tables[] = 'smw_ids';
		$tables[] = 'smw_redi2';
		$tables[] = 'smw_atts2';
		$tables[] = 'smw_rels2';
		$tables[] = 'smw_text2';
		$tables[] = 'smw_spec2';
		$tables[] = 'smw_inst2';
		$tables[] = 'smw_subs2';
		return true;
	}

	/**
	 * Add a link to the toolbox to view the properties of the current page in
	 * Special:Browse. The links has the CSS id "t-smwbrowselink" so that it can be
	 * skinned or hidden with all standard mechanisms (also by individual users
	 * with custom CSS).
	 *
	 * @since 1.7.1
	 *
	 * @param $skintemplate
	 *
	 * @return boolean
	 */
	public static function showBrowseLink( $skintemplate ) {
		if ( $skintemplate->data['isarticle'] ) {
			$browselink = SMWInfolink::newBrowsingLink( wfMsg( 'smw_browselink' ),
							$skintemplate->data['titleprefixeddbkey'], false );
			echo '<li id="t-smwbrowselink">' . $browselink->getHTML() . '</li>';
		}
		return true;
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/SkinTemplateTabs
	 * This is here for compatibility with MediaWiki 1.17. Once we can require 1.18, we can ditch this code :)
	 *
	 * @since 0.1
	 *
	 * @param SkinTemplate $skinTemplate
	 * @param array $contentActions
	 *
	 * @return boolean
	 */
	public static function addRefreshTab( SkinTemplate $skinTemplate, array &$contentActions ) {
		global $wgUser;

		if ( $wgUser->isAllowed( 'purge' ) ) {
			$contentActions['purge'] = array(
				'class' => false,
				'text' => wfMsg( 'smw_purge' ),
				'href' => $skinTemplate->getTitle()->getLocalUrl( array( 'action' => 'purge' ) )
			);
		}

		return true;
	}

	/**
	 * Alter the structured navigation links in SkinTemplates.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/SkinTemplateNavigation
	 *
	 * @since 1.8
	 *
	 * @param SkinTemplate $skinTemplate
	 * @param array $links
	 *
	 * @return boolean
	 */
	public static function addStructuredRefreshTab( SkinTemplate &$skinTemplate, array &$links ) {
		$actions = $links['actions'];
		self::addRefreshTab( $skinTemplate, $actions );
		$links['actions'] = $actions;

		return true;
	}

	/**
	* Hook to add PHPUnit test cases.
	* @see https://www.mediawiki.org/wiki/Manual:Hooks/UnitTestsList
	*
	* @since 1.8
	 * 
	* @param array $files
	*
	* @return boolean
	*/
	public static function registerUnitTests ( array &$files ) {
		$testDir = dirname( __FILE__ ). '/tests/phpunit/';

		//store tests
		$testDirStore = $testDir . 'includes/storage/';
		$files[] = $testDirStore . 'SMW_StoreTest.php';

		//dataitems tests
		$testDirDI = $testDir . 'includes/dataitems/';
		$files[] = $testDirDI . 'SMW_DI_NumberTest.php';
		$files[] = $testDirDI . 'SMW_DI_BoolTest.php';
		$files[] = $testDirDI . 'SMW_DI_BlobTest.php';
		$files[] = $testDirDI . 'SMW_DI_StringTest.php';
		$files[] = $testDirDI . 'SMW_DI_GeoCoordTest.php';

		//QueryProcessor tests
		$testDirInc = $testDir . 'includes/';
		$files[] = $testDirInc . 'SMW_QueryProcessorTest.php';

		return true;
	}

}
