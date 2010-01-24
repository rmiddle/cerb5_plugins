<?php
class Cerb5BlogPurgeContactAddressesEventListener extends DevblocksEventListenerExtension {
    function __construct($manifest) {
        parent::__construct($manifest);
    }

    /**
     * @param Model_DevblocksEvent $event
     */
    function handleEvent(Model_DevblocksEvent $event) {
        switch($event->id) {
            case 'cron.maint':
              $records_removed = 0;
              $logger = DevblocksPlatform::getConsoleLog();
              $logger->info("[Cerb5Blog.com Maint] Starting Purging Contact Addresses task");
              @set_time_limit(0); // Unlimited (if possible)
							@ini_set('memory_limit','128M');

              $logger->info("[Cerb5Blog.com Maint] Overloaded memory_limit to: " . ini_get('memory_limit'));
              $logger->info("[Cerb5Blog.com Maint] Overloaded max_execution_time to: " . ini_get('max_execution_time'));
              $runtime = microtime(true);
              //Do something
              $db = DevblocksPlatform::getDatabaseService();

              $sql = "SELECT a.id ";
              $sql .= "FROM address a ";
              $sql .= "LEFT JOIN message m ON a.id = m.address_id ";
              $sql .= "LEFT JOIN requester r ON a.id = r.address_id ";
              $sql .= "LEFT JOIN ticket_comment tc ON a.id = tc.address_id ";
              $sql .= "WHERE a.contact_org_id = 0 ";
              $sql .= "AND m.address_id IS NULL ";
              $sql .= "AND r.address_id IS NULL ";
              $sql .= "AND tc.address_id IS NULL ";
              $sql .= "ORDER BY a.id ASC ";
              $rs = $db->Execute($sql);
              while(!$rs->EOF) {
                // Loop though the records.
                DAO_Address::delete($rs->fields['id']);

                // Increament the records removed connecter
                $records_removed++;
                $rs->MoveNext();
              }
              $logger->info("[Cerb5Blog.com Maint] Total Records Removed: ".$records_removed);
              $logger->info("[Cerb5Blog.com Maint] Total Runtime: ".((microtime(true)-$runtime)*1000)." ms");
              break;
        }
    }
};

