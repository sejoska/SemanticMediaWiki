<?php

namespace SMW\Elastic\Indexer;

use SMW\ApplicationFactory;
use SMW\MediaWiki\Jobs\JobBase;
use SMW\Elastic\ElasticFactory;
use SMW\Elastic\Connection\Client as ElasticClient;
use SMW\SQLStore\ChangeOp\ChangeDiff;
use SMW\DIWikiPage;
use Title;

/**
 * @license GNU GPL v2+
 * @since 3.0
 *
 * @author mwjames
 */
class IndexerRecoveryJob extends JobBase {

	/**
	 * @since 3.0
	 *
	 * @param Title $title
	 * @param array $params job parameters
	 */
	public function __construct( Title $title, $params = array() ) {
		parent::__construct( 'smw.elasticIndexerRecovery', $title, $params );
		$this->removeDuplicates = true;
	}

	/**
	 * @see Job::run
	 *
	 * @since  3.0
	 */
	public function allowRetries() {
		return false;
	}

	/**
	 * @see Job::run
	 *
	 * @since  3.0
	 */
	public function run() {

		$applicationFactory = ApplicationFactory::getInstance();
		$store = $applicationFactory->getStore( '\SMW\SQLStore\SQLStore' );

		$connection = $store->getConnection( 'elastic' );

		// Make sure a node is available
		if ( $connection->hasLock( ElasticClient::TYPE_DATA ) || !$connection->ping() ) {

			if ( $connection->hasLock( ElasticClient::TYPE_DATA ) ) {
				$this->params['retryCount'] = 0;
			}

			return $this->requeueRetry( $connection->getConfig() );
		}

		$elasticFactory = new ElasticFactory();

		$this->indexer = $elasticFactory->newIndexer(
			$store
		);

		$this->indexer->setOrigin( __METHOD__ );

		$this->indexer->setLogger(
			$applicationFactory->getMediaWikiLogger( 'smw-elastic' )
		);

		if ( $this->hasParameter( 'delete' ) ) {
			$this->delete( $this->getParameter( 'delete' ) );
		}

		if ( $this->hasParameter( 'create' ) ) {
			$this->create( $this->getParameter( 'create' ) );
		}

		if ( $this->hasParameter( 'index' ) ) {
			$this->index(
				$applicationFactory->getCache(),
				$this->getParameter( 'index' )
			);
		}

		return true;
	}

	private function requeueRetry( $config ) {

		// Give up!
		if ( $this->getParameter( 'retryCount' ) >= $config->dotGet( 'indexer.job.recovery.retries' ) ) {
			return true;
		}

		if ( !isset( $this->params['retryCount'] ) ) {
			$this->params['retryCount'] = 1;
		} else {
			$this->params['retryCount']++;
		}

		if ( !isset( $this->params['createdAt'] ) ) {
			$this->params['createdAt'] = time();
		}

		$job = new self( $this->title, $this->params );
		$job->setDelay( 60 * 10 );

		$job->insert();
	}

	private function delete( array $idList ) {
		$this->indexer->delete( $idList );
	}

	private function create( $hash ) {
		$this->indexer->create( DIWikiPage::doUnserialize( $hash ) );
	}

	private function index( $cache, $hash ) {

		$changeDiff = ChangeDiff::fetch(
			$cache,
			DIWikiPage::doUnserialize( $hash )
		);

		if ( $changeDiff !== false ) {
			$this->indexer->index( $changeDiff );
		}
	}

}
