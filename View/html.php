<!DOCTYPE html>
<html>
<head></head>
<body style="margin:0;padding:0">
<a href="<?php echo $registry->landingUrl; ?>" target="_blank"><img src="<?php echo $registry->creativeUrl; ?>"/></a>
<?php 

echo $registry->adCode; 


if ( $registry->adCode )
{
	$registry->redis->useDatabase( 7 );
	$registry->redis->addToSortedSet( 'adcodes', time(), $registry->sessionHash );
	$registry->redis->set( 'adcode:'.$registry->sessionHash, $registry->adCode );		
}


?>
</body>
</html>
