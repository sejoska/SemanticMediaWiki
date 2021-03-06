<?php

namespace SMW\MediaWiki\Specials;

use ParamProcessor\Param;
use SMW\Query\PrintRequest;
use SMW\Query\RemoteRequest;
use SMW\Query\QueryLinker;
use SMW\MediaWiki\Specials\Ask\ErrorWidget;
use SMW\MediaWiki\Specials\Ask\LinksWidget;
use SMW\MediaWiki\Specials\Ask\ParametersWidget;
use SMW\MediaWiki\Specials\Ask\ParametersProcessor;
use SMW\MediaWiki\Specials\Ask\NavigationLinksWidget;
use SMW\MediaWiki\Specials\Ask\HelpWidget;
use SMW\MediaWiki\Specials\Ask\SortWidget;
use SMW\MediaWiki\Specials\Ask\FormatListWidget;
use SMW\MediaWiki\Specials\Ask\QueryInputWidget;
use SMW\MediaWiki\Specials\Ask\UrlArgs;
use SMW\Utils\HtmlModal;
use SMW\ApplicationFactory;
use SMWQueryProcessor as QueryProcessor;
use SMWQueryResult as QueryResult;
use SMW\Query\Result\StringResult;
use SMWInfolink as Infolink;
use SpecialPage;
use SMWOutputs;
use SMWQuery;
use Html;

/**
 * This special page for MediaWiki implements a customisable form for executing
 * queries outside of articles.
 *
 * @license GNU GPL v2+
 * @since   3.0
 *
 * @author mwjames
 * @author Markus Krötzsch
 * @author Yaron Koren
 * @author Sanyam Goyal
 * @author Jeroen De Dauw
 */
class SpecialAsk extends SpecialPage {

	/**
	 * @var QuerySourceFactory
	 */
	private $querySourceFactory;

	/**
	 * @var string
	 */
	private $queryString = '';

	/**
	 * @var array
	 */
	private $parameters = array();

	/**
	 * @var array
	 */
	private $printouts = array();

	/**
	 * @var boolean
	 */
	private $isEditMode = false;

	/**
	 * @var boolean
	 */
	private $isBorrowedMode = false;

	/**
	 * @var Param[]
	 */
	private $params = array();

	public function __construct() {
		parent::__construct( 'Ask' );
		$this->querySourceFactory = ApplicationFactory::getInstance()->getQuerySourceFactory();
	}

	/**
	 * @see SpecialPage::doesWrites
	 *
	 * @return boolean
	 */
	public function doesWrites() {
		return true;
	}

	/**
	 * @see SpecialPage::execute
	 *
	 * @param string $p
	 */
	public function execute( $p ) {

		$this->setHeaders();
		$settings = ApplicationFactory::getInstance()->getSettings();

		$out = $this->getOutput();
		$request = $this->getRequest();

		$request->setVal( 'wpEditToken',
			$this->getUser()->getEditToken()
		);

		if ( !$GLOBALS['smwgQEnabled'] ) {
			return $out->addHtml( ErrorWidget::disabled() );
		}

		// Administrative block when used in combination with the `RemoteRequest`.
		// It is not to be mistaken with an auth block as you always can fetch
		// the content from a public wiki via cURL.
		if ( $request->getVal( 'request_type', '' ) !== '' && !$settings->isFlagSet( 'smwgRemoteReqFeatures', SMW_REMOTE_REQ_SEND_RESPONSE ) ) {
			$out->disable();
			return print RemoteRequest::SOURCE_DISABLED;
		}

		$this->init();

		if ( $request->getCheck( 'showformatoptions' ) ) {
			// handle Ajax action
			$params = $request->getArray( 'params' );
			$params['format'] = $request->getVal( 'showformatoptions' );
			$out->disable();
			echo ParametersWidget::parameterList( $params );
		} else {
			$this->extractQueryParameters( $p );

			if ( $this->isBorrowedMode ) {
				$visibleLinks = [];
			} elseif( $request->getVal( 'eq', '' ) === 'no' || $p !== null || $request->getVal( 'x' ) || $request->getVal( 'cl' ) ) {
				$visibleLinks = [ 'search', 'empty' ];
			} else {
				$visibleLinks = [ 'options', 'search', 'help', 'empty' ];
			}

			$out->addHTML(
				NavigationLinksWidget::topLinks(
					SpecialPage::getSafeTitleFor( 'Ask' ),
					$visibleLinks
				)
			);

			$this->makeHTMLResult();
		}

		$out->addHTML( HelpWidget::html() );
		$this->addHelpLink( wfMessage( 'smw_ask_doculink' )->escaped(), true );

		// make sure locally collected output data is pushed to the output!
		SMWOutputs::commitToOutputPage( $out );
	}

