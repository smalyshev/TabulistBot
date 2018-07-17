<?php

use GetOpt\GetOpt;

require_once __DIR__ . '/vendor/autoload.php';

define( 'DATA_TALK_NS', 487 );
// DB name - with underscore!
define( 'TEMPLATE', 'Wikidata_tabular' );
define( 'SPARQL_ENDPOINT', 'https://query.wikidata.org/sparql' );

class Tabulist
{

	const DB_NAME = "tabulist";
	private $wiki;
	private $verbose = false;

	private static $servers = [
		'commonswiki' => 'commons.wikimedia.org'
	];
	/**
	 * @var PageHandler
	 */
	private $handler;

	public function __construct( $wiki ) {
		$this->wiki = $wiki;
		if ( !isset( self::$servers[$wiki] ) ) {
			throw new InvalidArgumentException( "Unknown wiki $wiki" );
		}
		$this->handler = new PageHandler( self::$servers[$wiki], SPARQL_ENDPOINT );
	}

	public function updateCommonsPagesList() {
		$ts = date( 'YmdHis' );
		$tool_db = ToolsDb::getLocal( self::DB_NAME );

		$sql = "UPDATE pagestatus SET `status`='CHECKING' WHERE wiki=:wiki AND page LIKE 'Data_talk:%'";
		$tool_db->query( $sql, ['wiki' => $this->wiki] );

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

			$tool_db->query( $sql, ['wiki' => $this->wiki, 'page' => $page, 'ts' => $ts] );
		}

		$tool_db->query( "DELETE FROM pagestatus WHERE `status`='CHECKING' AND wiki=:wiki",
			['wiki' => $this->wiki] );
	}

	public function updatePage( $page ) {
		$tool_db = ToolsDb::getLocal( self::DB_NAME );
		$ts = date( 'YmdHis' );

		$tool_db->query( "UPDATE pagestatus SET `status`='RUNNING',`message`='',timestamp=:ts WHERE wiki=:wiki and page=:page",
			['ts' => $ts, 'wiki' => $this->wiki, 'page' => $page] );

		try {
			$this->handler->login( __DIR__ . "/tabulist.ini" );
//	$handler->debugMode( true );
			if ( !$handler->updateTemplateData( $page, TEMPLATE ) ) {
				$message = implode( "\n", $handler->getErrors() );
				$status = "FAILED";
			} else {
				$message = $handler->getStatus();
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
		$tool_db->query( "UPDATE pagestatus SET `status`=:status,`message`=:msg,timestamp=:ts WHERE wiki=:wiki and page=:page",
			['ts' => $ts, 'wiki' => $this->wiki, 'page' => $page, 'msg' => $message, "status" => $status] );
	}

	public function listPages() {
		$tool_db = ToolsDb::getLocal( self::DB_NAME );
		$sql = "SELECT id,status,page FROM pagestatus WHERE wiki=:wiki ORDER BY id ASC";
		$result = $tool_db->query( $sql, ['wiki' => $this->wiki] );
		foreach ( $result as $row ) {
			print "{$row->id}\t{$row->status}\t{$row->page}\n";
		}
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

if ( $getopt->getOption( 'p' ) ) {
	$tabulist->updatePage( "Data_talk:Sandbox/Smalyshev/test.tab" );
}
