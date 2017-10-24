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
		private $_campaignsPool;
		private $_campaigns;
		private $_excludedAffiliates;
		private $_excludedPackageIds;
		private $_debugPlacement;


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

			if ( isset( $_GET['debug_placement'] ) && 
				(int)$_GET['debug_placement']==1 )
			{
				$this->_debugPlacement = true;
				$this->_cache->remove( 'lastdebug');
			}
		}


		public function render ( $placement_id, $tag_type )
		{
			//-------------------------------------
			// GET & VALIDATE USER DATA
			//-------------------------------------
			$userAgent     = $this->_registry->httpRequest->getUserAgent();
			$sessionId     = $this->_registry->httpRequest->getParam('session_id');
			$exchangeId    = $this->_registry->httpRequest->getParam('exchange_id');
			$pubId 		   = $this->_registry->httpRequest->getParam('pub_id');
			$subpubId      = $this->_registry->httpRequest->getParam('subpub_id');
			$deviceId      = $this->_registry->httpRequest->getParam('device_id');
			$idfa   	   = $this->_registry->httpRequest->getParam('idfa');
			$gaid   	   = $this->_registry->httpRequest->getParam('gaid');
			$timestamp     = $this->_registry->httpRequest->getTimestamp();
			$impStatus     = 'no_offer';
			$clicks 	   = 0;
			$clickIDs 	   = [];		

			// if idfa or gaid exist and is valid then use it as session ID
			if ( 
				$idfa 
				&& $idfa != '00000000-0000-0000-0000-000000000000' 
				&& \preg_match( '/^[A-Fa-f0-9]{8}(-)[A-Fa-f0-9]{4}(-)[A-Fa-f0-9]{4}(-)[A-Fa-f0-9]{4}(-)[A-Fa-f0-9]{12}$/', $idfa ) 				
			)
			{
				$sessionId = $idfa;
			}
			else if ( 
				$gaid 
				&& $gaid != '00000000-0000-0000-0000-000000000000' 
				&& \preg_match( '/^[A-Fa-f0-9]{8}(-)[A-Fa-f0-9]{4}(-)[A-Fa-f0-9]{4}(-)[A-Fa-f0-9]{4}(-)[A-Fa-f0-9]{12}$/', $gaid ) 
			)
			{
				$sessionId = $gaid;
			}

			// check if load balancer exists. If exists get original ip from X-Forwarded-For header
			$ip = $this->_registry->httpRequest->getHeader('X-Forwarded-For');

			if ( !$ip )
				$ip = $this->_registry->httpRequest->getSourceIp();

			// for the rare case in which coma separated IPs come as value
			$ips = \explode( ',', $ip );
			$ip  = $ips[0];

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

			$placement = $this->_cache->getMap( 'placement:'.$placement_id );

			if ( !$placement )
			{
				$this->_createWarning( 'Placement not found', 'M000002A', 404 );
				return false;				
			}

			$cluster = $this->_cache->getMap( 'cluster:'.$placement['cluster_id'] );
			$device  = $this->_getDeviceData( $userAgent );
			$this->_geolocation->detect( $ip );

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
				if ( Config\Ad::DEBUG_HTML )
				{
					echo '<!-- ip: '.$ip.' -->';
					echo '<!-- user agent: '.$userAgent.' -->';			
				}				

				$sessionHash = \md5( 
					\date( 'Y-m-d', $timestamp ) .
					$placement['cluster_id'] .
					$placement_id . 
					$ip . 
					$userAgent								
				);
			}			

			//-------------------------------------------------------
			// CHECK PLACEMENT STATUS, CAP & MATCH CLUSTER TARGETING
			//-------------------------------------------------------
			$this->_cache->useDatabase( $this->_getCurrentDatabase() );

			// check if cluster exists and how many imps
			$clusterImpCount = $this->_cache->getMapField( 'clusterlog:'.$sessionHash, 'imps' );

			// check if cluster log was targetted
			$logWasTargetted = $this->_cache->getMapField( 'clusterlog:'.$sessionHash, 'targetted' );

			// check cluster targeting
			$matchesClusterTargeting = $this->_matchClusterTargeting( $cluster, $device );

			if ( !$matchesClusterTargeting )
				$impStatus = 'inc_targeting';

			// check frequency cap
			if( $clusterImpCount && (int)$clusterImpCount < $placement['frequency_cap'] )
				$isUnderFrequencyCap = true;
			else
				$isUnderFrequencyCap = false;

			if ( $this->_debugPlacement )
			{
				$this->_cache->setMap( 'lastdebug', [
					'ip'					 => $ip,
					'session_hash'			 => $sessionHash,
					'previous_imp_count' 	 => $clusterImpCount,
					'under_cap'				 => $isUnderFrequencyCap,
					'previous_log_targetted' => $logWasTargetted,
					'targeting_matched'		 => $matchesClusterTargeting,
				]);										
			}			

			// print debug data
			if ( Config\Ad::DEBUG_HTML )
			{
				echo '<!-- placement status: '.$placement['status'].' -->';
				echo '<!-- placement imps: '.$placement['imps'].' -->';
				echo '<!-- cluster imps: '.$clusterImpCount.' -->';
				echo '<!-- process tracking: -->';
			}

			if ( $placement['status'] == 'health_check' )
				$impStatus = 'health_check';

			if ( $placement['status'] == 'testing' )
				$impStatus = 'testing';




			//-------------------------------------------------------
			// LOG
			//-------------------------------------------------------
			if (
				$placement['status'] == 'health_check' 
				|| $placement['status'] == 'testing' 
				|| ( $clusterImpCount && $logWasTargetted )						
			)
			{	
				if ( $this->_debugPlacement )
				{
					$this->_cache->setMap( 'lastdebug', [
						'repeated_imp'	=> 'yes',
					]);										
				}			

				// SKIP RETARGETING		
				$retargetted = false;	
			}
			else
			{		
				if ( $this->_debugPlacement )
				{
					$this->_cache->setMap( 'lastdebug', [
						'repeated_imp'	=> 'no',
					]);										
				}						
				
				// skip retargeting by default
				$retargetted = false;

				$this->_cache->useDatabase( 0 );

				// verify if ip was banned
				$ipRank = $this->_cache->getSortedSetElementRank('ipblacklist', $ip);

				if ( isset($ipRank) && (int)$ipRank>=0 )
				{
					$banned    = true;
					$impStatus = 'banned';
				}
				else
				{
					$banned = false;
				}

				// verify invalid parameters
				if(
 					\preg_match( '/^(((%)(.+)(%))|((\$)(.+)(\$))|((\()(.+)(\)))|((\{)(.+)(\}))|((\[)(.+)(\])))$/', $pubId )
					|| \preg_match( '/^(((%)(.+)(%))|((\$)(.+)(\$))|((\()(.+)(\)))|((\{)(.+)(\}))|((\[)(.+)(\])))$/', $subpubId )
					|| \preg_match( '/^(((%)(.+)(%))|((\$)(.+)(\$))|((\()(.+)(\)))|((\{)(.+)(\}))|((\[)(.+)(\])))$/', $gaid )				
					|| \preg_match( '/^(((%)(.+)(%))|((\$)(.+)(\$))|((\()(.+)(\)))|((\{)(.+)(\}))|((\[)(.+)(\])))$/', $idfa )					
				)
				{
					$invalidParams = true;

					if ( $impStatus != 'banned' )
						$impStatus = 'invalid_param';
				}
				else
				{
					$invalidParams = false;
				}


				$this->_cache->useDatabase( $this->_getCurrentDatabase() );

				if ( $this->_debugPlacement )
				{
					$this->_cache->setMap( 'lastdebug', [
						'ip_in_blacklist'	=> $banned,
						'invalid_params'	=> $invalidParams
					]);										
				}			

				// match cluster targeting. If not, skip retargeting
				if ( !$banned && $matchesClusterTargeting && !$invalidParams )
				{
					if ( Config\Ad::DEBUG_HTML )
						echo '<!-- matched cluster targeting -->';

					$detectionSuccess = $this->_fraudDetection->analize([
						'request_type'	=> 'display',
						'ip_address'	=> $ip,
						'session_id'	=> $sessionHash,
						'source_id'		=> $placement_id
					]);

					if ( $this->_debugPlacement )
					{
						$this->_cache->setMap( 'lastdebug', [
							'forensiq_success'	=> $detectionSuccess,
							'risk_level'		=> $this->_fraudDetection->getRiskLevel(),
							'forensiq_passed'	=> 'no'							
						]);										
					}	

					// if fraud detection passes, log and do retargeting
					if ( $detectionSuccess && $this->_fraudDetection->getRiskLevel() <= Config\Ad::FRAUD_RISK_LVL )
					{
						if ( $this->_debugPlacement )
						{
							$this->_cache->setMap( 'lastdebug', [
								'forensiq_passed'	=> 'yes'
							]);										
						}
											
						if ( Config\Ad::DEBUG_HTML )
							echo '<!-- fraud detection passed -->';

						$this->_cache->useDatabase( 0 );

						$this->_retrieveCampaigns( $placement['cluster_id'] );

						$this->_cache->useDatabase( $this->_getCurrentDatabase() );

						if ( count($this->_campaigns)>5 )
							$this->_cache->addToSortedSet( 'retrievecampaignfails', $timestamp, $sessionHash );

						if ( $this->_campaigns && is_array($this->_campaigns) && !empty($this->_campaigns) )
						{
							// generate clickids and campaign logs
							foreach ( $this->_campaigns as $campaignId )
							{
								$clickId    = md5( $campaignId.$sessionHash );
								$clickIDs[] = $clickId;
								$clicks++;

								if ( $this->_registry->httpRequest->getParam('test_campaign_pool')!=1 )
								{
									// save campaign log
									$this->_newCampaignLog( $clickId, $sessionHash, $campaignId, $timestamp );
								}

								if ( $this->_debugPlacement )
								{
									$i = 'click_id'.$clicks;
									$this->_cache->setMap( 'lastdebug', [
										$i => $clickId,
										'risk_level'		=> $this->_fraudDetection->getRiskLevel()
									]);										
								}	
							}

							if ( count($clickIDs)>5 )
								$this->_cache->addToSortedSet( 'clickcountfails', $timestamp, $sessionHash );

							// run campaign selection
							$this->_campaignSelection->run( $clickIDs );

							if ( Config\Ad::DEBUG_HTML )
								echo '<!-- cs ok -->';

							// store ad's code to be found by view and/or controller
							$this->_registry->adCode = $this->_campaignSelection->getAdCode();

							$retargetted = true;
							$impStatus   = 'print';						
						}
						else
						{
							if ( Config\Ad::DEBUG_HTML )
								echo '<!-- no active campaigns in cluster -->';	
						}						
					}
					else
					{
						$impStatus = 'high_risk';
					}
				}			
			}

			// save cluster log
			if ( $this->_registry->httpRequest->getParam('test_campaign_pool')!=1 )
			{
				$this->_clusterLog(
					$clusterImpCount,
					$sessionHash, 
					$timestamp, 
					$ip, 
					$placement,
					$cluster, 
					$device, 
					$placement_id, 
					$retargetted,
					$matchesClusterTargeting,
					$exchangeId,
					$pubId,
					$subpubId,
					$deviceId,
					$idfa,
					$gaid,
					$impStatus,
					count($clickIDs)
				);
			}

			//-------------------------------------
			// RENDER
			//-------------------------------------			

			// select view based on tag type
			switch ( $tag_type )
			{
				case 'js':
					$this->_registry->view = 'js';
				break;
				default:
					$this->_registry->view = 'html';
				break;
			}

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

			// Tell controller process completed successfully
			$this->_registry->status = 200;
			return true;
		}


		private function _updatePlacement ( $placement_id, array $placement )
		{
			// if health check is completed with this impression, set placement status to 'active'
			if ( isset($placement['health_check_imps']) && $placement['health_check_imps']>=0 )
				$hcImps = (int)$placement['health_check_imps'];
			else
				$hcImps = Config\Ad::PLACEMENT_HEALTH;

			if ( (int)$placement['imps']+1 == $hcImps )
				$this->_cache->setMapField( 'placement:'.$placement_id, 'status', 'active' );

			// increment placement's impression count
			$this->_cache->incrementMapField( 'placement:'.$placement_id, 'imps' );
		}


		private function _clusterLog ( 
			$clusterImpCount,
			$sessionHash, 
			$timestamp, 
			$ip, 
			array $placement, 
			array $cluster,
			array $device, 
			$placementId, 
			$retargetted,
			$matchesClusterTargeting,
			$exchangeId,
			$pubId,
			$subpubId,
			$deviceId,
			$idfa,
			$gaid,
			$impStatus,
			$clicks	
		)
		{
			// if cluster log already exists increment, otherwise create new
			if ( $clusterImpCount )
			{
				if ( Config\Ad::DEBUG_HTML )
					echo '<!-- increment log -->';

				$this->_incrementClusterLog( $sessionHash, $placement, $timestamp, $clusterImpCount, $retargetted, $matchesClusterTargeting, $clicks, $impStatus );
			}
			else
			{
				if ( Config\Ad::DEBUG_HTML )
					echo '<!-- new log -->';

				$this->_newClusterLog ( 
					$sessionHash, 
					$timestamp, 
					$ip, 
					$placement, 
					$cluster, 
					$device, 
					$placementId,  
					$retargetted, 
					$matchesClusterTargeting,
					$exchangeId,
					$pubId,
					$subpubId,
					$deviceId,
					$idfa,
					$gaid,
					$impStatus,
					$clicks				 
				);
			}

			// update placement imps and status
			$this->_cache->useDatabase( 0 );
			$this->_updatePlacement( $placementId, $placement );
			$this->_cache->useDatabase( $this->_getCurrentDatabase() );
		}


		private function _newClusterLog ( 
			$sessionHash, 
			$timestamp, 
			$ip, 
			array $placement,
			array $cluster,  
			array $device, 
			$placementId, 
			$retargetted,
			$matchesClusterTargeting,
			$exchangeId,
			$pubId,
			$subpubId,
			$deviceId,
			$idfa,
			$gaid,
			$impStatus,
			$clicks
		)
		{
			// calculate cost
			switch ( $placement['model'] )
			{
				case 'CPM':
					if ( $matchesClusterTargeting )
						$cost = $placement['payout']/1000;
					else
						$cost = 0;
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
				'cluster_name'	  => $cluster['name'], 
				'placement_id'	  => $placementId,  
				'exchange_id'	  => $exchangeId, 
				'pub_id'		  => $pubId, 
				'subpub_id'		  => $subpubId, 
				'device_id'		  => $deviceId, 
				'idfa'			  => $idfa, 
				'gaid'			  => $gaid, 
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
				'imp_status'	  => $impStatus, 
				'clicks'  		  => $clicks, 
				'targetted'		  => $retargetted, 
				'cost'			  => $cost
			]);
		}


		private function _incrementClusterLog ( 
			$sessionHash, 
			array $placement, 
			$timestamp, 
			$clusterImpCount, 
			$retargetted, 
			$matchesClusterTargeting,
			$clicks,
			$impStatus
		)
		{
			switch ( $placement['model'] )
			{
				case 'CPM':
					$this->_cache->incrementMapField( 'clusterlog:'.$sessionHash, 'cost', $placement['payout']/1000 );
				break;
			}		

			if ( $retargetted )
			{
				$this->_cache->setMapField( 'clusterlog:'.$sessionHash, 'targetted', $retargetted );
				$this->_cache->setMapField( 'clusterlog:'.$sessionHash, 'clicks', $clicks );
				$this->_cache->setMapField( 'clusterlog:'.$sessionHash, 'imp_status', $impStatus );				
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
			if ( $this->_debugPlacement )
			{
				$this->_cache->setMap( 'lastdebug', [
					'cluster_connection_type'	=> $cluster['connection_type'],
					'request_connection_type'	=> $this->_geolocation->getConnectionType()
				]);			
			}

			if ( 
				$cluster['connection_type'] 
				&& $cluster['connection_type'] != $this->_geolocation->getConnectionType()
				&& $cluster['connection_type'] != '-' 
				&& $cluster['connection_type'] != ''
			)
			{
				return false;
			}

			if ( $this->_debugPlacement )
			{
				$this->_cache->setMap( 'lastdebug', [
					'cluster_country'	=> $cluster['country'],
					'request_country'	=> $this->_geolocation->getCountryCode()
				]);			
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

			switch ( strtolower($deviceData['device']) )
			{
				case 'desktop':
					$device = 'desktop';
				break;
				case 'smartphone':
				case 'feature phone':
				case 'phablet':
					$device = 'smartphone';
				break;
				case 'tablet':
					$device = 'tablet';
				break;
				default:
					$device = 'other';
				break;
			}

			if ( $this->_debugPlacement )
			{
				$this->_cache->setMap( 'lastdebug', [
					'cluster_device_type'	=> $cluster['device_type'],
					'request_device_type'	=> $device
				]);			
			}

			if ( 
				$cluster['device_type'] 
				&& $cluster['device_type'] != $device
				&& $cluster['device_type'] != '-' 
				&& $cluster['device_type'] != '' 
			)
			{
				return false;
			}

			if ( $this->_debugPlacement )
			{
				$this->_cache->setMap( 'lastdebug', [
					'cluster_os'	=> $cluster['os'],
					'request_os'	=> $deviceData['os'] 
				]);			
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


			if ( $this->_debugPlacement )
			{
				$this->_cache->setMap( 'lastdebug', [
					'cluster_os_version'	=> $cluster['os_version'],
					'request_os_version'	=> $deviceData['os_version'] 
				]);			
			}

			if ( 
				$cluster['os_version'] 
				&& (float)$cluster['os_version'] <= (float)$deviceData['os_version'] 
				&& $cluster['os_version'] != '-'
				&& $cluster['os_version'] != ''
			)
			{
				return false;
			}			

			if ( $this->_debugPlacement )
			{
				$this->_cache->setMap( 'lastdebug', [
					'cluster_carrier'	=> $cluster['carrier'],
					'request_carrier'	=> $this->_geolocation->getMobileCarrier() 
				]);			
			}

			if ( 
				$cluster['carrier'] 
				&& $cluster['carrier'] = $this->_geolocation->getMobileCarrier()
				&& $cluster['carrier'] != '-'
				&& $cluster['carrier'] != ''
			)
			{
				return false;
			}			

			return true;
		}


		private function _getDeviceData( $ua )
		{
			$uaHash = \md5($ua);
			$data   = $this->_cache->getMap( 'ua:'.$uaHash );

			// if devie data is not in cache, use device detection
			if ( !$data )
			{
				$this->_deviceDetection->detect( $ua );
				//echo '<!-- using device detector: yes -->';
				$data = array(
					'os' 			  => $this->_deviceDetection->getOs(),
					'os_version'	  => $this->_deviceDetection->getOsVersion(), 
					'device'		  => \strtolower($this->_deviceDetection->getType()), 
					'device_model'    => $this->_deviceDetection->getModel(), 
					'device_brand'	  => $this->_deviceDetection->getBrand(), 
					'browser'		  => $this->_deviceDetection->getBrowser(), 
					'browser_version' => $this->_deviceDetection->getBrowserVersion() 
				);

				$this->_cache->setMap( 'ua:'.$uaHash, $data );

				// add user agent identifier to a set in order to be found by ETL
				$this->_cache->addToSortedSet( 'useragents', 0, $uaHash );
			}

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


		private function _retrieveCampaigns ( $cluster_id )
		{
			$this->_campaigns     	   = [];
			$this->_campaignsPool 	   = [];
			$this->_excludedAffiliates = [];
			$this->_excludedPackageIds = [];

			// retrieve campaigns from cluster
			$clusterCampaigns = $this->_cache->getSortedSet( 
				'clusterlist:'.$cluster_id,
				0,
				-1,
				[
					'WITHSCORES' => true
				]
			);

			$campaignsTotal = \count($clusterCampaigns);

			// retrieve campaigns
			for( $i=0; $i<=5; $i++ )
			{
				$this->_retrieveCampaign( $clusterCampaigns, $campaignsTotal );	
			}

			if ( $this->_registry->httpRequest->getParam('test_campaign_pool')==1 )
			{
				echo 'SELECTED CAMPAIGNS<br><br>';
				var_dump($this->_campaigns);
				die();
			}
		}

		private function _retrieveCampaign ( array $clusterCampaigns, $campaignsTotal )
		{
			if ( $this->_registry->httpRequest->getParam('test_campaign_pool')==1 )
			{
				echo 'CURRENT CAMPAIGN POOL<br><br>';		
				var_dump($this->_campaignsPool);
				echo '<br><br>CURRENT EXCLUDED AFFILIATES<br><br>';		
				var_dump($this->_excludedAffiliates);			
				echo '<br><br>CURRENT EXCLUDED PACKAGES<br><br>';		
				var_dump($this->_excludedPackageIds);						
				echo '<hr>';				
			}

			// select campaign from pool
			$poolCount    = \count($this->_campaignsPool);
			$randPosition = \rand ( 0, $poolCount-1 );

			if ( $poolCount>0 && $randPosition >= 0 )
			{
				$selectedCid = $this->_campaignsPool[$randPosition];
			}
			else
			{
				$selectedCid = false;
			}

			if ( $selectedCid )
			{
				// save selected campaign
				$this->_campaigns[] = $selectedCid;

				// save affiliate and package_id data to be excluded when recreating pool
				foreach ( $clusterCampaigns AS $campaign => $frequency )
				{
					$data = \explode( ':', $campaign );

					if ( $data[0] == $selectedCid )
					{
						$this->_excludedAffiliates[] = $data[1];
						$this->_excludedPackageIds[] = $data[2];
						break;
					}
				}
			}			

			// recreate pool removing retrieved campaign and campaigns with same affiliate or package_id
			unset( $this->_campaignsPool );
			$this->_campaignsPool = [];		

			foreach ( $clusterCampaigns AS $campaign => $frequency )
			{
				$data      = \explode( ':', $campaign );
				$id        = $data[0];

				if ( in_array( $id, $this->_campaigns ) )
					continue;

				for ( $c=0; $c<$frequency; $c++ )
				{
					if ( 
						!in_array( $data[1], $this->_excludedAffiliates ) 
						&& !in_array( $data[2], $this->_excludedPackageIds )
					)
					{
						$this->_campaignsPool[] = $id;
					}
				}

				unset( $data );
			}

			return true;
		}
	}

?>