	/**
	 * @see SpecialPage::getGroupName
	 */
	protected function getGroupName() {
		return 'smw_group';
	}

	private function init() {
		$out = $this->getOutput();
		$request = $this->getRequest();

		$out->addModuleStyles( 'ext.smw.style' );
		$out->addModuleStyles( 'ext.smw.ask.styles' );
		$out->addModuleStyles( 'ext.smw.table.styles' );

		$out->addModuleStyles(
			HtmlModal::getModuleStyles()
		);

		$out->addModules( 'ext.smw.ask' );
		$out->addModules( 'ext.smw.autocomplete.property' );

		$out->addModules(
			LinksWidget::getModules()
		);

		$out->addModules(
			HtmlModal::getModules()
		);

		$out->addHTML( ErrorWidget::noScript() );

		// #2590
		if ( !$this->getUser()->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
			return $out->addHtml( ErrorWidget::sessionFailure() );
		}

		$settings = ApplicationFactory::getInstance()->getSettings();

		NavigationLinksWidget::setMaxInlineLimit(
			$GLOBALS['smwgQMaxInlineLimit']
		);

		FormatListWidget::setResultFormats(
			$GLOBALS['smwgResultFormats']
		);

		ParametersWidget::setTooltipDisplay(
			$this->getUser()->getOption( 'smw-prefs-ask-options-tooltip-display' )
		);

		ParametersWidget::setDefaultLimit(
			$GLOBALS['smwgQDefaultLimit']
		);

		SortWidget::setSortingSupport(
			$settings->isFlagSet( 'smwgQSortFeatures', SMW_QSORT )
		);

		// @see #835
		SortWidget::setRandSortingSupport(
			$settings->isFlagSet( 'smwgQSortFeatures', SMW_QSORT_RANDOM )
		);

		ParametersProcessor::setDefaultLimit(
			$GLOBALS['smwgQDefaultLimit']
		);

		ParametersProcessor::setMaxInlineLimit(
			$GLOBALS['smwgQMaxInlineLimit']
		);

		$this->isBorrowedMode = $request->getCheck( 'bTitle' ) || $request->getCheck( 'btitle' );

	}

	/**
	 * @param string $p
	 */
	protected function extractQueryParameters( $p ) {

		$request = $this->getRequest();
		$this->isEditMode = false;

		if ( $request->getText( 'cl', '' ) !== '' ) {
			$p = Infolink::decodeCompactLink( 'cl:' . $request->getText( 'cl' ) );
		} else {
			$p = Infolink::decodeCompactLink( $p );
		}

		list( $this->queryString, $this->parameters, $this->printouts ) = ParametersProcessor::process(
			$request,
			$p
		);

		if ( isset( $this->parameters['btitle'] ) ) {
			$this->isBorrowedMode = true;
		}

		if ( ( $request->getVal( 'eq' ) == 'yes' ) || ( $this->queryString === '' ) ) {
			$this->isEditMode = true;
		}
	}

