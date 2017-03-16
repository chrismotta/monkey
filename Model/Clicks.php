<?php

	namespace Aff\Ad\Model;

	use Aff\Framework;


	class Clicks extends Framework\ModelAbstract
	{

		private $_cache;

		public function __construct ( 
			Framework\Registry $registry,
			Framework\Database\KeyValueInterface $cache
		)
		{
			parent::__construct( $registry );

			$this->_cache = $cache;
		}


		public function log ( $click_id )
		{
			//-------------------------------------
			// LOG
			//-------------------------------------
			if ( $click_id && $this->_cache->exists( 'campaignlog:'. $click_id ) )
			{
				$this->_cache->setMapField( 'campaignlog:'. $click_id, 'click_time', $this->_registry->httpRequest->getTimestamp() );
			}

			//-------------------------------------
			// NOTIFY AFFILIATE  
			//-------------------------------------
        	$httpClient 	   = new Framework\TCP\HTTP\Client\cURL();
			$httpClientRequest = new Framework\TCP\HTTP\Client\Request();

			$httpClientRequest->setURL( \str_replace( '{CLICK_ID}', $click_id, $campaign['callback'] ) );

			$httpClient->send( $httpClientRequest );

			//-------------------------------------
			// RENDER
			//-------------------------------------
			// Tell controller process completed successfully
			$this->_registry->status = 200;
			return true;
		}

	}

?>