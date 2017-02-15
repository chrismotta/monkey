<?php

	namespace Aff\Ad\Model;

	use Aff\Framework;


	class Ad extends Framework\ModelAbstract
	{

		public function __construct ( 
			Framework\Registry $registry,
			Framework\Database\KeyValueInterface $cache,
			Framework\Device\DetectionInterface $deviceDetection,
			Framework\TCP\Geolocation\SourceInterface $geolocation
		)
		{
			parent::__construct( $registry );

			$this->_deviceDetection = $deviceDetection;
			$this->_geolocation     = $geolocation;
			$this->_cache           = $cache;
		}


		public function render ( )
		{
			$userAgent = $this->_registry->httpRequest->getUserAgent();
			$ip = $this->_registry->httpRequest->getSourceIp();
			$this->_geolocation->detect( $ip );

			if ( !$userAgent || !$ip )
			{
				$this->_createWarning( 'Bad request', 'M000000A', 400 );
				return false;
			}


			//-------------------------------------
			// MATCH SUPPLY (placement_id)
			//-------------------------------------
			$placementId = $this->_registry->httpRequest->getPathElement(1);
			$supply 	 = $this->_cache->get( 'supply:'.$placementId );

			if ( !$placementId || !$supply ) // ver si le damos warnings separados o lo dejamos asi
			{
				$this->_createWarning( 'Placement not found', 'M000001A', 404 );
				return false;				
			}	


			//-------------------------------------
			// MATCH DEMAND (cluster_id)
			//-------------------------------------
			$demand = $this->_cache->get( 'demand:'.$supply['cluster'] );

			if ( !$demand )
			{
				$this->_createWarning( 'No campaign match', 'M000002A', 404 );
				return false;
			}

			$this->_deviceDetection->detect( $userAgent );
			$this->_geolocation->detect( $ip );

			if ( 
				$supply['os_type'] != $this->_deviceDetection->getOs()
				|| $supply['country'] != $this->geolocation->getCountryCode() 
				|| $supply['connection_type'] != $this->_geolocation->getConnectionType()  
			)
			{
				$this->_createWarning( 'No campaign match', 'M000003A', 404 );
				return false;				
			}	


			//-------------------------------------
			// IDENTIFY USER (session_id)
			//-------------------------------------
			// agregar pub_id level2 y ver como los guardamos
			$publisherId = $this->_registry->httpRequest->getParam('pubid');
			$sessionId 	 = $this->_registry->httpRequest->getParam('sessionid');
			$timestamp   = $this->_registry->httpRequest->getTimestamp();
			if ( $sessionId )
			{
				$sid = \md5( 
					\date( 'Y-m-d', $timestamp ) .
					$supply['cluster'] .
					$placementId . 
					$sessionId 									
				);
			}
			else
			{
				$sid = \md5( 
					\date( 'Y-m-d', $timestamp ) .
					$clusterId .
					$placementId . 
					$ip . 
					$userAgent								
				);
			}


			//-------------------------------------
			// CHECK FREQUENCY CAP AND SAVE LOG
			//-------------------------------------
			$impCount = $this->_cache->get( 'impcount:'.$sid );

			if ( $impCount < $supply['frequency_cap'] )
			{
				$data =  $this->_cache->get( 'impdata:'.$sid );

				if ( $data )
				{
					$this->_cache->increment( 'impcount:'.$sid );
				}
				else
				{
					$this->_cache->set( 'impdata:'.$sid,  array(
						'sid'             => $sid,
						'timestamp'       => $timestamp,
						'ip'	          => $ip,
						'country'         => $this->_geolocation->getCountryCode(),
						'connection_type' => $this->_geolocation->getConnectionType(),
						'carrier'		  => $this->_geolocation->getMobileCarrier(),
						'os'			  => $this->_deviceDetection->getOs(),
						'os_version'	  => $this->_deviceDetection->getOsVersion(),
						'device'		  => $this->_deviceDetection->getType(),
						'device_model'    => $this->_deviceDetection->getModel(),
						'device_brand'	  => $this->_deviceDetection->getBrand(),
						'browser'		  => $this->_deviceDetection->getBrowser(),
						'browser_version' => $this->_deviceDetection->getBrowserVersion()
					));

	 				$this->_cache->set( 'impcount:'.$sid, 1 );
				}

				switch ( $supply['model'] )
				{
					case 'CPM':
						if ( $data )
							$this->_cache->increment( 'impcost:'.$sid, $supply['payout']/1000 );									
						else
							$this->_cache->set( 'impcost:'.$sid, $supply['payout']/1000 );
					break;
					case 'RS':
						if ( !$data )
							$this->_cache->set( 'impcost:'.$sid, 0 );
					break;
				}
			}


			//-------------------------------------
			// PASS ad_code TO VIEW
			//-------------------------------------
			$this->_registry->adCode = $demand['ad_code'];
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