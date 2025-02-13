<?php
/**
 * graphviz-Plugin: Parses graphviz-blocks
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Carl-Christian Salvesen <calle@ioslo.net>
 * @author     Andreas Gohr <andi@splitbrain.org>
 */


if (!defined('DOKU_INC')) define('DOKU_INC',realpath(DOKU_PLUGIN.'/../../').'/');
require_once(DOKU_PLUGIN.'syntax.php');

class syntax_plugin_graphviz extends DokuWiki_Syntax_Plugin {

    /**
     * array holding defined styles.
     */
	 protected $_styles = array();


    /**
     * Constructor to check config
     */
	function __construct(){
		// disable use_svg if 'path' is not set.
		if(!$this->getConf('path')) $this->conf['use_svg'] = 0;
		$this->_parseStyles($s);
	}

    /**
     * What about paragraphs?
     */
    function getPType(){
        return 'normal';
    }

    /**
     * What kind of syntax are we?
     */
    function getType(){
        return 'substition';
    }

    /**
     * Where to sort in?
     */
    function getSort(){
        return 200;
    }

    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
		//Fix: removed newline requirements, as a accidental space before newline can break the pattern
        $this->Lexer->addSpecialPattern('<graphviz.*?>.*?</graphviz>',$mode,'plugin_graphviz');
    }

    /**
     * Handle the match
     */
    function handle($match, $state, $pos, Doku_Handler $handler) {
        $info = $this->getInfo();
        // prepare default data
        $return = array(
                        'width'     => 0,
                        'height'    => 0,
                        'layout'    => 'dot',
                        'align'     => '',
                        'version'   => $info['date'], //force rebuild of images on update
                       );

        // prepare input, UPDATE: separate tags from data by preg instead of first/last line, but leave the old behavior as fallback
		if (preg_match('~<graphviz([^>]*)>((?:[\n]|.)*)</graphviz>~Ui',$match,$parts)){
			$input = $parts[2];
			$conf = $parts[1];
		}
		else{
			$lines = explode("\n",$match);
			$conf = array_shift($lines);
			array_pop($lines);
			$input = join("\n",$lines);
		}

        // match config options
        if(preg_match('/\b(left|center|right)\b/i',$conf,$match)) $return['align'] = $match[1];
        if(preg_match('/\b(\d+)x(\d+)\b/',$conf,$match)){
            $return['width']  = $match[1];
            $return['height'] = $match[2];
        }
        if(preg_match('/\b(dot|neato|twopi|circo|fdp)\b/i',$conf,$match)){
            $return['layout'] = strtolower($match[1]);
        }
        if(preg_match('/\bwidth=([0-9]+)\b/i', $conf,$match)) $return['width'] = $match[1];
        if(preg_match('/\bheight=([0-9]+)\b/i', $conf,$match)) $return['height'] = $match[1];
        
		// Added code for styles.
		$styles = array();
		// match stylenames that are written into the graphviz tag
		if(preg_match_all('/\b(\w+)\b/i',$conf,$match)){
			foreach ($match[1] as $style){
				if (array_key_exists($tmp = strtolower($style),$this->_styles)){
					$styles[] = $tmp;
				}
			}
		}
		$styles = array_unique($styles);	// remove duplicated styles

		$this->_injectStyles($input,$styles);
		
		//Update: add dokulink support to URL tags (they can contain [[dokulink]] urls.
		if ($this->getConf('use_svg')){
			global $ID;
			$return['url'] = md5(wl($ID,true)); // add current page's url to 'data' to ensure exported links work on page
			$input = preg_replace_callback(
				'~\b(|head|label|tail|edge)'.	// match 0 -> prefix for URL
				'URL="\[\[(?:'.					// alternative urls to catch
					'(\w+\://[^\]\|]*)'.		// simple url with any shema
					'|(?:(\w+)>([^\]\|]*))'.	// interwiki link
					'|([^\]\|#]+)?(#[^\]\|]*)?)'.	// dokuwiki link and/or fragment 
				'(?:\|([^\]]*))?'.				// text 
				'\]\]"~U',
				array(__CLASS__,'_parse_links'),
				$input);
		}
        $return['md5'] = md5($input); // we only pass a hash around
        // store input for later use
        io_saveFile($this->_cachename($return,'txt'),$input);
        return $return;
    }

	/**
	* callback function to preg_replace_callback, that fixes urls.
	*/
	public static function _parse_links($matches){
		global $ID;
		$pref = @$matches[1];		// the prefix of the URL (i.e. "head" from "headURL")
		$url = @$matches[2];		// external link (it's already url)
		$int_t = @$matches[3];		// interwiki prefix (i.e. "wp" from "[[wp>blabla]]")
		$int_u = @$matches[4];		// interwiki url (i.e. "blabla" from "[[wp>blabla]]")
		$page = @$matches[5];		// dokuwiki internal page link
		$frag = @$matches[6] ? '#'.preg_replace('~\W+~','-',strtolower(ltrim($matches[6],"#"))) : ""; // fragment for dokuwiki internal link, simple cleaning
		$text = @$matches[7];		// text of link (i.e. "blabla" from "[[.:mylink:|blabla]]"
		if ($page || $frag){
			resolve_pageid(getNS($ID),$page,$exists);
			$url = wl($page).$frag;
		}
		elseif($int_t){
			static $xhtml_renderer = null;
			if(is_null($xhtml_renderer)){
				$xhtml_renderer = p_get_renderer('xhtml');
				$xhtml_renderer->interwiki = getInterwiki();
			}
			$url = $xhtml_renderer->_resolveInterWiki($int_t,$int_u,$exists);
		}
		return "{$pref}URL=\"{$url}\";{$pref}target=_top".($text ? ";{$pref}tooltip=\"{$text}\"" : "");
	}
	
    /**
     * Cache file is based on parameters that influence the result image
     */
    function _cachename($data,$ext){
        unset($data['width']);
        unset($data['height']);
        unset($data['align']);
        unset($data['format']);
        return getcachename(join('x',array_values($data)),'.graphviz.'.$ext);
    }

    /**
     * Create output
     */
    function render($format, Doku_Renderer $R, $data) {
		if($format == 'xhtml' || $format == 'dw2pdf'){
			if ($this->getConf('use_svg') && get_class($R) != 'renderer_plugin_dw2pdf'){
				//Update: generate both png and svg, embed svg but with fallback to png.
				$img_svg = DOKU_BASE.'lib/plugins/graphviz/img.php?'.buildURLparams(array_merge($data,array('format'=>'svg')));
				// embed svg as object: the links in svg can be clicked (and also animations are supported)
				$R->doc .= '<object type="image/svg+xml" data="'.$img_svg.'" class="media'.$data['align'].'" alt=""';
				if($data['width'])  $R->doc .= ' width="'.$data['width'].'"';
				if($data['height']) $R->doc .= ' height="'.$data['height'].'"';
				if($data['align'] == 'right') $R->doc .= ' align="right"';
				if($data['align'] == 'left')  $R->doc .= ' align="left"';
				$R->doc .= '>';
			}
			if (get_class($R) == 'renderer_plugin_dw2pdf'){
				// for pdf export: mpdf fetches the url, and without authorization it is automatic fail. We need to set a file src as real path.
				$cache = $this->_imgfile(array_merge($data,array('format'=>'png')));
				$img_png = $cache;
			}else{
				$img_png = DOKU_BASE.'lib/plugins/graphviz/img.php?'.buildURLparams(array_merge($data,array('format'=>'png')));
			}
			// embed fallback: if browser does not support svg bia object embed, it will display the png image instead.
			$R->doc .= '<img src="'.$img_png.'" class="media'.$data['align'].'" alt=""';
            if($data['width'])  $R->doc .= ' width="'.$data['width'].'"';
            if($data['height']) $R->doc .= ' height="'.$data['height'].'"';
            if($data['align'] == 'right') $R->doc .= ' align="right"';
            if($data['align'] == 'left')  $R->doc .= ' align="left"';
            $R->doc .= '/>';
			if ($this->getConf('use_svg') && get_class($R) != 'renderer_plugin_dw2pdf'){	
				$R->doc .='</object>'; 
			}
            return true;
        }elseif($format == 'odt'){
            $src = $this->_imgfile($data);
            $R->_odtAddImage($src,$data['width'],$data['height'],$data['align']);
            return true;
        }
        return false;
    }

    /**
     * Return path to the rendered image on our local system
     */
    function _imgfile($data){
		// Update: support pnd and svg format as well.
		$format = ($data['format'] == 'png' ? 'png' : 'svg');
        $cache  = $this->_cachename($data,$format);

        // create the file if needed
        if(!file_exists($cache)){
            $in = $this->_cachename($data,'txt');
            if($this->getConf('path')){
                $ok = $this->_run($data,$in,$cache);
            }else{
                $ok = $this->_remote($data,$in,$cache);
            }
            if(!$ok) return false;
            clearstatcache();
        }

        // resized version
        if($data['width']){
            $cache = media_resize_image($cache,'png',$data['width'],$data['height']);
        }

        // something went wrong, we're missing the file
        if(!file_exists($cache)) return false;

        return $cache;
    }

    /**
     * Render the output remotely at google
     */
    function _remote($data,$in,$out){
        if(!file_exists($in)){
            if($conf['debug']){
                dbglog($in,'no such graphviz input file');
            }
            return false;
        }

        $http = new DokuHTTPClient();
        $http->timeout=30;

        $pass = array();
        $pass['cht'] = 'gv:'.$data['layout'];
        $pass['chl'] = io_readFile($in);

        $img = $http->post('http://chart.apis.google.com/chart',$pass,'&');
        if(!$img) return false;

        return io_saveFile($out,$img);
    }

    /**
     * Run the graphviz program
     */
    function _run($data,$in,$out) {
        global $conf;

        if(!file_exists($in)){
            if($conf['debug']){
                dbglog($in,'no such graphviz input file');
            }
            return false;
        }

        $cmd  = $this->getConf('path');
		// Update: support both svg and png
        $cmd .= ' -T'.($data['format'] == 'png' ? 'png' : 'svg');
        $cmd .= ' -K'.$data['layout'];
        $cmd .= ' -o'.escapeshellarg($out); //output
        $cmd .= ' '.escapeshellarg($in); //input
        exec($cmd, $output, $error);

        if ($error != 0){
            if($conf['debug']){
                dbglog(join("\n",$output),'graphviz command failed: '.$cmd);
            }
            return false;
        }
        return true;
    }

    /**
     * parse styles form configuration
     */
	 function _parseStyles(){
		if (preg_match_all('~<style\s*name=([\'"]?)([\w]+)\\1\s*>(.*)</style>~U',$this->getConf('styles'),$matches,PREG_SET_ORDER)){
			foreach ($matches as $match){
				$this->_styles[strtolower($match[2])] = $match[3];
			}
		}
	 }

    /**
     * parse styles form configuration
     */
	function _injectStyles(&$input,$styles){
		// if there are no styles or the beginning of the graph is not found, leave it as is
		if (!empty($styles) && (($p = strpos($input,"{")) !== false)){
			$beg = substr($input,0,$p+1);	// beginning, i.e. "digraph name{"
			$rest = substr($input,$p+1);	// the rest of the string
			$input = $beg;					
			foreach ($styles as $style){	// add each matched style right after the beginning.
				$input .= "\n".$this->_styles[$style]."\n";
			}
			$input .= $rest;				// the graph code itself.
		}
	}
	
}



