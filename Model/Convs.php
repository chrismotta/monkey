<?php

	namespace Aff\Ad\Model;

	use Aff\Framework;


	class Convs extends Framework\ModelAbstract
	{

		private $_cache;
		private $_dateShort;

		public function __construct ( 
			Framework\Registry $registry,
			Framework\Database\KeyValueInterface $cache
		)
		{
			parent::__construct( $registry );

			$this->_cache 	   = $cache;
			$this->_dateShort  = \date('Ymd');
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

				if ( $this->_cache->exists('conv:'. $click_id) )
				{						
					$this->_registry->message 	  = 'Conversion already registered';
					$this->_registry->messageType = 'warning';
					$this->_registry->code        = 'exists';
					$this->_registry->status      = 400;
				}
				else
				{			
					$this->_cache->addToSortedSet( 'convs', $this->_registry->httpRequest->getTimestamp(), $click_id  );							

					$this->_cache->set( 'conv:'. $click_id, $this->_registry->httpRequest->getTimestamp() );

					// increment campaign daily convs used for daily cap calc in the request

					$this->_cache->useDatabase( $this->_getTrafficDatabase() );

					$campaignId = $this->_cache->getMapField( 'campaignlog:'.$click_id, 'campaign_id' );

					if ( $campaignId )
					{										
						$this->_cache->incrementSortedSetElementScore( 'campaignconvs'.$this->_dateShort, $campaignId );						
					}

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

		private function _getTrafficDatabase ( )
		{
			return \floor(($this->_registry->httpRequest->getTimestamp()/60/60/24))%2+1;
		}		

	}

?>