	protected function makeHTMLResult() {

		$result = '';
		$res = null;

		$navigation = '';
		$urlArgs = $this->newUrlArgs();

		$isFromCache = false;
		$duration = 0;

		$queryLink = null;
		$error = '';

		if ( $this->queryString !== '' ) {

			list( $res, $debug, $duration, $queryobj ) = $this->getQueryResult();

			$printer = QueryProcessor::getResultPrinter(
				$this->parameters['format'],
				QueryProcessor::SPECIAL_PAGE
			);

			$printer->setShowErrors( false );
			$hidequery = $this->getRequest()->getVal( 'eq' ) == 'no';
			$request_type = $this->getRequest()->getVal( 'request_type', '' );

			if ( !$printer->isExportFormat() ) {
				if ( $request_type !== '' ) {
					$this->getOutput()->disable();
					$query_result = '';

					if ( $res->getCount() > 0 ) {
						$query_result = $printer->getResult( $res, $this->params, SMW_OUTPUT_HTML );
					} elseif ( $res->getCountValue() > 0 ) {
						$query_result = $res->getCountValue();
					}

					// Don't send an ID for a raw type but for all others add one
					// so that the `RemoteRequest` can respond appropriately and
					// filter those back-ends that don't send a clean output.
					if ( $request_type !== 'raw' ) {
						$query_result .= RemoteRequest::REQUEST_ID;
					}

					return print $query_result;
				} elseif ( ( $res instanceof QueryResult && $res->getCount() > 0 ) || $res instanceof StringResult ) {
					if ( $this->isEditMode ) {
						$urlArgs->set( 'eq', 'yes' );
					} elseif ( $hidequery ) {
						$urlArgs->set( 'eq', 'no' );
					}

					$query_result = $printer->getResult( $res, $this->params, SMW_OUTPUT_HTML );
					$result .= is_string( $debug ) ? $debug : '';

					if ( is_array( $query_result ) ) {
						$result .= $query_result[0];
					} else {
						$result .= $query_result;
					}
				} else {
					$result = ErrorWidget::noResult();
					$result .= is_string( $debug ) ? $debug : '';
				}
			}

			if ( $this->getRequest()->getVal( 'score_set' ) === 'show' && ( $scoreSet = $res->getScoreSet() ) !== null ) {
				$table = $scoreSet->asTable( 'sortable wikitable smwtable-striped broadtable' );

				if ( $table !== '' ) {
					$result .= '<h2>Score set</h2>' . $table;
				};
			}
		}

		if ( isset( $printer ) && $printer->isExportFormat() ) {

			// Avoid a possible "Cannot modify header information - headers already sent by ..."
			if ( defined( 'MW_PHPUNIT_TEST' ) && method_exists( $printer, 'disableHttpHeader' ) ) {
				$printer->disableHttpHeader();
			}

			$this->getOutput()->disable();
			$request_type = $this->getRequest()->getVal( 'request_type' );

			if ( $request_type === 'embed' ) {
				// Just send a furthers link output for an embedded remote request
				echo $printer->getResult( $res, $this->params, SMW_OUTPUT_HTML ) . RemoteRequest::REQUEST_ID;
			} elseif ( $request_type === 'special_page' ) {
				// Generate raw content when being requested from a remote special_page
				echo $printer->getResult( $res, $this->params, SMW_OUTPUT_FILE ) . RemoteRequest::REQUEST_ID;
			} else {
				return $printer->outputAsFile( $res, $this->params );
			}
		}

		if ( $this->queryString ) {
			$this->getOutput()->setHTMLtitle( $this->queryString );
		} else {
			$this->getOutput()->setHTMLtitle( wfMessage( 'ask' )->text() );
		}

		$urlArgs->set( 'offset', $this->parameters['offset'] );
		$urlArgs->set( 'limit', $this->parameters['limit'] );
		$urlArgs->set( 'eq', $this->isEditMode ? 'yes' : 'no' );

		$result = Html::rawElement(
			'div',
			[
				'id' => 'result',
				'class' => 'smw-ask-result' . ( $this->isBorrowedMode ? ' is-disabled' : '' )
			],
			$result
		);

		if ( $res instanceof QueryResult ) {
			$navigation = NavigationLinksWidget::navigationLinks(
				SpecialPage::getSafeTitleFor( 'Ask' ),
				$urlArgs,
				$res->getCount(),
				$res->hasFurtherResults()
			);

			$isFromCache = $res->isFromCache();
			$queryLink = QueryLinker::get( $queryobj, $this->parameters );
			$error = ErrorWidget::queryError( $queryobj );
		}

		$infoText = $this->getInfoText(
			$duration,
			$isFromCache
		);

		$form = $this->buildForm(
			$urlArgs,
			$queryLink,
			$navigation,
			$infoText,
			$isFromCache
		);

		// The overall form is "soft-disabled" so that when JS is fully
		// loaded, the ask module will remove this class and releases the form
		// for input
		$html = Html::rawElement(
			'div',
			[
				'id' => 'ask',
				"class" => ( $this->isBorrowedMode ? '' : 'is-disabled' )
			],
			$form . $error . $result
		);

		$this->getOutput()->addHTML(
			$html
		);
	}

	private function getInfoText( $duration, $isFromCache = false ) {

		$infoText = '';
		$source = null;

		if ( isset( $this->parameters['source'] ) ) {
			$source = $this->parameters['source'];
		}

		if ( $this->getRequest()->getVal( 'q_engine' ) === 'sql_store' ) {
			$source = 'sql_store';
		}

		$querySource = $this->querySourceFactory->getAsString(
			$source
		);

		if ( $duration > 0 ) {
			$infoText = wfMessage( 'smw-ask-query-search-info', $this->queryString, $querySource, $isFromCache, $duration )->parse();
		}

		return $infoText;
	}

	/**
	 * Generates the Search Box UI
	 *
	 * @param string $printoutstring
	 * @param string $urltail
	 *
	 * @return string
	 */
	protected function buildForm( UrlArgs $urlArgs, Infolink $queryLink = null, $navigation = '', $infoText = '', $isFromCache = false ) {

		$html = '';
		$hideForm = false;

		$title = SpecialPage::getSafeTitleFor( 'Ask' );
		$urlArgs->set( 'eq', 'yes' );

		if ( $this->isEditMode ) {
			$html .= Html::hidden( 'title', $title->getPrefixedDBKey() );

			// Table for main query and printouts.
			$html .= Html::rawElement(
				'div',
				[
					'id' => 'query',
					'class' => 'smw-ask-query'
				],
				QueryInputWidget::table(
					$this->queryString,
					$urlArgs->get( 'po' )
				)
			);

			// Format selection
			$html .= Html::rawElement(
				'div',
				[
					'id' => 'format',
					'class' => "smw-ask-format"
				],
				''
			);

			// Other options fieldset
			$html .= Html::rawElement(
				'div',
				[
					'id' => 'options',
					'class' => 'smw-ask-options'
				],
				ParametersWidget::fieldset(
					$title,
					$this->parameters
				)
			);

			$urlArgs->set( 'eq', 'no' );
			$hideForm = true;
		}

		$isEmpty = $queryLink === null;

		// Submit
		$links = LinksWidget::resultSubmitLink(
			$hideForm
		) . LinksWidget::showHideLink(
			$title,
			$urlArgs,
			$hideForm,
			$isEmpty
		) .	LinksWidget::clipboardLink(
			$queryLink
		);

		if ( !isset( $this->parameters['source'] ) || $this->parameters['source'] === '' ) {
			$links .= LinksWidget::debugLink( $title, $urlArgs, $isEmpty );
			$links .= LinksWidget::noQCacheLink( $title, $urlArgs, $isFromCache );
		}

		$links .= LinksWidget::embeddedCodeLink(
			$isEmpty
		) . LinksWidget::embeddedCodeBlock(
			$this->getQueryAsCodeString()
		);

		$links .= '<p></p>';

		$this->applyFinalOutputChanges(
			$links,
			$infoText
		);

		$links .= NavigationLinksWidget::wrap(
			$navigation,
			$infoText,
			$queryLink
		);

		$html .= Html::rawElement(
			'div',
			[
				'id' => 'search',
				'class' => 'smw-ask-search plainlinks'
			],
			LinksWidget::fieldset( $links )
		);

		return Html::rawElement(
			'form',
			[
				'action' => $GLOBALS['wgScript'],
				'name' => 'ask',
				'method' => 'get'
			],
			$html
		);
	}

	private function getQueryAsCodeString() {

		$code = $this->queryString ? htmlspecialchars( $this->queryString ) . "\n" : "\n";

		foreach ( $this->printouts as $printout ) {
			$serialization = $printout->getSerialisation( true );
			$mainlabel = isset( $this->parameters['mainlabel'] ) ? '?=' . $this->parameters['mainlabel'] . '#' : '';

			if ( $serialization !== '?#' && $serialization !== $mainlabel ) {
				$code .= ' |' . $serialization . "\n";
			}
		}

		foreach ( $this->params as $param ) {

			if ( !isset( $this->parameters[$param->getName()] ) ) {
				continue;
			}

			if ( !$param->wasSetToDefault() ) {
				$code .= ' |' . htmlspecialchars( $param->getName() ) . '=';
				$code .= htmlspecialchars( $this->parameters[$param->getName()] ) . "\n";
			}
		}

		return '{{#ask: ' . $code . '}}';
	}

