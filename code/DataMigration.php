<?php

class DataMigration extends ContentController {

	public static $allowed_actions = array(
		'export',
		'import',
		'test',
	);

	/* Don't go creating these. */
	public static $ignore_classes = array(
		'Member',
		'Group',
	);

	public static function enable() {
		Director::addRules(100, array(
		    'datamigration' => 'DataMigration',
		));
	}

	public function test() {
		
	}

	public function export() {

		// Useful for finding out what's going on with your export
		$debug = false;

		// If we didn't submit, show the form.
		if ( !isset($_POST['data']) ) {
			return $this->renderWith('DataMigrationExportForm');
		}

		// This can take a while...
		set_time_limit(0);

		// Grab list of ID/ClassName pairs
		$lines = explode("\n", $_POST['data']);
		// create our data arrays to populate
		$objects = array();
		$files = array();
		// Use a do...while loop as we want to test after each cycle, not before.
		// This is because the last item may well add more items into the loop.
		do {
			// Just in case we have nothing in $lines
			if ( count($lines) === 0 ) {
				break;
			}
			$line = array_pop($lines);
			// Remove all spaces - they're not useful
			$line = str_replace(' ', '', $line);
			// Get rid of leading and trailing whitespace and pipes
			$line = trim($line, " \t\n\r\0\x0B|");
			$cells = explode('|', $line);
			// Do we have enough to go on?
			if ( count($cells) < 2 ) {
				if ( $debug ) echo '[NOT ENOUGH INFO] ' . $line . '<br>'."\n";
				continue;
			}
			// Do we have an ID and a classname?
			$ID = intval($cells[0]);
			if ( $ID === 0 ) {
				if ( $debug ) echo '[NO ID] ' . $line . '<br>'."\n";
				continue;
			}
			$ClassName = $cells[1];
			if ( in_array($ClassName, self::$ignore_classes) ) {
				if ( $debug ) echo '[IGNORE CLASS '.$ClassName.'] ' . $line . '<br>'."\n";
				continue;
			}

			if ( !ClassInfo::is_subclass_of($ClassName, 'DataObject') ) {
				if ( $debug ) echo '[NOT A DATAOBJECT '.$ClassName.'] ' . $line . '<br>'."\n";
				continue;
			}
			// Check cache - no sense migrating things multiple times
			if ( isset($objects[$ClassName.':'.$ID]) ) {
				if ( $debug ) echo '[ALREADY MIGRATED] '.$ClassName.':'.$ID.'<br>';
				continue;
			}

			if ( $ClassName == 'SiteTree' || ClassInfo::is_subclass_of($ClassName, 'SiteTree') ) {
				// If it's a SiteTree, grab the most recent version
				$obj = Versioned::get_latest_version('SiteTree', $ID);
				if ( $obj ) {
					if ( $debug ) echo '[VERSIONED] Got latest version - SiteTree:'.$ID.'<br>';
				}
			} else {
				// Otherwise, Grab the object from the database
				$obj = DataObject::get_by_id($ClassName, $ID);
			}

			if ( !$obj ) {
				if ( $debug ) echo '[NOT FOUND] ' . $line . '<br>'."\n";
				continue;
			}

			// If we have referred to a descendant object, we may have already cached it.
			// Cache under the most specific version to avoid double-creating ancestor
			// database rows.
			$ClassName = $obj->ClassName;
			// Check cache again - still no sense migrating things multiple times
			if ( isset($objects[$ClassName.':'.$ID]) ) {
				if ( $debug ) echo '[ALREADY MIGRATED] '.$ClassName.':'.$ID.'<br>';
				continue;
			}

			// If it's a file, add the filename to the list of files we need to move later
			if ( is_a($obj, 'File') || is_a($obj, 'Image') ) {
				$files[$obj->Filename] = $obj->Filename;
			}

			// Get value of all fields in $db
			// array( Fieldname => Field value )
			$db = $obj->db();
			foreach( $db as $name => $type ) {
				// Add field to data array for the current object
				$db[$name] = $obj->$name;
				// Look for images in content fields we may need to migrate
				if ( $type === 'HTMLText' ) {
					preg_match_all('/<img src="([^"]+)"/', $db[$name], $matches);
					if ( isset($matches[1]) ) {
						foreach( $matches[1] as $image_src ) {
							// Store file path so we know to grab it for later
							$files[$image_src] = $image_src;
							// Find the image in the database
							$image = DataObject::get_one('File', '"Filename" = \''.Convert::raw2sql($image_src).'\'');
							if ( $image ) {
								// Add the image to the list of objects to migrate
								$lines[] = sprintf(
									'|%s|%s|',
									$image->ID,
									$image->ClassName
								);
								if ( $debug ) echo 'Added '.$image->ClassName.':'.$image->ID.' from '.$ClassName.':'.$ID.':'.$name.'<br>';
								$image->destroy();
								unset($image);
							}
						}
					}
				}
			}

			// Get all has_one connected objects
			// array( Fieldname => ObjectID )
			// Fieldname is eg. Parent, not ParentID
			$has_one = array();
			$has_one_relations = $obj->has_one();
			foreach( $has_one_relations as $name => $type) {
				$idField = $name.'ID';
				// Add connected object to list of objects to migrate
				$lines[] = sprintf(
					'|%s|%s|',
					$obj->$idField,
					$type
				);
				// Add connected object to data array for the current object
				$has_one[$name] = $obj->$idField;
				if ( $debug ) echo 'Added '.$type.':'.$obj->$idField.' via '.$ClassName.':'.$ID.':has_one:'.$idField.'<br>';
			}

			// Get all has_many connected objects
			// array( Fieldname => array(1, 2, 3) )
			// Values are arrays of dataobject IDs
			$has_many = array();
			$has_many_relations = $obj->has_many();
			foreach ( $has_many_relations as $name => $type ) {
				if ( $name == 'Versions' ) {
					continue;
				}
				$children = $obj->getComponents($name);
				$has_many[$name] = array();
				foreach( $children as $child ) {
					// Add connected object to list of objects to migrate
					$lines[] = sprintf(
						'|%s|%s|',
						$child->ID,
						$child->ClassName
					);
					// Add connected object to data array for the current object
					$has_many[$name][$child->ID] = $child->ID;
					if ( $debug ) echo 'Added '.$child->ClassName.' via '.$ClassName.':'.$ID.':has_many:'.$name.'<br>';
					$child->destroy();
					unset($child);
				}
				$children->destroy();
				unset($children);
			}
			// Get all many_many connected objects
			// array( Fieldname => array(1, 2, 3) )
			// Values are arrays of dataobject IDs
			$many_many = array();

			$many_many_relations = $obj->many_many();
			foreach ( $many_many_relations as $name => $type ) {
				$children = $obj->getManyManyComponents($name);
				$extraFields = $obj->many_many_extraFields($name);
				$many_many[$name] = array();
				foreach( $children as $child ) {
					// Add connected object to list of objects to migrate
					$lines[] = sprintf(
						'|%s|%s|',
						$child->ID,
						$child->ClassName
					);
					// Add connected object to data array for the current object
					$many_many[$name][$child->ID] = $child->ID;
					if ( count($extraFields) > 0 ) {
						if ( $debug ) echo '<strong>IMPLEMENT MANY MANY EXTRA FIELDS</strong> '.$ClassName.'<br>';
					}
					$child->destroy();
					unset($child);
				}
				$children->destroy();
				unset($children);
			}

			$objects[$ClassName.':'.$ID] = array(
				'ID' => $ID,
				'ClassName' => $ClassName,
				'Created' => $obj->Created,
				'db' => $db,
				'has_one' => $has_one,
				'has_many' => $has_many,
				'many_many' => $many_many,
			);
		} while ( count($lines) > 0 );

		if ( $debug ) {
			return;
		}

		// Remove any old files
		$target_folder = TEMP_FOLDER . '/datamigration';
		$target_file = BASE_PATH.'/assets/datamigration.tgz';
		shell_exec('rm -rf '.escapeshellarg($target_folder));
		shell_exec('rm '.escapeshellarg($target_file));
		// Create the target directory and data file
		shell_exec('mkdir '.escapeshellarg($target_folder));
		file_put_contents($target_folder.'/data.out', json_encode($objects));
		// Copy all the filesystem assets
		foreach( $files as $file ) {
			if ( !is_file(BASE_PATH.'/'.$file) || is_dir(BASE_PATH.'/'.$file) ) {
				continue;
			}
			$cmd = 'mkdir -p '.escapeshellarg(dirname($target_folder.'/'.$file));
			$result = shell_exec($cmd);
			$cmd = 'cp '.escapeshellarg(BASE_PATH.'/'.$file).' '.escapeshellarg($target_folder.'/'.$file);
			$result = shell_exec($cmd);
		}

		// Create a zipfile
		shell_exec('cd '.escapeshellarg($target_folder).' && tar zcf '.escapeshellarg($target_file).' *');
		// Output to browser
		header('Content-Disposition: attachment; filename="datamigration-'.date('Ymd-his').'.tgz"');
		header('Content-Type: application/gzip');
		header('Content-Length: '.filesize($target_file));
		readfile($target_file);
		// Tidy up
		shell_exec('rm '.escapeshellarg($target_file));
		shell_exec('rm -rf '.escapeshellarg($target_folder));
	}

