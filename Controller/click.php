<?php

	namespace Aff\Ad\Controller;

	use Aff\Framework,
		Aff\Ad\Model,
		Aff\Config,
		Aff\Ad\Core;


	class click extends Core\ControllerAbstract
	{

		public function __construct ( Framework\Registry $registry )
		{
			parent::__construct( $registry );
		}


		public function route ( )
        {
        	$clicks = new Model\Clicks(
        		$this->_registry,
        		new Framework\Database\Redis\Predis( 'tcp://'.Config\Ad::REDIS_CONFIG.':6379' )
        	);

            $path1 = $this->_registry->httpRequest->getPathElement(1);

            switch ( $path1 )
            {
                case 'test':
                	$campaignId = $this->_registry->httpRequest->getPathElement(2);
                	$clicks->test( $campaignId );
                break;           
                case 'pixel':
		        	$clickId = $this->_registry->httpRequest->getPathElement(1);
		        	$clicks->pixel( $clickId );
		        	$this->render( 'pixel' );
                break;                   
                default:
		        	$clickId = $this->_registry->httpRequest->getPathElement(1);
		        	$clicks->log( $clickId );
		        	$this->render( 'click' );		        	
                break;
            }
        }

	}

?>