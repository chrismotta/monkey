<?php

	namespace Aff\Tr\Controller;

	use Aff\Framework,
		Aff\Tr\Model,
		Aff\Config,
		Aff\Tr\Core;


	class tr extends Core\ControllerAbstract
	{

		public function __construct ( Framework\Registry $registry )
		{
			parent::__construct( $registry );
		}


		public function route ( )
        {
        	$tr = new Model\Clicks(
        		$this->_registry,
        		new Framework\Database\Redis\Predis( 'tcp://'.Config\Tr::REDIS_CONFIG.':6379' ),
        		new Framework\Device\Detection\Piwik(),
        		new Framework\TCP\Geolocation\Source\IP2Location( Config\Tr::IP2LOCATION_BIN )
        	);

        	$tr->render();

            $this->render( 'tr' );
        }

	}

?>