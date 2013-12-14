 <link rel="stylesheet" href="css/style.css"></link>
<?php
require 'aws/aws-autoloader.php';

use Aws\Common\Aws;


/**
 * Convert bytes to human readable format
 *
 * @param integer bytes Size in bytes to convert
 * @return string
 */
function bytesToSize($bytes, $precision = 2)
{  
    $kilobyte = 1024;
    $megabyte = $kilobyte * 1024;
    $gigabyte = $megabyte * 1024;
    $terabyte = $gigabyte * 1024;
   
    if (($bytes >= 0) && ($bytes < $kilobyte)) {
        return $bytes . ' B';
 
    } elseif (($bytes >= $kilobyte) && ($bytes < $megabyte)) {
        return round($bytes / $kilobyte, $precision) . ' KB';
 
    } elseif (($bytes >= $megabyte) && ($bytes < $gigabyte)) {
        return round($bytes / $megabyte, $precision) . ' MB';
 
    } elseif (($bytes >= $gigabyte) && ($bytes < $terabyte)) {
        return round($bytes / $gigabyte, $precision) . ' GB';
 
    } elseif ($bytes >= $terabyte) {
        return round($bytes / $terabyte, $precision) . ' TB';
    } else {
        return $bytes . ' B';
    }
}

function is_public($s3, $bucket, $key){
    $all_users = array('URI' => 'http://acs.amazonaws.com/groups/global/AllUsers');

	$grants = $s3->getObjectAcl(array('Bucket' => $bucket, 'Key' => $key))->get('Grants');

  	$public_read = false;
  	foreach($grants as $grant) {
    $is_all_users = $grant['Grantee'] == $all_users;
    $is_read = $grant['Permission'] == 'READ';
    if( $is_all_users && $is_read ) $public_read = true;
  }
  return $public_read;
}

// Create a service builder using a configuration file
$aws = Aws::factory('config/credentials.json');
// Get the client from the builder by namespace
$client = $aws->get('S3');

$result = $client->listBuckets();

$owner = $result->getPath('Owner');
echo "<div id=\"content\">";
//if(isset($argv[1])){$_GET['path'] = $argv[1];}
if(!isset($_GET['path'])){
	echo "Hello {$owner['DisplayName']}, below are your buckets<br/><br/>";
	
	echo "<ul id=\"list\">";
	foreach ($result['Buckets'] as $bucket) {
    //Each Bucket value will contain a Name and CreationDate
    $date = explode('T',$bucket['CreationDate']);
    $time = substr($date[1],0,8);
    $date = $date[0];

    printf("<li class=\"litem\"><span class=\"item\"><a href=\"index.php?path={$bucket['Name']}\">{$bucket['Name']}</a></span><span class=\"dtime\">created on $date at $time</span></li>");
	}
	echo "</ul>";

}else{
	$path = $_GET['path'];
	
	echo "Path: $path<br/>";
	$tmp = explode('/',trim($path));
	$bucket = $tmp[0];


	$item = $tmp[count($tmp)-1];
	$parent = (count($tmp) >= 2)?join("/",array_slice($tmp,0,count($tmp)-1)):'/';
	//var_dump($parent);
	if($parent == '/'){
		echo "<a href=\"index.php\">..</a><br/>";
	}else{
		echo "<a href=\"index.php?path=$parent\">..</a><br/>";
	}

	if(isset($_GET['action'])){
		if($_GET['action'] == 'publish'){
			$client->putObjectAcl(['ACL' => 'public-read','Bucket' => $bucket,'Key' => join("/",array_slice($tmp,1,count($tmp)-1))]);
			header("Location: index.php?path=$parent");
		}
		if($_GET['action'] == 'unpublish'){
			$client->putObjectAcl(['ACL' => 'private','Bucket' => $bucket,'Key' => join("/",array_slice($tmp,1,count($tmp)-1))]);
			header("Location: index.php?path=$parent");
		}
	}

	$clean_path = join('/', array_slice($tmp, 1));
	//var_dump($clean_path) . "<br/>";
	
	if($path == $bucket){
	$iterator = $client->getIterator('ListObjects', array(
    	'Bucket' => $bucket
		));
	}
	else{
	$iterator = $client->getIterator('ListObjects', array(
    	'Bucket' => $bucket,
    	//'Delimiter' => '/',
    	'Prefix' => $clean_path
		),array('limit' => 100,'page_size' => 10));
	}

//var_dump(iterator_to_array($iterator));echo "<br/>";
$list = array();
$total = 0;
$klist = array();
foreach ($iterator as $object) {
	$objkey['Key'] = $object['Key'];
	$klist[] = $objkey;
	$size = $object['Size'];
	$total += $size;
	$acl = is_public($client, $bucket, $object['Key']);
	//echo $acl . "<br/>";
	
	$npath = trim($object['Key']," \t\n\r\0\x0B/");
    $npath = explode('/',$npath);
    //$sub = explode($clean_path,$object['Key']);
    //echo $object['Key'] . "<br/>";

    //var_dump($object);echo "<br/>";

    //echo $object['Key'] . "<br/>";
    //echo $access . "<br/>";
    //continue;

    if($path == $bucket){
    	//echo $object['Key'] . "<br/>";
    	//echo $object['Key'] . "<br/>";
    	//echo '['.$path . "]<br/>";
    	//echo '['.$npath[1] . "]<br/>";
    	//if(!empty($npath[1])){continue;}
    	//if(count($npath) > 1){continue;}
    	//echo $object['Key'] . "<br/>";
    	//$object['access'] = $access;
    	//$object['Key'] = $npath[1];
    	$list[$npath[0]] = $object;

    	$list[$npath[0]]['public'] = $acl;
    	//$list[$object['Key']]['obj'] = $object['Key'];
	}else{
		//echo $object['Key'] . "<br/>";
		$sub = explode($clean_path . '/',$object['Key']);
		//echo $clean_path ."<br/>";
		$sub = $sub[1];
		if(empty($sub)){continue;}
		$sub = explode('/', $sub);
		$sub = $sub[0];
		//echo $sub . "<br/>";
		//$list[] = $sub;
		$list[$sub] = $object;
		$list[$sub]['public'] = $acl;
		
		//var_dump($list[$sub]);
		//$list[$sub]['Key'] = $object;
		//$list[$object['Key']]['access'] = $access;
	}

}

if(isset($_GET['action']) and $_GET['action'] == 'empty' and !empty($klist)){
	//var_dump($klist);
	$result = $client->deleteObjects(array('Bucket' => $bucket, 'Objects' => $klist, 'Quiet' => true));
	header("Location: index.php?path=$path");
}
//die;

if(isset($total)){
	$total = bytesToSize($total);
	echo  "<br/>Folder Size: $total";
}

if(!empty($klist)){
	//var_dump($klist);
	echo  " <a href=\"index.php?path=$path&action=empty\">(Delete Folder Contents)</a><br/><br/>";	
}else{
	echo  "<br/><br/>";	
}

foreach ($list as $key2 => $item2) {
	//die;
	//echo substr($item, strlen($item)-1) . "<br/>";
	
	//var_dump($item2);echo "<br/>";
	$key = $key2;
	$item = $item2['Key'];
	//if(substr($item, strlen($item)-1) == '/'){$item = substr($item, 0, strlen($item)-1);}
	if(substr($key, strlen($key)-1) == '/'){$key = substr($key, 0, strlen($key)-1);}
	$last = substr(strrchr(trim($bucket.'/'.$item), "/"), 1);
	//echo $last . "<br/>";
	//echo $key . "<br/>";
	//echo $item . "<br/>";
	if($last == $key){//is the actual file so download
	$size = bytesToSize($item2['Size']);
	$size = ($size == "0 B")?'':$size;
	$url = $client->getObjectUrl($bucket, $item);
	//$torrent = $client->getObjectTorrent(array('Bucket' => $bucket, 'Key' => $item));
	//var_dump($torrent);
	$action = ($item2['public'])?'unpublish':'publish';
	$text = ucfirst($action);
	if($item2['public']){
		echo  "<a href=\"$url\">$key</a> - $size - <a href=\"index.php?path=$bucket/$item&action=$action\">$text</a> - <a href=\"$url?torrent\">Torrent</a><br/>";
	}else{
		echo  "$key - $size - <a href=\"index.php?path=$bucket/$item&action=$action\">$text</a><br/>";
}
	//echo  "<a href=\"$url\">$key</a> - $size<br/>";

	}else{
		$url = str_replace('//', '/', "$bucket/$clean_path/$key");

		echo  "<a href=\"index.php?path=$url\">$key</a><br/>";
	}
	
}

}

echo "</div>";

?>