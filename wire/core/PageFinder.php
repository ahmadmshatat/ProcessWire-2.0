<?php

/**
 * ProcessWire PageFinder
 *
 * Matches selector strings to pages
 * 
 * ProcessWire 2.x 
 * Copyright (C) 2010 by Ryan Cramer 
 * Licensed under GNU/GPL v2, see LICENSE.TXT
 * 
 * http://www.processwire.com
 * http://www.ryancramer.com
 *
 */

class PageFinder extends Wire {

	protected $fieldgroups; 
	protected $total = 0;
	protected $limit = 0; 
	protected $start = 0;
	protected $checkStatus = true; 

	/**
	 * Construct the PageFinder
	 *
	 * @param Fieldgroups $fieldgroups
	 *
	 */
	public function __construct($fieldgroups) {
		$this->fieldgroups = $fieldgroups; 
	}

	/**
	 * Pre-process the selectors to add Page status checks
	 *
	 */
	protected function setupStatusChecks(Selectors $selectors, $options = array()) {

		$maxStatus = null; 

		foreach($selectors as $key => $selector) {
			if($selector->field == 'status') {
				$value = $selector->value; 
				if(!ctype_digit("$value")) {
					// allow use of some predefined labels for Page statuses
					if($value == 'hidden') $selector->value = Page::statusHidden; 
						else if($value == 'unpublished') $selector->value = Page::statusUnpublished; 
						else if($value == 'locked') $selector->value = Page::statusLocked; 
						else $selector->value = 1; 

					if($selector->operator == '=') {
						// there is no point in an equals operator here, so we make it a bitwise AND, for simplification
						$selectors[$key] = new SelectorBitwiseAnd('status', $selector->value); 
					}
				}
				if(is_null($maxStatus) || $value > $maxStatus) 
					$maxStatus = (int) $selector->value; 
			}
		}

		if(!is_null($maxStatus)) {
			// if a status was already present in the selector, then just make sure the page isn't unpublished
			if($maxStatus < Page::statusUnpublished) 
				$selectors->add(new SelectorLessThan('status', Page::statusUnpublished)); 

		} else if($options['findOne']) {
			// findOne option, apply optimizations enabling hidden pages to be loaded
			$selectors->add(new SelectorLessThan('status', Page::statusUnpublished)); 

		} else {
			// no status is present, so exclude everything hidden and above
			$selectors->add(new SelectorLessThan('status', Page::statusHidden)); 
		}

		if($options['findOne']) {
			$selectors->add(new SelectorEqual('start', 0)); 
			$selectors->add(new SelectorEqual('limit', 1)); 
		}
	}

	/**
	 * Return all pages matching the given selector.
	 *
	 */
	public function find(Selectors $selectors, $options = array()) {

		$defaultOptions = array(
			'findOne' => false,
			);
		$options = array_merge($defaultOptions, $options); 

		$this->start = 0; // reset for new find operation
		$this->limit = 0; 
		if($this->checkStatus) $this->setupStatusChecks($selectors, $options); 
		$cnt = count($selectors); 
		$matches = array(); 
		$query = $this->getQuery($selectors); 
		if($this->fuel('config')->debug) $query->set('comment', "Selector: " . (string) $selectors); 

		if(!$result = $query->execute()) throw new WireException($this->db->error); 
		$this->total = $result->num_rows; 
		if(!$this->total) return $matches; 

		while($row = $result->fetch_assoc()) {

			// determine score for this row
			$score = 0;
			foreach($row as $k => $v) if(strpos($k, '_score') === 0) {
				$score += $v; 
				unset($row[$k]); 

			}
			$row['score'] = $score; 
			$matches[] = $row; 
		}
		$result->free();

		if($options['findOne']) {
			$this->total = count($matches); 

		} else if(count($query->limit)) {
			$result = $this->db->query("SELECT FOUND_ROWS()"); 	
			list($this->total) = $result->fetch_array();
			$result->free();
		}

		return $matches; 
	}


