<?php

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
			// MATCH SUPPLY (placement_id)
			//-------------------------------------
			$placementId = $this->_registry->httpRequest->getPathElement(0);

			if ( !$placementId )
			{
				$this->_createWarning( 'Placement not found', 'M000001A', 404 );
				return false;				
			}

			$supply = $this->_cache->getMap( 'supply:'.$placementId );

			if ( !$supply )
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


			//-------------------------------------------------------
			// CHECK PLACEMENT STATUS & IF IMP EXISTS
			//-------------------------------------------------------
			$clusterImpCount = $this->_cache->getMapField( 'clusterlog:'.$sessionHash, 'imps' );
			$logWasTargetted = $this->_cache->getMapField( 'clusterlog:'.$sessionHash, 'targetted' );
			echo $supply['status'];
			if (
				$supply['status'] == 'health_check' 
				|| $supply['status'] == 'testing' 
				|| ( $clusterImpCount && $logWasTargetted )
			)
			//-------------------------------------------------------			
			// LOG & SKIP RETARGETING
			//-------------------------------------------------------
			{
				// if cluster log already exists increment, otherwise create new
				if ( $clusterImpCount )
				{
					echo '1, ';
					$this->_incrementClusterLog( $sessionHash, $supply, $clusterImpCount );
				}
				else
				{
					echo '2, ';
					$device = $this->_getDeviceData( $userAgent );
					$this->_geolocation->detect( $ip );

					$this->_newClusterLog ( $sessionHash, $timestamp, $ip, $supply, $device );
				}

				// if health check is completed with this impression, set placement status to 'active'
				if ( $supply['imps']+1 == Config\Ad::PLACEMENT_HEALTH )
					$this->_cache->setMapField( 'supply:'.$placementId, 'status', 'active' );

				// increment placement's impression count
				$this->_cache->incrementMapField( 'supply:'.$placementId, 'imps' );
			}
			else
			//-------------------------------------------------------				
			// LOG AND DO RETARGETING
			//-------------------------------------------------------
			{
				// match cluster targeting. If not, skip log and retargeting
				$cluster = $this->_cache->getMap( 'cluster:'.$supply['cluster'] );
				$device  = $this->_getDeviceData( $userAgent );

				$this->_geolocation->detect( $ip );
				echo '3, ';

				if ( $this->_matchClusterTargeting( $cluster, $device ) )
				{
					echo '4, ';
					$this->_fraudDetection->analize([
						'request_type'	=> 'display',
						'ip_address'	=> $ip,
						'session_id'	=> $sessionHash,
						'source_id'		=> ''
					]);

					// if fraud detection passes, log and do retargeting
					if ( $this->_fraudDetection->getRiskLevel() < Config\Ad::FRAUD_RISK_LVL )
					{
						echo '5, ';
						$this->_newClusterLog ( $sessionHash, $timestamp, $ip, $supply, $device, true );

						$campaigns = $this->_cache->getSet( 'clusterlist:'.$supply['cluster'] );
						$clickIDs  = [];

						foreach ( $campaigns as $campaignId )
						{
							$clickId    = md5( $campaignId.$sessionHash );
							$clickIDs[] = $clickId;

							$this->_newCampaignLog( $clickId, $sessionHash, $timestamp, $ip, $supply, $device );	
						}

						// run campaign selection with retargeting
						$this->_campaignSelection->run( $clickIDs );

						// store ad's code to be found by view and/or controller
						$this->_registry->adCode = $this->_campaignSelection->getAdCode();					
					}
				}	
			}


			//-------------------------------------
			// RENDER
			//-------------------------------------
			if ( !$this->_registry->adCode )
				echo 'fake code';

			// pass sid for testing
			//$this->_registry->sid = $sessionHash;
			echo $sessionHash.': ';
			// Tell controller process completed successfully
			$this->_registry->status = 200;
			return true;
		}


		private function _newClusterLog ( 
			$sessionHash, 
			$timestamp,
			$ip,
			array $supply,
			array $device,
			$targetted = false
		)
		{
			// save session hash into a set in order to know all logs from ETL script
			$this->_cache->addToSet( 'clusterlogs', $sessionHash );			

			// calculate cost
			switch ( $supply['model'] )
			{
				case 'CPM':
					$cost = $supply['payout']/1000;
				break;
				default:
					$cost = 0;
				break;
			}

			// write cluster log
			$this->_cache->setMap( 'clusterlog:'.$sessionHash, [
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
				'imps'			  => 1, 				
				'targetted'		  => $targetted, 
				'cost'			  => $cost
			]);
		}


		private function _incrementClusterLog ( $sessionHash, array $supply, $clusterImpCount )
		{
			// if imp count is under frequency cap, add cost
			if ( $clusterImpCount < $supply['frequency_cap'] )
			{
				switch ( $supply['model'] )
				{
					case 'CPM':
						$this->_cache->incrementMapField( 'clusterlog:'.$sessionHash, 'cost', $supply['payout']/1000 );
					break;
				}
			}

			$this->_cache->incrementMapField( 'clusterlog:'.$sessionHash, 'imps' );
		}


		private function _newCampaignLog ( 
			$clickId,
			$sessionHash, 
			$timestamp,
			$ip,
			array $supply,
			array $device
		)
		{
			// save campaign log index into a set in order to know all logs from ETL script
			$this->_cache->addToSet( 'clickids', $clickId );

			// write campaign log
			$this->_cache->setMap( 'campaignlog:'.$clickId, [
				'sid'             => $sessionHash, 
				'timestamp'       => $timestamp, 
				'imps'			  => 1
			]);
		}


		private function _matchClusterTargeting ( $cluster, array $deviceData )
		{
			if ( 
				$cluster 
				&& $cluster['os'] == $deviceData['os'] 
				&& $cluster['country'] == $this->_geolocation->getCountryCode() 
				&& $cluster['connection_type'] == $this->_geolocation->getConnectionType()   
			)
			{
				return true;
			}

			return false;
		}


		private function _getDeviceData( $ua )
		{
			$uaHash = md5($ua);
			$data   = $this->_cache->getMap( 'ua:'.$uaHash );

			// if devie data is not in cache, use device detection
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

				$this->_cache->setMap( 'ua:'.$uaHash, $data );

				// add user agent identifier to a set in order to be found by ETL
				$this->_cache->addToSet( 'user_agents', $uaHash );
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