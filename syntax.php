<?php
/**
 * dataplot-Plugin: Parses Gnuplot data blocks
 *
 * @license    GPL 3 (http://www.gnu.org/licenses/gpl-3.0.html)
 * @author     Yann Pouillon <yann.pouillon@gmail.com>
 */


if ( !defined('DOKU_INC') ) {
  define('DOKU_INC', realpath(dirname(__FILE__).'/../../').'/');
}
if ( !defined('DOKU_PLUGIN') ) {
  define('DOKU_PLUGIN', DOKU_INC.'lib/plugins/');
}
require_once(DOKU_PLUGIN.'syntax.php');

class syntax_plugin_dataplot extends DokuWiki_Syntax_Plugin {

  /**
   * What about paragraphs?
   */
  function getPType() {
    return 'normal';
  }

  /**
   * What kind of syntax are we?
   */
  function getType() {
    return 'substition';
  }

  /**
   * Where to sort in?
   */
  function getSort() {
    return 200;
  }

  /**
   * Connect pattern to lexer
   */
  function connectTo($mode) {
    $this->Lexer->addSpecialPattern('<dataplot.*?>\n.*?\n</dataplot>', $mode, 'plugin_dataplot');
  }

  /**
   * Handle the match
   */
  function handle($match, $state, $pos, &$handler) {
    $info = $this->getInfo();

    // Set-up default data
    $return = array(
      'width'    => 0,
      'height'   => 0,
      'align'    => '',
      'layout'   => '2D',
      'columns'  => 2,
      'plottype' => 'linespoints',
      'smooth'   => false,
      'xlabel'   => '',
      'ylabel'   => '',
      'gnuplot'  => '',
      'debug'    => false,
      'version'  => ''
    );
    $gnu_colors = array(
      'white',
      'red',
      'medium-blue',
      'orange-red',
      'dark-violet',
      'dark-chartreuse',
      'dark-turquoise',
      'grey40',
      'black'
    );

    // Prepare input
    $lines = explode("\n", $match);
    $conf = array_shift($lines);
    array_pop($lines);
    $lines = trim(join("\n", $lines))."\n";
    $lines = explode("\n", $lines);

    // Get number of data columns
    $cols = explode(" ", preg_replace("!\s+!", " ", trim($lines[0])));
    $return['columns'] = count($cols);

    // Match config options
    // Note: treating xlabel and ylabel first then removing them from the
    //       config string, in order to avoid misinterpretations of
    //       further options.
    if ( preg_match('/xlabel="([^"]*)"/i', $conf, $match) ) {
      $return['xlabel'] = $match[1];
      $conf = preg_replace('/xlabel="([^"]*)"/i', '', $conf);
    }
    if ( preg_match('/ylabel="([^"]*)"/i', $conf, $match) ) {
      $return['ylabel'] = $match[1];
      $conf = preg_replace('/ylabel="([^"]*)"/i', '', $conf);
    }
    if ( preg_match('/\b(left|center|right)\b/i', $conf, $match) ) {
      $return['align'] = $match[1];
    }
    if ( preg_match('/\b(\d+)x(\d+)\b/', $conf, $match) ) {
      $return['width']  = $match[1];
      $return['height'] = $match[2];
    }
    if ( preg_match('/\b(2D|3D)\b/i', $conf, $match) ) {
      $return['layout'] = strtolower($match[1]);
    }
    if ( preg_match('/\bwidth=([0-9]+)\b/i', $conf, $match) ) {
      $return['width'] = $match[1];
    }
    if ( preg_match('/\bheight=([0-9]+)\b/i', $conf, $match) ) {
      $return['height'] = $match[1];
    }
    if ( preg_match('/\b(boxes|lines|linespoints|points)\b/i', $conf, $match) ) {
      $return['plottype'] = $match[1];
    }
    if ( preg_match('/\b(smooth)\b/i', $conf, $match) ) {
      $return['smooth'] = true;
    }
    if ( preg_match('/\b(debug)\b/i', $conf, $match) ) {
      $return['debug'] = true;
    }

    // Force rebuild of images on update
    $return['version'] = date('Y-m-d H:i:s');
    $return['hash'] = (string) uniqid("dataplot_", true);

    // Generate Gnuplot code (must be last)
    $input = trim(join("\n", $lines))."\n";
    if ( $return['width'] != 0 && $return['height'] != 0 ) {
      $gnu_size = ' size '.$return['width'].','.$return['height'];
    } else {
      $gnu_size = '';
    }
    $gnu_labels = '';
    if ( strlen($return['xlabel']) > 0 ) {
      $gnu_labels .= "set xlabel \"".$return['xlabel']."\"\nshow xlabel\n";
    }
    if ( strlen($return['ylabel']) > 0 ) {
      $gnu_labels .= "set ylabel \"".$return['ylabel']."\"\nshow ylabel\n";
    }

    $gnu_code  = "# Input parameters:\n#\n";
    foreach ($return as $param => $value) {
      if ( $param != 'gnuplot' ) {
        $gnu_code .= "#  - $param = $value\n";
      }
    }
    $gnu_code .= "#\n\n";
    $gnu_code .= 'set terminal pngcairo enhanced dashed font "arial,14" linewidth 2'.$gnu_size."\n";
    $gnu_code .= $gnu_labels;
    $gnu_code .= "set output \"@gnu_output@\"\n";
    for ($i=1; $i<sizeof($gnu_colors); $i++) {
      $gnu_code .= "set style line $i linetype rgb \"".$gnu_colors[$i]."\" linewidth 1.2 pointtype $i\n";
    }
    $gnu_code .= 'plot';
    $sep  = ' ';
    for ($i=2; $i<=$return['columns']; $i++) {
      $gnu_style = $i-1;
      if ( $return['smooth'] && ($return['plottype'] == 'linespoints') ) {
        $gnu_code .= $sep.'"@gnu_input@" using 1:'.$i.' notitle smooth csplines with lines linestyle '.$gnu_style;
        $sep = ", \\\n     ";
        $gnu_code .= $sep.'"@gnu_input@" using 1:'.$i.' notitle with points linestyle '.$gnu_style;
      } else {
        $gnu_code .= $sep.'"@gnu_input@" using 1:'.$i.' notitle with '.$return[plottype].' linestyle '.$gnu_style;
      }
      $sep = ", \\\n     ";
    }
    $gnu_code .= "\n";
    $return['gnuplot'] = $gnu_code;

    // Store input for later use
    io_saveFile($this->_cachename($return, 'txt'), $input);

    return $return;
  }