	/**
	 * Given one or more selectors, create the SQL query for finding pages.
	 *
	 * @TODO split this method up into more parts, it's too long
	 *
	 * @param array $selectors Array of selectors. 
	 * @return string SQL statement. 
	 *
	 */
	protected function getQuery($selectors) {

		$where = '';
		$cnt = 1;
		$fieldtypes = $this->fieldtypes;
		$fieldCnt = array(); // counts number of instances for each field to ensure unique table aliases for ANDs on the same field
		$lastSelector = null; 
		$sortSelectors = array(); // selector containing 'sort=', which gets added last
		$joins = array();
		$nullPage = new NullPage();

		$query = new DatabaseQuerySelect();
		$query->select(array('pages.id', 'pages.templates_id')); 
		$query->from("pages"); 
		$query->groupby("pages.id"); 

		foreach($selectors as $selectorCnt => $selector) {

			if(is_null($lastSelector)) $lastSelector = $selector; 

			$fields = $selector->field; 
			$fields = is_array($fields) ? $fields : array($fields); 
			$field = reset($fields); // first field


			// TODO Make native fields and path/url multi-field and multi-value aware
			if($field == 'sort') {
				$sortSelectors[] = $selector; 
				continue; 

			} else if($field == 'limit' || $field == 'start') {
				$this->getQueryStartLimit($query, $selectors); 
				continue; 

			} else if($field == 'path' || $field == 'url') {
				$this->getQueryJoinPath($query, $selector); 
				continue; 

			} else if($field == 'has_parent') {
				$this->getQueryHasParent($query, $selector); 
				continue; 

			} else if($this->getFuel('fields')->isNativeName($field)) {
				$this->getQueryNativeField($query, $selector, $field); 
				continue; 
			} 

			foreach($fields as $n => $field) {

				// if a specific DB field from the table has been specified, then get it, otherwise assume 'data'
				if(strpos($field, ".")) list($field, $subfield) = explode(".", $field); 	
					else $subfield = 'data';

				if(!$field = $this->fuel('fields')->get($field)) throw new WireException("Field does not exist: $fields[$n]");

				// keep track of number of times this table name has appeared in the query
				if(!isset($fieldCnt[$field->table])) $fieldCnt[$field->table] = 0; 
					else $fieldCnt[$field->table]++; 

				// use actual table name if first instance, if second instance of table then add a number at the end
				$tableAlias = $field->table . ($fieldCnt[$field->table] ? $fieldCnt[$field->table] : '');

				$valueArray = is_array($selector->value) ? $selector->value : array($selector->value); 
				$join = '';
				$fieldtype = $field->type; 

				foreach($valueArray as $value) {

					if(isset($subqueries[$tableAlias])) $q = $subqueries[$tableAlias];
						else $q = new DatabaseQuerySelect();

					//if($subfield == 'data' && in_array($selector->operator, array('=', '!=', '<>')) && $value === $fieldtype->getBlankValue($nullPage, $field)) {
					if($subfield == 'data' && in_array($selector->operator, array('=', '!=', '<>')) && empty($value)) {
						// handle blank values -- look in table that has no pages_id relation back to pages, using the LEFT JOIN / IS NULL trick
						$query->leftjoin("$tableAlias ON $tableAlias.pages_id=pages.id"); 
						$query->where("$tableAlias.pages_id " . ($selector->operator == '=' ? "IS" : "IS NOT") . " NULL"); 
						continue; 

					} else {

						$q = $fieldtype->getMatchQuery($q, $tableAlias, $subfield, $selector->operator, $value); 
						$query->select($q->select); 
						$query->orderby($q->orderby); 
					}

					if(count($q->where)) { 
						$and = $selector->not ? "AND NOT" : "AND";
						$sql = ''; 
						foreach($q->where as $w) $sql .= $sql ? "$and $w " : "$w ";
						$sql = "($sql) "; 
						if($selector->not) $sql = "(NOT $sql)";
						$join .= ($join ? "\n\t\tOR $sql " : $sql); 
					}

					$cnt++; 
				}

				if($join) {
					$joinType = "join";
					if(count($fields) > 1) {
						$joinType = "leftjoin";

						if($where) {
							$whereType = $lastSelector->str == $selector->str ? "OR" : ") AND (";
							$where .= "\n\t$whereType ($join) ";
						} else {
							$where .= "($join) ";
						}

					}

					// we compile the joins after going through all the selectors, so that we can 
					// match up conditions to the same tables
					if(isset($joins[$tableAlias])) {
						$joins[$tableAlias]['join'] .= " AND ($join) ";
					} else {
						$joins[$tableAlias] = array(
							'joinType' => $joinType, 
							'table' => $field->table, 
							'tableAlias' => $tableAlias, 	
							'join' => "($join)", 
							);
					}

				}

				$lastSelector = $selector; 	
			} // fields
		
		} // selectors

		if($where) $query->where("($where)"); 

		// complete the joins, matching up any conditions for the same table
		foreach($joins as $j) {
			$joinType = $j['joinType']; 
			$query->$joinType("$j[table] AS $j[tableAlias] ON $j[tableAlias].pages_id=pages.id AND ($j[join])"); 
		}

		if(count($sortSelectors)) foreach(array_reverse($sortSelectors) as $s) $this->getQuerySortSelector($query, $s);

		return $query; 

	}

	protected function getQuerySortSelector(DatabaseQuerySelect $query, Selector $selector) {

		$field = is_array($selector->field) ? reset($selector->field) : $selector->field; 
		$values = is_array($selector->value) ? $selector->value : array($selector->value); 	
		$fields = $this->fuel('fields'); 
		
		foreach($values as $value) {

			$fc = substr($value, 0, 1); 
			$lc = substr($value, -1); 
			$value = trim($value, "-+"); 

			if(strpos($value, ".")) list($value, $subValue) = explode(".", $value); // i.e. some_field.title
				else $subValue = '';

			if($value == 'random') { 
				$value = 'RAND()';

			} else if($value == 'parent') {
				// sort by parent native field. does not work with non-native parent fields. 
				$tableAlias = "_sort_parent" . ($subValue ? "_$subValue" : ''); 
				$query->join("pages AS $tableAlias ON $tableAlias.id=pages.parent_id"); 
				$value = "$tableAlias." . ($subValue ? $subValue : "name"); 

			} else if($fields->isNativeName($value)) {
				if(!strpos($value, ".")) $value = "pages.$value";

			} else {
				$field = $fields->get($value);
				if(!$field) continue; 
				$tableAlias = "_sort_{$field->name}" . ($subValue ? "_$subValue" : '');
				$query->leftjoin("{$field->table} AS $tableAlias ON $tableAlias.pages_id=pages.id");

				if($field->type instanceof FieldtypePage) {
					// If it's a FieldtypePage, then data isn't worth sorting on because it just contains an ID to the page
					// so we also join the page and sort on it's name instead of the field's "data" field.
					$tableAlias2 = "_sort_page_{$field->name}" . ($subValue ? "_$subValue" : '');
					$query->leftjoin("pages AS $tableAlias2 ON $tableAlias.data=$tableAlias2.id"); 
					$value = "$tableAlias2." . ($subValue ? $subValue : "name");
				} else {
					$value = "$tableAlias." . ($subValue ? $subValue : "data"); ; 
				}
			}

			if($fc == '-' || $lc == '-') $query->orderby("$value DESC", true);
				else $query->orderby("$value", true); 

		}
	}

	protected function getQueryStartLimit(DatabaseQuerySelect $query, $selectors) {

		$start = null; 
		$limit = null;
		$sql = '';

		foreach($selectors as $selector) {
			if($selector->field == 'start') $start = (int) $selector->value; 	
				else if($selector->field == 'limit') $limit = (int) $selector->value; 
		}


		if($limit) {

			$this->limit = $limit; 

			if(is_null($start) && ($input = $this->fuel('input'))) {
				// if not specified in the selector, assume the 'start' property from the default page's pageNum
				$pageNum = $input->pageNum - 1; // make it zero based for calculation
				$start = $pageNum * $limit; 
			}

			if(!is_null($start)) {
				$sql .= "$start,";
				$this->start = $start; 
			}

			$sql .= "$limit";
			if($this->limit > 1) $query->select("SQL_CALC_FOUND_ROWS"); 
		}

		if($sql) $query->limit($sql); 
	}


