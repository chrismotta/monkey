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


		public function getPostbackRules ( )
		{
			// index: pub_id, value: postback URL, macros: {click_id}

			return [
				'MP_Songo' => 'http://pixel.externalapi.com/pixel?pixelId=ff80808160dee1f80160e03f72a90151&clickId={click_id}'
			];
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

					$campaignLog = $this->_cache->getMap( 'campaignlog:'.$click_id );

					if ( $campaignLog && $campaignLog['campaign_id'] )
					{
						$this->_cache->incrementSortedSetElementScore( 'campaignconvs'.$this->_dateShort, $campaignLog['campaign_id'] );

						$clusterLog = $this->_cache->getMap( 'clusterlog:'.$campaignLog['session_hash'] );

						if ( $clusterLog && $clusterLog['pub_id'] )
						{
							$pub_id 	   = $clusterLog['pub_id'];
							$postbackRules = $this->getPostbackRules();

							if ( isset( $postbackRules[$pub_id] ) )
							{
								$postbackURL = $postbackRules[$pub_id];
								$postbackURL = preg_replace( '({click_id})', $click_id, $postbackURL );

								//-------------------------------------
								// NOTIFY PUB
								//-------------------------------------
					        	$httpClient 	   = new Framework\TCP\HTTP\Client\cURL();
								$httpClientRequest = new Framework\TCP\HTTP\Client\Request();

								$httpClientRequest->setURL( $postbackURL );

								$response = $httpClient->send( $httpClientRequest );

								echo $postbackURL.'<hr>';
								var_dump($response);
							}
						}
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