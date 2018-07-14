<?php

/**
 * Fetch terms for a set of items.
 */
class TermFetcher
{
	const CHUNK_SIZE = 50;
	/**
	 * @var ToolsDb
	 */
	private $db;

	public function __construct() {
		$this->db = ToolsDb::getReplica( 'wikidatawiki' );
	}

	public function fetchTerm( $termIDs, $type ) {
		$out = [];
		foreach ( array_chunk( $termIDs, self::CHUNK_SIZE ) as $chunk ) {
			$params = str_repeat( '?,', count($chunk) - 1 ) . "?";
			$sql = "SELECT term_full_entity_id,term_language,term_text FROM wb_terms WHERE term_full_entity_id IN ($params) AND term_type='$type'";
			$results = $this->db->query( $sql, $chunk );
			foreach ( $results as $result ) {
				$out[$result->term_full_entity_id][$result->term_language] = $result->term_text;
			}
		}
		return $out;
	}
}