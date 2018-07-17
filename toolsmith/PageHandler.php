<?php

use Asparagus\QueryExecuter;
use Mediawiki\Api\ApiUser;
use Mediawiki\Api\MediawikiApi;
use Mediawiki\Api\MediawikiFactory;
use Mediawiki\DataModel\Content;
use Mediawiki\DataModel\EditInfo;
use Mediawiki\DataModel\Revision;

class PageHandler
{

	/**
	 * @var MediawikiApi
	 */
	private $api;
	/**
	 * @var MediawikiFactory
	 */
	private $services;
	/**
	 * @var string[]
	 */
	private $errors = [];
	/**
	 * @var string
	 */
	private $status;
	/**
	 * @var QueryExecuter
	 */
	private $sparql;
	/**
	 * @var TermFetcher
	 */
	private $terms;
	/**
	 * @var boolean
	 */
	private $debugMode;

	public function __construct( $server, $sparql ) {
		$this->api = new MediawikiApi( 'https://' . $server . '/w/api.php' );
		$this->services = new MediawikiFactory( $this->api );
		$this->sparql = new QueryExecuter( $sparql );
		$this->terms = new TermFetcher();
	}

	public function getPageSource( $title ) {
		$pageHandle = $this->services->newPageGetter()->getFromTitle( $title );
		$pageRevision = $pageHandle->getRevisions()->getLatest();
		if ( !$pageRevision || !is_object( $pageRevision ) ) {
			$this->error( "Could not load page" );
			return false;
		}
		return $pageRevision->getContent()->getData();
	}

	/**
	 * Log in with credentials in file.
	 * @param $creds_file
	 * @throws \Mediawiki\Api\UsageException
	 */
	public function login( $creds_file ) {
		if ( !file_exists( $creds_file ) ) {
			throw new \Mediawiki\Api\UsageException( "Login creds file not found" );
		}
		$creds = parse_ini_file( $creds_file );
		$this->api->login( new ApiUser( $creds['user'], $creds['pass'] ) );
	}

	private static $knownFields = [
		'item'        => 'item',
		'label'       => 'label',
		'description' => 'description',
		'alias'       => 'alias',
		'qid'         => 'item'
	];

	private function parseField( $field, array &$fields ) {
		if ( strpos( $field, ':' ) === false ) {
			$type = "string";
		} else {
			list( $field, $type ) = explode( ':', $field, 2 );
		}
		if ( $field[0] === '?' ) {
			$fields[substr($field, 1)] = $type;
		} else if ( isset( self::$knownFields[$field] ) ) {
			$fields[$field] = self::$knownFields[$field];
		} else {
			return $this->error( "Unknown field: [$field]" );
		}
		return true;
	}

	/**
	 * @param $text
	 * @param $template
	 * @return DataTemplate|false
	 */
	private function extractTemplate( $text, $template ) {
		$template = str_replace( "_", "[ ]", $template );
		$regex = <<<"TEMPLATE"
/{{(?:$template)\s*
	(
	(?:\s+[|]\w+=[^|]+\s*)+
	)
}}/x
TEMPLATE;
		if ( !preg_match( $regex, $text, $m ) ) {
			return $this->error( "Did not find template" );
		}
		$params = explode( "|", trim( $m[1], "| \n\r\t" ) );
		foreach ( $params as $param ) {
			list( $name, $value ) = explode( "=", $param, 2 );
			$templateData[$name] = $value;
		}
		if ( empty( $templateData['sparql'] ) ) {
			return $this->error( "Template does not have SPARQL" );
		}

		$fields = [];
		if ( empty( $templateData['columns'] ) ) {
			$fields = [
				'item'  => 'item',
				'label' => 'label'
			];
		} else {
			foreach ( explode( ',', $templateData['columns'] ) as $column ) {
				if(!$this->parseField($column, $fields)) {
					return false;
				}
			}
		}

		if ( empty( $fields ) ) {
			return $this->error( "No fields specified" );
		}

		return new DataTemplate( $templateData['sparql'], $fields );
	}

