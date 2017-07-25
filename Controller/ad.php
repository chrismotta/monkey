<?php

	namespace Aff\Ad\Controller;

	use Aff\Framework,
		Aff\Ad\Model,
		Aff\Config,
		Aff\Priv,
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
        		$this->_registry,
        		new Priv\CampaignSelection( $this->_registry ),
        		new Framework\AdServing\FraudDetection\Forensiq(
        			new Framework\TCP\HTTP\Client\cURL(),
        			new Framework\TCP\HTTP\Client\Request(),
        			Config\Ad::FORENSIQ_KEY
        		),
        		new Framework\Database\Redis\Predis( 'tcp://'.Config\Ad::REDIS_CONFIG.':6379' ),
        		new Framework\Device\Detection\Piwik(),
        		new Framework\TCP\Geolocation\Source\IP2Location( Config\Ad::IP2LOCATION_BIN )
        	);

            $path0 = $this->_registry->httpRequest->getPathElement(0);

            switch ( $path0 )
            {
                case 'js':
                    $tagType     = 'js';
                    $placementId = $this->_registry->httpRequest->getPathElement(1);
                break;           
                default:
                    $tagType     = null;
                    $placementId = $path0;
                break;
            }


        	$ad->render( $placementId, $tagType );

            $this->render( $this->_registry->view );
        }

	}

?>