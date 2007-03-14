<?php 
/** 
 * Repository Plugin: show files from a remote repository with GesHi syntax highlighting
 * Syntax: {{repo>url}}
 * 
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html) 
 * @author     Esther Brunner <wikidesign@gmail.com>
 * @author     Christopher Smith <chris@jalakai.co.uk>
 */ 
 
if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/'); 
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/'); 
require_once(DOKU_PLUGIN.'syntax.php'); 
  
/** 
 * All DokuWiki plugins to extend the parser/rendering mechanism 
 * need to inherit from this class 
 */ 
class syntax_plugin_repo extends DokuWiki_Syntax_Plugin { 
 
  /** 
   * return some info 
   */ 
  function getInfo(){ 
    return array( 
      'author' => 'Esther Brunner', 
      'email'  => 'wikidesign@gmail.com', 
      'date'   => '2006-11-28', 
      'name'   => 'Repository Plugin', 
      'desc'   => 'Show files from a remote repository with GesHi syntax highlighting', 
      'url'    => 'http://www.wikidesign.ch/en/plugin/repo/start', 
    ); 
  } 
 
  function getType(){ return 'substition'; } 
  function getSort(){ return 301; } 
  function getPType(){ return 'block'; } 
  function connectTo($mode){  
    $this->Lexer->addSpecialPattern("{{repo>.+?}}", $mode, 'plugin_repo');  
  } 
 
  /** 
   * Handle the match 
   */ 
  function handle($match, $state, $pos, &$handler){ 
 
      $match = substr($match, 7, -2);
      list($base, $title) = explode('|', $match, 2);
      list($base, $refresh) = explode(' ', $base, 2);
      
      if (preg_match('/(\d+)([dhm])/', $refresh, $match)){
        $period = array('d' => 86400, 'h' => 3600, 'm' => 60);
        // n * period in seconds, minimum 10 minutes
        $refresh = max(600, $match[1] * $period[$match[2]]);
      } else {
        // default to 4 hours
        $refresh = 14400;
      }
      
      return array(trim($base), trim($title), $pos, $refresh);
  }     
 
  /** 
   * Create output 
   */ 
  function render($mode, &$renderer, $data){
    
    // construct requested URL
    $base  = hsc($data[0]);
    $title = ($data[1] ? hsc($data[1]) : $base);
    $path  = hsc($_REQUEST['repo']);
    $url   = $base.$path;

    if ($mode == 'xhtml'){
                        
      // output
      $renderer->header($title.$path, 5, $data[2]);
      $renderer->section_open(5);
      if ($url{strlen($url) - 1} == '/'){                 // directory
        $this->_directory($base, $renderer, $path, $data[3]);
      } elseif (preg_match('/(jpe?g|gif|png)$/i', $url)){ // image
        $this->_image($url, $renderer);
      } else {                                            // source code file
        $this->_codefile($url, $renderer, $data[3]);
      }
      if ($path) $this->_location($path, $title, $renderer);
      $renderer->section_close();
      
    // for metadata renderer
    } elseif ($mode == 'metadata'){
    
      // list the URL as an included part of the page
      $renderer->meta['relation']['haspart'][$url] = 1;
      
      // set the time for cache expiration
      if (isset($renderer->meta['date']['valid']['age']))
        $renderer->meta['date']['valid']['age'] =
        min($renderer->meta['date']['valid']['age'], $data[3]);
      else
        $renderer->meta['date']['valid']['age'] = $data[3];
    }
 
    return $ok;  
  }
  
  /**
   * Handle remote directories
   */
  function _directory($url, &$renderer, $path, $refresh){
    global $conf;
    
    $cache = getCacheName($url.$path, '.repo');
    $mtime = @filemtime($cache); // 0 if it doesn't exist
    
    if (($mtime != 0) && !$_REQUEST['purge'] && ($mtime > time() - $refresh)){
      $idx = io_readFile($cache, false);
      if ($conf['allowdebug']) $idx .= "\n<!-- cachefile $cache used -->\n";
    } else {
      $items = $this->_index($url, $path);
      $idx = html_buildlist($items, 'idx', 'repo_list_index', 'html_li_index');
      
      io_saveFile($cache, $idx);
      if ($conf['allowdebug']) $idx .= "\n<!-- no cachefile used, but created -->\n";
    }
    
    $renderer->doc .= $idx;
  }
  
