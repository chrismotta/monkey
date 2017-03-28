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
			$cache->setMap( 'placement:1',  [
				'frequency_cap'	  => 10,
				'payout'		  => 2,
				'model'			  => 'CPM',
				'cluster'		  => 5,
				'status'		  => 'health_check',
				'imps'			  => 0,
				'size'			  => '320x50'
			]);

			// test normal
			$cache->setMap( 'placement:2',  [
				'frequency_cap'	  => 20,
				'payout'		  => 5,
				'model'			  => 'CPM',
				'cluster'		  => 5,
				'status'		  => 'health_check',
				'imps'			  => 0,
				'size'			  => '300x250'
			]);


			$cache->setMap( 'placement:3',  [
				'frequency_cap'	  => 100,
				'payout'		  => 3,
				'model'			  => 'CPM',
				'cluster'		  => 6,
				'status'		  => 'active',
				'imps'			  => 0,
				'size'			  => '320x50'
			]);	

			$cache->setMap( 'placement:4',  [
				'frequency_cap'	  => 100,
				'payout'		  => 3,
				'model'			  => 'RS',
				'cluster'		  => 6,
				'status'		  => 'testing',
				'imps'			  => 0,
				'size'			  => '320x50'
			]);										

			$cache->setMap( 'placement:5',  [
				'frequency_cap'	  => 100,
				'payout'		  => 3,
				'model'			  => 'RS',
				'cluster'		  => 6,
				'status'		  => 'testing',
				'imps'			  => 0,
				'size'			  => '320x50'
			]);									

			$cache->setMap( 'cluster:5',  [
				'country'		   => $geolocation->getCountryCode(),
				'connection_type'  => $geolocation->getConnectionType(),
				'carrier'		   => $geolocation->getMobileCarrier(),
				'static_cp_land'   => 'https://www.google.com.ar',
				'static_cp_300x250'=> 'http://www.adsthatwow.com/image/Audi_expandable.jpg',
				'static_cp_320x50' => 'http://img.photobucket.com/albums/v74/Vincered/hoxasig.jpg',	
				'os'			   => $deviceDetection->getOs()
			]);


			$cache->setMap( 'cluster:6',  [
				'country'		  => $geolocation->getCountryCode(),
				'connection_type' => $geolocation->getConnectionType(),
				'carrier'		  => $geolocation->getMobileCarrier(),
				'static_cp_land'   => 'https://www.google.com.ar',
				'static_cp_300x250'=> 'http://www.adsthatwow.com/image/Audi_expandable.jpg',
				'static_cp_320x50' => 'http://img.photobucket.com/albums/v74/Vincered/hoxasig.jpg',	
				'os'			  => $deviceDetection->getOs()
			]);


			$cache->setMap( 'cluster:7',  array(
				'country'		  => $geolocation->getCountryCode(),
				'connection_type' => $geolocation->getConnectionType(),
				'carrier'		  => $geolocation->getMobileCarrier(),
				'static_cp_land'   => 'https://www.google.com.ar',
				'static_cp_300x250'=> 'http://www.adsthatwow.com/image/Audi_expandable.jpg',
				'static_cp_320x50' => 'http://img.photobucket.com/albums/v74/Vincered/hoxasig.jpg',	
				'os'			  => $deviceDetection->getOs()
			));


			$cache->addToSet( 'clusterlist:5', [ 10, 11, 12, 13, 14 ] );
			$cache->addToSet( 'clusterlist:6', [ 20, 21, 22, 23, 24 ] );
			$cache->addToSet( 'clusterlist:7', [ 30, 31, 32, 33, 34 ] );

			$cache->setMap( 'campaign:10',  [
				'callback'   => 'https://www.google.com.ar',
				'payout'	 => 10
			]);

			$cache->setMap( 'campaign:11',  [
				'callback'   => 'https://www.google.com.ar',
				'payout'	 => 11
			]);

			$cache->setMap( 'campaign:12',  [
				'callback'   => 'https://www.google.com.ar',
				'payout'	 => 12
			]);

			$cache->setMap( 'campaign:13',  [
				'callback'   => 'https://www.google.com.ar',
				'payout'	 => 13
			]);

			$cache->setMap( 'campaign:14',  [
				'callback'   => 'https://www.google.com.ar',
				'payout'	 => 14
			]);	

			$cache->setMap( 'campaign:20',  [
				'callback'   => 'https://www.google.com.ar',
				'payout'	 => 20
			]);

			$cache->setMap( 'campaign:21',  [
				'callback'   => 'https://www.google.com.ar',
				'payout'	 => 21
			]);

			$cache->setMap( 'campaign:22',  [
				'callback'   => 'https://www.google.com.ar',
				'payout'	 => 22
			]);

			$cache->setMap( 'campaign:23',  [
				'callback'   => 'https://www.google.com.ar',
				'payout'	 => 23
			]);

			$cache->setMap( 'campaign:24',  [
				'callback'   => 'https://www.google.com.ar',
				'payout'	 => 24
			]);	

			$cache->setMap( 'campaign:30',  [
				'callback'   => 'https://www.google.com.ar',
				'payout'	 => 30
			]);

			$cache->setMap( 'campaign:31',  [
				'callback'   => 'https://www.google.com.ar',
				'payout'	 => 31
			]);

			$cache->setMap( 'campaign:32',  [
				'callback'   => 'https://www.google.com.ar',
				'payout'	 => 32
			]);

			$cache->setMap( 'campaign:33',  [
				'callback'   => 'https://www.google.com.ar',
				'payout'	 => 33
			]);

			$cache->setMap( 'campaign:34',  [
				'callback'   => 'https://www.google.com.ar',
				'payout'	 => 34
			]);																		


        }

	}

?>