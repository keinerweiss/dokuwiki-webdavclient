<?php
/**
 * DokuWiki Plugin webdavclient (Admin Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Boehler <dev@aboehler.at>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();
require_once(DOKU_PLUGIN.'admin.php');

class admin_plugin_webdavclient extends DokuWiki_Admin_Plugin {

    protected $hlp = null;
    protected $error = false;
    protected $errmsg = '';
    protected $action = null;
    protected $result = null;

    /**
     * Constructor. Load helper plugin
     */
    function __construct(){
        $this->hlp = plugin_load('helper', 'webdavclient');
        if(is_null($this->hlp))
          msg('Error loading WebDAV Client helper module!');
    }

    function getMenuSort() 
    {
        return 501; 
    }

    function handle() 
    {
      global $INPUT;

      if (!isset($_REQUEST['cmd'])) 
        return;   // first time - nothing to do

      if (!checkSecurityToken()) 
        return;
      if (!is_array($_REQUEST['cmd'])) 
        return;
        
      $fn = $_REQUEST['cmd'];
        
      if (is_array($fn)) 
      {
        $cmd = key($fn);
        $param = is_array($fn[$cmd]) ? key($fn[$cmd]) : null;
      } 
      else 
      {
        $cmd = $fn;
        $param = null;
      }
      
      $this->action = $cmd;
      
      // Parse the command and react accordingly
      switch($cmd)
      {
          case 'forcesync':
            $connid = $param;
            if($this->hlp->syncConnection($connid, true, true) === false)
            {
                $this->error = true;
                $this->errmsg = $this->hlp->getLastError();
            }
            break;
          case 'forceresync':
            $connid = $param;
            if($this->hlp->syncConnection($connid, true, true, true) === false)
            {
                $this->error = true;
                $this->errmsg = $this->hlp->getLastError();
            }
            break;
          case 'delete':
            $connid = $param;
            $this->hlp->deleteConnection($connid);
            break;
          case 'modify':
            $connid = $param;
            $dn = $_REQUEST['moddn'][$connid];
            $permission = $_REQUEST['modperm'][$connid];
            $syncinterval = $_REQUEST['modsyncinterval'][$connid];
            $write = $_REQUEST['modwrite'][$connid];
            $active = $_REQUEST['modactive'][$connid];
            $this->hlp->modifyConnection($connid, $permission, $dn, $syncinterval, $write, $active);
            break;
          case 'add':
            // FIXME: Should we sanity-check the settings and query the server for correctness first?
            $uri = $_REQUEST['manuri'];
            $dn = $_REQUEST['mandn'];
            $username = $_REQUEST['manusername'];
            $password = $_REQUEST['manpassword'];
            $type = $_REQUEST['mantype'];
            $this->hlp->addConnection($uri, $username, $password, $dn, $dn, $type, '3600', false, false);
            break;
          case 'addselected':
            $calendars = $_REQUEST['cb']['calendar'];
            $addressbooks = $_REQUEST['cb']['addressbook'];
            if(count($calendars) == 0 && count($addressbooks) == 0)
            {
                $this->error = true;
                $this->errmsg = $this->getLang('nothing_selected');
                return;
            }
            foreach($calendars as $cal)
            {
                $idx = intval($cal);
                $this->hlp->addConnection($_REQUEST['calendar'][$idx], $_REQUEST['addusername'], $_REQUEST['addpassword'], $_REQUEST['calendardn'][$idx], $_REQUEST['calendardn'][$idx], 'calendar', '3600', false, false);
            }
            foreach($addressbooks as $addr)
            {
                $idx = intval($addr);
                $this->hlp->addConnection($_REQUEST['addressbook'][$idx], $_REQUEST['addusername'], $_REQUEST['addpassword'], $_REQUEST['addressbookdn'][$idx], $_REQUEST['addressbookdn'][$idx], 'contacts', '3600', false, false);
            }
            break;
          case 'empty':
            $this->result['connid'] = $param;
            break; 
          case 'reallyempty':
            $connid = $param;
            $this->hlp->deleteAllEntries($connid);
            break;
          case 'discover':
            $username = $INPUT->str('username', '');
            $password = $INPUT->str('password', '');
            $uri = $INPUT->str('uri', '');
            if(($username === '') || ($password === '') || ($uri === ''))
            {
                $this->error = true;
                $this->errmsg = $this->getLang('empty_input');
                return;
            }
            $this->result = $this->hlp->queryServer($uri, $username, $password);
            $this->result['username'] = $username;
            $this->result['password'] = $password;
            $this->result['uri'] = $uri;
            break;
          default:
            break;
      } 
        
    }
    

    function html() 
    {
      ptln('<h1>WebDAV Client</h1>');
      
      ptln('<h2>'.$this->getLang('existing_connections').'</h2>');
      
      ptln('<form action="'.wl($ID).'" method="post">');
 
      // output hidden values to ensure dokuwiki will return back to this plugin
      ptln('  <input type="hidden" name="do"   value="admin" />');
      ptln('  <input type="hidden" name="page" value="'.$this->getPluginName().'" />');
      formSecurityToken();
      
      if($this->error === true)
      {
          ptln($this->errmsg);
      }
      else 
      {
          switch($this->action)
          {
              case 'empty':
                ptln($this->getLang('reallyempty'));
                ptln('<input type="submit" name="cmd[reallyempty]['.$this->result['connid'].']" value="' . $this->getLang('empty').'">');
              break;
              case 'discover':
                if(count($this->result['calendars']) == 0 && count($this->result['addressbooks']) == 0)
                {
                    ptln($this->getLang('nothing_found'));
                    break;
                }
                ptln('<h3>'.$this->getLang('calendars_found').'</h3>');
                ptln('<table>');
                ptln('<tr><th>'.$this->getLang('select').'</th><th>'.
                    $this->getLang('name').'</th><th>'.$this->getLang('uri').'</th></tr>');
                $idx = 0;
                foreach($this->result['calendars'] as $href => $dn)
                {
                    ptln('<tr><td><input type="checkbox" name="cb[calendar][]" value="'.$idx.'"></td><td>'.
                        '<input type="hidden" name="calendar['.$idx.']" value="'.$href.'">'.
                        '<input type="hidden" name="calendardn['.$idx.']" value="'.$dn.'">'.
                        hsc($dn).'</td><td>'.hsc($href).'</td></tr>');
                    $idx++;
                }
                ptln('</table>');
                ptln('<h3>'.$this->getLang('addressbooks_found').'</h3>');
                ptln('<table>');
                ptln('<tr><th>'.$this->getLang('select').'</th><th>'.
                    $this->getLang('name').'</th><th>'.$this->getLang('uri').'</th></tr>');
                $idx = 0;
                foreach($this->result['addressbooks'] as $href => $dn)
                {
                    ptln('<tr><td><input type="checkbox" name="cb[addressbook][]" value="'.$idx.'"></td><td>'.
                        '<input type="hidden" name="addressbook['.$idx.']" value="'.$href.'">'.
                        '<input type="hidden" name="addressbookdn['.$idx.']" value="'.$dn.'">'.
                        hsc($dn).'</td><td>'.hsc($href).'</td></tr>');
                    $idx++;
                }
                ptln('</table>');
                ptln('<input type="hidden" name="addusername" value="'.$this->result['username'].'">');
                ptln('<input type="hidden" name="addpassword" value="'.$this->result['password'].'">');
                ptln('<input type="submit" name="cmd[addselected]" value="'.
                    $this->getLang('add_selected').'">'); 
          }
      }

      ptln('<table>');
      $connections = $this->hlp->getConnections();
      
      ptln('<tr>');
      ptln('<th>'.$this->getLang('id').'</th><th>'.$this->getLang('name').'</th><th>'.
      $this->getLang('syncinterval').'</th><th>'.$this->getLang('active').
        '</th><th>'.$this->getLang('write').'</th><th>'.$this->getLang('permission').'</th><th>'.$this->getLang('action').'</th>');
      ptln('</tr>');
      
      foreach($connections as $conn)
      {
          ptln('<tr>');
          ptln('  <td>'.hsc($conn['id']).
            '</td><td><input type="text" name="moddn['.$conn['id'].']" value="'.$conn['displayname'].'">'.
            '</td><td><input type="text" size="5" name="modsyncinterval['.$conn['id'].']" value="'.$conn['syncinterval'].'">'.
            '</td><td><select name="modactive['.$conn['id'].']">'.
            '<option value="1" '. (($conn['active']) ? 'selected="selected"' : '').'>'.
                $this->getLang('active').'</option>'.
            '<option value="0" '. (($conn['active']) ? '' : 'selected="selected"').'>'.
                $this->getLang('inactive').'</option>'.
            '</select>'.
            '</td><td><select name="modwrite['.$conn['id'].']"'.
            (($conn['type'] === 'icsfeed') ? ' disabled' : '').'>'.
            '<option value="1" '. (($conn['write']) ? 'selected="selected"' : '').'>'.
                $this->getLang('write').'</option>'.
            '<option value="0" '. (($conn['write']) ? '' : 'selected="selected"').'>'.
                $this->getLang('nowrite').'</option>'.
            '</select>'.
            '</td><td><input type="text" name="modperm['.$conn['id'].']" value="'.$conn['permission'].'">'.
            '</td><td><input type="submit" name="cmd[modify]['.$conn['id'].']" value="'.
            $this->getLang('modify').'" /><input type="submit" name="cmd[delete]['.$conn['id'].']" value="'.
            $this->getLang('delete').'" /><input type="submit" name="cmd[forcesync]['.$conn['id'].']" value="'.
            $this->getLang('forcesync').'" /><input type="submit" name="cmd[forceresync]['.
            $conn['id'].']" value="'.$this->getLang('forceresync').'" /><input type="submit" name="cmd[empty]['.
            $conn['id'].']" value="'.$this->getLang('empty').'" /></td>');
          ptln('</tr>');
      }
      
      ptln('</table>');

      ptln('<div style="width:46%; float:left">');
      ptln('<h2>'.$this->getLang('add_connection').'</h2>');
      ptln($this->getLang('discovery_text'));
      ptln('<table>');
      ptln('<tr><td>'.$this->getLang('uri').'</td><td><input type="text" name="uri"></td></tr>');
      ptln('<tr><td>'.$this->getLang('username').'</td><td><input type="text" name="username"></td></tr>');
      ptln('<tr><td>'.$this->getLang('password').'</td><td><input type="password" name="password"></td></tr>');
      ptln('<tr><td></td><td><input type="submit" name="cmd[discover]" value="'.$this->getLang('discover').'">'.
        '</td></tr>');
      ptln('</table>');
      ptln('</div>');
      ptln('<div style="width:46%; float:left">');
      ptln('<h2>'.$this->getLang('add_connection_manual').'</h2>');
      ptln($this->getLang('add_text'));
      ptln('<table>');
      ptln('<tr><td>'.$this->getLang('uri').'</td><td><input type="text" name="manuri"></td></tr>');
      ptln('<tr><td>'.$this->getLang('name').'</td><td><input type="text" name="mandn"></td></tr>');
      ptln('<tr><td>'.$this->getLang('type').'</td><td><select name="mantype"><option value="calendar">'.
        $this->getLang('calendar').'</option><option value="contacts">'.
        $this->getLang('contacts').'</option><option value="icsfeed">'.
        $this->getLang('icsfeed').'</option></select></td></tr>');
      ptln('<tr><td>'.$this->getLang('username').'</td><td><input type="text" name="manusername"></td></tr>');
      ptln('<tr><td>'.$this->getLang('password').'</td><td><input type="password" name="manpassword"></td></tr>');
      ptln('<tr><td></td><td><input type="submit" name="cmd[add]" value="'.$this->getLang('add').'">'.
        '</td></tr>');
      ptln('</table>');
      ptln('</div>');
      

      ptln('</form>');
    }

}

// vim:ts=4:sw=4:et:enc=utf-8:
