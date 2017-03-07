<?php

	namespace Aff\Model;

	use Aff\Framework;


	interface CampaignSelectionInterface
	{

		public function run ( array $campaigns_tags, array $options = null ); // bool

		/*
			options = [
				'tag_type'	= 'iframe' | 'script' | 'js_raw'
			]
		*/			

		public function getCampaignId ( );

		public function getTag ( );

	}
	
?>