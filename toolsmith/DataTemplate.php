<?php

/**
 * Processing various types of data for the tabular result.
 */
class DataTemplate
{

	// http://www.wikidata.org/entity/
	const PREFIX_LEN = 31;
	/**
	 * SPARQL query
	 * @var string
	 */
	private $sparql;
	/**
	 * Fields to fetch: name => type
	 * @var string[]
	 */
	private $fields;

	public function __construct( $sparql, $fields ) {
		$this->sparql = $sparql;
		$this->fields = $fields;
	}

	public function hasField( $field ) {
		return isset( $this->fields[$field] );
	}

	public function setFieldType( $field, $type ) {
		if ( !isset( $this->fields[$field] ) ) {
			throw new InvalidArgumentException( "Bad field: $field" );
		}
		$this->fields[$field] = $type;
	}

	public function getSPARQL() {
		return $this->sparql;
	}

	public function formatField( $name, $value ) {
		if ( !isset( $this->fields[$name] ) ) {
			return $value;
		}
		$type = $this->fields[$name];
		if ( !is_callable( [$this, "{$type}Format"] ) ) {
			return $value;
		}
		return $this->{"{$type}Format"}( $value );
	}

	protected function itemFormat( $value ) {
		return substr( $value, self::PREFIX_LEN );
	}

	/**
	 * Arrange data according to the order of fields.
	 * Keys are not preserved.
	 * @param array $data
	 */
	public function arrangeRows( array $data ) {
		$out = [];
		foreach ( $this->fields as $name => $value ) {
			if(isset($data[$name])) {
				$out[] = $data[$name];
			} else {
				$out[] = null;
			}
		}
		return $out;
	}

}