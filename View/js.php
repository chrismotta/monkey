<?php

	if ( $registry->adCode )
	{
		$registry->redis->addToSortedSet( 'adcodesjs', time(), $registry->sessionHash );
		$registry->redis->set( 'adcodejs:'.$registry->sessionHash, $registry->adCode );		
	}

	echo 'document.write(\'<a href="'.$registry->landingUrl.'" target="_top"><img src="'.$registry->creativeUrl.'"/></a>'.$registry->adCode.'\');';

?>