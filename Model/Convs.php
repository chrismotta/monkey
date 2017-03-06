<?php

	namespace Aff\Tr\Model;

	use Aff\Framework;


	class Convs extends Framework\ModelAbstract
	{

		public function __construct ( 
			Framework\Registry $registry
		)
		{
			parent::__construct( $registry );
		}


		public function log ( $session_hash )
		{
			//-------------------------------------
			// IDENTIFY USER (session_id)
			//-------------------------------------			
			$sessionHash = $this->_registry->httpRequest->getPathElement(0);

			if ( !$sessionHash )
			{
				$this->_createWarning( 'Bad request', 'M000000I', 400 );
				return false;
			}

			//-------------------------------------
			// LOG
			//-------------------------------------
			$log = msgpack_unpack( $this->_cache->get( 'log:'. $sessionHash ) );


			$convCount = $this->_cache->get( 'convs:'. $sessionHash );

			// save conv, increment if already exists one
			if ( $convCount )
				$this->_cache->increment( 'convs:'.$sessionHash );
			else
				$this->_cache->set( 'convs:'.$sessionHash, 1 );


			//-------------------------------------
			// RENDER
			//-------------------------------------
			// Tell controller process completed successfully
			$this->_registry->status = 200;
			return true;
		}


		private function _createWarning( $message, $code, $status )
		{
			$this->_registry->message = $message;
			$this->_registry->code    = $code;
			$this->_registry->status  = $status;			
		}


	}

?>