<?php

/**
 * ProcessWire Template
 *
 * A template is a Page's connection to fields (via a Fieldgroup) and output TemplateFile.
 *
 * Templates also maintain several properties which can affect the render behavior of pages using it. 
 * 
 * ProcessWire 2.x 
 * Copyright (C) 2010 by Ryan Cramer 
 * Licensed under GNU/GPL v2, see LICENSE.TXT
 * 
 * http://www.processwire.com
 * http://www.ryancramer.com
 *
 */

class Template extends WireData implements Saveable {

	/**
	 * The PHP output filename used by this Template
	 *
	 */
	protected $filename;
	 
	/**
	 * The Fieldgroup instance assigned to this Template
	 *
	 */
	protected $fieldgroup; 

	/**
	 * The previous Fieldgroup instance assigned to this template, if changed during runtime
	 *
	 */
	protected $fieldgroupPrevious = null; 

	/**
	 * The template's settings, as they relate to database schema
	 *
	 */
	protected $settings = array(
		'id' => 0, 
		'name' => '', 
		'fieldgroups_id' => 0, 
		'cache_time' => 0, 
		); 

	/**
	 * Get a Template property
	 *
	 * @param string $key
	 * @return mixed
	 *
	 */
	public function get($key) {
		if($key == 'filename') return $this->filename();
		if($key == 'fields') $key = 'fieldgroup';
		if($key == 'fieldgroup') return $this->fieldgroup; 
		if($key == 'fieldgroupPrevious') return $this->fieldgroupPrevious; 
		return isset($this->settings[$key]) ? $this->settings[$key] : parent::get($key); 
	}

	/**
	 * Set a Template property
	 *
	 * @param string $key
	 * @param mixed $value
	 * @return this
	 *
	 */
	public function set($key, $value) {

		if(isset($this->settings[$key])) { 

			if($key == 'id') $value = (int) $value; 
				else if($key == 'name') $value = $this->fuel('sanitizer')->name($value); 
				else if($key == 'fieldgroups_id') return $this->setFieldgroup($this->getFuel('fieldgroups')->get($value)); 
				else if($key == 'cache_time' || $key == 'cacheTime') $value = (int) $value; 
				else $value = '';

			if($this->settings[$key] != $value) $this->trackChange($key); 
			$this->settings[$key] = $value; 

		} else if($key == 'fieldgroup' || $key == 'fields') {
			$this->setFieldgroup($value); 

		} else if($key == 'filename') {
			$this->filename = $value; 

		} else {
			parent::set($key, $value); 
		}
		return $this; 
	}

	/**
	 * Set this Template's Fieldgroup
	 *
	 * @param Fieldgroup $fieldgroup
	 * @return this
	 *
	 */
	public function setFieldgroup(Fieldgroup $fieldgroup) {

		if(is_null($this->fieldgroup) || $fieldgroup->id != $this->fieldgroup->id) $this->trackChange('fieldgroup'); 

		if($this->fieldgroup && $fieldgroup->id != $this->fieldgroup->id) {
			// save record of the previous fieldgroup so that unused fields can be deleted during save()
			$this->fieldgroupPrevious = $this->fieldgroup; 
		}

		$this->fieldgroup = $fieldgroup;
		$this->settings['fieldgroups_id'] = $fieldgroup->id; 
		return $this; 
	}

	/**
	 * Return the number of pages used by this template. 
	 *
	 * @return int
	 *
	 */
	public function getNumPages() {
		return Wire::getFuel('templates')->getNumPages($this); 	
	}

	/**
	 * Save the template to database
	 *
	 * @return this|bool Returns Template if successful, or false if not
	 *
	 */
	public function save() {

		$result = Wire::getFuel('templates')->save($this); 	

		return $result ? $this : false; 
	}

	/**
	 * Return corresponding template filename, including path
	 *
	 * @return string
	 *	
	 */
	public function filename() {
		if($this->filename) return $this->filename; 
		if(!$this->settings['name']) throw new WireException("Template must be assigned a name before 'filename' can be accessed"); 
		$this->filename = $this->getFuel('templates')->path . $this->settings['name'] . '.' . $this->config->templateExtension;
		return $this->filename;
	}

	/**
	 * Per Saveable interface
	 *
	 */
	public function getTableData() {
		$tableData = $this->settings; 
		$tableData['data'] = $this->getArray();
		return $tableData; 
	}

	/**
	 * The string value of a Template is always it's name
	 *
	 */
	public function __toString() {
		return $this->name; 
	}



}



