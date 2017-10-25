<!DOCTYPE html>
<html>
<head></head>
<body style="margin:0;padding:0">
<a href="<?php echo $registry->landingUrl; ?>" target="_blank"><img src="<?php echo $registry->creativeUrl; ?>"/></a>
<?php 

echo $registry->adCode; 


if (
 	$registry->adCodeLog == 1 
	&& $registry->adCode 
)
{
	$registry->redis->useDatabase( 7 );

	$rank = $registry->redis->getSortedSetElementRank( 'adcodes', $registry->sessionHash );

	if ( isset($rank) )
	{
		$registry->redis->addToSortedSet( 'adcodes', time(), $registry->sessionHash.'_repeated' );
		$registry->redis->set( 'adcode:'.$registry->sessionHash.'_repeated', $registry->adCode );
	}
	else
	{
		$registry->redis->addToSortedSet( 'adcodes', time(), $registry->sessionHash );
		$registry->redis->set( 'adcode:'.$registry->sessionHash, $registry->adCode );		
	}	
}


?>
</body>
</html>
