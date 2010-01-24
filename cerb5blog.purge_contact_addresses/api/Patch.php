<?php
  class Cerb5BlogPurgeContactAddressesPatchContainer extends DevblocksPatchContainerExtension {
	function __construct($manifest) {
		parent::__construct($manifest);

		/*
		 * [JAS]: Just add a sequential build number here (and update plugin.xml) and
		 * write a case in runVersion().  You should comment the milestone next to your build
		 * number.
		 */

		$file_prefix = dirname(dirname(__FILE__)) . '/patches';

		$this->registerPatch(new DevblocksPatch('cerb5blog.purge_contact_addresses',3,$file_prefix.'/4.2.3.php',''));
	}
};