  /**
   * Extract links and list them as directory contents
   */
  function _index($url, $path, $base = '', $lvl = 0){
    
    // download the index html file
    $http = new DokuHTTPClient();
    $http->timeout = 25; //max. 25 sec
    $data = $http->get($url.$base);
    preg_match_all('/<li><a href="(.*?)">/i', $data, $results);
    
    $lvl++;
    foreach ($results[1] as $result){
      if ($result == '../') continue;
      
      $type = ($result{strlen($result) - 1} == '/' ? 'd' : 'f');
      $open = (($type == 'd') && (strpos($path, $base.$result) === 0));
      $items[] = array(
        'level' => $lvl,
        'type'  => $type,
        'path'  => $base.$result,
        'open'  => $open,
      );
      if ($open){
        $items = array_merge($items, $this->_index($url, $path, $base.$result, $lvl));
      }
    }
    return $items; 
  }
  
  /**
   * Handle remote images
   */
  function _image($url, &$renderer){
    $renderer->p_open();
    $renderer->externalmedia($url, NULL, NULL, NULL, NULL, 'recache');
    $renderer->p_close();
  }
  
  /**
   * Handle remote source code files: display as code box with link to file at the end
   */
  function _codefile($url, &$renderer, $refresh){
          
    // output the code box with syntax highlighting
    $renderer->doc .= $this->_cached_geshi($url, $refresh);
    
    // and show a link to the original file
    $renderer->p_open();
    $renderer->externallink($url);
    $renderer->p_close();
  }
  
  /**
   * Wrapper for GeSHi Code Highlighter, provides caching of its output
   * Modified to calculate cache from URL so we don't have to re-download time and again
   *
   * @author Christopher Smith <chris@jalakai.co.uk>
   * @author Esther Brunner <wikidesign@gmail.com>
   */
  function _cached_geshi($url, $refresh){
    global $conf;
    
    $cache = getCacheName($url, '.code');
    $mtime = @filemtime($cache); // 0 if it doesn't exist
  
    if (($mtime != 0) && !$_REQUEST['purge'] &&
      ($mtime > time() - $refresh) &&
      ($mtime > filemtime(DOKU_INC.'inc/geshi.php'))){
  
      $hi_code = io_readFile($cache, false);
      if ($conf['allowdebug']) $hi_code .= "\n<!-- cachefile $cache used -->\n";
  
    } else {
      require_once(DOKU_INC . 'inc/geshi.php');
      
      // get the source code language first
      $search = array('/^htm/', '/^js$/');
      $replace = array('html4strict', 'javascript');
      $lang = preg_replace($search, $replace, substr(strrchr($url, '.'), 1));
      
      // download external file
      $http = new DokuHTTPClient();
      $http->timeout = 25; //max. 25 sec
      $code = $http->get($url);
  
      $geshi = new GeSHi($code, strtolower($lang), DOKU_INC.'inc/geshi');
      $geshi->set_encoding('utf-8');
      $geshi->enable_classes();
      $geshi->set_header_type(GESHI_HEADER_PRE);
      $geshi->set_overall_class("code $language");
      $geshi->set_link_target($conf['target']['extern']);
  
      $hi_code = $geshi->parse_code();
  
      io_saveFile($cache, $hi_code);
      if ($conf['allowdebug']) $hi_code .= "\n<!-- no cachefile used, but created -->\n";
    }
  
    return $hi_code;
  }
    
  /**
   * Show where we are with link back to main repository
   */
  function _location($path, $title, &$renderer){
    global $ID;
    
    $renderer->p_open();
    $renderer->internallink($ID, $title);
    
    $base = '';
    $dirs = explode('/', $path);
    $n = count($dirs);
    for ($i = 0; $i < $n-1; $i++){
      $base .= hsc($dirs[$i]).'/';
      $renderer->doc .= '<a href="'.wl($ID, 'repo='.$base).'" class="idx_dir">'.
        hsc($dirs[$i]).'/</a>';
    }
    
    $renderer->doc .= hsc($dirs[$n-1]);
    $renderer->p_close();
  }
            
}

/**
 *
 */
function repo_list_index($item){
  global $ID;
  
  if ($item['type'] == 'd'){
    $title = substr($item['path'], 0, -1);
    $class = 'idx_dir';
  } else {
    $title = $item['path'];
    $class = 'wikilink1';
  }
  $title = substr(strrchr('/'.$title, '/'), 1);
  return '<a href="'.wl($ID, 'repo='.$item['path']).'" class="'.$class.'">'.$title.'</a>';
}

//Setup VIM: ex: et ts=4 enc=utf-8 :