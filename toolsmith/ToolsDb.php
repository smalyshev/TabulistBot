<?php

/**
 * Connections to tools databases
 */
class ToolsDb
{

	/**
	 * @var PDO
	 */
	private $database;

	public function __construct( $host, $dbname ) {
		$ts_mycnf = self::getConfig();
		$this->database = $db = new PDO( "mysql:host=$host;dbname=$dbname", $ts_mycnf['user'], $ts_mycnf['password'] );
	}

	public static function getReplica( $wiki ) {
		return new self( "$wiki.analytics.db.svc.eqiad.wmflabs", $wiki . "_p" );
	}

	public static function getLocal( $dbname ) {
		$conf = self::getConfig();
		return new self( "tools.db.svc.eqiad.wmflabs", $conf['user'] . "__" . $dbname );
	}

	private static function getConfig() {
		$ts_pw = posix_getpwuid( posix_getuid() );
		return parse_ini_file( $ts_pw['dir'] . "/replica.my.cnf" );
	}

	/**
	 * @param $sql
	 * @param array $params
	 * @return bool|PDOStatement
	 */
	public function query( $sql, $params = [] ) {
		if ( empty( $params ) ) {
			return $this->database->exec( $sql );
		}

		$statement = $this->database->prepare( $sql );
		$statement->execute( $params );
		$statement->setFetchMode( PDO::FETCH_OBJ );
		return $statement;
	}

}