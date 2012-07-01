<?php

$DBNAME = "mydatabase";
$DIR = "./db/";
$COLLECTION = "users";


//STORE
$data = array( name=>"Andrew", email=>"andrew@someemail.com" );
$data2 = array( name=>"Joe", email=>"joe@someemail.com" );

$raccoon = new RaccoonDB( $DBNAME, $DIR ); //creates a database if not already defined
$response = $raccoon->store( $COLLECTION, $data ); //stores some data
$response = $raccoon->store( $COLLECTION, $data2 );
$raccoon->close();

//RETRIEVE
$raccoon = new RaccoonDB( $DBNAME, $DIR ); //creates a database if not already defined
$response = $raccoon->retrieve( $COLLECTION ); //stores some data
$raccoon->close();

//output as JSON
header('Content-type: application/json');
echo $response;	

//RETRIEVE WITH FILTER
$filter = array( name=>"A" ); //get all the things with name starting with "A"

$raccoon = new RaccoonDB( $DBNAME, $DIR ); //creates a database if not already defined
$response = $raccoon->retrieve( $COLLECTION, $filter ); //stores some data
$raccoon->close();

//output as JSON
header('Content-type: application/json');
echo $response;	

//Super Extra Bonus! Pass in $_GET as your filter to build a quick rest api!

?>