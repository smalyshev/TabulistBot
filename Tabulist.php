<?php

use GetOpt\GetOpt;

require_once __DIR__ . '/vendor/autoload.php';

define( 'DATA_TALK_NS', 487 );
// DB name - with underscore!
define( 'TEMPLATE', 'Wikidata_tabular' );

class Tabulist
{

	const DB_NAME = "tabulist";
	const SPARQL_ENDPOINT = 'https://query.wikidata.org/sparql';
	private $wiki;
	private $verbose = false;

	private static $servers = [
		'commonswiki' => 'commons.wikimedia.org'
	];
	/**
	 * @var PageHandler
	 */
	private $handler;
	/**
	 * @var ToolsDb
	 */
	private $tool_db;

	public function __construct( $wiki ) {
		$this->wiki = $wiki;
		if ( !isset( self::$servers[$wiki] ) ) {
			throw new InvalidArgumentException( "Unknown wiki $wiki" );
		}
		$this->handler = new PageHandler( self::$servers[$wiki], self::SPARQL_ENDPOINT );
		$this->tool_db = ToolsDb::getLocal( self::DB_NAME );
	}

	public function updateCommonsPagesList() {
		$ts = date( 'YmdHis' );

		$sql = "UPDATE pagestatus SET `status`='CHECKING' WHERE wiki=:wiki AND page LIKE 'Data_talk:%'";
		$this->tool_db->query( $sql, ['wiki' => $this->wiki] );

		$replica = ToolsDb::getReplica( $this->wiki );

		$sql = "select page.* from page,templatelinks t1
            where page_id=t1.tl_from and t1.tl_title=:title AND t1.tl_namespace=10 
              AND page.page_namespace = :ns";

		$result = $replica->query( $sql, ['title' => TEMPLATE, 'ns' => DATA_TALK_NS] );
		if ( $this->verbose ) {
			print "{$result->rowCount()} pages found.\n";
		}
		foreach ( $result as $row ) {
			if ( $row->page_namespace != DATA_TALK_NS ) {
				continue;
			}
			$page = 'Data_talk:' . $row->page_title;
			$sql = "INSERT INTO pagestatus (wiki,page,status,message,timestamp) 
				VALUES (:wiki,:page,'WAITING','',:ts)
				ON DUPLICATE KEY UPDATE status='WAITING',message='',timestamp=:ts";

			$this->tool_db->query( $sql, ['wiki' => $this->wiki, 'page' => $page, 'ts' => $ts] );
		}

		$this->tool_db->query( "DELETE FROM pagestatus WHERE `status`='CHECKING' AND wiki=:wiki",
			['wiki' => $this->wiki] );
	}

	public function getPage( $pageId ) {
		$sql = "SELECT * FROM pagestatus WHERE wiki=:wiki AND id=:id ORDER BY id ASC";
		$result = $this->tool_db->query( $sql, ['id' => $pageId, 'wiki' => $this->wiki] );
		foreach ( $result as $row ) {
			return $row;
		}
		return null;
	}

	public function updatePage( $pageId ) {
		$ts = date( 'YmdHis' );

		$pageData = $this->getPage( $pageId );
		if ( !$pageData ) {
			if ( $this->verbose ) {
				print "Page $pageId not found";
			}
			return false;
		}

		$this->tool_db->query( "UPDATE pagestatus SET `status`='RUNNING',`message`='',timestamp=:ts WHERE wiki=:wiki and id=:id",
			['ts' => $ts, 'wiki' => $this->wiki, 'id' => $pageId] );

		try {
			$this->handler->login( __DIR__ . "/tabulist.ini" );
//	$handler->debugMode( true );
			if ( !$this->handler->updateTemplateData( $pageData->page, TEMPLATE ) ) {
				$message = implode( "\n", $this->handler->getErrors() );
				$status = "FAILED";
			} else {
				$message = $this->handler->getStatus();
				$status = "OK";
			}
		} catch ( Exception $e ) {
			$status = "FAILED";
			$message = $e->getMessage();
		}

		if ( $this->verbose ) {
			print "$status: $message\n";
		}

		$ts = date( 'YmdHis' );
		$this->tool_db->query( "UPDATE pagestatus SET `status`=:status,`message`=:msg,timestamp=:ts WHERE wiki=:wiki and id=:id",
			['ts' => $ts, 'wiki' => $this->wiki, 'id' => $pageId, 'msg' => $message, "status" => $status] );
	}

	public function listPages() {
		$sql = "SELECT id,status,page FROM pagestatus WHERE wiki=:wiki ORDER BY id ASC";
		$result = $this->tool_db->query( $sql, ['wiki' => $this->wiki] );
		foreach ( $result as $row ) {
			print "{$row->id}\t{$row->status}\t{$row->page}\n";
		}
	}

	public function showPage( $pageId ) {
		$pageData = $this->getPage( $pageId );
		var_dump( $pageData );
	}

	/**
	 * @param bool $verbose
	 */
	public function setVerbose( $verbose ) {
		$this->verbose = $verbose;
	}
}

$getopt = new GetOpt( [
	['h', 'help', GetOpt::NO_ARGUMENT, "Usage instructions"],
	['u', 'update', GetOpt::NO_ARGUMENT, 'Update pages list'],
	['l', 'list', GetOpt::NO_ARGUMENT, 'Show pages list'],
	['s', 'show', GetOpt::REQUIRED_ARGUMENT, 'Show page data'],
	['p', 'page', GetOpt::REQUIRED_ARGUMENT, 'Update specific page'],
	['v', 'verbose', GetOpt::NO_ARGUMENT, "More verbose output"],
] );

$getopt->process();

if ( $getopt->count() == 0 || $getopt->getOption( 'h' ) ) {
	echo $getopt->getHelpText();
	exit( 0 );
}

$tabulist = new Tabulist( 'commonswiki' );
$tabulist->setVerbose( $getopt->getOption( 'v' ) );

if ( $getopt->getOption( 'u' ) ) {
	$tabulist->updateCommonsPagesList();
}

if ( $getopt->getOption( 'l' ) ) {
	$tabulist->listPages();
}

if ( $getopt->getOption( 's' ) ) {
	$tabulist->showPage( $getopt->getOption( 's' ) );
}

if ( $getopt->getOption( 'p' ) ) {
	$tabulist->updatePage( $getopt->getOption( 'p' ) );
}
