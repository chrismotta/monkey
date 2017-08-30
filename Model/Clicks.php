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


		public function pixel ( $click_id )
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

		public function log ( $click_id )
		{
			//-------------------------------------
			// LOG
			//-------------------------------------
			$this->_cache->useDatabase( $this->_getCurrentDatabase() );

			$campaignLog = $this->_cache->getMap( 'campaignlog:'. $click_id );

			if ( $campaignLog )
			{
				if ( $campaignLog['click_time'] && $campaignLog['click_time']!='' )
				{
					$this->_registry->status  = 400;
					$this->_registry->message = 'Click already done';
					$this->_registry->code    = 'exists';
				}
				else
				{
					$this->_cache->setMapField( 'campaignlog:'. $click_id, 'click_time', $this->_registry->httpRequest->getTimestamp() );

					$this->_cache->addToSortedSet( 'clickids', $this->_registry->httpRequest->getTimestamp(), $click_id );

					$this->_cache->useDatabase( 0 );

					$campaign = $this->_cache->getMap( 'campaign:'.$campaignLog['campaign_id'], ['callback', 'click_macro'] ); 

					$queryString = \parse_url( $campaign[0], \PHP_URL_QUERY );

					if ( $queryString && $queryString != '' )
						$paramsPrefix = '&';
					else
						$paramsPrefix = '?';

					$pattern  = '/('.$campaign[1].'=)[^& ]+/';
					$param 	  = $campaign[1] . '=' . $click_id;

					if ( preg_match_all($pattern, $campaign[0]) )
						$callbackURL = preg_replace( $pattern, $param, $campaign[0] );
					else					
						$callbackURL = $campaign[0] . $paramsPrefix . $param;

					header('Location: '. $callbackURL );
					exit();
				}
			}
			else
			{
				$this->_registry->status  = 404;
				$this->_registry->message = 'Click ID not found';
				$this->_registry->code    = 'not_found';
			}

			//-------------------------------------
			// RENDER
			//-------------------------------------
			// Tell controller process completed successfully
			return true;
		}


		public function test ( $campaign_id )
		{
			//-------------------------------------
			// LOG
			//-------------------------------------
			if ( $this->_cache->exists('campaign:'.$campaign_id) )
			{
				$clickId = 'test_'.\md5( $campaign_id.$this->_registry->httpRequest->getTimestamp() );


				$campaign = $this->_cache->getMap( 'campaign:'.$campaign_id, ['callback', 'click_macro'] ); 

				$queryString = \parse_url( $campaign[0], \PHP_URL_QUERY );

				if ( $queryString && $queryString != '' )
					$paramsPrefix = '&';
				else
					$paramsPrefix = '?';

				$pattern  = '/('.$campaign[1].'=)[^& ]+/';
				$param 	  = $campaign[1] . '=' . $clickId;

				if ( preg_match_all($pattern, $campaign[0]) )
					$callbackURL = preg_replace( $pattern, $param, $campaign[0] );
				else					
					$callbackURL = $campaign[0] . $paramsPrefix . $param;

				$this->_cache->useDatabase( 8 );

				// write campaign log
				$this->_cache->setMap( 'campaignlog:'.$clickId, [
					'session_hash'    => 'test', 
					'campaign_id'	  => $campaign_id, 
					'click_time'      => $this->_registry->httpRequest->getTimestamp() 
				]);		

				$this->_cache->addToSortedSet( 'testclickids', 
					$this->_registry->httpRequest->getTimestamp(), 
					$clickId 
				);
				echo $callbackURL;die();
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