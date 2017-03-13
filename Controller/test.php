<?php

	namespace Aff\Ad\Controller;

	use Aff\Framework,
		Aff\Ad\Model,
		Aff\Config,
		Aff\Ad\Core;


	class test extends Core\ControllerAbstract
	{

		public function __construct ( Framework\Registry $registry )
		{
			parent::__construct( $registry );
		}


		public function route ( )
        {
			$userAgent = $this->_registry->httpRequest->getUserAgent();

			$ip = $this->_registry->httpRequest->getHeader('X-Forwarded-For');
			if ( !$ip )
				$ip = $this->_registry->httpRequest->getSourceIp();

        	$cache 			 = new Framework\Database\Redis\Predis( 'tcp://'.Config\Ad::REDIS_CONFIG.':6379' );
        	$deviceDetection = new Framework\Device\Detection\Piwik();
        	$geolocation 	 = new Framework\TCP\Geolocation\Source\IP2Location( Config\Ad::IP2LOCATION_BIN );

			$deviceDetection->detect( $userAgent );

			$geolocation->detect( $ip );

			//test testing status
			$cache->setMap( 'supply:1',  [
				'frequency_cap'	  => 10,
				'payout'		  => 2,
				'model'			  => 'CPM',
				'cluster'		  => 5,
				'status'		  => 'testing',
				'imps'			  => 0
			]);

			// test normal
			$cache->setMap( 'supply:2',  [
				'frequency_cap'	  => 20,
				'payout'		  => 5,
				'model'			  => 'CPM',
				'cluster'		  => 5,
				'status'		  => 'active',
				'imps'			  => 0
			]);


			$cache->setMap( 'supply:3',  [
				'frequency_cap'	  => 100,
				'payout'		  => 3,
				'model'			  => 'CPM',
				'cluster'		  => 6,
				'status'		  => 'testing',
				'imps'			  => 0
			]);	

			$cache->setMap( 'supply:4',  [
				'frequency_cap'	  => 100,
				'payout'		  => 3,
				'model'			  => 'RS',
				'cluster'		  => 6,
				'status'		  => 'testing',
				'imps'			  => 0
			]);										

			$cache->setMap( 'supply:4',  [
				'frequency_cap'	  => 100,
				'payout'		  => 3,
				'model'			  => 'RS',
				'cluster'		  => 6,
				'status'		  => 'testing',
				'imps'			  => 0
			]);									

			$cache->addToSet( 'clusterlist:5', [ 10, 11, 12 ] );
			$cache->addToSet( 'clusterlist:6', [ 20, 21, 22 ] );
			$cache->addToSet( 'clusterlist:7', [ 30, 31, 32 ] );

			$cache->setMap( 'cluster:5',  [
				'ad_code'		  => 100,
				'country'		  => $geolocation->getCountryCode(),
				'connection_type' => $geolocation->getConnectionType(),
				'carrier'		  => $geolocation->getMobileCarrier(),
				'os'			  => $deviceDetection->getOs()
			]);        	


			$cache->setMap( 'cluster:6',  [
				'ad_code'		  => 200,
				'country'		  => $geolocation->getCountryCode(),
				'connection_type' => $geolocation->getConnectionType(),
				'carrier'		  => $geolocation->getMobileCarrier(),
				'os'			  => $deviceDetection->getOs()
			]);     	


			$cache->setMap( 'cluster:7',  array(
				'ad_code'		  => 300,
				'country'		  => $geolocation->getCountryCode(),
				'connection_type' => $geolocation->getConnectionType(),
				'carrier'		  => $geolocation->getMobileCarrier(),
				'os'			  => $deviceDetection->getOs()
			));     	

        }

	}

?>