	private function applyFinalOutputChanges( &$html, &$searchInfoText ) {

		if ( !$this->isBorrowedMode ) {
			return;
		}

		$borrowedMessage = $this->getRequest()->getVal( 'bMsg' );

		if ( isset( $this->parameters['bmsg'] ) ) {
			$borrowedMessage = $this->parameters['bmsg'];
		}

		$searchInfoText = '';

		if ( $borrowedMessage !== null && wfMessage( $borrowedMessage )->exists() ) {
			$html = wfMessage( $borrowedMessage, $this->queryString )->parse();
		}

		$borrowedTitle = $this->getRequest()->getVal( 'bTitle' );

		if ( isset( $this->parameters['btitle'] ) ) {
			$borrowedTitle = $this->parameters['btitle'];
		}

		if ( $borrowedTitle !== null && wfMessage( $borrowedTitle )->exists() ) {
			$this->getOutput()->setPageTitle( wfMessage( $borrowedTitle )->text() );
		}
	}

	private function newUrlArgs() {

		$urlArgs = new UrlArgs();

		// build parameter strings for URLs, based on current settings
		$urlArgs->set( 'q', $this->queryString );

		$tmp_parray = array();

		foreach ( $this->parameters as $key => $value ) {
			if ( !in_array( $key, array( 'sort', 'order', 'limit', 'offset', 'title' ) ) ) {
				$tmp_parray[$key] = $value;
			}
		}

		$urlArgs->set( 'p', Infolink::encodeParameters( $tmp_parray ) );
		$printoutstring = '';

		/**
		 * @var PrintRequest $printout
		 */
		foreach ( $this->printouts as $printout ) {
			$printoutstring .= $printout->getSerialisation( true ) . "\n";
		}

		if ( $printoutstring !== '' ) {
			$urlArgs->set( 'po', $printoutstring );
		}

		if ( array_key_exists( 'sort', $this->parameters ) ) {
			$urlArgs->set( 'sort', $this->parameters['sort'] );
		}

		if ( array_key_exists( 'order', $this->parameters ) ) {
			$urlArgs->set( 'order', $this->parameters['order'] );
		}

		if ( $this->getRequest()->getCheck( 'bTitle' ) ) {
			$urlArgs->set( 'bTitle', $this->getRequest()->getVal( 'bTitle' ) );
			$urlArgs->set( 'bMsg', $this->getRequest()->getVal( 'bMsg' ) );
		}

		if ( isset( $this->parameters['btitle'] ) ) {
			$urlArgs->set( 'bTitle', $this->parameters['btitle'] );
			$urlArgs->set( 'bMsg', $this->parameters['bmsg'] );
		}

		return $urlArgs;
	}

	private function getQueryResult() {

		$res = null;
		$debug = '';
		$duration = 0;
		$queryobj = null;

		// FIXME: this is a hack
		QueryProcessor::addThisPrintout( $this->printouts, $this->parameters );

		$params = QueryProcessor::getProcessedParams(
			$this->parameters,
			$this->printouts
		);

		$this->parameters['format'] = $params['format']->getValue();
		$this->params = $params;

		$queryobj = QueryProcessor::createQuery(
			$this->queryString,
			$params,
			QueryProcessor::SPECIAL_PAGE,
			$this->parameters['format'],
			$this->printouts
		);

		if ( $this->getRequest()->getVal( 'cache' ) === 'no' ) {
			$queryobj->setOption( SMWQuery::NO_CACHE, true );
		}

		$queryobj->setOption( SMWQuery::PROC_CONTEXT, 'SpecialAsk' );
		$source = $params['source']->getValue();
		$noSource = $source === '';

		if ( $this->getRequest()->getVal( 'q_engine' ) === 'sql_store' ) {
			$source = 'sql_store';
		}

		$qp = [];

		foreach ( $params as $key => $value) {
			$qp[$key] = $value->getValue();
		}

		$queryobj->setOption( 'query.params', $qp );

		/**
		 * @var QueryEngine $queryEngine
		 */
		$queryEngine = $this->querySourceFactory->get(
			$source
		);

		// Measure explicit to account for a federated (sourced) query
		$duration = microtime( true );

		/**
		 * @var QueryResult $res
		 */
		$res = $queryEngine->getQueryResult(
			$queryobj
		);

		$duration = number_format( ( microtime( true ) - $duration ), 4, '.', '' );

		// Allow to generate a debug output
		if ( $this->getRequest()->getVal( 'debug' ) && $noSource ) {

			$queryobj = QueryProcessor::createQuery(
				$this->queryString,
				$params,
				QueryProcessor::SPECIAL_PAGE,
				'debug',
				$this->printouts
			);

			$debug = $queryEngine->getQueryResult( $queryobj );
		}

		return [ $res, $debug, $duration, $queryobj ];
	}


}
