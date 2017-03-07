<?php

	namespace Aff\Ad\Model;

	use Aff\Framework;


	class CampaignSelection extends Framework\ModelAbstract implements CampaignSelectionInterface
	{

		public function __construct ( 
			Framework\Registry $registry
		)
		{
			parent::__construct( $registry );
		}


		public function run ( array $campaigns_tags, array $options = null )
		{

		}


		public function getCampaignId ( )
		{
			return 'cid';
		}


		public function getTag ( )
		{
			return 'tag';
		}

	}

?>