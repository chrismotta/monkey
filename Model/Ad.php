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


			if ( !$userAgent || !$ip )
			{
				$this->_createWarning( 'Bad request', 'M000000A', 400 );
				return false;
			}

			//-------------------------------------
			// ADD TEST DATA
			//-------------------------------------			

			//$this->_deviceDetection->detect( $userAgent );
			/*
			$this->_geolocation->detect( $ip );

			$this->_cache->set( 'supply:2',  msgpack_pack( array(
				'frequency_cap'	  => 20,
				'payout'		  => 5,
				'model'			  => 'CPM',
				'cluster'		  => 10
			)));

			$this->_cache->set( 'demand:10',  msgpack_pack( array(
				'ad_code'		  => 100,
				'country'		  => $this->_geolocation->getCountryCode(),
				'connection_type' => $this->_geolocation->getConnectionType(),
				'carrier'		  => $this->_geolocation->getMobileCarrier(),
				'os'			  => 'asasdasdasdasd' // $this->_deviceDetection->getOs()
			)));
			*/

			//-------------------------------------
			// MATCH SUPPLY (placement_id)
			//-------------------------------------
			$placementId = $this->_registry->httpRequest->getPathElement(0);
			$supply 	 = msgpack_unpack( $this->_cache->get( 'supply:'.$placementId ) );

			if ( !$placementId || !$supply ) // ver si le damos warnings separados o lo dejamos asi
			{
				$this->_createWarning( 'Placement not found', 'M000001A', 404 );
				return false;				
			}


			//-------------------------------------
			// MATCH DEMAND (cluster_id)
			//-------------------------------------
			$demand = msgpack_unpack( $this->_cache->get( 'demand:'.$supply['cluster'] ) );

			if ( !$demand )
			{
				$this->_createWarning( 'No campaign match', 'M000002A', 404 );
				return false;
			}

			//$this->_deviceDetection->detect( $userAgent );
			$this->_geolocation->detect( $ip );

			if ( 
				$demand['os'] != 'asasdasdasdasd'/*$this->_deviceDetection->getOs()*/
				|| $demand['country'] != $this->_geolocation->getCountryCode() 
				|| $demand['connection_type'] != $this->_geolocation->getConnectionType()  
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
			$impCount = $this->_cache->get( 'impcount:'.$sessionHash );

			// check frequency cap for the current session
			if ( $impCount < $supply['frequency_cap'] )
			{
				// save log data
				$data =  $this->_cache->get( 'impdata:'.$sessionHash );

				if ( $data )
				{
					$this->_cache->increment( 'impcount:'.$sessionHash );
				}
				else
				{
					// investigar algo como $this->_cache->addtolist( 'impdata', $sessionHash ) para  traer data desde el ETL;
					$this->_cache->set( 'impdata:'.$sessionHash,  msgpack_pack( array(
						'sid'             => $sessionHash,
						'timestamp'       => $timestamp,
						'ip'	          => $ip,
						'country'         => $this->_geolocation->getCountryCode(),
						'connection_type' => $this->_geolocation->getConnectionType(),
						'carrier'		  => $this->_geolocation->getMobileCarrier(),
						'os'			  => 'asasdasdasdasd',//$this->_deviceDetection->getOs(),
						'os_version'	  => 'asdasdasdasdad',//$this->_deviceDetection->getOsVersion(),
						'device'		  => 'asdasdasdasdasd',//$this->_deviceDetection->getType(),
						'device_model'    => 'asdasdasdasdasd',//$this->_deviceDetection->getModel(),
						'device_brand'	  => 'asdasdasdasdasd',//$this->_deviceDetection->getBrand(),
						'browser'		  => 'asdasdasdasdas',//$this->_deviceDetection->getBrowser(),
						'browser_version' => 'asdasdasdasdasd'//$this->_deviceDetection->getBrowserVersion()
					)));

	 				$this->_cache->set( 'impcount:'.$sessionHash, 1 );
				}



				switch ( $supply['model'] )
				{
					case 'CPM':
						if ( $data )
							$this->_cache->increment( 'impcost:'.$sessionHash, $supply['payout']/1000 );	
						else 
							$this->_cache->set( 'impcost:'.$sessionHash, $supply['payout']/1000 );
					break;
					case 'RS':
						if ( !$data )
							$this->_cache->set( 'impcost:'.$sessionHash, 0 );
					break;
				}
			}


			//-------------------------------------
			// RENDER
			//-------------------------------------
			// Store ad's code in registry to be acceded by view and/or controller
			$this->_registry->adCode = $demand['ad_code'];

			// pass sid for testing
			$this->_registry->sid = $sessionHash;

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