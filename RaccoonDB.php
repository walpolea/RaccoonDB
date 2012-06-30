<?php
/*
Copyright (C) 2012 Andrew Walpole - walpolea@gmail.com

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in the
Software without restriction, including without limitation the rights to use,
copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the
Software, and to permit persons to whom the Software is furnished to do so,
subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED,
INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A
PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT
HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION
OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE
SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
*/


//RaccoonDB - the Quick and Dirty Prototyping Database for PHP
class RaccoonDB {
    
    public $dbh;
    public $isConnected = false;
    
	public function __construct( $dbname, $directory = "./" ) {
		
		$this->connect( $dbname, $directory );
		
	}
	
	/*******************************
	connects to a database given $dbname
	you may also specify the $directory if needed
	if a database does not exist it will be created
	*******************************/
	public function connect( $dbname, $directory = "./" ) {
		
		if( isset( $this->dbh ) ) {
			$this->close();
		}
		
		$this->dbh = new SQLite3( $directory.$dbname.'.db' );
		$this->isConnected = true;
	}
	
	//close the db connection
	public function close() {
		if( $this->dbh ) {
            $this->dbh->close();
            unset($this->dbh);
			$this->isConnected = false;
		}
	}
	
	/*******************************
	Stores $data into a $collection.
	$data may be a JSON string or an associative array
	*******************************/
	public function store( $collection, $data ) {
		$this->addCollection( $collection );
		
		$key = uniqid();
		
		$data = $this->prepareData( $data );
		
		$statement = $this->dbh->prepare( "INSERT INTO ".$collection." ( _key, _modified, _data ) VALUES ( :key, (SELECT strftime('%s','now')), :data )" );
		$statement->bindValue(':key', $key);
		$statement->bindValue(':data', $data);
		$statement->execute();
		
		//$this->dbh->query( "INSERT INTO ".$collection." ( _key, _modified, _data ) VALUES ( '".$key."', (SELECT strftime('%s','now')), '".$data."')" );
		
		return $this->retrieveByKey($collection, $key);
	}
	
	public function prepareData( $data ) {
		
		if( is_array($data) || is_object($data) ) {
			
			foreach( $data as $k=>$v ) {
				if( (strpos( $v, "{" ) === 0 && strrpos( $v, "}" ) === strlen($v)-1) || (strpos( $v, "[" ) === 0 && strrpos( $v, "]" ) === strlen($v)-1)  ) {
					$newdata = json_decode( stripslashes($v) );
					
					if( $newdata != null ) {
						$data[$k] = $newdata;
					}
				}
			}
			
			return stripslashes( json_encode( $data ) );
		} else {
			return $data;	
		}
		
	}
	
	
	
