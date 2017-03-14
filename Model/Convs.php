<?php

	namespace Aff\Ad\Model;

	use Aff\Framework;


	class Convs extends Framework\ModelAbstract
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
			if ( $click_id && \is_integer( $click_id ) )
			{
				$clickCount = $this->_cache->addToSet( 'convs', $click_id );
				$clickCount = $this->_cache->set( 'conv:'. $click_id, $this->_registry->httpRequest->getTimestamp() );
			}

			//-------------------------------------
			// RENDER
			//-------------------------------------
			// Tell controller process completed successfully
			$this->_registry->status = 200;
			return true;
		}

	}

?>