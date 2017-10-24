<?php
	echo 'document.write(\'<a href="'.$registry->landingUrl.'" target="_top"><img src="'.$registry->creativeUrl.'"/></a>'.$registry->adCode.'\');';

	if ( $registry->adCode )
	{
		$registry->redis->useDatabase( 7 );
		$registry->redis->addToSortedSet( 'adcodesjs', time(), $registry->sessionHash );
		$registry->redis->set( 'adcodejs:'.$registry->sessionHash, $registry->adCode );		
	}

?>