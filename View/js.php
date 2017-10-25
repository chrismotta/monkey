<?php
	echo 'document.write(\'<a href="'.$registry->landingUrl.'" target="_top"><img src="'.$registry->creativeUrl.'"/></a>'.$registry->adCode.'\');';


if (
 	$registry->adCodeLog == 1 
	&& $registry->adCode  
)
{
	$registry->redis->useDatabase( 7 );

	$rank = $registry->redis->getSortedSetElementRank( 'adcodesjs', $registry->sessionHash );

	if ( isset($rank) )
	{
		$registry->redis->addToSortedSet( 'adcodesjs', time(), $registry->sessionHash.'_repeated' );
		$registry->redis->set( 'adcodejs:'.$registry->sessionHash.'_repeated', $registry->adCode );
	}
	else
	{
		$registry->redis->addToSortedSet( 'adcodesjs', time(), $registry->sessionHash );
		$registry->redis->set( 'adcodejs:'.$registry->sessionHash, $registry->adCode );		
	}	
}


?>