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
    
    // Init DB
    if(!$this->sqlite->init('webdavclient', DOKU_PLUGIN.'webdavclient/db/'))
    {
        if($conf['allowdebug'])
            dbglog('Error initialising the SQLite DB for webdavclient');
        return;
    }
  }
  
  /**
   * Add a new calendar entry to a given connection ID - currently not supported
   */
  public function addCalendarEntry($connectionId, $data, $dwuser = null)
  {
      return false;
  }
  
  /**
   * Edit a calendar entry for a given connection ID - currently not supported
   */
  public function editCalendarEntry($onnectionId, $uid, $data, $dwuser = null)
  {
      return false;
  }
  
  /**
   * Delete a calendar entry for a given connection ID - currently not supported
   */
  public function deleteCalendarEntry($connectionId, $uid, $dwuser = null)
  {
      return false;
  }
  
  /**
   * Retreive all calendar events for a given connection ID.
   * A sync is NOT performed during this stage, only locally cached data
   * are available.
   * 
   * @param int $connectionId The connection ID to retrieve
   * @param string $startDate The start date as a string
   * @param string $endDate The end date as a string
   * @param string $dwuser Unused
   * 
   * @return An array with events
   */
  public function getCalendarEntries($connectionId, $startDate = null, $endDate = null, $dwuser = null)
  {
      $query = "SELECT calendardata, componenttype, uid FROM calendarobjects WHERE calendarid = ?";
      $startTs = null;
      $endTs = null;
      if($startDate !== null)
      {
        $startTs = new \DateTime($startDate);
        $query .= " AND lastoccurence > ".$this->sqlite->quote_string($startTs->getTimestamp());
      }
      if($endDate !== null)
      {
        $endTs = new \DateTime($endDate);
        $query .= " AND firstoccurence < ".$this->sqlite->quote_string($endTs->getTimestamp());
      }
      $res = $this->sqlite->query($query, $connectionId);
      return $this->sqlite->res2arr($res);
  }
  
  /**
   * Add a new address book entry to a given connection ID - currently not supported
   */
  public function addAddressbookEntry($connectionId, $data, $dwuser = null)
  {
      return false;
  }
  
  /**
   * Edit an address book entry for a given connection ID - currently not supported
   */
  public function editAddressbookEntry($connectionId, $uid, $data, $dwuser = null)
  {
      return false;
  }
  
  /**
   * Delete an address book entry from a given connection ID - currently not supported
   */
  public function deleteAddressbookEntry($connectionId, $uid, $dwuser = null)
  {
      return false;
  }
  
  /**
   * Retrieve all address book entries for a given connection ID
   * 
   * @param int $connectionId The connection ID to work with
   * @param string $dwuser (optional) currently unused
   * 
   * @param An array with the address book entries
   */
  public function getAddressbookEntries($connectionId, $dwuser = null)
  {
      $query = "SELECT contactdata, uid FROM addressbookobjects WHERE addressbookid = ?";
      $res = $this->sqlite->query($query, $connectionId);
      return $this->sqlite->res2arr($res);
  }
  
  /**
   * Add a new WebDAV connection to the backend
   * 
   * @param string $uri The URI of the new ressource
   * @param string $username The username for logging in
   * @param string $password The password for logging in
   * @param string $displayname The displayname of the ressource
   * @param string $description The description of the ressource
   * @param string $type The connection type, can be 'contacts' or 'calendar'
   * @param int $syncinterval The sync interval in seconds
   * @param boolean $active (optional) If the connection is active, defaults to true
   * @param string $dwuser (optional) currently unused
   * 
   * @return true on success, otherwise false
   */
  public function addConnection($uri, $username, $password, $displayname, $description, $type, $syncinterval = 3600, $active = true, $dwuser = null)
  {
      $query = "INSERT INTO connections (uri, displayname, description, username, password, dwuser, type, syncinterval, lastsynced, active) ".
               "VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?);";
      $res = $this->sqlite->query($query, $uri, $displayname, $description, $username, $password, $dwuser, $type, $syncinterval, $active, 0);
      if($res === false)
        return false;
      
      // Retrieve the connection ID
      $query = "SELECT id FROM connections WHERE uri = ? AND displayname = ? AND description = ? AND username = ? AND password = ? AND dwuser = ? AND type = ? and syncinterval = ? and lastsynced = 0 AND active = ?";
      $res = $this->sqlite->query($query, $uri, $displayname, $description, $username, $password, $dwuser, $type, $syncinterval, $active);
      $row = $this->sqlite->res2row($res);
      
      if(isset($row['id']))
        return $row['id'];
      
      return false;
  }
  
  /**
   * Retrieve information about all configured connections
   * Attention: This includes usernames and passwords
   * 
   * @return An array containing the connection information
   */
  public function getConnections()
  {
      $query = "SELECT id, uri, displayname, description, synctoken, username, password, dwuser, type, syncinterval, lastsynced, ctag, active FROM connections";
      $res = $this->sqlite->query($query);
      return $this->sqlite->res2arr($res);
  }
  
  /**
   * Retrieve information about a specific connection
   * Attention: This includes usernames and passwords
   * 
   * @return An array containing the connection information
   */
  public function getConnection($connectionId)
  {
      $query = "SELECT uri, displayname, description, synctoken, username, password, dwuser, type, syncinterval, lastsynced, ctag, active FROM connections WHERE id = ?";
      $res = $this->sqlite->query($query, $connectionId);
      return $this->sqlite->res2row($res);
  }
  
  /**
   * Sync a single connection if required.
   * Sync requirement is checked based on
   *   1) Time
   *   2) CTag
   * 
   * @param int $connectionId The connection ID to work with
   * 
   * @return true on success, otherwise false
   */
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
      
      // Active?
      if($conn['active'] !== 1)
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

  /**
   * Retrieve the status of a collection
   * 
   * @param array $conn An array containing connection information
   * @return an Array containing the status
   */
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

  /**
   * Update the status of a connection
   * 
   * @param int $connectionId The connection ID to work with
   * @param int $lastSynced The last time the connection was synnchronised
   * @param string $ctag (optional) The CTag of the current sync run
   * 
   * @return true on success, otherwise false
   */
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
  
  /**
   * Convert a given calendar object (including ICS data) and save
   * it in the local database
   * 
   * @param int $connectionId The connection ID to work with
   * @param array $calendarobject The calendar object to convert
   * 
   * @return true on success, otherwise false
   */
  private function object2calendar($connectionId, $calendarobject)
  {
      // Load Sabre/VObject - we need this to parse the ICS file and generate the
      // event's start and end timestamps
      // The code is heavily based on Sabre's PDO backend
      require_once(DOKU_PLUGIN.'webdavclient/vendor/autoload.php');
      $vcal = \Sabre\VObject\Reader::read($calendarobject['calendar-data']);
      
      $maxDate = new \DateTime('2038-01-01'); // Max. timestamp on 32bit
      $firstoccurrence = $vcal->VEVENT->DTSTART->getDateTime()->getTimeStamp();
      $lastoccurrence = $maxDate->getTimestamp();
      $recurrence = $vcal->VEVENT->RRULE;
      // If it is a recurring event, pass it through Sabre's EventIterator
      if($recurrence != null)
      {
          $it = new \Sabre\VObject\Recur\EventIterator(array($vcal->VEVENT));
          if($it->isInfinite())
          {
              $lastoccurrence = $maxDate->getTimestamp();
          }
          else
          {
              $end = $it->getDtEnd();
              while($it->valid() && $end < $maxDate)
              {
                  $end = $it->getDtEnd();
                  $it->next();
              }
              $lastoccurrence = $end->getTimestamp();
          }
      }
      else
      {
          if(isset($vcal->VEVENT->DTEND))
          {
              $lastoccurrence = $vcal->VEVENT->DTEND->getDateTime()->getTimeStamp();
          }
          elseif(isset($vcal->VEVENT->DURATION))
          {
              $endDate = clone $vcal->VEVENT->DTSTART->getDateTime();
              $endDate = $endDate->add(\Sabre\VObject\DateTimeParser::parse($vcal->VEVENT->DURATION->getValue()));
              $lastoccurrence = $endDate->getTimeStamp();
          }
          elseif(!$vcal->VEVENT->DTSTART->hasTime())
          {
              $endDate = clone $vcal->VEVENT->DTSTART->getDateTime();
              $endDate = $endDate->modify('+1 day');
              $lastoccurrence = $endDate->getTimeStamp();
          }
          else
          {
              $lastoccurrence = $firstoccurrence;
          }
      }
            
      $query = "INSERT INTO calendarobjects (calendardata, uri, calendarid, lastmodified, etag, size, componenttype, uid, firstoccurence, lastoccurence) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
      $lastmod = new \DateTime($addressobject['getlastmodified']);
      $res = $this->sqlite->query($query, $calendarobject['calendar-data'], $calendarobject['href'], $connectionId, $lastmod->getTimestamp(), $calendarobject['getetag'], strlen($calendarobject['calendar-data']), $calendarobject['componenttype'], $calendarobject['uid'], $firstoccurrence, $lastoccurrence);
      if($res !== false)
        return true;
      return false;
  }

  /**
   * Convert a given address book object (including VCF data) and save it in 
   * the local database
   * 
   * @param int $connectionId The ID of the connection to work with
   * @param array $addressobject The address object data
   * 
   * @return true on success, otherwise false
   */
  private function object2addressbook($connectionId, $addressobject)
  {
      $query = "INSERT INTO addressbookobjects (contactdata, uri, addressbookid, lastmodified, etag, size, uid) VALUES(?, ?, ?, ?, ?, ?, ?)";
      $lastmod = new \DateTime($addressobject['getlastmodified']);
      $res = $this->sqlite->query($query, $addressobject['address-data'], $addressobject['href'], $connectionId, $lastmod->getTimestamp(), $addressobject['getetag'], strlen($addressobject['address-data']), $addressobject['uid']);
      if($res !== false)
        return true;
      return false;
  }

  /**
   * Helper function to parse a HTTP status response into a status code only
   * 
   * @param string $statusString The HTTP status string
   * 
   * @return The status as a string
   */
  private function parseHttpStatus($statusString)
  {
      $status = explode(' ', $statusString, 3);
      $status = $status[1];
      return $status;
  }

  /**
   * Helper function to remove all namespace prefixes from XML tags
   * 
   * @param string $response The response to clean
   * 
   * @return String containing the cleaned response
   */
  private function clean_response($response)
  {
      $response = utf8_encode($response);
      // Strip the namespace prefixes on all XML tags
      $response = preg_replace('/(<\/*)[^>:]+:/', '$1', $response);
      return $response;
  }

  /**
   * Helper function to generate a PROPFIND request
   * 
   * @param array $props The properties to retrieve
   * 
   * @return String containing the XML
   */
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
  
  /**
   * Helper function to generate a REPORT
   * 
   * @param string $ns The namespace
   * @param string $op The report operation
   * @param array $props (optional) The properties to retrieve
   * @param array $filters (optional) The filters to apply
   * 
   * @return String containing the XML
   */
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
  
  /**
   * Synchronise all configured connections
   */
  public function syncAllConnections()
  {
      $connections = $this->getConnections();
      foreach($connections as $connection)
      {
          $this->syncConnection($connection['id']);
      }
  }
  
  /**
   * Synchronise all configured connections when running with the indexer
   * This takes care that only *one* connection is synchronised.
   * 
   * @return true if something was synchronised, otherwise false
   */
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
  
  /**
   * Retrieve a configuration option for the plugin
   * 
   * @param string $key The key to query
   * 
   * @return mixed The option set, null if not found
   */
  public function getConfig($key)
  {
      return $this->getConf($key);
  }
}