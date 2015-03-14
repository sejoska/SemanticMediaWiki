<?php

namespace SMW\MediaWiki\Hooks;

use Parser;
use SMW\ApplicationFactory;

/**
 * Hook: ParserAfterTidy to add some final processing to the
 * fully-rendered page output
 *
 * @see http://www.mediawiki.org/wiki/Manual:Hooks/ParserAfterTidy
 *
 * @license GNU GPL v2+
 * @since 1.9
 *
 * @author mwjames
 */
class ParserAfterTidy {

	/**
	 * @var Parser
	 */
	private $parser = null;

	/**
	 * @var string
	 */
	private $text;

	/**
	 * @var ApplicationFactory
	 */
	private $applicationFactory = null;

	/**
	 * @since  1.9
	 *
	 * @param Parser $parser
	 * @param string $text
	 */
	public function __construct( Parser &$parser, &$text ) {
		$this->parser = $parser;
		$this->text =& $text;
	}

	/**
	 * @since 1.9
	 *
	 * @return true
	 */
	public function process() {
		return $this->canPerformUpdate() ? $this->performUpdate() : true;
	}

	protected function canPerformUpdate() {

		if ( $this->parser->getTitle()->isSpecialPage() ) {
			return false;
		}

		if ( $this->parser->getOutput()->getProperty( 'smw-semanticdata-status' ) ||
			$this->parser->getOutput()->getCategoryLinks() ||
			$this->parser->getDefaultSort() ) {
			return true;
		}

		return false;
	}

	protected function performUpdate() {

		$this->applicationFactory = ApplicationFactory::getInstance();

		$parserData = $this->applicationFactory->newParserData(
			$this->parser->getTitle(),
			$this->parser->getOutput()
		);

		$this->updateAnnotionsForAfterParse(
			$this->applicationFactory->newPropertyAnnotatorFactory(),
			$parserData->getSemanticData()
		);

		$parserData->pushSemanticDataToParserOutput();

		$this->checkForRequestedUpdateByPagePurge( $parserData );

		return true;
	}

	private function updateAnnotionsForAfterParse( $propertyAnnotatorFactory, $semanticData ) {

		$propertyAnnotator = $propertyAnnotatorFactory->newSortkeyPropertyAnnotator(
			$semanticData,
			$this->parser->getDefaultSort()
		);

		$propertyAnnotator->addAnnotation();

		$propertyAnnotator = $propertyAnnotatorFactory->newCategoryPropertyAnnotator(
			$semanticData,
			$this->parser->getOutput()->getCategoryLinks()
		);

		$propertyAnnotator->addAnnotation();

		$propertyAnnotator = $propertyAnnotatorFactory->newTypeByImportPropertyAnnotator(
			$semanticData
		);

		$propertyAnnotator->addAnnotation();
	}

	/**
	 * @note Article purge: In case an article was manually purged/moved
	 * the store is updated as well; for all other cases LinksUpdateConstructed
	 * will handle the store update
	 */
	private function checkForRequestedUpdateByPagePurge( $parserData ) {

		$cache = $this->applicationFactory->getCache();

		$cache->setKey( ArticlePurge::newCacheId( $this->parser->getTitle()->getArticleID() ) );

		if( $cache->get() ) {
			$cache->delete();
			$parserData->updateStore();
		}

		return true;
	}

}
