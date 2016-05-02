<?php
/** 
  * Helper Class for the webdavclient plugin
  * This helper does the actual work.
  * 
  * Configurable in DokuWiki's configuration
  */
  
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

class helper_plugin_webdavclient extends DokuWiki_Plugin {
  
  protected $sqlite = null;
  protected $curl;
  
  /**
    * Constructor to load the configuration
    */
  public function helper_plugin_webdavclient() {
    global $conf;
    $this->sqlite =& plugin_load('helper', 'sqlite');
    if(!$this->sqlite)
    {
        if($conf['allowdebug'])
            dbglog('This plugin requires the sqlite plugin. Please install it.');
        msg('This plugin requires the sqlite plugin. Please install it.');
        return;
    }
    
    if(!$this->sqlite->init('webdavclient', DOKU_PLUGIN.'webdavclient/db/'))
    {
        if($conf['allowdebug'])
            dbglog('Error initialising the SQLite DB for webdavclient');
        return;
    }
  }
  
  public function addCalendarEntry($connectionId, $data, $dwuser = null)
  {
      return false;
  }
  
  public function editCalendarEntry($onnectionId, $uid, $data, $dwuser = null)
  {
      return false;
  }
  
  public function deleteCalendarEntry($connectionId, $uid, $dwuser = null)
  {
      return false;
  }
  
  public function getCalendarEntries($connectionId, $dwuser = null)
  {
      $query = "SELECT calendardata, componenttype, uid FROM calendarobjects WHERE calendarid = ?";
      $res = $this->sqlite->query($query, $connectionId);
      return $this->sqlite->res2arr($res);
  }
  
  public function addAddressbookEntry($connectionId, $data, $dwuser = null)
  {
      return false;
  }
  
  public function editAddressbookEntry($connectionId, $uid, $data, $dwuser = null)
  {
      return false;
  }
  
  public function deleteAddressbookEntry($connectionId, $uid, $dwuser = null)
  {
      return false;
  }
  
  public function getAddressbookEntries($connectionId, $dwuser = null)
  {
      $query = "SELECT contactdata, uid FROM addressbookobjects WHERE addressbookid = ?";
      $res = $this->sqlite->query($query, $connectionId);
      return $this->sqlite->res2arr($res);
  }
  
  public function addConnection($uri, $username, $password, $displayname, $description, $type, $syncinterval = 3600, $dwuser = null)
  {
      $query = "INSERT INTO connections (uri, displayname, description, username, password, dwuser, type, syncinterval, lastsynced) ".
               "VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?);";
      $res = $this->sqlite->query($query, $uri, $displayname, $description, $username, $password, $dwuser, $type, $syncinterval, 0);
      if($res === false)
        return false;
      
      // Retrieve the connection ID
      $query = "SELECT id FROM connections WHERE uri = ? AND displayname = ? AND description = ? AND username = ? AND password = ? AND dwuser = ? AND type = ? and syncinterval = ? and lastsynced = 0";
      $res = $this->sqlite->query($query, $uri, $displayname, $description, $username, $password, $dwuser, $type, $syncinterval);
      $row = $this->sqlite->res2row($res);
      
      if(isset($row['id']))
        return $row['id'];
      
      return false;
  }
  
  public function getConnections()
  {
      $query = "SELECT id, uri, displayname, description, synctoken, username, password, dwuser, type, syncinterval, lastsynced, ctag FROM connections";
      $res = $this->sqlite->query($query);
      return $this->sqlite->res2arr($res);
  }
  
  public function getConnection($connectionId)
  {
      $query = "SELECT uri, displayname, description, synctoken, username, password, dwuser, type, syncinterval, lastsynced, ctag FROM connections WHERE id = ?";
      $res = $this->sqlite->query($query, $connectionId);
      return $this->sqlite->res2row($res);
  }
  
