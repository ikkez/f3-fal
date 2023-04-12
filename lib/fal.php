<?php

/**
 *	FAL - a file abstraction layer for the PHP Fat-Free Framework
 *
 *  The contents of this file are subject to the terms of the GNU General
 *  Public License Version 3.0. You may not use this file except in
 *  compliance with the license. Any of the license terms and conditions
 *  can be waived if you get permission from the copyright holder.
 *
 *  crafted by
 *   __ __     __
 *  |__|  |--.|  |--.-----.-----.
 *  |  |    < |    <|  -__|-- __|
 *  |__|__|__||__|__|_____|_____|
 *
 *  Copyright (c) 2013 by ikkez
 *  Christian Knuth <ikkez0n3@gmail.com>
 *  https://github.com/ikkez/F3-Sugar/
 *
 *  @version 0.7.3
 **/

class FAL extends Magic
{
	protected
		$fs,                // FileSystem adapter
		$meta,              // Meta Data, Array
		$metaHandle,        // meta storage adapter
		$file,              // File Name
		$content,           // File Content
		$changed,           // File Content changed indicator, Bool
		$cacheHash,         // cached file name
		$ttl;               // caching ttl

	/** @var \Base */
	protected
		$f3;

	public function __construct(\FAL\FileSystem $filesystemAdapter,
								\FAL\MetaStorageInterface $metaAdapter = NULL)
	{
		$this->fs = $filesystemAdapter;
		if (is_null($metaAdapter))
			$metaAdapter = new \FAL\MetaFileStorage($filesystemAdapter);
		$this->metaHandle = $metaAdapter;
		$this->meta = array();
		$this->metaFileMask = '%s.meta';
		$this->f3 = \Base::instance();
	}

	/**
	 * create FAL on local filesystem as prefab default
	 * @return mixed
	 */
	static public function instance() {
		$f3 = \Base::instance();
		$dir = $f3->split($f3->get('UI'));
		$localFS = new \FAL\LocalFS($dir[0]);
		return new self($localFS);
	}

	/**
	 * update file contents and meta
	 * or create new file, if not existing
	 * @param int $ttl
	 * @return bool
	 */
	public function save($ttl = 0)
	{
		$ttl = ($ttl)?:$this->ttl;
		if (empty($this->file)) {
			trigger_error('Unable to save. No file specified.');
			return false;
		}
		if(is_null($this->content)) {
			trigger_error(sprintf('Unable to save. Contents of file `%s´ is NULL.',$this->file));
			return false;
		}
		if ($this->changed) {
			$this->fs->write($this->file, (string) $this->content);
			if ($this->f3->get('CACHE')) {
				$cache = \Cache::instance();
				$cacheHash = $this->getCacheHash($this->file);
				if ($this->ttl)
					$cache->set($cacheHash,$this->content,$this->ttl);
				elseif ($cache->exists($cacheHash))
					$cache->clear($cacheHash);
			}
		}
		$this->changed = false;
		$this->metaHandle->save($this->file,$this->meta,$ttl);
		return true;
	}

	function meta() {
		return $this->metaHandle;
	}

	/**
	 * return general file idenfitier
	 * @return string
	 */
	function getUUID() {
		if (method_exists($this->metaHandle,'getUUID'))
			$id = $this->metaHandle->getUUID();
		else
			$id = $this->getCacheHash($this->file);
		return $id;
	}

	/**
	 * loads a file and it's meta data
	 * @param string $file
	 * @param int $ttl
	 * @return bool
	 */
	public function load($file,$ttl = 0)
	{
		$this->reset();
		$cache = \Cache::instance();
		$this->file = $file;
		$this->changed = false;
		$this->ttl = $ttl;
		$cacheHash = $this->getCacheHash($file);
		$exists = false;
		if ($this->f3->get('CACHE') && $ttl && ($cached = $cache->exists(
			$cacheHash,$content)) && $cached[0] + $ttl > microtime(TRUE)
		) {
			// load from cache
			$this->content = $content;
			$this->meta = $this->metaHandle->load($file,$ttl);
			$exists = true;
		} elseif ($this->fs->exists($file)) {
			// load from FS
			$exists = true;
			$this->meta = $this->metaHandle->load($file,$ttl);
			// if caching is on, save content to cache backend, otherwise it gets lazy loaded
			if ($this->fs->engine() != 'local'
				&& $this->f3->get('CACHE') && $ttl) {
				$this->content = $this->fs->read($file);
				$cache->set($cacheHash, $this->content, $ttl);
			}
		}
		return $exists;
	}

