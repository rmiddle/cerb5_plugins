<?php

$db = DevblocksPlatform::getDatabaseService();
$logger = DevblocksPlatform::getConsoleLog();
$tables = $db->metaTables();

// ===========================================================================
// Add index to message.address_id to make this purge works well.

$logger->info("[Cerb5Blog.com] Creating index for message.address_id");
list($columns, $indexes) = $db->metaTable('message');

if(!isset($indexes['address_id'])) {
	$db->Execute("ALTER TABLE message ADD INDEX address_id (address_id)");
}

return TRUE;
?>