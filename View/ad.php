<?php
	
	header( 'Content-Type: text/json' );
	echo json_encode( array ( 
		'success'	=>  array(
			'ad_code'	=> $registry->adCode,
			'sid'		=> $registry->sid
		)
	) );

?>