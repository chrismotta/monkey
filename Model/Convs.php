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
			if ( $click_id )
			{
				$this->_cache->useDatabase( $this->_getCurrentDatabase() );

				$this->_cache->addToSortedSet( 'convs', $this->_registry->httpRequest->getTimestamp(), $click_id  );

				if ( $this->_cache->exists('conv:'. $click_id) )
				{
					$this->_registry->message 	  = 'Conversion already exists';
					$this->_registry->messageType = 'warning';
				}
				else
				{
					$this->_cache->set( 'conv:'. $click_id, $this->_registry->httpRequest->getTimestamp() );

					$this->_registry->message 	  = 'Conversion tracked';
					$this->_registry->messageType = 'success';

				}
			}

			//-------------------------------------
			// RENDER
			//-------------------------------------
			// Tell controller process completed successfully
			$this->_registry->status = 200;
			return true;
		}


		private function _getCurrentDatabase ( )
		{
			return \floor(($this->_registry->httpRequest->getTimestamp()/60/60/24))%2+3;
		}

	}

?>