	public function updateTemplateData( $title, $template ) {
		$text = $this->getPageSource( $title );
		$template = $this->extractTemplate( $text, $template );
		if ( !$template ) {
			return $this->error( "Could not find template data" );
		}

		$mainTitle = preg_replace( '/^Data_talk:/', 'Data:', $title );

		$tabdata = $this->getPageSource( $mainTitle );
		$parsedData = @json_decode( $tabdata, true );
		if ( !$parsedData ) {
			return $this->error( "Could not parse tabular template" );
		}

		$newData = $this->getTabularData( $template );

		if ( !isset( $this->force_update ) && $newData == $parsedData['data'] ) {
			$this->status = 'no change';
			return true;
		}
		$parsedData['data'] = $newData;
		$parsedData['sources'] = "This data set is generated by a bot, please see the [[$title|Talk Page]].";
		$content = json_encode( $parsedData );

		$this->status = 'changed, now ' . count( $newData ) . " items";

		$summary = "Wikidata list updated";

		if ( !$this->savePage( $mainTitle, $content, $summary ) ) {
			return $this->error( "Could not save page" );
		}

		return true;
	}

	/**
	 * Save data to the page
	 * @param string $title
	 * @param string $content
	 * @param string $summary
	 * @return bool|false
	 */
	protected function savePage( $title, $content, $summary ) {
		if ( $this->debugMode ) {
			print "$title($summary): $content\n";
			return true;
		}
		$pageHandle = $this->services->newPageGetter()->getFromTitle( $title );
		$pageRevision = $pageHandle->getRevisions()->getLatest();
		if ( !$pageRevision || !is_object( $pageRevision ) ) {
			return $this->error( "Page disappeared" );
		}
		$editInfo = new EditInfo ( $summary, EditInfo::NOTMINOR, EditInfo::BOT );
		$contentObject = new Content ( $content );
		$revision = new Revision ( $contentObject, $pageRevision->getPageIdentifier(), null, $editInfo );

		try {
			$this->services->newRevisionSaver()->save( $revision, $editInfo );
		} catch ( Exception $e ) {
			return $this->error( $e->getMessage() );
		}
		return true;
	}

	protected function parseTabularHeader( array $data, DataTemplate $template ) {
		if ( empty( $data['schema']['fields'] ) ) {
			return false;
		}

		return $data['schema']['fields'];
	}

	/**
	 * Report an error.
	 * @param string $msg
	 * @return false
	 */
	protected function error( $msg ) {
		$this->errors[] = $msg;
		return false;
	}

	public function getErrors() {
		return $this->errors;
	}

	public function getStatus() {
		return $this->status;
	}

	protected function trimID( $uri ) {
		// http://www.wikidata.org/entity/
		return substr( $uri, strlen( 'http://www.wikidata.org/entity/' ) );
	}

	protected function getTabularData( DataTemplate $template ) {
		$queryResults = $this->runQuery( $template->getSPARQL() );
		if ( !$queryResults ) {
			$this->error( "Query failed" );
			return null;
		}
		$items = [];
		$output = array_map(
			function ( $resultRow ) use ( $template, &$items ) {
				$outputRow = [];
				foreach ( $resultRow as $name => $value ) {
					$outputRow[$name] = $template->formatField( $name, $value );
					if ( $name === 'item' ) {
						$items[$outputRow['item']] = true;
					}
				}
				return $outputRow;
			}, $queryResults );
		// Process weird fields like label, description, alias
		$itemIDs = array_keys( $items );
		foreach ( ['label', 'description', 'alias'] as $type ) {
			if ( $template->hasField( $type ) ) {
				$labels = $this->terms->fetchTerm( $itemIDs, $type );
				foreach ( $output as $n => &$row ) {
					if ( $row['item'] && isset( $labels[$row['item']] ) ) {
						$row[$type] = $labels[$row['item']];
					}
				}
			}
		}
		// Reassemble data in correct order
		return array_map( function ( $row ) use ( $template ) {
			return $template->arrangeRows( $row );
		}, $output );
	}

	protected function runQuery( $sparql ) {
		$result = $this->sparql->execute( $sparql );
		if ( !$result ) {
			return false;
		}
		return array_map( function ( $row ) {
			$res = [];
			foreach ( $row as $n => $v ) {
				$res[$n] = $v['value'];
			}
			return $res;
		}, $result['bindings'] );
	}

	public function debugMode( $debug ) {
		$this->debugMode = $debug;
	}
}