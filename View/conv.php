<?php
	
	header( 'Content-Type: text/json;charset=UTF-8' );
	echo json_encode( array ( 
		$registry->messageType	=>  array(
			'message'	=> $registry->message,
		)
	), \JSON_PRETTY_PRINT );

?>