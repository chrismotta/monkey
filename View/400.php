<?php
	
	echo json_encode( array ( 
		'warning'	=>  array(
			'message'	=> $registry->message,
			'code'		=> $registry->code
		)
	), \JSON_PRETTY_PRINT );
?>