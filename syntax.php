<?php
/**
 * dataplot-Plugin: Parses Gnuplot data blocks
 *
 * @license    GPL 3 (http://www.gnu.org/licenses/gpl-3.0.html)
 * @author     Yann Pouillon <yann.pouillon@materialsevolution.es>
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
  function handle($match, $state, $pos, Doku_Handler $handler) {
    $info = $this->getInfo();

    // Set-up default data
    $return = array(
      'width'    => 0,
      'height'   => 0,
      'align'    => '',
      'layout'   => '2D',
      'columns'  => 2,
      'y2tics'   => false,
      'y2use'    => [],
      'plottype' => [],
      'smooth'   => false,
      'xlabel'   => '',
      'ylabel'   => '',
      'y2label'   => '',
      'hline'    => [],
      'vline'    => [],
      'xrange'   => '',
      'yrange'   => '',
      'y2range'   => '',
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
      'dark-turquoise',
      'dark-chartreuse',
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
    if ( preg_match('/y2label="([^"]*)"/i', $conf, $match) ) {
      $return['y2label'] = $match[1];
      $conf = preg_replace('/y2label="([^"]*)"/i', '', $conf);
    }
    if ( preg_match_all('/hline="([^"]*)"/i', $conf, $match) ) {
      $return['hline'] = $match[1];
      $conf = preg_replace('/hline="([^"]*)"/i', '', $conf, -1);
    }
    if ( preg_match_all('/vline="([^"]*)"/i', $conf, $match) ) {
      $return['vline'] = $match[1];
      $conf = preg_replace('/vline="([^"]*)"/i', '', $conf, -1);
    }
    if ( preg_match('/xrange=(-?\d*\.\d+(e-?\d+)?:-?\d*\.\d+(e-?\d+)?)/i', $conf, $match) ) {
      $return['xrange'] = $match[1];
    }
    if ( preg_match('/yrange=(-?\d*\.\d+(e-?\d+)?:-?\d*\.\d+(e-?\d+)?)/i', $conf, $match) ) {
      $return['yrange'] = $match[1];
    }
    if ( preg_match('/y2range=(-?\d*\.\d+(e-?\d+)?:-?\d*\.\d+(e-?\d+)?)/i', $conf, $match) ) {
      $return['y2range'] = $match[1];
    }
    if ( preg_match('/\b(2D|3D)\b/i', $conf, $match) ) {
      $return['layout'] = strtolower($match[1]);
    }
    if ( preg_match('/\b(y2tics)\b/i', $conf, $match) ) {
      $return['y2tics'] = true;
    }
    if ( preg_match('/\by2use=((?:\d,)*\d)\b/', $conf, $match) ) {
      $return['y2use'] = json_decode('['.$match[1].']');
    }
    if ( preg_match_all('/\b(boxes|lines|linespoints|points)\b/i', $conf, $match) ) {
      $return['plottype'] = $match[1];
    } else {
      $return['plottype'] = ['linespoints'];
    }
    if ( preg_match('/\b(smooth)\b/i', $conf, $match) ) {
      $return['smooth'] = true;
    }
    if ( preg_match('/\b(left|center|right)\b/i', $conf, $match) ) {
      $return['align'] = $match[1];
    }
    if ( preg_match('/\b(\d+)x(\d+)\b/', $conf, $match) ) {
      $return['width']  = $match[1];
      $return['height'] = $match[2];
    }
    if ( preg_match('/\bwidth=([0-9]+)\b/i', $conf, $match) ) {
      $return['width'] = $match[1];
    }
    if ( preg_match('/\bheight=([0-9]+)\b/i', $conf, $match) ) {
      $return['height'] = $match[1];
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
      $gnu_labels .= "set xlabel \"".$return['xlabel']."\"\n";
    }
    if ( strlen($return['ylabel']) > 0 ) {
      $gnu_labels .= "set ylabel \"".$return['ylabel']."\"\n";
    }
    if ( strlen($return['xrange']) > 0 ) {
      $gnu_ranges .= "set xrange [".$return['xrange']."]\n";
    }
    if ( strlen($return['yrange']) > 0 ) {
      $gnu_ranges .= "set yrange [".$return['yrange']."]\n";
    }
    $gnu_ranges .= "set ytics nomirror\n";
    $gnu_ranges .= "set xtics nomirror\n";
    if ( $return['y2tics'] ) {
      $gnu_ranges .= "set y2tics\n";
      if ( strlen($return['y2range']) > 0 ) {
        $gnu_ranges .= "set y2range [".$return['y2range']."]\n";
      }
      if ( strlen($return['y2label']) > 0 ) {
        $gnu_labels .= "set y2label \"".$return['y2label']."\"\n"; 
      }
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
    $gnu_code .= $gnu_ranges;
    $gnu_code .= "set output \"@gnu_output@\"\n";
    for ($i=1; $i<sizeof($gnu_colors); $i++) {
      $gnu_code .= "set style line $i linetype rgb \"".$gnu_colors[$i]."\" linewidth 1.2 pointtype $i\n";
    }
    $j=$i;
    if ( count($return['hline']) > 0 ) {
      foreach ($return['hline'] as $id => $hline) {
        $hline=explode(':',$hline);
        $gnu_code .= "set style line $i linetype rgb \"".$hline[0]."\" linewidth 0.8 pointtype $i\n";
        $i++;
      }
    }
    if ( count($return['vline']) > 0 ) {
      foreach ($return['vline'] as $id => $vline) {
        $vline=explode(':',$vline);
        $gnu_code .= "set style line $i linetype rgb \"".$vline[0]."\" linewidth 0.8 pointtype $i\n";
        $gnu_code .= "set arrow from ".$vline[1].", graph 0 to ".$vline[1].", graph 1 nohead linestyle $i\n";
        $i++;
      }
    }
    $gnu_code .= 'plot';
    $sep  = ' ';
    if ( count($return['hline']) > 0 ) {
      foreach ($return['hline'] as $id => $hline) {
        $hline=explode(':',$hline);
        $gnu_code .= $sep.$hline[1].$sep."notitle linestyle $j,";
        $j++;
      }
    }
    for ($i=2; $i<=$return['columns']; $i++) {
      $gnu_style = $i-1;
      $_plt=$return['plottype'][$i-2];
      if (!$_plt) { $_plt=$return['plottype'][0]; }
      if ( in_array($i-1, $return['y2use']) ) {
        $axis='x1y2';
      } else {
        $axis='x1y1';
      }
      if ( $return['smooth'] && ($_plt == 'linespoints') ) {
        $gnu_code .= $sep.'"@gnu_input@" using 1:'.$i.' axis '.$axis.' notitle smooth csplines with lines linestyle '.$gnu_style;
        $sep = ", \\\n     ";
        $gnu_code .= $sep.'"@gnu_input@" using 1:'.$i.' axis '.$axis.' notitle with points linestyle '.$gnu_style;
      } else {
        $gnu_code .= $sep.'"@gnu_input@" using 1:'.$i.' axis '.$axis.' notitle with '.$_plt.' linestyle '.$gnu_style;
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
  function render($format, Doku_Renderer $renderer, $data) {
    if ( $format == 'xhtml' ) {
      $img = DOKU_BASE.'lib/plugins/dataplot/img.php?'.buildURLparams($data);
      $renderer->doc .= '<img src="'.$img.'" class="media'.$data['align'].'" alt=""';
      if ( $data['width'] )  $renderer->doc .= ' width="'.$data['width'].'"';
      if ( $data['height'] ) $renderer->doc .= ' height="'.$data['height'].'"';
      if ( $data['align'] == 'right' ) $renderer->doc .= ' align="right"';
      if ( $data['align'] == 'left' )  $renderer->doc .= ' align="left"';
      $renderer->doc .= '/>';

      // Debugging
      if ( $data['debug'] ) {
        $renderer->doc .= '<pre>'.$data['gnuplot'].'</pre>';
      }

      return true;
    } elseif ( $format == 'odt' ) {
      $src = $this->_imgfile($data);
      $renderer->_odtAddImage($src, $data['width'], $data['height'], $data['align']);

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
