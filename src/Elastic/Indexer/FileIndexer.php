<?php

namespace SMW\Elastic\Indexer;

use Psr\Log\LoggerAwareTrait;
use SMW\DIWikiPage;
use SMW\DIProperty;
use SMW\Store;
use SMW\Elastic\QueryEngine\FieldMapper;
use SMW\Elastic\Connection\Client as ElasticClient;
use Onoi\MessageReporter\MessageReporterAwareTrait;
use SMWContainerSemanticData as ContainerSemanticData;
use SMW\ApplicationFactory;
use File;
use Title;
use RuntimeException;

/**
 * Experimental file indexer that uses the ES ingest pipeline to ingest and retrieve
 * data from an attachment and make file content searchable outside of a normal
 * wiki content.
 *
 * @license GNU GPL v2+
 * @since 3.0
 *
 * @author mwjames
 */
class FileIndexer {

	use MessageReporterAwareTrait;
	use LoggerAwareTrait;

	/**
	 * @var Indexer
	 */
	private $indexer;

	/**
	 * @var string
	 */
	private $origin = '';

	/**
	 * @var boolean
	 */
	private $sha1Check = true;

	/**
	 * @since 3.0
	 *
	 * @param Indexer $indexer
	 */
	public function __construct( Indexer $indexer ) {
		$this->indexer = $indexer;
	}

	/**
	 * @since 3.0
	 *
	 * @param string $origin
	 */
	public function setOrigin( $origin ) {
		$this->origin = $origin;
	}

	/**
	 * @since 3.0
	 */
	public function noSha1Check() {
		$this->sha1Check = false;
	}

	/**
	 * @since 3.0
	 *
	 * @param File|null $file
	 */
	public function planIngestJob( Title $title ) {

		$fileIngestJob = new FileIngestJob(
			$title
		);

		$fileIngestJob->lazyPush();
	}

	/**
	 * The ES ingest pipeline only does create (not update) index content which
	 * means any other content is deleted after the ingest process has finished
	 * therefore:
	 *
	 * - Read the document before, and retrieve any annotations that exists for
	 * that entity
	 * - Let ES ingest the file content and attach the earlier retrieved
	 * annotations
	 * - SMW doesn't know anything about the file attachment details ES has gather
	 * from the the file hence update the SQLStore (!important not the
	 * ElasticStore) with the data
	 * - After the SQLStore update make sure that those attachment details (which
	 * are represented as subobject) are added to ES manually (means not through
	 * the standard Store::updateData to avoid an update circle) otherwise there
	 * will be invisible the any SMW user
	 *
	 * @since 3.0
	 *
	 * @param DIWikiPage $dataItem
	 * @param File|null $file
	 */
	public function index( DIWikiPage $dataItem, File $file = null ) {

		if ( $dataItem->getId() == 0 ) {
			$dataItem->setId( $this->indexer->getId( $dataItem ) );
		}

		if ( $dataItem->getId() == 0 || $dataItem->getNamespace() !== NS_FILE || $dataItem->getSubobjectName() !== '' ) {
			return;
		}

		$time = -microtime( true );

		$params = [
			'id' => 'attachment',
			'body' => [
				'description' => 'Extract attachment information',
				'processors' => [
					[
						'attachment' => [
							'field' => 'file_content',
							'indexed_chars' => -1
						]
					],
					[
						'remove' => [
							"field" => "file_content"
						]
					]
				]
			],
		];

		$connection = $this->indexer->getConnection();
		$connection->ingest()->putPipeline( $params );

		if ( $file === null ) {
			$file = wfFindFile( $dataItem->getTitle() );
		}

		if ( $file === false || $file === null ) {
			return;
		}

		$url = $file->getFullURL();
		$id = $dataItem->getId();

		$sha1 = $file->getSha1();
		$ingest = true;

		$index = $this->indexer->getIndexName( ElasticClient::TYPE_DATA );
		$doc = [ '_source' => [] ];

		$params = [
			'index' => $index,
			'type'  => ElasticClient::TYPE_DATA,
			'id'    => $id,
		];

		// Do we have any existing data? The ingest pipeline will override the
		// entire document, so rescue any data before starting the ingest.
		if ( $connection->exists( $params ) ) {
			$doc = $connection->get( $params + [ '_source_include' => [ 'file_sha1', 'subject', 'text_raw', 'text_copy', 'P*' ] ] );
		}

		// Is the sha1 the same? Don't do anything since the content is expected
		// to be the same!
		if ( $this->sha1Check && isset( $doc['_source']['file_sha1'] ) && $doc['_source']['file_sha1'] === $sha1 ) {
			$ingest = false;
		}

		$context = [
			'method' => __METHOD__,
			'role' => 'production',
			'origin' => $this->origin,
			'subject' => $dataItem->getHash()
		];

		if ( $ingest === false ) {

			$msg = [
				'File indexer',
				'Skipping the ingest process',
				'Found identical file_sha1 ({subject})'
			];

			return $this->logger->info( $msg, $context );
		}

		$params += [
			'pipeline' => 'attachment',
			'body' => [
				'file_content' => base64_encode( file_get_contents( $url ) ),
				'file_path' => $url,
				'file_sha1' => $sha1,
			] + $doc['_source']
		];

		$context['response'] = $connection->index( $params );
		$context['procTime'] = microtime( true ) + $time;

		$msg = [
			'File indexer',
			'Ingest process completed ({subject})',
			'procTime (in sec): {procTime}',
			'Response: {response}'
		];

		$this->logger->info( $msg, $context );

		// Don't use the ElasticStore otherwise we index the added fields once more
		// and hereby remove the content from the attachment! and start a circle
		// since the annotation update can only happen after the information is
		// retrieved from ES.
		$this->addAnnotation(
			ApplicationFactory::getInstance()->getStore( '\SMW\SQLStore\SQLStore' ),
			$dataItem
		);
	}