  public function syncConnection($connectionId)
  {
      global $conf;
      if($conf['allowdebug'])
        dbglog('syncConnection: '.$connectionId);
      $conn = $this->getConnection($connectionId);
      if($conn === false)
        return false;
      
      if($conf['allowdebug'])
        dbglog('Got connection information for connectionId: '.$connectionId);
      
      // Sync required?
      if(time() < ($conn['lastsynced'] + $conn['syncinterval']))
        return false;
      
      if($conf['allowdebug'])
        dbglog('Sync required for ConnectionID: '.$connectionId);
      
      if(($conn['type'] !== 'contacts') && ($conn['type'] !== 'calendar'))
        return false;
      
      // Perform the sync

      $syncResponse = $this->getCollectionStatus($conn);
      
      // If the server supports getctag, we can check using the ctag if something has changed

      if(!is_null($syncResponse['getctag']))
      {
          if($conn['ctag'] === $syncResponse['getctag'])
          {
            if($conf['allowdebug'])
              dbglog('CTags match, no need to sync');
            return false;
          }
      }
            
      // Download all data in one request
      $client = new DokuHTTPClient();
      $client->user = $conn['username'];
      $client->pass = $conn['password'];
      $client->http = '1.1';
      $client->debug = true;
      $client->headers['Depth'] = '1';
      $client->headers['Content-Type'] = 'application/xml; charset=utf-8';
      
      if($conn['type'] === 'contacts')
      {
        $data = $this->buildReport('urn:ietf:params:xml:ns:carddav', 
                                   'C:addressbook-query', array('D:getetag', 
                                   'D:getlastmodified', 'C:address-data'));
      }
      elseif($conn['type'] === 'calendar')
      {
        $data = $this->buildReport('urn:ietf:params:xml:ns:caldav', 
                                   'C:calendar-query', array('D:getetag', 
                                   'D:getlastmodified', 'C:calendar-data'),
                                   array('C:comp-filter' => 'VCALENDAR'));
      }
      
      $client->headers['Content-Length'] = strlen($data);
           
      $resp = $client->sendRequest($conn['uri'], $data, 'REPORT');
      if($client->status > 400)
      {
          if($conf['allowdebug'])
            dbglog('Error: Status reported was ' . $client->status);
          return false;
      }
      
      $response = $this->clean_response($client->resp_body);
      if($conf['allowdebug'])
        dbglog($response);
      
      try
      {
        $xml = simplexml_load_string($response);
      }
      catch(Exception $e)
      {
        if($conf['allowdebug'])
          dbglog('Exception occured: '.$e->getMessage());
        return false;
      }
      
      $this->sqlite->query("BEGIN TRANSACTION");
      // Handle Contacts
      if($conn['type'] === 'contacts')
      {
        $this->sqlite->query("DELETE FROM addressbookobjects WHERE addressbookid = ?", $connectionId);

        if(!empty($xml->response))
        {
            foreach($xml->response as $response)
            {
                $addressobject = array();
                $addressobject['uid'] = uniqid();
                $addressobject['getetag'] = '';
                $addressobject['getlastmodified'] = '';
                $addressobject['address-data'] = '';
                $addressobject['href'] = basename((string)$response->href);
                
                $status = $this->parseHttpStatus((string)$response->propstat->status);
                if($status === '200')
                {
                    foreach($response->propstat->prop->children() as $child)
                    {
                        $addressobject[$child->getName()] = trim((string)$child, '"');
                    }
                }
                $this->object2addressbook($connectionId, $addressobject);
            }
        }
      
      }
      // Handle Calendar
      elseif($conn['type'] === 'calendar')
      {
        $this->sqlite->query("DELETE FROM calendarobjects WHERE calendarid = ?", $connectionId);
        if(!empty($xml->response))
        {
            foreach($xml->response as $response)
            {
                $calendarobject = array();
                $calendarobject['uid'] = uniqid();
                $calendarobject['getetag'] = '';
                $calendarobject['getlastmodified'] = '';
                $calendarobject['calendar-data'] = '';
                $calendarobject['componenttype'] = 'VCALENDAR';
                $calendarobject['href'] = basename((string)$response->href);
                
                $status = $this->parseHttpStatus((string)$response->propstat->status);
                if($status === '200')
                {
                    foreach($response->propstat->prop->children() as $child)
                    {
                        $calendarobject[$child->getName()] = trim((string)$child, '"');
                    }
                }
                $this->object2calendar($connectionId, $calendarobject);
            }
        }
      }
      
      $this->sqlite->query("COMMIT TRANSACTION");
      
      $this->updateConnection($connectionId, time(), $syncResponse['getctag']);
            
      return true;
  }

  private function getCollectionStatus($conn)
  {
      global $conf;
      $client = new DokuHTTPClient();
      $client->user = $conn['username'];
      $client->pass = $conn['password'];
      $client->http = '1.1';
      $client->headers['Content-Type'] = 'application/xml; charset=utf-8';
      $client->headers['Depth'] = ' 0'; // The space is necessary because DokuHTTPClient checks using empty()
      $data = $this->buildPropfind(array('d:displayname', 'cs:getctag'));
      $client->headers['Content-Length'] = strlen($data);
      $resp = $client->sendRequest($conn['uri'], $data, 'PROPFIND');

      if($client->status > 400)
      {
          if($conf['allowdebug'])
            dbglog('Error: Status reported was ' . $client->status);
          return false;
      }
      
      if($conf['allowdebug'])
      {
        dbglog($client->status);
      }
      
      $response = $this->clean_response($client->resp_body);
      if($conf['allowdebug'])
        dbglog($response);

      try
      {
        $xml = simplexml_load_string($response);
      }
      catch(Exception $e)
      {
        if($conf['allowdebug'])
          dbglog('Exception occured: '.$e->getMessage());
        return false;
      }
            
      $syncResponse = array();
      $syncResponse['getctag'] = null;
      $syncResponse['displayname'] = '';
      
      if(!empty($xml->response))
      {
          foreach($xml->response as $response)
          {
              $status = $this->parseHttpStatus((string)$response->propstat->status);
              // We parse here all props that succeeded and ignore the failed ones
              if($status === '200')
              {
                  foreach($response->propstat->prop->children() as $child)
                  {
                      $syncResponse[$child->getName()] = trim((string)$child, '"');
                  }
              }
          }
      }
      return $syncResponse;
  }

