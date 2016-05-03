<?php
/**
 * DokuWiki Plugin webdavclient (Action Component)
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Böhler <dev@aboehler.at>
 */
 
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

class action_plugin_webdavclient extends DokuWiki_Action_Plugin {

  protected $hlp = null;
  
  // Load the helper plugin
  public function action_plugin_webdavclient() {
    
    $this->hlp =& plugin_load('helper', 'webdavclient');
        
  }
   
  // Register our hooks 
  function register(Doku_Event_Handler $controller) {
    //controller->register_hook('INDEXER_TASKS_RUN', 'BEFORE', $this, 'handle_indexer_sync');
    $controller->register_hook('TPL_ACT_RENDER', 'AFTER', $this, 'handle_indexer_sync');
  }
  
  function handle_indexer_sync(&$event, $param)
  {
      // Check if we use an external CRON or WebCRON instead
      if($this->getConf('use_cron') === 1)
        return;

      // Try to sync the connectins; if one connection synced successfully,
      // we stop the propagation
      if($this->hlp->indexerSyncAllConnections() === true)
      {
          $event->preventDefault();
          $event->stopPropagation();
      }
  }
}