	/**
	 * @since 3.0
	 *
	 * @param Store $store
	 * @param DIWikiPage $dataItem
	 */
	public function addAnnotation( Store $store, DIWikiPage $dataItem ) {

		$time = -microtime( true );

		if ( $dataItem->getId() == 0 ) {
			$dataItem->setId( $this->indexer->getId( $dataItem ) );
		}

		if ( $dataItem->getId() == 0 ) {
			throw new RuntimException( "Missing ID: " . $dataItem );
		}

		$context = [
			'method' => __METHOD__,
			'role' => 'production',
			'origin' => $this->origin,
			'subject' => $dataItem->getHash()
		];

		$semanticData = $store->getSemanticData( $dataItem );
		$connection = $this->indexer->getConnection();

		$index = $this->indexer->getIndexName( ElasticClient::TYPE_DATA );
		$doc = [ '_source' => [] ];

		$params = [
			'index' => $index,
			'type'  => ElasticClient::TYPE_DATA,
			'id'    => $dataItem->getId(),
		];

		if ( !$connection->exists( $params ) ) {

			$msg = [
				'File indexer',
				'Abort annotation update',
				'Missing {id} document!'
			];

			return $this->logger->info( $msg, $context + [ 'id' => $dataItem->getId() ] );
		}

		$params = $params + [
			'_source_include' => [
				'file_sha1',
				'attachment.date',
				'attachment.content_type',
				'attachment.author',
				'attachment.language',
				'attachment.title',
				'attachment.content_length'
			]
		];

		$doc = $connection->get( $params );

		if ( !isset( $doc['_source']['file_sha1'] ) ) {

			$msg = [
				'File indexer',
				'No annotation update',
				'Missing file_sha1!'
			];

			return $this->logger->info( $msg, $context );
		}

		$containerSemanticData = $this->newContainerSemanticData(
			$dataItem,
			$doc
		);

		$attachmentAnnotator = new AttachmentAnnotator(
			$containerSemanticData,
			$doc
		);

		$attachmentAnnotator->addAnnotation();
		$property = $attachmentAnnotator->getProperty();

		// Remove any existing `_FILE_ATTCH` in case it was a reupload with a different
		// content sha1
		$semanticData->removeProperty( $property );

		$semanticData->addPropertyObjectValue(
			$property,
			$attachmentAnnotator->getContainer()
		);

		$callableUpdate = ApplicationFactory::getInstance()->newDeferredTransactionalCallableUpdate( function() use( $store, $semanticData, $attachmentAnnotator ) {
			// Update the SQLStore with the annotated information which will NOT
			// trigger another ES index update BUT ...
			$store->updateData( $semanticData );

			// ... we need to replicate the container data (subobject) in order to
			// make them usable via query engine therefore ...
			$this->indexAttachmentInfo( $attachmentAnnotator );
		} );

		$callableUpdate->setOrigin( __METHOD__ );
		$callableUpdate->waitOnTransactionIdle();
		$callableUpdate->pushUpdate();

		$context['procTime'] = microtime( true ) + $time;

		$msg = [
			'File indexer',
			'Attachment annotation update completed ({subject})',
			'procTime (in sec): {procTime}'
		];

		$this->logger->info( $msg, $context );
	}

