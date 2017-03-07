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

			$cache->setMap( 'supply:2',  array(
				'frequency_cap'	  => 20,
				'payout'		  => 5,
				'model'			  => 'CPM',
				'cluster'		  => 5
			));

			$cache->addToSet( 'cluster:5', [ 10, 11, 12 ] );

			$cache->setMap( 'cp:10',  array(
				'ad_code'		  => 100,
				'country'		  => $geolocation->getCountryCode(),
				'connection_type' => $geolocation->getConnectionType(),
				'carrier'		  => $geolocation->getMobileCarrier(),
				'os'			  => $deviceDetection->getOs()
			));        	


			$cache->setMap( 'cp:11',  array(
				'ad_code'		  => 200,
				'country'		  => $geolocation->getCountryCode(),
				'connection_type' => $geolocation->getConnectionType(),
				'carrier'		  => $geolocation->getMobileCarrier(),
				'os'			  => $deviceDetection->getOs()
			));     	


			$cache->setMap( 'cp:12',  array(
				'ad_code'		  => 300,
				'country'		  => $geolocation->getCountryCode(),
				'connection_type' => $geolocation->getConnectionType(),
				'carrier'		  => $geolocation->getMobileCarrier(),
				'os'			  => $deviceDetection->getOs()
			));     	

        }

	}

?>