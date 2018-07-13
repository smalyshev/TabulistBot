<?php

define( 'DB_NAME', "tabulist" );
define( 'DATA_TALK_NS', 487 );
// DB name - with underscore!
define( 'TEMPLATE', 'Wikidata_tabular' );

function updateCommonsPagesList( $wiki ) {
	$ts = date( 'YmdHis', time() );
	$tool_db = ToolsDb::getLocal( DB_NAME );

	$sql = "UPDATE pagestatus SET `status`='CHECKING' WHERE wiki='$wiki' AND page LIKE 'Data_talk:%'";
	$tool_db->query( $sql );

	$replica = ToolsDb::getReplica( $wiki );

	$sql = "select page.* from page,templatelinks t1
            where page_id=t1.tl_from and t1.tl_title=:title AND t1.tl_namespace=10 
              AND page.page_namespace = :ns";

	$result = $replica->query( $sql, ['title' => TEMPLATE, 'ns' => DATA_TALK_NS] );
	foreach ( $result as $row ) {
		if ( $row->page_namespace !== DATA_TALK_NS ) continue;
		$page = 'Data_talk:' . $row->page_title;
		$sql = "INSERT INTO pagestatus (wiki,page,status,message,timestamp) 
				VALUES (:wiki,:page,'WAITING','',:ts)
				ON DUPLICATE KEY UPDATE status='WAITING',message='',timestamp=:ts";

		$tool_db->query( $sql, ['wiki' => $wiki, 'page' => $page, 'ts' => $ts] );
	}
}