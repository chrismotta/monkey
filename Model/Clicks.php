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
				$callbackURL = $this->_cache->getMapField( 'campaign:'.$campaignLog['campaign_id'], 'callback' ) . '&aff_sub='.$click_id;

				//-------------------------------------
				// NOTIFY AFFILIATE  
				//-------------------------------------
	        	$httpClient 	   = new Framework\TCP\HTTP\Client\cURL();
				$httpClientRequest = new Framework\TCP\HTTP\Client\Request();

				$httpClientRequest->setURL( $callbackURL );

				$httpClient->send( $httpClientRequest );
			}



			//-------------------------------------
			// RENDER
			//-------------------------------------
			// Tell controller process completed successfully
			$this->_registry->status = 200;

			return true;
		}

		public function test ( $campaign_id )
		{
			//-------------------------------------
			// LOG
			//-------------------------------------
			if ( $this->_cache->exists('campaign:'.$campaign_id) )
			{
				$clickId = \md5( $campaign_id.'test' );

				$callbackURL = $this->_cache->getMapField( 'campaign:'.$campaign_id, 'callback' ) . '&aff_sub='.$clickId;

				$this->_cache->useDatabase( $this->_getCurrentDatabase() );

				$this->_cache->addToSortedSet( 'clickids', 
					$this->_registry->httpRequest->getTimestamp(), 
					$clickId 
				);

				// write campaign log
				$this->_cache->setMap( 'campaignlog:'.$clickId, [
					'session_hash'    => 'test', 
					'campaign_id'	  => $campaign_id, 
					'click_time'      => $this->_registry->httpRequest->getTimestamp() 
				]);		

				header('Location: '. $callbackURL );
			}
			else
			{
				die('Campaign not found');
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