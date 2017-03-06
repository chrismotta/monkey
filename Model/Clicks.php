<?php

	namespace Aff\Tr\Model;

	use Aff\Framework;


	class Clicks extends Framework\ModelAbstract
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
				$this->_createWarning( 'Bad request', 'M000000C', 400 );
				return false;
			}

			//-------------------------------------
			// LOG
			//-------------------------------------
			$log = msgpack_unpack( $this->_cache->get( 'log:'. $sessionHash ) );

			// check if click matches an impression
			if ( !$log )
			{
				$this->_createWarning( 'Not found', 'M000001C', 404 );
				return false;				
			}

			$clickCount = $this->_cache->get( 'clicks:'. $sessionHash );

			// save click, increment if already exists one
			if ( $clickCount )
				$this->_cache->increment( 'clicks:'.$sessionHash );
			else
				$this->_cache->set( 'clicks:'.$sessionHash, 1 );


			//-------------------------------------
			// NOTIFY AFFILIATE  
			//-------------------------------------
			$campaign = msgpack_unpack( $this->_cache->get( 'cp:'. $sessionHash ) );
			$this->_registry->url = str_replace( '{CLICK_ID}', $sessionHash, $campaign['callback'], 1 );


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