  /**
   * Cache file is based on parameters that influence the resulting image
   */
  function _cachename($data, $ext) {
    return getcachename(
      $data['hash'].'x'.$data['layout'].'x'.$data['plottype'], '.'.$ext);
  }

  /**
   * Create output
   */
  function render($format, &$R, $data) {
    if ( $format == 'xhtml' ) {
      $img = DOKU_BASE.'lib/plugins/dataplot/img.php?'.buildURLparams($data);
      $R->doc .= '<img src="'.$img.'" class="media'.$data['align'].'" alt=""';
      if ( $data['width'] )  $R->doc .= ' width="'.$data['width'].'"';
      if ( $data['height'] ) $R->doc .= ' height="'.$data['height'].'"';
      if ( $data['align'] == 'right' ) $R->doc .= ' align="right"';
      if ( $data['align'] == 'left' )  $R->doc .= ' align="left"';
      $R->doc .= '/>';

      // Debugging
      if ( $data['debug'] ) {
        $R->doc .= '<pre>'.$data['gnuplot'].'</pre>';
      }

      return true;
    } elseif ( $format == 'odt' ) {
      $src = $this->_imgfile($data);
      $R->_odtAddImage($src, $data['width'], $data['height'], $data['align']);

      return true;
    }

    return false;
  }

  /**
   * Return path to the rendered image on our local system
   */
  function _imgfile($data) {
    $cache  = $this->_cachename($data, 'png');

    // Create the file if needed
    if ( !file_exists($cache) ) {
      $in = $this->_cachename($data, 'txt');
      if ( $this->getConf('path') ) {
        $ok = $this->_run($data, $in, $cache);
      } else {
        $ok = false;
      }
      if ( !$ok ) return false;
      clearstatcache();
    }

    // Resized version
    if ( $data['width'] ) {
      $cache = media_resize_image($cache, 'png', $data['width'], $data['height']);
    }

    // Something went wrong, we're missing the file
    if ( !file_exists($cache) ) return false;

    return $cache;
  }

  /**
   * Run Gnuplot
   */
  function _run($data, $in, $out) {
    global $conf;

    // Check input data
    if ( !file_exists($in) ) {
      if ( $conf['debug'] ) {
        dbglog($in,'no such dataplot input file');
      }

      return false;
    }

    // Create Gnuplot script
    $gnu_code = $data['gnuplot'];
    $gnu_code = preg_replace('!@gnu_input@!', $in, $gnu_code);
    $gnu_code = preg_replace('!@gnu_output@!', $out, $gnu_code);
    $gnu_script = tempnam('/tmp', 'dataplot');
    $gnu_handle = fopen($gnu_script, 'w');
    fwrite($gnu_handle, $gnu_code);
    fclose($gnu_handle);

    // Run command
    $cmd  = $this->getConf('path');
    $cmd .= ' '.$gnu_script;
    exec($cmd, $output, $error);

    // Remove Gnuplot script
    //unlink($gnu_script);

    if ( $error != 0 ) {
      if ( $conf['debug'] ) {
        dbglog(join("\n", $output), 'dataplot command failed: '.$cmd);
      }

      return false;
    }

    return true;
  }

}
