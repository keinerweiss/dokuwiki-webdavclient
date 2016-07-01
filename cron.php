<?php

/**
 * DoukWiki webdavclient PlugIn - CRON service
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Böhler <dev@aboehler.at>
 */

if(!defined('DOKU_INC')) define('DOKU_INC', dirname(__FILE__).'/../../../');
if (!defined('DOKU_DISABLE_GZIP_OUTPUT')) define('DOKU_DISABLE_GZIP_OUTPUT', 1);
require_once(DOKU_INC.'inc/init.php');
session_write_close(); //close session

// Load the helper plugin
$hlp = null;
$hlp =& plugin_load('helper', 'webdavclient');

global $conf;

if(is_null($hlp))
{
    if($conf['allowdebug'])
        dbglog('Error loading helper plugin');
    die('Error loading helper plugin');
}

$cron = $hlp->getConfig('use_cron');
if($cron === 0)
{
    if($conf['allowdebug'])
        dbglog('CRON support is disabled');
    die('CRON support is disabled');
}

$hlp->syncAllConnections();

echo 'CRON done.';
