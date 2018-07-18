<?php

if ( !empty( $_GET['update'] ) ) {
	require __DIR__ . '/Tabulist.php';
	$tabulist = new Tabulist( 'commonswiki' );
	$tabulist->setVerbose( true );
	$tabulist->updatePage( $tabulist->getPageByTitle( $_GET['update'] ) );
	exit(0);
}

echo file_get_contents( __DIR__ . '/index.html' );