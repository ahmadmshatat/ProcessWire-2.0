<?php
/**
 * ProcessWire DatabaseQuerySelect
 *
 * A wrapper for SELECT SQL queries.
 *
 * The intention behind these classes is to have a query that can safely
 * be passed between methods and objects that add to it without knowledge
 * of what other methods/objects have done to it. It also means being able
 * to build a complex query without worrying about correct syntax placement.
 * 
 * ProcessWire 2.x 
 * Copyright (C) 2010 by Ryan Cramer 
 * Licensed under GNU/GPL v2, see LICENSE.TXT
 * 
 * http://www.processwire.com
 * http://www.ryancramer.com
 *
 */
class DatabaseQuerySelect extends DatabaseQuery {

	/**
	 * Setup the components of a SELECT query
	 *
	 */
	public function __construct() {
		$this->set('select', array()); 
		$this->set('join', array()); 
		$this->set('from', array()); 
		$this->set('leftjoin', array()); 
		$this->set('where', array()); 
		$this->set('orderby', array()); 
		$this->set('groupby', array()); 
		$this->set('limit', array()); 
		$this->set('comment', ''); 
	}

	/**
	 * Return the resulting SQL ready for execution with the database
 	 *
	 */
	public function getQuery() {

		$sql = 	$this->getQuerySelect() . 
			$this->getQueryFrom() . 
			$this->getQueryJoin($this->join, "JOIN") . 
			$this->getQueryJoin($this->leftjoin, "LEFT JOIN") . 
			$this->getQueryWhere() . 
			$this->getQueryGroupby() . 
			$this->getQueryOrderby() . 
			$this->getQueryLimit(); 

		if($this->get('comment')) {
			$comment = str_replace('*/', '', $this->comment); 
			$sql .= "/* $comment */";
		}

		return $sql; 
	}

	/**
	 * Add an 'order by' element to the query
	 *
	 * @param string|array $value
	 * @param bool $prepend Should the value be prepended onto the existing value? default is to append rather than prepend.
	 * 	Note that $prepend is applicable only when you pass this method a string. $prepend is ignored if you pass an array. 
	 * @return this
	 *
	 */
	public function orderby($value, $prepend = false) {

		$oldValue = $this->get('orderby'); 

		if(is_array($value)) {
			$this->set('orderby', array_merge($oldValue, $value)); 

		} else if($prepend) { 
			array_unshift($oldValue, $value); 
			$this->set('orderby', $oldValue); 

		} else {
			$oldValue[] = $value;
			$this->set('orderby', $oldValue); 
		}

		return $this; 
	}

	protected function getQuerySelect() {

		$sql = "SELECT ";
		$select = $this->select; 

		// ensure that an SQL_CALC_FOUND_ROWS request comes first
		while(($key = array_search("SQL_CALC_FOUND_ROWS", $select)) !== false) {
			$sql .= "SQL_CALC_FOUND_ROWS ";	
			unset($select[$key]); 
		}

		foreach($select as $s) $sql .= "$s,";
		$sql = rtrim($sql, ",") . " "; 
		return $sql;
	}

	protected function getQueryFrom() {
		$sql = "\nFROM ";
		foreach($this->from as $s) $sql .= "`$s`,";	
		$sql = rtrim($sql, ",") . " "; 
		return $sql; 
	}

	protected function getQueryJoin(array $join, $type) {
		$sql = '';
		foreach($join as $s) $sql .= "\n$type $s ";
		return $sql;
	}

	protected function getQueryOrderby() {
		if(!count($this->orderby)) return '';
		$sql = "\nORDER BY ";
		foreach($this->orderby as $s) $sql .= "$s,";
		$sql = rtrim($sql, ",") . " ";
		return $sql;
	}

	protected function getQueryGroupby() {
		if(!count($this->groupby)) return '';
		$sql = "\nGROUP BY ";
		foreach($this->groupby as $s) $sql .= "$s,";
		$sql = rtrim($sql, ",") . " ";
		return $sql;
	}

	protected function getQueryLimit() {
		if(!count($this->limit)) return '';
		$limit = $this->limit; 
		$sql = "\nLIMIT " . reset($limit) . " ";
		return $sql; 
	}

	
}