	// Can either upload a file from the form, or use a GET var to
	// the local filesystem path.
	// Using the local filesystem path lets us use commandline PHP
	// when the job won't run quickly enough via webserver.
	public function import() {

		// Turn off validation... if it was OK in the old system, it's OK in the new system.
		DataObject::set_validation_enabled(false);

		$filepath = null;
		if ( isset($_FILES['file']) ) {
			$filepath = $_FILES['file']['tmp_name'];
		}
		if ( isset($_GET['filepath']) ) {
			$filepath = $_GET['filepath'];
		}

		// If we didn't submit, show the form.
		if ( !$filepath ) {
			return $this->renderWith('DataMigrationImportForm');
		}

echo '<h3>Importing data file</h3>'; flush();

		// This can take a while...
		set_time_limit(0);

		// Clean up any old files
		$target_folder = TEMP_FOLDER . '/datamigration';
		shell_exec('rm -rf '.escapeshellarg($target_folder));
		// Create the target directory and data file
		shell_exec('mkdir '.escapeshellarg($target_folder));
		shell_exec('cd '.escapeshellarg($target_folder).' && tar zxf '.escapeshellarg($filepath));
		// Copy any file assets
		if ( trim(`which rsync`) !== '' ) {
			// Use rsync in case of symlinks
			shell_exec('cd '.escapeshellarg($target_folder.'/assets').' && rsync -K -a . '.escapeshellarg(BASE_PATH.'/assets'));
		} else {
			// Fall back to CP
			shell_exec('cp -R '.escapeshellarg($target_folder.'/assets/*').' '.escapeshellarg(BASE_PATH.'/assets/'));
		}
		// Extract data file
		$all_data = json_decode(file_get_contents($target_folder.'/data.out'), true);

		// Reverse the data, so dependant objects get created/updated before parent objects
		$all_data = array_reverse($all_data);

echo 'Data extracted.<br>'; flush();

		// Create objects, remembering new/existing IDs
		$IDcache = array();
		foreach( $all_data as $cache_key => $data ) {
echo '<strong>'.$cache_key.':object</strong>';
			// Duplicate detection for this object
			// TODO: Use the same duplicate detection that BulkCsvUploader uses
			$duplicateField = 'Created';
			$duplicateValue = $data['Created'];
			// Files/Folders/Images match on filename
			if ( isset($data['db']['Filename']) ) {
				$duplicateField = 'Filename';
				$duplicateValue = $data['db']['Filename'];
			}
			// Anything with URLSegment can match on that - BUT, if there are multiple
			// results we can't really pick one.  We're testing dependant objects first
			// (see array_reverse above) which means we should be testing for matches on
			// the site root parent.
			// If the site root parent has changed its URLSegment, or if there are multiple
			// pages with the same URLSegment (which can happen) this will NOT work
			// and we will create a duplicate.  This can be deleted and moved after the
			// import.
			if ( isset($data['db']['URLSegment']) ) {
				$duplicateField = 'URLSegment';
				$duplicateValue = $data['db']['URLSegment'];
			}

			// Does the object exist?
			$obj = DataObject::get_one(
				$data['ClassName'],
				sprintf(
					'"%s"."ID" = %s AND "%s" = \'%s\'',
					ClassInfo::baseDataClass($data['ClassName']),
					$data['ID'],
					$duplicateField,
					$duplicateValue
				)
			);
			if ( ! $obj ) {
				// Need to create one
				$obj = Object::create($data['ClassName']);
echo ' created';
			} else {
echo ' found';
			}
echo '<br>';

			// Set data fields
			foreach( $data['db'] as $field => $value ) {
				if ( $field === 'ID' ) {
					continue; // this one can fill itself in.
				}
				if ( $obj->$field == $value ) {
					continue; // don't mark unchanged fields as dirty
				}
				$obj->$field = $value;
echo 'db:'.$field.'='.(is_string($value)?htmlentities(substr($value, 0, 50)):$value).'<br>';
			}

			// Write the object
			try {
				$obj->write();
				$obj->flushCache();
				if ( $obj->is_a('SiteTree') ) {
					echo 'Status = '.$obj->Status.'<br>';
					$obj->doRestoreToStage();
					echo 'Did restore to Stage - '.$cache_key.'<br>';
					if ( $obj->Status == 'Published' ) {
						$obj->doRevertToLive();
						echo 'Did revert to Live - '.$cache_key.'<br>';
					}
				}
			} catch (Exception $e) {
				echo 'Writing '.$cache_key.' threw '.get_class($e).':'.$e->getMessage().'<br>';
			}

			// Add to cache with new ID, so we can set the correct relationships up
			$IDcache[$data['ClassName'].':'.$data['ID']] = $obj->ID;
			echo 'Cache set '.$data['ClassName'].':'.$data['ID'].'='.$obj->ID.'<br>';
			$obj->destroy();
			unset($obj);

		}

		// Now that we have created all the objects to connect up, update the relationships
		foreach( $all_data as $cache_key => $data ) {

			// Get new object
			$obj = DataObject::get_by_id($data['ClassName'], $IDcache[$data['ClassName'].':'.$data['ID']]);
			if ( !$obj ) {
				die('Something went wrong creating an object: <pre>'.var_export($data, true).'</pre>');
			}

echo '<strong>'.$cache_key.':relations</strong> ID='.$obj->ID.'<br>';

			// Set all has_one relations
			foreach( $data['has_one'] as $field => $value ) {
				$fieldname = $field.'ID';
				// Zero doesn't need a connected item
				if ( $value == 0 ) {
echo 'has_one:'.$field.'=0 (detected 0)<br>';
					$obj->$fieldname = 0;
					continue;
				}
				// Could be any ancestor, so we use the base data class, which will
				// get any subclasses or ancestors.
				$relationClass = $obj->has_one($field);
				$relationClasses = array($relationClass);
				$relationClasses = array_merge(
					$relationClasses,
					ClassInfo::ancestry($relationClass)
				);
				$relationClasses = array_merge(
					ClassInfo::subclassesFor($relationClass)
				);
				$cachedValue = null;
				foreach( $relationClasses as $vv ) {
					if ( $cachedValue === null && isset($IDcache[$vv.':'.$value]) ) {
						$cachedValue = $IDcache[$vv.':'.$value];
					}
				}
				// If it's an ignored class, maybe we have them already?
				if ( $cachedValue === null && in_array($relationClass, self::$ignore_classes) ) {
					// TODO: account for this
				}
				if ( $cachedValue === null ) {
					// Can't find it - probably was an error in the old system
					$obj->$fieldname = $value;
echo 'has_one:'.$field.'='.$value.' (no integrity check - nothing in cache for '.$relationClass.':'.$value.')<br>';
					continue;
				}
				$obj->$fieldname = $cachedValue;
echo 'has_one:'.$field.'='.$cachedValue.'<br>';
				$obj->write();
				$obj->flushCache();
			}

			// Set all has_many relations
			foreach( $data['has_many'] as $field => $values ) {
				$relationClass = $obj->has_many($field);
				$relationClasses = array($relationClass);
				$relationClasses = array_merge(
					$relationClasses,
					ClassInfo::ancestry($relationClass)
				);
				$relationClasses = array_merge(
					ClassInfo::subclassesFor($relationClass)
				);
				$newIDs = array();
				foreach( $values as $oldID ) {
					$cachedValue = null;
					foreach( $relationClasses as $vv ) {
						if ( $cachedValue === null && isset($IDcache[$vv.':'.$oldID]) ) {
							$cachedValue = $IDcache[$vv.':'.$oldID];
						}
					}
					if ( $cachedValue === null ) {
						// Can't find it - possibly a bug in the old database
						$newIDs[] = $value;
echo 'has_many:'.$field.'[]='.$value.' (no integrity check - nothing in cache for '.$relationClass.':'.$value.')<br>';
						continue;
					}
					$newIDs[] = $cachedValue;
				}
				$componentset = $obj->getComponents($field);
				$componentset->setByIDList($newIDs);
				$componentset->write();
				$componentset->destroy();
				unset($componentset);
echo 'has_many:'.$field.'='.implode(',',$newIDs).'<br>';
				$obj->write();
				$obj->flushCache();
			}

			// Set all many_many relations
			foreach( $data['many_many'] as $field => $values ) {
				// Skip Versions
				if ( $field == 'Versions' ) {
					$obj->publish();
					$obj->write();
					$obj->flushCache();
					continue;
				}
				// Get all related classes
				$relationInfo = $obj->many_many($field);
				$relationClass = $relationInfo[1];
				$relationClasses = array($relationClass);
				$relationClasses = array_merge(
					$relationClasses,
					ClassInfo::ancestry($relationClass)
				);
				$relationClasses = array_merge(
					ClassInfo::subclassesFor($relationClass)
				);
				$newIDs = array();
				foreach( $values as $oldID ) {
					$cachedValue = null;
					foreach( $relationClasses as $vv ) {
						if ( $cachedValue === null && isset($IDcache[$vv.':'.$oldID]) ) {
							$cachedValue = $IDcache[$vv.':'.$oldID];
						}
					}
					if ( $cachedValue === null ) {
						// Can't find it - possibly a bug in the old database
						$newIDs[] = $value;
echo 'many_many:'.$field.'[]='.$value.' (no integrity check - nothing in cache for '.$relationClass.':'.$value.')<br>';
						continue;
					}
					$newIDs[] = $cachedValue;
				}
				$componentset = $obj->getManyManyComponents($field);
				$componentset->setByIDList($newIDs);
				$componentset->write();
				$componentset->destroy();
				unset($componentset);
echo 'many_many:'.$field.'='.implode(',',$newIDs).'<br>';
				$obj->write();
				$obj->flushCache();
			}
			// Set Created
			$obj->Created = $data['Created'];

			try {
				$obj->write();
				$obj->flushCache(); // avoid relation caching confusion
				if ( $obj->is_a('SiteTree') ) {
					$obj->doRestoreToStage();
					echo 'Did restore to Stage - '.$cache_key.'<br>';
					if ( $obj->Status == 'Published' ) {
						$obj->doRevertToLive();
						echo 'Did revert to Live - '.$cache_key.'<br>';
					}
				}
			} catch (Exception $e) {
				echo 'Writing '.$cache_key.' threw '.get_class($e).':'.$e->getMessage().'<br>';
				echo '<pre>';
				var_dump($obj);
				var_dump($data);
				echo '</pre>';
			}
			$obj->destroy();
			unset($obj);
flush();
		}

		echo '<p><strong>Done.</strong></p>';
	}

}
