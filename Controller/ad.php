<?php

	namespace Aff\Ad\Controller;

	use Aff\Framework,
		Aff\Ad\Model,
		Aff\Config,
		Aff\Ad\Core;


	class ad extends Core\ControllerAbstract
	{

		public function __construct ( Framework\Registry $registry )
		{
			parent::__construct( $registry );
		}


		public function route ( )
        {
        	$ad = new Model\Ad(
        		new Model\CampaignSelection( $this->_registry ),
        		$this->_registry,
        		new Framework\Database\Redis\Predis( 'tcp://'.Config\Ad::REDIS_CONFIG.':6379' ),
        		new Framework\Device\Detection\Piwik(),
        		new Framework\TCP\Geolocation\Source\IP2Location( Config\Ad::IP2LOCATION_BIN )
        	);

        	$placementId = $this->_registry->httpRequest->getPathElement(0);

        	$ad->render( $placementId );

            $this->render( 'ad' );
        }

	}

?>