	/**
	 * Special case when requested value is path or URL
	 *
	 */ 
	protected function getQueryJoinPath(DatabaseQuerySelect $query, $selector) {

		if($selector->value == '/') {
			$parts = array();
			$query->where("pages.id=1");
		} else {
			$parts = explode('/', rtrim($selector->value, '/')); 
			$query->where("pages.name='" . $this->db->escape_string(array_pop($parts)) . "'");
			if(!count($parts)) $query->where("pages.parent_id=1");
		}

		$alias = 'pages';
		$lastAlias = 'pages';

		while($n = count($parts)) {
			$part = $this->db->escape_string(array_pop($parts)); 
			if($part) {
				$alias = "parent$n";
				$query->join("pages AS $alias ON ($lastAlias.parent_id=$alias.id AND $alias.name='$part')");

			} else {
				$query->join("pages AS rootparent ON ($alias.parent_id=rootparent.id AND rootparent.id=1)");
			}
			$lastAlias = $alias; 
		}
	}

	/**
	 * Special case when field is native to the pages table
	 *
	 * TODO not all operators will work here, so may want to add some translation or filtering
	 *
	 */

	protected function getQueryNativeField(DatabaseQuerySelect $query, $selector, $field) {

		$value = $selector->value; 
		$valueArray = is_array($value) ? $value : array($value); 
		$sql = '';

		if($field == 'template') {
			// convert templates specified as a name to the numeric template ID
			// allows selectors like 'template=my_template_name'
			foreach($valueArray as $k => $v) {
				if(!ctype_digit("$v")) $valueArray[$k] = (($template = $this->fuel('templates')->get($v)) ? $template->id : 0); 
			}
			$field = 'templates_id';

		} else if($field == 'parent') {
			// convert parent fields like '/about/company/history' to the equivalent ID
			foreach($valueArray as $k => $v) {
				if(!ctype_digit("$v")) $valueArray[$k] = (($parent = $this->fuel('pages')->get($v)) ? $parent->id : null); 
			}
			$field = 'parent_id';
		}

		foreach($valueArray as $value) { 

			if(is_null($value)) {
				// an invalid/unknown walue was specified, so make sure it fails
				$sql .= "1>2";
				continue; 
			}

			if(in_array($field, array('created', 'modified'))) {
				// prepare value for created or modified date fields
				if(!ctype_digit($value)) $value = strtotime($value); 
				$value = date('Y-m-d H:i:s', $value); 
			}

			if(!$this->db->isOperator($selector->operator)) 
				throw new WireException("Operator '{$selector->operator}' is not yet supported for fields native to pages table"); 

			$value = $this->db->escape_string($value); 
			$s = "pages." . $field . $selector->operator . (ctype_digit("$value") ? (int) $value : "'$value'");
			if($selector->not) $s = "NOT ($s)";
			$sql .= $sql ? " OR $s": "$s"; 
		}

		$query->where("($sql)"); 
	}

	/**
	 * Make the query specific to all pages below a certain parent (children, grandchildren, great grandchildren, etc.)
	 *
	 */
	protected function getQueryHasParent(DatabaseQuerySelect $query, $selector) {

		$parent_id = (int) $selector->value;

		$query->join(
			"pages_parents ON (" . 
				"pages_parents.pages_id=pages.parent_id " . 
				"AND (" . 
					"pages_parents.parents_id=$parent_id " . 
					"OR pages_parents.pages_id=$parent_id " . 
				")" . 
			")"
		); 
	}

	/**
	 * Returns the total number of results returned from the last find() operation
	 *
	 * If the last find() included limit, then this returns the total without the limit
	 *
	 * @return int
	 *
	 */
	public function getTotal() {
		return $this->total; 
	}

	/**
	 * Returns the limit placed upon the last find() operation, or 0 if no limit was specified
	 *
	 */
	public function getLimit() {
		return $this->limit; 
	}

	/**
	 * Returns the start placed upon the last find() operation
	 *
	 */
	public function getStart() {
		return $this->start; 
	}

	/**
	 * Should the page finder consider a Page's status when doing a find()?
	 *
	 */
	public function checkStatus($checkStatus = true) {
		$this->checkStatus = $checkStatus ? true : false; 
	}

}

