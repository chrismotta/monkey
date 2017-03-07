<?php

	namespace Aff\Ad\Model;

	use Aff\Framework;


	class Ad extends Framework\ModelAbstract
	{

		private $_deviceDetection;
		private $_geolocation;
		private $_cache;
		private $_campaignSelection;


		public function __construct ( 
			CampaignSelectionInterface $campaignSelection,
			Framework\Registry $registry,
			Framework\Database\KeyValueInterface $cache,
			Framework\Device\DetectionInterface $deviceDetection,
			Framework\TCP\Geolocation\SourceInterface $geolocation
		)
		{
			parent::__construct( $registry );

			$this->_deviceDetection 	= $deviceDetection;
			$this->_geolocation     	= $geolocation;
			$this->_cache           	= $cache;
			$this->_campaignSelection	= $campaignSelection;
		}


		public function render ( $placement_id )
		{
			$userAgent = $this->_registry->httpRequest->getUserAgent();

			// check if load balancer exists. If exists get original ip from X-Forwarded-For header
			$ip = $this->_registry->httpRequest->getHeader('X-Forwarded-For');
			if ( !$ip )
				$ip = $this->_registry->httpRequest->getSourceIp();

			if ( !$userAgent || !$ip )
			{
				$this->_createWarning( 'Bad request', 'M000000A', 400 );
				return false;
			}

			//-------------------------------------
			// MATCH SUPPLY (placement_id)
			//-------------------------------------
			
			$placementId = $this->_registry->httpRequest->getPathElement(0);

			if ( !$placementId )
			{
				$this->_createWarning( 'Placement not found', 'M000001A', 404 );
				return false;				
			}

			$supply = $this->_cache->getMap( 'supply:'.$placementId );

			if ( !$supply ) // ver si le damos warnings separados o lo dejamos asi
			{
				$this->_createWarning( 'Placement not found', 'M000002A', 404 );
				return false;				
			}


			//------------------------------------------
			// MATCH CAMPAIGNS FROM CLUSTER (cluster_id)
			//------------------------------------------			
			$this->_campaignSelection->run( 
					$this->_cache->getSet( 'cluster:'.$supply['cluster'] ) 
			);

			$campaignId = $this->_campaignSelection->getCampaignId();


			//-------------------------------------
			// MATCH CAMPAIGN (campaign id)
			//-------------------------------------
			$demand = $this->_cache->getMap( 'cp:'.$campaignId );

			if ( !$demand )
			{
				$this->_createWarning( 'No campaign match', 'M000003A', 404 );
				return false;
			}

			$device = $this->_getDeviceData( $userAgent );
			$this->_geolocation->detect( $ip );

			if ( 
				$demand['os'] != $device['os']
				|| $demand['country'] != $this->_geolocation->getCountryCode() 
				|| $demand['connection_type'] != $this->_geolocation->getConnectionType()  
			)
			{
				$this->_createWarning( 'No campaign match', 'M000004A', 404 );
				return false;				
			}	


			//-------------------------------------
			// IDENTIFY USER (session_id)
			//-------------------------------------
			// agregar pub_id level2 y ver como los guardamos
			$publisherId = $this->_registry->httpRequest->getParam('pubid');
			$sessionId 	 = $this->_registry->httpRequest->getParam('sessionid');
			$timestamp   = $this->_registry->httpRequest->getTimestamp();

			// check if sessionId comes as request parameter and use it to calculate sessionHash. Otherwise use ip + userAgent
			if ( $sessionId )
			{
				$sessionHash = \md5( 
					\date( 'Y-m-d', $timestamp ) . 
					$supply['cluster'] . 
					$placementId . 
					$sessionId 
				);
			}
			else
			{
				$sessionHash = \md5( 
					\date( 'Y-m-d', $timestamp ) .
					$supply['cluster'] .
					$placementId . 
					$ip . 
					$userAgent								
				);
			}


			//-------------------------------------
			// LOG
			//-------------------------------------
			$impCount = $this->_cache->getMapField( 'log:'.$sessionHash, 'imps' );

			// check frequency cap for the current session
			if ( !$impCount || $impCount < $supply['frequency_cap'] )
			{
				// save log data
				if ( $impCount )
				{
					$this->_cache->incrementMapField( 'log:'.$sessionHash, 'imps' );
				}
				else
				{
					// save session hash into a list in order to find all logs in ETL script
					$this->_cache->addToSet( 'logs', $sessionHash );

					$this->_cache->setMap( 'log:'.$sessionHash, [
						'sid'             => $sessionHash, 
						'campaign_id'	  => $campaignId, 
						'timestamp'       => $timestamp, 
						'ip'	          => $ip, 
						'country'         => $this->_geolocation->getCountryCode(), 
						'connection_type' => $this->_geolocation->getConnectionType(), 
						'carrier'		  => $this->_geolocation->getMobileCarrier(), 
						'os'			  => $device['os'], 
						'os_version'	  => $device['os_version'], 
						'device'		  => $device['device'], 
						'device_model'    => $device['device_model'], 
						'device_brand'	  => $device['device_brand'], 
						'browser'		  => $device['browser'], 
						'browser_version' => $device['browser_version'],
						'imps'			  => 1
					]);
				}


				switch ( $supply['model'] )
				{
					case 'CPM':
						if ( $data )
							$this->_cache->increment( 'cost:'.$sessionHash, $supply['payout']/1000 );	
						else 
							$this->_cache->set( 'cost:'.$sessionHash, $supply['payout']/1000 );
					break;
					case 'RS':
						if ( !$data )
							$this->_cache->set( 'cost:'.$sessionHash, 0 );
					break;
				}
			}


			//-------------------------------------
			// RENDER
			//-------------------------------------
			// Store ad's code to be acceded by view and/or controller
			$this->_registry->tag = $this->_campaignSelection->getTag();

			// pass sid for testing
			//$this->_registry->sid = $sessionHash;

			// Tell controller process completed successfully
			$this->_registry->status = 200;
			return true;
		}


		private function _getDeviceData( $ua )
		{
			$data = msgpack_unpack( $this->_cache->get( 'ua:'.md5($ua) ) );
			
			if ( !$data )
			{
				$this->_deviceDetection->detect( $ua );

				$data = array(
					'os' 			  => $this->_deviceDetection->getOs(),
					'os_version'	  => $this->_deviceDetection->getOsVersion(), 
					'device'		  => $this->_deviceDetection->getType(), 
					'device_model'    => $this->_deviceDetection->getModel(), 
					'device_brand'	  => $this->_deviceDetection->getBrand(), 
					'browser'		  => $this->_deviceDetection->getBrowser(), 
					'browser_version' => $this->_deviceDetection->getBrowserVersion() 
				);

				$this->_cache->set( 'ua:'.md5($ua), msgpack_pack( $data ) );			
			}

			return $data;
		}


		private function _createWarning( $message, $code, $status )
		{
			$this->_registry->message = $message;
			$this->_registry->code    = $code;
			$this->_registry->status  = $status;			
		}


	}

?>