	/**
	 * Meta assignments from a file ingest need to be republished in a SMW conform
	 * manner so that property path `[[File attachment.Content title::..]]` work
	 * as expected.
	 *
	 * @since 3.0
	 *
	 * @param AttachmentAnnotator $attachmentAnnotator
	 */
	public function indexAttachmentInfo( AttachmentAnnotator $attachmentAnnotator ) {

		$data = [];
		$time = -microtime( true );

		$semanticData = $attachmentAnnotator->getSemanticData();
		$subject = $semanticData->getSubject();

		// Find base document ID
		$baseDocId = $this->indexer->getId( $subject->asBase() );

		if ( $baseDocId == 0 ) {
			throw new RuntimeException( "Missing ID: " . $subject );
		}

		$subject->setId( $this->indexer->getId( $subject ) );

		if ( $subject->getId() == 0 ) {
			throw new RuntimeException( "Missing ID: " . $subject );
		}

		$context = [
			'method' => __METHOD__,
			'role' => 'production',
			'origin' => $this->origin,
			'subject' => $subject->getHash()
		];

		foreach ( $semanticData->getProperties() as $property ) {

			$pid = $this->indexer->getId(
				$property->getCanonicalDiWikiPage()
			);

			$pid = FieldMapper::getPID( $pid );
			$data[$pid] = [];
			$field = FieldMapper::getField( $property );

			$data[$pid][$field] = [];

			foreach ( $semanticData->getPropertyValues( $property ) as $dataItem ) {
				$data[$pid][$field][] = $dataItem->getSortKey();
			}
		}

		$this->indexer->create( $subject, $data );

		// Attach the subobject to the base subject
		$response = $this->upsertDoc(
			$baseDocId,
			$subject,
			$attachmentAnnotator->getProperty()
		);

		$context['time'] = microtime( true ) + $time;
		$context['response'] = $response;

		$msg = [
			'File indexer',
			'Pushed attachment information to ES ({subject})',
			'procTime (in sec): {procTime}',
			'Response: {response}'
		];

		$this->logger->info( $msg, $context );
	}

	private function upsertDoc( $baseDocId, $subject, $property ) {

		$params = [
			'_index' => $this->indexer->getIndexName( ElasticClient::TYPE_DATA ),
			'_type'  => ElasticClient::TYPE_DATA
		];

		$bulk = $this->indexer->newBulk( $params );
		$data = [];

		$pid = $this->indexer->getId(
			$property->getCanonicalDiWikiPage()
		);

		$pid = FieldMapper::getPID( $pid );
		$data[$pid] = [];

		// It is the ID field we want not any type related field!
		$field = 'wpgID';

		$data[$pid][$field] = [];
		$data[$pid][$field][] = $subject->getId();

		// Upsert of the base document to link subject -> subobject otherwise
		// a property path like `File attachment.Content length`) is not going
		// to work
		$bulk->upsert( [ '_id' => $baseDocId ], $data );

		return $bulk->execute();
	}

	private function newContainerSemanticData( $dataItem, $doc ) {

		$subobjectName = '_FILE' . md5( $doc['_source']['file_sha1'] );

		$subject = new DIWikiPage(
			$dataItem->getDBkey(),
			$dataItem->getNamespace(),
			$dataItem->getInterwiki(),
			$subobjectName
		);

		return new ContainerSemanticData( $subject );
	}

}
