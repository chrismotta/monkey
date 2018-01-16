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
            $this->_registry->redis = new Framework\Database\Redis\Predis( 'tcp://'.Config\Ad::REDIS_CONFIG.':6379' );

            //$ipToLocation = new Framework\TCP\Geolocation\Source\IP2Location( Config\Ad::IP2LOCATION_BIN );

            $maxmind = new Framework\TCP\Geolocation\Source\Maxmind([
                'path_conn_type' => Config\Ad::MAXMIND_DB_CONN_TYPE, 
                'path_country'   => Config\Ad::MAXMIND_DB_COUNTRY, 
                'path_isp'       => Config\Ad::MAXMIND_DB_ISP 
            ]);

            if(isset($_GET['testfq'])){
                
                $forensiqObj = new Framework\AdServing\FraudDetection\Forensiq(
                        new Framework\TCP\HTTP\Client\cURL(),
                        new Framework\TCP\HTTP\Client\Request(),
                        Config\Ad::FORENSIQ_KEY
                    );

                die('testfq');

                $ana = $forensiqObj->analize([
                    'request_type' =>'display',
                    'ip_address'   =>'200.69.24.13',
                    'session_id'   =>'123',
                    'source_id'    =>'123'
                ]);

                if($ana)
                    var_dump($forensiqObj->getRiskLevel());
                else
                    echo 'not analized';
                
            
            }

        	$ad = new Model\Ad(
        		$this->_registry,
        		new Priv\CampaignSelection( $this->_registry ),
        		new Framework\AdServing\FraudDetection\Forensiq(
        			new Framework\TCP\HTTP\Client\cURL(),
        			new Framework\TCP\HTTP\Client\Request(),
        			Config\Ad::FORENSIQ_KEY
        		),
        		$this->_registry->redis,
        		new Framework\Device\Detection\Piwik(),
        		$maxmind
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