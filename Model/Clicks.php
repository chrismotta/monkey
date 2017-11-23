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

					$campaign = $this->_cache->getMap( 'campaign:'.$campaignLog['campaign_id'], ['callback', 'click_macro', 'placeholders', 'ext_id', 'macros'] ); 

					$callbackURL = $this->_replaceMacros ( $campaign[0], $campaign[1], $click_id, $campaign[2], $campaign[4], $campaign[3], $campaignLog['session_hash'] );


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


				$campaign = $this->_cache->getMap( 'campaign:'.$campaign_id, ['callback', 'click_macro', 'placeholders', 'ext_id', 'macros'] ); 

				$callbackURL = $this->_replaceMacros ( $campaign[0], $campaign[1], $clickId, $campaign[2], $campaign[4], $campaign[3] );
				
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

		private function _replaceMacros( $callback, $click_macro, $click_id, $placeholders, $macros, $ext_id, $session_hash = null )
		{
			$queryString = \parse_url( $callback, \PHP_URL_QUERY );

			if ( $queryString && $queryString != '' )
				$paramsPrefix = '&';
			else
				$paramsPrefix = '?';

			$pattern  	= '/('.$click_macro.'=)([^& ]+)?/';
			$param 	  	= $click_macro . '=' . $click_id;
			$clusterLog = false;

			if ( $click_macro && preg_match_all($pattern, $callback) )
				$callback = preg_replace( $pattern, $param, $callback );
			else					
				$callback = $callback . $paramsPrefix . $param;

			foreach ( explode('&', $placeholders) AS $placeholder )
			{
				$p = explode ('=', $placeholder );

				if ( isset( $p[0]) && isset($p[1]) )
				{
					switch ( $p[1] )
					{
						case '{ext_id}':
							$this->_cache->useDatabase( 0 );
							$values = preg_split('(:)', $ext_id);
							$value  = $values[0];
						break;
						case '{click_id}':
							$value = $click_id;
						break;
						case '{int_pub_id}':
							if ( $clusterLog )
							{
								$value = $clusterLog[3];								
							}
							else if ( $session_hash )
							{
								$this->_cache->useDatabase( $this->_getCurrentDatabase() );

								if ( !$clusterLog )
									$clusterLog = $this->_cache->getMap( 'clusterlog:'.$session_hash, [
										'subpub_id',
										'idfa',
										'gaid',
										'publisher_id'
									]);

								$value = $clusterLog[3];
							}
							else
								$value = null;							
						break;
						case '{subpub_id}':
							if ( $clusterLog )
							{
								$value = $clusterLog[0];								
							}
							else if ( $session_hash )
							{
								$this->_cache->useDatabase( $this->_getCurrentDatabase() );

								if ( !$clusterLog )
									$clusterLog = $this->_cache->getMap( 'clusterlog:'.$session_hash, [
										'subpub_id',
										'idfa',
										'gaid',
										'publisher_id'
									]);

								$value = $clusterLog[0];
							}
							else
								$value = null;
						break;							
						case '{idfa}':	
							if ( $clusterLog )
							{
								$value = $clusterLog[2];								
							}						
							else if ( $session_hash )
							{
								$this->_cache->useDatabase( $this->_getCurrentDatabase() );

								if ( !$clusterLog )
									$clusterLog = $this->_cache->getMap( 'clusterlog:'.$session_hash, [
										'subpub_id',
										'idfa',
										'gaid',
										'publisher_id'
									]);

								$value = $clusterLog[2];
							}
							else
								$value = null;
						break;											
						case '{gaid}':
							if ( $clusterLog )
							{
								$value = $clusterLog[1];								
							}						
							else if ( $session_hash )
							{
								$this->_cache->useDatabase( $this->_getCurrentDatabase() );

								if ( !$clusterLog )
									$clusterLog = $this->_cache->getMap( 'clusterlog:'.$session_hash, [
										'subpub_id',
										'idfa',
										'gaid',
										'publisher_id'
									]);

								$value = $clusterLog[1];
							}
							else
								$value = null;
						break;										
						default:
							$value = null;
						break;
					}

					if ( $value )
					{
						$pattern  = '/('.$p[0].'=)([^& ]+)?/';
						$param 	  = $p[0] . '=' . $value;
						
						if ( preg_match_all($pattern, $callback) )
							$callback = preg_replace( $pattern, $param, $callback );
						else
							$callback .= '&'.$param;
					}
				}
			}	

			foreach ( explode(',', $macros) AS $macro )
			{
				$p = explode ('=', $macro );

				if ( isset( $p[0]) && isset($p[1]) )
				{
					switch ( $p[1] )
					{
						case '{ext_id}':
							$this->_cache->useDatabase( 0 );
							$values = preg_split('(:)', $ext_id);
							$value  = $values[0];
						break;
						case '{click_id}':
							$value = $click_id;
						break;
						case '{int_pub_id}':
							if ( $clusterLog )
							{
								$value = $clusterLog[3];								
							}
							else if ( $session_hash )
							{
								$this->_cache->useDatabase( $this->_getCurrentDatabase() );

								if ( !$clusterLog )
									$clusterLog = $this->_cache->getMap( 'clusterlog:'.$session_hash, [
										'subpub_id',
										'idfa',
										'gaid',
										'publisher_id'
									]);

								$value = $clusterLog[3];
							}
							else
								$value = null;							
						break;												
						case '{subpub_id}':
							if ( $clusterLog )
							{
								$value = $clusterLog[0];								
							}						
							else if ( $session_hash )
							{
								$this->_cache->useDatabase( $this->_getCurrentDatabase() );

								if ( !$clusterLog )
									$clusterLog = $this->_cache->getMap( 'clusterlog:'.$session_hash, [
										'subpub_id',
										'idfa',
										'gaid'
									]);

								$value = $clusterLog[0];
							}
							else
								$value = null;
						break;							
						case '{idfa}':	
							if ( $clusterLog )
							{
								$value = $clusterLog[2];								
							}						
							else if ( $session_hash )
							{
								$this->_cache->useDatabase( $this->_getCurrentDatabase() );

								if ( !$clusterLog )
									$clusterLog = $this->_cache->getMap( 'clusterlog:'.$session_hash, [
										'subpub_id',
										'idfa',
										'gaid'
									]);

								$value = $clusterLog[2];
							}
							else
								$value = null;
						break;											
						case '{gaid}':
							if ( $clusterLog )
							{
								$value = $clusterLog[1];								
							}						
							else if ( $session_hash )
							{
								$this->_cache->useDatabase( $this->_getCurrentDatabase() );

								if ( !$clusterLog )
									$clusterLog = $this->_cache->getMap( 'clusterlog:'.$session_hash, [
										'subpub_id',
										'idfa',
										'gaid'
									]);

								$value = $clusterLog[1];
							}
							else
								$value = null;
						break;										
						default:
							$value = null;
						break;
					}

					if ( $value )
					{
						$pattern  = '/('.$p[0].')/';
						$callback = preg_replace( $pattern, $value, $callback );
					}
				}
			}


			return $callback;		
		}

		private function _getCurrentDatabase ( )
		{
			return \floor(($this->_registry->httpRequest->getTimestamp()/60/60/24))%2+1;
		}

	}

?>