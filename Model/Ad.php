<?php

	// TO DO:
	// agregar el database selection de adnigma
	// hacer que guarde toda la data persistente en la db 0
	// reformular etl con loadedlogs
	// testear match de device y geo
	
	namespace Aff\Ad\Model;

	use Aff\Framework,
		Aff\Config;


	class Ad extends Framework\ModelAbstract
	{

		private $_deviceDetection;
		private $_geolocation;
		private $_cache;
		private $_campaignSelection;
		private $_fraudDetection;


		public function __construct ( 
			Framework\Registry $registry,
			CampaignSelectionInterface $campaignSelection,
			Framework\AdServing\FraudDetectionInterface $fraudDetection,			
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
			$this->_fraudDetection		= $fraudDetection;
		}


		public function render ( $placement_id )
		{
			if ( Config\Ad::DEBUG_CACHE )
			{
				$this->_cache->useDatabase( $this->_getCurrentDatabase() );
				$this->_cache->incrementMapField( 'addebug', 'requests' );
			}

			//-------------------------------------
			// GET & VALIDATE USER DATA
			//-------------------------------------
			$userAgent = $this->_registry->httpRequest->getUserAgent();
			$sessionId = $this->_registry->httpRequest->getParam('session_id');
			$timestamp = $this->_registry->httpRequest->getTimestamp();

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
			// MATCH PLACEMENT (placement_id)
			//-------------------------------------
			if ( !$placement_id )
			{
				$this->_createWarning( 'Placement not found', 'M000001A', 404 );
				return false;
			}

			$this->_cache->useDatabase(0);
			$placement = $this->_cache->getMap( 'placement:'.$placement_id );

			if ( !$placement )
			{
				$this->_createWarning( 'Placement not found', 'M000002A', 404 );
				return false;				
			}

			//-------------------------------------
			// CALCULATE SESSION HASH
			//-------------------------------------

			// check if sessionId comes as request parameter and use it to calculate sessionHash. Otherwise use ip + userAgent
			if ( $sessionId )
			{
				$sessionHash = \md5( 
					\date( 'Y-m-d', $timestamp ) . 
					$placement['cluster_id'] . 
					$placement_id . 
					$sessionId 
				);
			}
			else
			{
				//echo '<!-- ip: '.$ip.' -->';
				//echo '<!-- user agent: '.$userAgent.' -->';
				$sessionHash = \md5( 
					\date( 'Y-m-d', $timestamp ) .
					$placement['cluster_id'] .
					$placement_id . 
					$ip . 
					$userAgent								
				);
				/*
				$sessionHash = \md5(rand());
				*/
			}			

			//-------------------------------------------------------
			// CHECK PLACEMENT STATUS & IF IMP EXISTS
			//-------------------------------------------------------
			$this->_cache->useDatabase( $this->_getCurrentDatabase() );

			$clusterImpCount = $this->_cache->getMapField( 'clusterlog:'.$sessionHash, 'imps' );
			$logWasTargetted = $this->_cache->getMapField( 'clusterlog:'.$sessionHash, 'targetted' );

			if ( Config\Ad::DEBUG_HTML )
			{
				echo '<!-- placement status: '.$placement['status'].' -->';
				echo '<!-- placement imps: '.$placement['imps'].' -->';
				echo '<!-- cluster imps: '.$clusterImpCount.' -->';
				echo '<!-- process tracking: -->';
			}


			if (
				$placement['status'] == 'health_check' 
				|| $placement['status'] == 'testing' 
				|| ( $clusterImpCount && $logWasTargetted )
			)
			//-------------------------------------------------------			
			// LOG & SKIP RETARGETING
			//-------------------------------------------------------
			{
				// if cluster log already exists increment, otherwise create new
				if ( $clusterImpCount )
				{
					if ( Config\Ad::DEBUG_HTML )
						echo '<!-- no cs => increment log -->';

					$this->_incrementClusterLog( $sessionHash, $placement, $clusterImpCount, $timestamp );
				}
				else
				{
					if ( Config\Ad::DEBUG_HTML )
						echo '<!-- no cs => new log -->';

					$device = $this->_getDeviceData( $userAgent );
					$this->_geolocation->detect( $ip );

					$this->_newClusterLog ( $sessionHash, $timestamp, $ip, $placement, $device, $placement_id,  false );
				}

				// update placement imps and status
				$this->_cache->useDatabase( 0 );
				$this->_updatePlacement( $placement_id, $placement );
				$this->_cache->useDatabase( $this->_getCurrentDatabase() );
			}
			else
			//-------------------------------------------------------				
			// LOG AND DO RETARGETING
			//-------------------------------------------------------
			{
				if ( Config\Ad::DEBUG_CACHE )
					$this->_cache->incrementMapField( 'addebug', 'retargeting' );
				
				$this->_cache->useDatabase( 0 );
				$cluster = $this->_cache->getMap( 'cluster:'.$placement['cluster_id'] );
				$this->_cache->useDatabase( $this->_getCurrentDatabase() );

				$device  = $this->_getDeviceData( $userAgent );
				$this->_geolocation->detect( $ip );

				if ( Config\Ad::DEBUG_CACHE )
					$this->_cache->incrementMapField( 'addebug', 'geodetections' );

				// log
				$this->_newClusterLog ( $sessionHash, $timestamp, $ip, $placement, $device, $placement_id, true );
			
				if ( Config\Ad::DEBUG_HTML )
					echo '<!-- cs init -->';

				// match cluster targeting. If not, skip retargeting
				if ( $this->_matchClusterTargeting( $cluster, $device ) )
				{
					if ( Config\Ad::DEBUG_CACHE )
						$this->_cache->incrementMapField( 'addebug', 'target_matches' );

					if ( Config\Ad::DEBUG_HTML )
						echo '<!-- matched cluster targeting -->';

					$detectionSuccess = $this->_fraudDetection->analize([
						'request_type'	=> 'display',
						'ip_address'	=> $ip,
						'session_id'	=> $sessionHash,
						'source_id'		=> $placement_id
					]);

					// if fraud detection passes, log and do retargeting
					if ( $detectionSuccess && $this->_fraudDetection->getRiskLevel() < Config\Ad::FRAUD_RISK_LVL )
					{
						if ( Config\Ad::DEBUG_CACHE )
							$this->_cache->incrementMapField( 'addebug', 'passed_fraud_detect' );

						if ( Config\Ad::DEBUG_HTML )
							echo '<!-- fraud detection passed -->';

						$this->_cache->useDatabase( 0 );
						$campaigns = $this->_cache->getSet( 'clusterlist:'.$placement['cluster_id'] );
						$this->_cache->useDatabase( $this->_getCurrentDatabase() );

						$clickIDs  = [];

						foreach ( $campaigns as $campaignId )
						{
							$clickId    = md5( $campaignId.$sessionHash );
							$clickIDs[] = $clickId;

							$this->_newCampaignLog( $clickId, $sessionHash, $campaignId, $timestamp );	
						}

						//echo '<!-- <br>click IDs:'.json_encode($clickIDs) . ' -->';

						// run campaign selection with retargeting
						$this->_campaignSelection->run( $clickIDs );
						
						if ( Config\Ad::DEBUG_CACHE )
							$this->_cache->incrementMapField( 'addebug', 'campaign_selections' );

						if ( Config\Ad::DEBUG_HTML )
							echo '<!-- cs ok -->';

						// store ad's code to be found by view and/or controller
						$this->_registry->adCode = $this->_campaignSelection->getAdCode();
					}					
				}	
			}


			//-------------------------------------
			// RENDER
			//-------------------------------------			

			// select static creative from cluster based on placement's size
			$creativeSize = $placement['size'];
			if ( isset( $cluster ) )
			{
				$this->_registry->creativeUrl = $cluster['static_cp_'.$creativeSize];
				$this->_registry->landingUrl  = $cluster['static_cp_land'];
			}
			else
			{
				$this->_cache->useDatabase( 0 );

				$this->_registry->creativeUrl = $this->_cache->getMapField( 'cluster:'.$placement['cluster_id'], 'static_cp_'.$creativeSize );
				$this->_registry->landingUrl  = $this->_cache->getMapField( 'cluster:'.$placement['cluster_id'], 'static_cp_land' );
			}

			// pass sid for testing
			//$this->_registry->sid = $sessionHash;
			//echo '<!-- session_hash: '.$sessionHash.' -->';
			// Tell controller process completed successfully
			$this->_registry->status = 200;
			return true;
		}


		private function _updatePlacement ( $placement_id, array $placement )
		{
			// if health check is completed with this impression, set placement status to 'active'
			if ( $placement['imps']+1 == Config\Ad::PLACEMENT_HEALTH )
				$this->_cache->setMapField( 'placement:'.$placement_id, 'status', 'active' );

			// increment placement's impression count
			$this->_cache->incrementMapField( 'placement:'.$placement_id, 'imps' );
		}


		private function _newClusterLog ( 
			$sessionHash, 
			$timestamp, 
			$ip, 
			array $placement, 
			array $device, 
			$placementId, 
			$targetted = false
		)
		{
			// calculate cost
			switch ( $placement['model'] )
			{
				case 'CPM':
					$cost = $placement['payout']/1000;
				break;
				default:
					$cost = 0;
				break;
			}

			// save cluster log index into a set in order to know all logs from ETL script
			$this->_cache->addToSortedSet( 'sessionhashes', $timestamp, $sessionHash );

			// write cluster log
			$this->_cache->setMap( 'clusterlog:'.$sessionHash, [
				'cluster_id'	  => $placement['cluster_id'], 
				'cluster_name'	  => $placement['cluster_name'],  
				'placement_id'	  => $placementId, 
				'imp_time'        => $timestamp, 
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
				'imps'			  => 1, 				
				'targetted'		  => $targetted, 
				'cost'			  => $cost
			]);
		}


		private function _incrementClusterLog ( $sessionHash, array $placement, $clusterImpCount, $timestamp )
		{
			// if imp count is under frequency cap, add cost
			if ( $clusterImpCount < $placement['frequency_cap'] )
			{
				switch ( $placement['model'] )
				{
					case 'CPM':
						$this->_cache->incrementMapField( 'clusterlog:'.$sessionHash, 'cost', $placement['payout']/1000 );
					break;
				}
			}

			$this->_cache->addToSortedSet( 'sessionhashes', $timestamp, $sessionHash );
			$this->_cache->incrementMapField( 'clusterlog:'.$sessionHash, 'imps' );
		}


		private function _newCampaignLog ( 
			$clickId,
			$sessionHash,
			$campaignId,
			$timestamp
		)
		{
			// save campaign log index into a set in order to know all logs from ETL script
			$this->_cache->addToSortedSet( 'clickids', $timestamp, $clickId );

			// write campaign log
			$this->_cache->setMap( 'campaignlog:'.$clickId, [
				'session_hash'    => $sessionHash, 
				'campaign_id'	  => $campaignId,
				'click_time'      => null
			]);
		}


		private function _matchClusterTargeting ( $cluster, array $deviceData )
		{
			if ( 
				$cluster['connection_type'] 
				&& $cluster['connection_type'] != $this->_geolocation->getConnectionType()
				&& $cluster['connection_type'] != '-' 
				&& $cluster['connection_type'] != ''
			)
			{
				return false;
			}


			if ( 
				$cluster['country']
				&& $cluster['country'] != $this->_geolocation->getCountryCode()
				&& $cluster['country'] != '-'
				&& $cluster['country'] != ''
			)
			{
				return false;			
			}

			if ( 
				$cluster['os'] 
				&& $cluster['os'] != $deviceData['os'] 
				&& $cluster['os'] != '-'
				&& $cluster['os'] != ''
			)
			{
				return false;
			}

			return true;
		}


		private function _getDeviceData( $ua )
		{
			$this->_cache->useDatabase( 0 );

			$uaHash = md5($ua);
			$data   = $this->_cache->getMap( 'ua:'.$uaHash );

			// if devie data is not in cache, use device detection
			if ( !$data )
			{
				$this->_deviceDetection->detect( $ua );
				//echo '<!-- using device detector: yes -->';
				$data = array(
					'os' 			  => $this->_deviceDetection->getOs(),
					'os_version'	  => $this->_deviceDetection->getOsVersion(), 
					'device'		  => $this->_deviceDetection->getType(), 
					'device_model'    => $this->_deviceDetection->getModel(), 
					'device_brand'	  => $this->_deviceDetection->getBrand(), 
					'browser'		  => $this->_deviceDetection->getBrowser(), 
					'browser_version' => $this->_deviceDetection->getBrowserVersion() 
				);

				$this->_cache->setMap( 'ua:'.$uaHash, $data );

				// add user agent identifier to a set in order to be found by ETL
				$this->_cache->addToSet( 'uas', $uaHash );
			}

			$this->_cache->useDatabase( $this->_getCurrentDatabase() );

			return $data;
		}


		private function _createWarning( $message, $code, $status )
		{
			$this->_registry->message = $message;
			$this->_registry->code    = $code;
			$this->_registry->status  = $status;			
		}


		private function _getCurrentDatabase ( )
		{
			return \floor(($this->_registry->httpRequest->getTimestamp()/60/60/24))%2+1;
		}

	}

?>
