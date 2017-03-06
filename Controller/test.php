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

			$cache->set( 'supply:2',  msgpack_pack( array(
				'frequency_cap'	  => 20,
				'payout'		  => 5,
				'model'			  => 'CPM',
				'cluster'		  => 5
			)));


			$cache->appendToList( 'cluster:5', 10 );
			$cache->appendToList( 'cluster:5', 11 );
			$cache->appendToList( 'cluster:5', 12 );

			$cache->set( 'demand:10',  msgpack_pack( array(
				'ad_code'		  => 100,
				'country'		  => $geolocation->getCountryCode(),
				'connection_type' => $geolocation->getConnectionType(),
				'carrier'		  => $geolocation->getMobileCarrier(),
				'os'			  => $deviceDetection->getOs()
			)));        	


			$cache->set( 'demand:11',  msgpack_pack( array(
				'ad_code'		  => 200,
				'country'		  => $geolocation->getCountryCode(),
				'connection_type' => $geolocation->getConnectionType(),
				'carrier'		  => $geolocation->getMobileCarrier(),
				'os'			  => $deviceDetection->getOs()
			)));        	


			$cache->set( 'demand:12',  msgpack_pack( array(
				'ad_code'		  => 300,
				'country'		  => $geolocation->getCountryCode(),
				'connection_type' => $geolocation->getConnectionType(),
				'carrier'		  => $geolocation->getMobileCarrier(),
				'os'			  => $deviceDetection->getOs()
			)));        	

        }

	}

?>