	/**
	 * compute file cache name
	 * @param $file
	 * @return string
	 */
	protected function getCacheHash($file)
	{
		if(is_null($this->cacheHash)) {
			$fs_class = explode('\\', get_class($this->fs));
			$this->cacheHash = $this->f3->hash($this->f3->stringify($file)).
				'.'.strtolower(array_pop($fs_class));
		}
		return $this->cacheHash;
	}

	/**
	 * delete a file and it's meta data
	 * @param null $file
	 */
	public function delete($file = null)
	{
		$file = $file ?: $this->file;
		if ($this->fs->exists($file))
			$this->fs->delete($file);
		if ($this->f3->get('CACHE')) {
			$cache = \Cache::instance();
			if($cache->exists($cacheHash = $this->getCacheHash($file)))
				$cache->clear($cacheHash);
		}
		$this->metaHandle->delete($file);
	}

	/**
	 * @param $newPath
	 */
	public function move($newPath)
	{
		if(!empty($this->file)) {
			$this->fs->move($this->file,$newPath);
			if ($this->f3->get('CACHE')) {
				$cache = \Cache::instance();
				if ($cache->exists($cacheHash = $this->getCacheHash($this->file)))
					$cache->clear($cacheHash);
			}
			if (method_exists($this->metaHandle, 'move'))
				$this->metaHandle->move($this->file,$newPath);
			$this->file = $newPath;
		}
	}

	/**
	 * get meta data field
	 * @param $key
	 * @return bool
	 */
	public function &get($key)
	{
		$out = ($this->exists($key)) ? $this->meta[$key] : null;
		return $out;
	}

	/**
	 * set meta data field
	 * @param $key
	 * @param $val
	 * @return mixed
	 */
	public function set($key, $val)
	{
		return $this->meta[$key] = $val;
	}

	/**
	 * check whether meta field exists
	 * @param $key
	 * @return bool
	 */
	public function exists($key)
	{
		if(empty($this->meta)) return false;
		return array_key_exists($key, $this->meta);
	}

	/**
	 * remove a meta field
	 * @param $key
	 */
	public function clear($key)
	{
		unset($this->meta[$key]);
	}

	/**
	 * free the layer of loaded data
	 */
	public function reset()
	{
		$this->meta = [];
		$this->file = false;
		$this->content = false;
		$this->changed = false;
		$this->ttl = 0;
		$this->cacheHash = null;
	}

	/**
	 * @param $key
	 * @param $fields
	 */
	public function copyfrom($key, $fields = null) {
		$srcfields = is_array($key) ? $key : $this->f3->get($key);
		if ($fields) {
			if (is_callable($fields))
				$srcfields = $fields($srcfields);
			else {
				if (is_string($fields))
					$fields = $this->f3->split($fields);
				$srcfields = array_intersect_key($srcfields, array_flip($fields));
			}
		}
		$this->meta = $srcfields;
	}

	public function copyto($key) {
		$this->f3->set($key,$this->meta);
	}

	public function cast() {
		return $this->meta;
	}


	/**
	 * lazy load file contents
	 * @return mixed|bool
	 */
	public function getContent()
	{
		if(!empty($this->content))
			return $this->content;
		elseif ($this->fs->exists($this->file)) {
			$this->content = $this->fs->read($this->file);
			return $this->content;
		} else
			return false;
	}

	/**
	 * set file contents
	 * @param $data
	 */
	public function setContent($data)
	{
		$this->changed = true;
		$this->content = $data;
	}

	/**
	 * return file stream path
	 * @return string
	 */
	public function getFileStream() {
		$stream = new \FAL\FileStream();
		$pt = $stream->getProtocolName();
		file_put_contents($path = $pt.'://'.$this->file,$this->getContent());
		return $path;
	}
}
