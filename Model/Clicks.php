<?php

	namespace Aff\Ad\Model;

	use Aff\Framework;


	class Clicks extends Framework\ModelAbstract
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
			$this->_cache->useDatabase( $this->_getCurrentDatabase() );
			$campaignLog = $this->_cache->getMap( 'campaignlog:'. $click_id );

			if ( $campaignLog )
			{
				$this->_cache->setMapField( 'campaignlog:'. $click_id, 'click_time', $this->_registry->httpRequest->getTimestamp() );

				$this->_cache->addToSortedSet( 'clickids', $this->_registry->httpRequest->getTimestamp(), $click_id );

				$this->_cache->useDatabase( 0 );
				$callbackURL = $this->_cache->getMapField( 'campaign:'.$campaignLog['campaign_id'], 'callback' );

				//-------------------------------------
				// NOTIFY AFFILIATE  
				//-------------------------------------
	        	$httpClient 	   = new Framework\TCP\HTTP\Client\cURL();
				$httpClientRequest = new Framework\TCP\HTTP\Client\Request();

				$httpClientRequest->setURL( \str_replace( '{CLICK_ID}', $click_id, $callbackURL ) );

				$httpClient->send( $httpClientRequest );
			}



			//-------------------------------------
			// RENDER
			//-------------------------------------
			// Tell controller process completed successfully
			$this->_registry->status = 200;

			return true;
		}


		private function _getCurrentDatabase ( )
		{
			return \floor(($this->_registry->httpRequest->getTimestamp()/60/60/24))%2+1;
		}

	}

?>