  private function updateConnection($connectionId, $lastSynced, $ctag = null)
  {
      if(is_null($ctag))
        $ctag = '';
      
      $query = "UPDATE connections SET lastsynced = ?, ctag = ? WHERE id = ?";
      $res = $this->sqlite->query($query, $lastSynced, $ctag, $connectionId);
      if($res !== false)
        return true;
      return false;
  }
  
  private function object2calendar($connectionId, $calendarobject)
  {
      $query = "INSERT INTO calendarobjects (calendardata, uri, calendarid, lastmodified, etag, size, componenttype, uid) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
      $lastmod = new \DateTime($addressobject['getlastmodified']);
      $res = $this->sqlite->query($query, $calendarobject['calendar-data'], $calendarobject['href'], $connectionId, $lastmod->getTimestamp(), $calendarobject['getetag'], strlen($calendarobject['calendar-data']), $calendarobject['componenttype'], $calendarobject['uid']);
      if($res !== false)
        return true;
      return false;
  }

  private function object2addressbook($connectionId, $addressobject)
  {
      $query = "INSERT INTO addressbookobjects (contactdata, uri, addressbookid, lastmodified, etag, size, uid) VALUES(?, ?, ?, ?, ?, ?, ?)";
      $lastmod = new \DateTime($addressobject['getlastmodified']);
      $res = $this->sqlite->query($query, $addressobject['address-data'], $addressobject['href'], $connectionId, $lastmod->getTimestamp(), $addressobject['getetag'], strlen($addressobject['address-data']), $addressobject['uid']);
      if($res !== false)
        return true;
      return false;
  }

  private function parseHttpStatus($statusString)
  {
      $status = explode(' ', $statusString, 3);
      $status = $status[1];
      return $status;
  }

  private function clean_response($response)
  {
      $response = utf8_encode($response);
      // Strip the namespace prefixes on all XML tags
      $response = preg_replace('/(<\/*)[^>:]+:/', '$1', $response);
      return $response;
  }

  private function buildPropfind($props)
  {
      $xml = new XMLWriter();
      $xml->openMemory();
      $xml->setIndent(4);
      $xml->startDocument('1.0', 'utf-8');
      $xml->startElement('d:propfind');
      $xml->writeAttribute('xmlns:d', 'DAV:');
      $xml->writeAttribute('xmlns:cs', 'http://calendarserver.org/ns/');
      $xml->startElement('d:prop');
        foreach($props as $prop)
          $xml->writeElement($prop);
      $xml->endElement();
      $xml->endElement();
      $xml->endDocument();
      return $xml->outputMemory()."\r\n";
  }
  
  private function buildReport($ns, $op, $props = array(), $filters = array())
  {
      $xml = new XMLWriter();
      $xml->openMemory();
      $xml->setIndent(4);
      $xml->startDocument('1.0', 'utf-8');
      $xml->startElement($op);
          $xml->writeAttribute('xmlns:D', 'DAV:');
          $xml->writeAttribute('xmlns:C', $ns);
          $xml->startElement('D:prop');
              foreach($props as $prop)
              {
                $xml->writeElement($prop);
              }
          $xml->endElement();
          if(!empty($filters))
          {
              $xml->startElement('C:filter');
                foreach($filters as $filter => $name)
                {
                    $xml->startElement($filter);
                    $xml->writeAttribute('name', $name);
                    $xml->endElement();
                }
              $xml->endElement();
          }
      $xml->endElement();
      $xml->endDocument();
      return $xml->outputMemory()."\r\n";
  }
  
  private function updateCtag($connectionId, $ctag)
  {
      $query = "UPDATE connections SET ctag = ? WHERE id = ?";
      $res = $this->sqlite->query($query, $ctag, $connectionId);
      if($res !== false)
        return true;
      return false;
  }
  
  public function syncAllConnections()
  {
      $connections = $this->getConnections();
      foreach($connections as $connection)
      {
          $this->syncConnection($connection['id']);
      }
  }
  
  public function indexerSyncAllConnections()
  {
      global $conf;
      if($conf['allowdebug'])
        dbglog('IndexerSyncAllConnections');
      $connections = $this->getConnections();
      foreach($connections as $connection)
      {
          if($this->syncConnection($connection['id']) === true)
            return true;
      }
      return false;
  }
}