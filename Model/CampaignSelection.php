<?php

	namespace Aff\Ad\Model;

	use Aff\Framework;


	class CampaignSelection extends Framework\ModelAbstract
	{

		public function __construct ( 
			Framework\Registry $registry
		)
		{
			parent::__construct( $registry );
		}


		public function getCampaignId ( array $campaign_ids )
		{
			$r = rand( 0, count( $campaign_ids ) );

			return $campaign_ids[$r];
		}

	}

?>