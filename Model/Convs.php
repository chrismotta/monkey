<?php

	namespace Aff\Ad\Model;

	use Aff\Framework;


	class Convs extends Framework\ModelAbstract
	{

		private $_cache;

		public function __construct ( 
			Framework\Registry $registry,
			Framework\Database\KeyValueInterface $cache
		)
		{
			parent::__construct( $registry );

			$this->_cache = $cache;
		}


		public function log ( $click_id )
		{
			//-------------------------------------
			// LOG
			//-------------------------------------
			if ( $click_id )
			{
				if ( substr( $click_id, 0, 5 ) === "test_" )
				{
					$this->_cache->useDatabase( 8 );
				}
				else
				{
					$this->_cache->useDatabase( $this->_getCurrentDatabase() );
				}
				

				$this->_cache->addToSortedSet( 'convs', $this->_registry->httpRequest->getTimestamp(), $click_id  );


				if ( $this->_cache->exists('conv:'. $click_id) )
				{
					$this->_registry->message 	  = 'Conversion already registered';
					$this->_registry->messageType = 'warning';
					$this->_registry->code        = 'exists';
					$this->_registry->status      = 400;
				}
				else
				{
					$this->_cache->set( 'conv:'. $click_id, $this->_registry->httpRequest->getTimestamp() );

					$this->_registry->message 	  = 'Conversion tracked';
					$this->_registry->messageType = 'success';
					$this->_registry->status      = 200;
				}
			}
			else
			{
				$this->_registry->message 	  = 'Click not matched';
				$this->_registry->messageType = 'warning';
				$this->_registry->code        = 'no_match';	
				$this->_registry->status      = 404;			
			}

			return true;
		}


		private function _getCurrentDatabase ( )
		{
			return \floor(($this->_registry->httpRequest->getTimestamp()/60/60/24))%2+3;
		}

	}

?>