	/*******************************
	Updates information in a $collection given the $key to the document.
	new $data passed in will overwrite existing keys and add new keys.
	if a key is set to "_null" it will remove it from the document.
	$data may be a JSON string or an associative array
	or a PHP associative array (true).
	
	returns the key generated for the document
	*******************************/
	public function update( $collection, $key, $data ) {
		
		//select old data from db
		$q = $this->dbh->query( 'SELECT * FROM '.$collection.' WHERE _key="'.$key.'"' );
		$results = $q->fetchArray(SQLITE_ASSOC);
		
		//if there is no collection 
		if( count( $results ) == 0 ) {
			return null;
		}
		
		//update properties that have changed in new $data, leave props that have not
		$old_data = json_decode( stripslashes($results["_data"]), true );
		if( !is_array($data) ) {
			$data = json_decode( $data, true );
		}
		
		foreach( $data as $k => $v ) {
			if( $v === "_null" ) {
				unset( $old_data[$k] );	
			} else {
				$old_data[$k] = $v;
			}
		}
		
		$new_data_str = $this->prepareData( $old_data );
		//$new_data_str = stripslashes(json_encode( $old_data ));
		
		//update record in db
		$statement = $this->dbh->prepare( "UPDATE ".$collection." SET _data=:data, _modified=(SELECT strftime('%s','now')) WHERE _key=:key" );
		$statement->bindValue(':key', $key);
		$statement->bindValue(':data', $new_data_str);
		$q = $statement->execute();
		
		//$q = $this->dbh->query( "UPDATE ".$collection." SET _data='".$new_data_str."', _modified=(SELECT strftime('%s','now')) WHERE _key='".$key."'" );
		
		return $this->retrieveByKey($collection, $key);
	}
	
	
	/***********************************
	Returns $collection of data, filtered by $filter.
	Will return all documents in collection if $filter is null.
	$filter is an associative array that specifies the items that should be equal in the collection results
	$as_array lets you specify whether you want the response as a JSON string (false, default)
	or as a PHP associative array (true).
	$auto_output lets you specify if you want the retrival to auomatically set the content type to JSON
	and show the JSON data.
	***********************************/
	public function retrieve( $collection, $filter = null, $injections = null, $as_array = false, $auto_output = false ) {
		
        $this->addCollection( $collection );
        
        //Get the data out
		$r = $this->dbh->query( "SELECT * FROM ".$collection." ORDER BY _modified DESC" );
        
        $counter = 0;
        
        //Build the data object
		$response = '{ "'.$collection.'": [ ';
        
        /*if( $r === false ) {
            $response .=  ']}';
            return $response;
        }*/
        
		$matchcount = count( $filter );
		while( $row = $r->fetchArray(SQLITE_ASSOC) ) {
			
			$match = 0;
			
			if( $counter > 0 ) {
				$response .= ",";	
			}
			$counter++;
			$response .=  '{"key":"' . $row["_key"] . '", "last_modified":"' . $row["_modified"] . '", "data":' . stripslashes($row["_data"]) . '}';
		}
		
		$response .=  ']}';
		
        //Filter the data
        if( isset($filter) && count($filter) > 0 ) {
            $response = $this->filter( $response, $filter );
        }
		
		//Inject Data - [{"keyname":"collection"},{"owner":"owners"},...]
		if( isset($injections) ) {
			
			$search_arr = array();
			$replace_arr = array();
			
			foreach( $injections as $key => $value ) {
				
				$items = $this->retrieve( $value, null, null, true );
				
				foreach( $items[$value] as $item ) {
					$item["data"]["key"] = $item["key"];
					array_push( $search_arr, '"'.$key.'":"'.$item["key"].'"' );
					array_push( $replace_arr, '"'.$key.'":'.stripslashes(json_encode($item["data"])).'' );
				}
			}
			
			$response = str_replace( $search_arr, $replace_arr, $response );
		}
        
		if( $auto_output ) {
			header('Content-type: application/json');
			echo $response;	
		} else {
			if( $as_array ) {
				return json_decode( $response, true );
			} else {
				return $response;
			}
		}
		
	}
    
