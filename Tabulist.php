<?php
use GetOpt\GetOpt;

require_once __DIR__ . '/vendor/autoload.php';

define( 'DB_NAME', "tabulist" );
define( 'DATA_TALK_NS', 487 );
// DB name - with underscore!
define( 'TEMPLATE', 'Wikidata_tabular' );
define( 'SPARQL_ENDPOINT', 'https://query.wikidata.org/sparql' );
define( 'DEBUG', true );

function updateCommonsPagesList( $wiki ) {
	$ts = date( 'YmdHis' );
	$tool_db = ToolsDb::getLocal( DB_NAME );

	$sql = "UPDATE pagestatus SET `status`='CHECKING' WHERE wiki='$wiki' AND page LIKE 'Data_talk:%'";
	$tool_db->query( $sql );

	$replica = ToolsDb::getReplica( $wiki );

	$sql = "select page.* from page,templatelinks t1
            where page_id=t1.tl_from and t1.tl_title=:title AND t1.tl_namespace=10 
              AND page.page_namespace = :ns";

	$result = $replica->query( $sql, ['title' => TEMPLATE, 'ns' => DATA_TALK_NS] );
	if ( $GLOBALS['VERBOSE'] ) {
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

		$tool_db->query( $sql, ['wiki' => $wiki, 'page' => $page, 'ts' => $ts] );
	}

	$tool_db->query( "DELETE FROM pagestatus WHERE `status`='CHECKING' AND wiki=:wiki", ['wiki' => $wiki] );
}

function updatePage( $wiki, $wikiServer, $page ) {
	$tool_db = ToolsDb::getLocal( DB_NAME );
	$ts = date( 'YmdHis' );

	$tool_db->query( "UPDATE pagestatus SET `status`='RUNNING',`message`='',timestamp=:ts WHERE wiki=:wiki and page=:page",
		['ts' => $ts, 'wiki' => $wiki, 'page' => $page] );

	$handler = new PageHandler( $wikiServer, SPARQL_ENDPOINT );
	if ( !$handler->updateTemplateData( $page, TEMPLATE ) ) {
		$message = implode( "\n", $handler->getErrors() );
		$status = "FAILED";
	} else {
		$message = $handler->getStatus();
		$status = "OK";
	}

	if ( $GLOBALS['VERBOSE'] ) {
		print "$status: $message\n";
	}

	$ts = date( 'YmdHis' );
	$tool_db->query( "UPDATE pagestatus SET `status`=:status,`message`=:msg,timestamp=:ts WHERE wiki=:wiki and page=:page",
		['ts' => $ts, 'wiki' => $wiki, 'page' => $page, 'msg' => $message, "status" => $status] );
}

$getopt = new GetOpt( [
	['h', 'help', GetOpt::NO_ARGUMENT, "Usage instructions"],
	['u', 'update', GetOpt::NO_ARGUMENT, 'Update pages list'],
	['p', 'page', GetOpt::REQUIRED_ARGUMENT, 'Update specific page'],
	['v', 'verbose', GetOpt::NO_ARGUMENT, "More verbose output"],
] );

$getopt->process();

if ( $getopt->count() == 0 || $getopt->getOption( 'h' ) ) {
	echo $getopt->getHelpText();
	exit( 0 );
}

if ( $getopt->getOption( 'v' ) ) {
	$VERBOSE = true;
}

if ( $getopt->getOption( 'u' ) ) {
	updateCommonsPagesList( 'commonswiki' );
}

if ( $getopt->getOption( 'p' ) ) {
	updatePage( "commonswiki", "commons.wikimedia.org", "Data_talk:Sandbox/Smalyshev/test.tab" );
}