    /**************************************
	Filters $data given object $filter. Will return objects with keys that match filter key=>value pairs:
	{ "name":"A", "color":"red,blue", "level":">5" } -return all objects with name starts with A, has color either red or blue and level is greater than 5
    **************************************/
    public function filter( $data, $filter, $as_array = false, $auto_output = false ) {
          
		  if( $filter && isset( $filter["_unique"] ) ) {
			  unset( $filter["_unique"] );
		  }
		  
          if( !is_array($filter) ) {
              $filter = json_decode( $filter, true );
          }
        
          if( !is_array($data) ) {
      		  $data = json_decode( $data, true );
          }
          
          $collection;
          foreach( $data as $key => $value ) {
              $collection = $key;
          }
          
          $response = array();
          $response[$collection] = array();
          
          $filter_count = count($filter);
          
          $counter = -1;
          foreach( $data[$collection] as $object ) {
              
			  $counter++;
              $match_count = 0;
              
			  foreach( $object["data"] as $key => $value ) {
                  
				 
				  
				  //if the $value is a json object
				  if( is_array($value) || is_object($value) ) {
					  foreach( $filter as $k => $v ) {
						 // echo $k;
						  //if there is a multilevel reference
						  if( stripos( $k, "_" ) == true ) {
							 $kvs = explode( "_", $k );
							 if( $kvs[0] == $key ) { //if the first parameter in the multilevel reference matches the current object looping over
								 for($i = 1; $i < count($kvs); $i++ ) {
									 if( isset( $value[$kvs[$i]] ) ) {
										 $value = $value[$kvs[$i]];
									 }
								 }
								 if( is_array($value) || is_object($value) ) {
									$value = json_encode($value);
									$v = stripslashes($v);
								 }
								
								 if( $value === $v ) {
									 $match_count++;
								 	 break;
								 }
							 }
						  } else if( $k === $key ) {
							  
							  $v = json_encode(json_decode( stripslashes($v) ));
							  $value = json_encode( $value );
							  if( $value === $v ) {
								  $match_count++;
								  break;
							  }
						  }
					  }
				   } else { //otherwise it's a string
				  
					  
					  foreach( $filter as $k => $v ) {
						  $values = null;
						  
						  if( stripos( $v, "," ) == true ) {
							 $values = explode( ",", $v );
						  }
					  
						  if( $k === $key ) {
							   $match = false;
							   if( isset( $values ) ) {
								   for( $i = 0; $i < count( $values ); $i++ ) {
									   if( stripos( $value, $values[$i] ) === 0 ) {
										   $match_count++;
										   break;
									   } else if( stripos( $values[$i], "<" ) === 0 ) {
										   $values[$i] = substr( $values[$i], 1 );
										   if( intval($value) < intval($values[$i]) ) {
												$match_count++;
												break; 
										   }
									   } else if( stripos( $values[$i], ">" ) === 0 ) {
										   $values[$i] = substr( $values[$i], 1 );
										   if( intval($value) > intval($values[$i]) ) {
												$match_count++;
												break; 
										   }
									   } else if( stripos( $values[$i], "!" ) === 0 ) {
										   $values[$i] = substr( $values[$i], 1 );
										   if( stripos( $value, $values[$i] ) !== 0 ) {	
												$match_count++;
												break; 
										   }
									   }
									   
								   }
							   } else {
								   if( stripos( $value, $v ) === 0 ) {
									   $match_count++;
								   } else if( stripos( $v, "<" ) === 0 ) {
									   $v = substr( $v, 1 );
									   if( intval($value) < intval($v) ) {
											$match_count++;
											break; 
									   }
								   } else if( stripos( $v, ">" ) === 0 ) {
									   $v = substr( $v, 1 );
									   if( intval($value) > intval($v) ) {
											$match_count++;
											break; 
									   }
								   } else if( stripos( $v, "!" ) === 0 ) {
									   $v = substr( $v, 1 );
									   if( stripos( $value, $v ) !== 0 ) {
											$match_count++;
											break; 
									   }
								   }
							   } //end if else
						  } //end foreach3
					  }//end if else
                  } //end foreach2
              } //end foreach1
			  if( $match_count == $filter_count ) {
				  array_push( $response[$collection], $data[$collection][$counter] );
			  }
          }
          
			if( $auto_output ) {
			    header('Content-type: application/json');
				echo stripslashes(json_encode($response));
			} else {
				if( $as_array ) {
					return $response;
				} else {
					return stripslashes(json_encode($response));
				}
			}
    }
    
    //Gets a Document by Key
    public function retrieveByKey( $collection, $key, $as_array=false ) {
        $r = $this->dbh->query( "SELECT * FROM ".$collection." WHERE _key='".$key."'" ); 
        $counter = 0;
        $response = '{ "'.$collection.'": [ ';
        
        while( $row = $r->fetchArray(SQLITE_ASSOC) ) {
            
            if( $counter > 0 ) {
    			$response .= ",";	
			}
			$counter++;
            $response .=  '{"key":"' . $row["_key"] . '", "last_modified":"' . $row["_modified"] . '", "data":' . stripslashes($row["_data"]) . '}';
        }
        
        $response .=  ']}';
        
        if( $as_array ) {
			return json_decode($response, true);
		} else {
			return $response;
		}
    }
	
	
	//Creates an empty collection if it does not exist
	public function addCollection( $collection ) {
		if ( $this->dbh ) {
			
			//$q = @$this->dbh->query( 'SELECT _key FROM '.$collection );
            $q = $this->dbh->query( "SELECT name FROM sqlite_master WHERE type='table' AND name='".$collection."'");
            $result = $q->fetchArray();
            
			if ( $result === false ) {
				$this->dbh->exec('CREATE TABLE '.$collection.' ( _id int, _key text, _modified int, _data text, PRIMARY KEY(_id ASC) )');
				$this->dbh->exec('CREATE INDEX '.$collection.'_idx ON '.$collection.' (_key)');
			}
			
		}	
	}
	
	
	//Deletes document with $key in $collection
	public function delete( $collection, $key ) {
		$this->dbh->exec( 'DELETE FROM '.$collection.' WHERE _key="'.$key.'"' );
        return $key;
	}
	
	public function emptyCollection( $collection ) {
		$this->dbh->exec( 'DROP TABLE IF EXISTS '.$collection );
		//addCollection( $collection );
	}
	
}
?>