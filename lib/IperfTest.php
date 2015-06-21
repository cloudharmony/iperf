<?php
// Copyright 2014 CloudHarmony Inc.
// 
// Licensed under the Apache License, Version 2.0 (the "License");
// you may not use this file except in compliance with the License.
// You may obtain a copy of the License at
// 
//     http://www.apache.org/licenses/LICENSE-2.0
// 
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.


/**
 * Used to manage OLTP Benchmark testing
 */
require_once(dirname(__FILE__) . '/benchmark/util.php');
ini_set('memory_limit', '512m');
date_default_timezone_set('UTC');

class IperfTest {
  
  /**
   * date format string
   */
  const IPERF_DATE_FORMAT = 'm/d/Y H:i e';
  
  /**
   * database date format
   */
  const IPERF_DB_DATE_FORMAT = 'Y-m-d H:i:s';
  
  /**
   * name of the file where serializes options should be written to for given 
   * test iteration
   */
  const IPERF_TEST_OPTIONS_FILE_NAME = '.options';
  
  /**
   * actual bandwidth used for UDP tests (indexed by server)
   */
  private $bandwidth = array();
  
  /**
   * optional results directory object was instantiated for
   */
  private $dir;
  
  /**
   * graph colors array
   */
  private $graphColors = array();
  
  /**
   * iperf binary name (iperf or iperf3)
   */
  private $iperf = 'iperf';
  
  /**
   * run options
   */
  private $options;
  
  /**
   * array containing test results
   */
  private $results = array();
  
  /**
   * array of hashes representing the servers to test. Each element has the 
   * following keys:
   *  hostname => hostname or ip
   *  instance_id => instance type identifier
   *  os => operating system
   *  port => port
   *  provider => provider name
   *  provider_id => provider ID
   *  region => service region
   *  service => service name
   *  service_id => service ID
   */
  private $servers = array();
  
  /**
   * enable verbose output?
   */
  private $verbose;
  
  
  /**
   * constructor
   * @param string $dir optional results directory object is being instantiated
   * for. If set, runtime parameters will be pulled from the .options file. Do
   * not set when running a test
   */
  public function IperfTest($dir=NULL) {
    $this->dir = $dir;
  }
  
  /**
   * writes test results and finalizes testing
   * @param int $success number of successful tests performed
   * @return boolean
   */
  private function endTest($success) {
    $ended = FALSE;
    $dir = $this->options['output'];
    if ($success) $this->options['results'] = $this->results;
    
    // serialize options
    $ofile = sprintf('%s/%s', $dir, self::IPERF_TEST_OPTIONS_FILE_NAME);
    if (is_dir($dir) && is_writable($dir)) {
      $fp = fopen($ofile, 'w');
      fwrite($fp, serialize($this->options));
      fclose($fp);
      $ended = TRUE;
    }
    
    // generate test report
    if ($success && $this->results && !isset($this->options['noreport'])) {
      mkdir($tdir = sprintf('%s/%d', $dir, rand()));
      print_msg(sprintf('Generating HTML report in %s', $tdir), $this->verbose, __FILE__, __LINE__);
      if ($this->generateReport($tdir) && file_exists(sprintf('%s/index.html', $tdir))) {
        if (!isset($this->options['nopdfreport'])) {
          print_msg('Generating PDF report using wkhtmltopdf', $this->verbose, __FILE__, __LINE__);
          $cmd = sprintf('cd %s; wkhtmltopdf -s Letter --footer-left [date] --footer-right [page] --footer-font-name rfont --footer-font-size %d index.html report.pdf >/dev/null 2>&1; echo $?', $tdir, $this->options['font_size']);
          $ecode = trim(exec($cmd));
          if ($ecode > 0) print_msg(sprintf('Failed to generate PDF report'), $this->verbose, __FILE__, __LINE__, TRUE);
          else {
            print_msg(sprintf('Successfully generated PDF report'), $this->verbose, __FILE__, __LINE__);
            exec(sprintf('mv %s/report.pdf %s', $tdir, $dir));
          }
        }
        $zip = sprintf('%s/report.zip', $dir);
        exec('rm -f ' . $zip);
        exec(sprintf('cd %s; zip %s *; mv %s %s', $tdir, basename($zip), basename($zip), $dir));
      }
      else print_msg('Unable to generate HTML report', $this->verbose, __FILE__, __LINE__, TRUE);
      
      print_msg(sprintf('Deleting temporary directory %s', $tdir), $this->verbose, __FILE__, __LINE__);
      exec(sprintf('rm -rf %s', $tdir));
    }
    else print_msg('Test report will not be generated because --noreport flag was set or no results exist', $this->verbose, __FILE__, __LINE__);
    
    return $ended;
  }
  
  /**
   * evaluates a string containing an expression. The substring [cpus] will be 
   * replaced with the number of CPU cores
   * @param string $expr the expression to evaluate
   * @return float
   */
  private function evaluateExpression($expr) {
    $sysInfo = get_sys_info();
    $expr = str_replace('[cpus]', isset($sysInfo['cpu_cores']) ? $sysInfo['cpu_cores'] : 2, $expr);
    eval(sprintf('$value=round(%s);', $expr));
    $value *= 1;
    return $value;
  }
  
  /**
   * generates graphs for $result in the directory $dir using the prefix 
   * $prefix
   * @param array $result the results data to generate graphs for
   * @param string $dir directory where graphs should be generate in
   * @param string prefix for generated files
   * @return boolean
   */
  private function generateGraphs($result, $dir, $prefix) {
    $graphs = array();
    if ($result && is_dir($dir) && is_writable($dir) && $prefix) {
      foreach(array('bandwidth', 'jitter', 'loss') as $attr) {
        if (isset($this->options['iperf_reverse']) && isset($this->options['skip_bandwidth_graphs']) && $attr == 'bandwidth') continue;
        
        $key = sprintf('%s_values', $attr);
        if (isset($result[$key]) && count($result[$key]) > 1) {
          print_msg(sprintf('Generating %s graphs using coordinates %s', $attr, implode(', ', $result[$key])), $this->verbose, __FILE__, __LINE__);
          
          $timeline = $this->makeCoords($result[$key]);
          $coords = array('' => $timeline,
                          'Median' => array(array($timeline[0][0], $result[sprintf('%s_median', $attr)]), array($timeline[count($timeline) - 1][0], $result[sprintf('%s_median', $attr)])));
          $settings = array();
          $settings['lines'] = array(1 => "lt 1 lc rgb '#5DA5DA' lw 3 pt -1",
                                     2 => "lt 2 lc rgb '#4D4D4D' lw 3 pt -1");
          $settings['nogrid'] = TRUE;
          $settings['yMin'] = 0;
          if ($graph = $this->generateGraph($dir, $prefix . '-' . $attr, $coords, 'Time (secs)', sprintf('%s (%s)', ucwords($attr == 'loss' ? 'datagram loss' : $attr), $attr == 'bandwidth' ? 'Mb/s' : ($attr == 'jitter' ? 'ms' : '%')), NULL, $settings)) $graphs[sprintf('%s - %s', ucwords($attr == 'loss' ? 'datagram loss' : $attr), $result['iperf_server'])] = $graph;
          
          // Histogram
          $coords = $this->makeCoords($result[$key], TRUE);
          $settings = array();
          $settings['nogrid'] = TRUE;
          $settings['yMin'] = 0;
          $settings['yFloatPrec'] = 0;
          $settings['yMax'] = '20%';
          if ($graph = $this->generateGraph($dir, sprintf('%s-%s-histogram', $prefix, $attr), $coords, sprintf('%s (%s)', ucwords($attr == 'loss' ? 'datagram loss' : $attr), $attr == 'bandwidth' ? 'Mb/s' : ($attr == 'jitter' ? 'ms' : '%')), 'Samples', NULL, $settings, TRUE, 'histogram')) $graphs[sprintf('%s Histogram - %s', ucwords($attr == 'loss' ? 'datagram loss' : $attr), $result['iperf_server'])] = $graph;
        }
      }
    }
    return $graphs;
  }
  
  /**
   * generates a line chart based on the parameters provided. return value is 
   * the name of the image which may in turn be used in an image element for 
   * a content section. returns NULL on error
   * @param string $dir the directory where the line chart should be generated
   * @param string $prefix the file name prefix
   * @param array $coords either a single array of tuples representing the x/y
   * values, or a hash or tuple arrays indexed by the name of each set of data
   * points. coordinates should have the same index
   * @param string $xlabel optional x label
   * @param string $ylabel optional y label
   * @param string $title optional graph title
   * @param array $settings optional array of custom gnuplot settings. the 
   * following special settings are supported:
   *   height: the graph height
   *   lines:     optional line styles (indexed by line #)
   *   nogrid:    don't add y axis grid lines
   *   nokey:     don't show the plot key/legend
   *   nolinespoints: don't use linespoints
   *   xFloatPrec: x float precision
   *   xLogscale: use logscale for the x axis
   *   xMin:      min value for the x axis tics - may be a percentage relative to 
   *              the lowest value
   *   xMax:      max value for the x axis tics - may be a percentage relative to 
   *              the highest value
   *   xTics:     the number of x tics to show (default 8)
   *   yFloatPrec: y float precision
   *   yLogscale: use logscale for the y axis
   *   yMin:      min value for the x axis tics - may be a percentage relative to 
   *              the lowest value
   *   yMax:      max value for the y axis tics - may be a percentage relative to 
   *              the highest value
   *   yTics:     the number of y tics to show (default 8)
   * 
   * xMin, xMax, yMin and yMax all default to the same value as the other for 
   * percentages and 15% otherwise if only 1 is set for a given 
   * axis. If neither are specified, gnuplot will auto assign the tics. If xMin
   * or xMax are specified, but not xTics, xTics defaults to 8
   * @param boolean $html whether or not to return the html <img element or just
   * the name of the file
   * @param string $type the type of graph to generate - line, histogram or bar. 
   * If histogram, $coords should represent all of the y values for a 
   * given X. The $coords hash key will be used as the X label and the value(s) 
   * rendered using a clustered histogram (grouped column chart)
   * @return string
   */
  private function generateGraph($dir, $prefix, $coords, $xlabel=NULL, $ylabel=NULL, $title=NULL, $settings=NULL, $html=TRUE, $type='line') {
    print_msg(sprintf('Generating line chart in %s using prefix %s with %d coords', $dir, $prefix, count($coords)), $this->verbose, __FILE__, __LINE__);
    
    $chart = NULL;
    $script = sprintf('%s/%s.pg', $dir, $prefix);
    $dfile = sprintf('%s/%s.dat', $dir, $prefix);
    if (is_array($coords) && ($fp = fopen($script, 'w')) && ($df = fopen($dfile, 'w'))) {
      $colors = $this->getGraphColors();
      $xFloatPrec = isset($settings['xFloatPrec']) && is_numeric($settings['xFloatPrec']) ? $settings['xFloatPrec'] : 0;
      $yFloatPrec = isset($settings['yFloatPrec']) && is_numeric($settings['yFloatPrec']) ? $settings['yFloatPrec'] : 0;
      
      // just one array of tuples
      if (isset($coords[0]) && isset($coords[1])) $coords = array('' => $coords);
      
      // determine max points/write data file header
      $maxPoints = NULL;
      foreach(array_keys($coords) as $i => $key) {
        if ($maxPoints === NULL || count($coords[$key]) > $maxPoints) $maxPoints = count($coords[$key]);
        if ($type == 'line') fwrite($df, sprintf("%s%s%s\t%s%s", $i > 0 ? "\t" : '', $key ? $key . ' ' : '', $xlabel ? $xlabel : 'X', $key ? $key . ' ' : '', $ylabel ? $ylabel : 'Y'));
      }
      if ($type == 'line') fwrite($df, "\n");
      
      // determine value ranges and generate data file
      $minX = NULL;
      $maxX = NULL;
      $minY = NULL;
      $maxY = NULL;
      if ($type != 'line') {
        foreach($coords as $x => $points) {
          if ($type == 'bar' && is_numeric($x) && ($minX === NULL || $x < $minX)) $minX = $x;
          if ($type == 'bar' && is_numeric($x) && $x > $maxX) $maxX = $x;
          fwrite($df, $x);
          for($n=0; $n<$maxPoints; $n++) {
            $y = isset($points[$n]) && is_numeric($points[$n]) ? $points[$n]*1 : (isset($points[0]) && is_numeric($points[0]) ? $points[0] : 0);
            if (is_numeric($y) && ($minY === NULL || $y < $minY)) $minY = $y;
            if (is_numeric($y) && $y > $maxY) $maxY = $y;
            fwrite($df, sprintf("\t%s", $y));
          }
          fwrite($df, "\n");
        }
        if ($type == 'histogram') fwrite($df, ".\t0\n");
      }
      else {
        for($n=0; $n<$maxPoints; $n++) {
          foreach(array_keys($coords) as $i => $key) {
            $x = isset($coords[$key][$n][0]) ? $coords[$key][$n][0] : '';
            if (is_numeric($x) && ($minX === NULL || $x < $minX)) $minX = $x;
            if (is_numeric($x) && $x > $maxX) $maxX = $x;
            $y = isset($coords[$key][$n][1]) ? $coords[$key][$n][1] : '';
            if (is_numeric($y) && ($minY === NULL || $y < $minY)) $minY = $y;
            if (is_numeric($y) && $y > $maxY) $maxY = $y;
            fwrite($df, sprintf("%s%s\t%s", $i > 0 ? "\t" : '', $x, $y));
          }
          fwrite($df, "\n");
        } 
      }
      fclose($df);
      
      // determine x tic settings
      $xMin = isset($settings['xMin']) ? $settings['xMin'] : NULL;
      $xMax = isset($settings['xMax']) ? $settings['xMax'] : NULL;
      $xTics = isset($settings['xTics']) ? $settings['xTics'] : NULL;
      if (!isset($xMin) && (isset($xMax) || $xTics)) $xMin = isset($xMax) && preg_match('/%/', $xMax) ? $xMax : '15%';
      if (!isset($xMax) && (isset($xMin) || $xTics)) $xMax = isset($xMin) && preg_match('/%/', $xMin) ? $xMin : '15%';
      if (!isset($xMin)) $xMin = $minX;
      if (!isset($xMax)) $xMax = $maxX;
      if (preg_match('/^([0-9\.]+)%$/', $xMin, $m)) {
        $xMin = floor($minX - ($minX*($m[1]*0.01)));
        if ($xMin < 0) $xMin = 0;
      }
      if (preg_match('/^([0-9\.]+)%$/', $xMax, $m)) {
        $xMax = ceil($maxX + ($maxX*($m[1]*0.01)));
        if ($xMax == 1) $xMax = round($maxX + ($maxX*($m[1]*0.01)), 3);
      }
      if (!$xTics) $xTics = 8;
      $xDiff = $xMax - $xMin;
      $xStep = floor($xDiff/$xTics);
      if ($xDiff <= 1) {
        $xStep = round($xDiff/$xTics, 3);
        if (!isset($settings['xFloatPrec'])) $xFloatPrec = 3;
      }
      
      // determine y tic settings
      $yMin = isset($settings['yMin']) ? $settings['yMin'] : NULL;
      $yMax = isset($settings['yMax']) ? $settings['yMax'] : NULL;
      $yTics = isset($settings['yTics']) ? $settings['yTics'] : NULL;
      if (!isset($yMin) && (isset($yMax) || $yTics)) $yMin = isset($yMax) && preg_match('/%/', $yMax) ? $yMax : '15%';
      if (!isset($yMax) && (isset($yMin) || $yTics)) $yMax = isset($yMin) && preg_match('/%/', $yMin) ? $yMin : '15%';
      if (isset($yMin) && preg_match('/^([0-9\.]+)%$/', $yMin, $m)) {
        $yMin = floor($minY - ($minY*($m[1]*0.01)));
        if ($yMin < 0) $yMin = 0;
      }
      if (isset($yMin)) {
        if (preg_match('/^([0-9\.]+)%$/', $yMax, $m)) {
          $yMax = ceil($maxY + ($maxY*($m[1]*0.01)));
          if ($yMax == 1) $yMax = round($maxY + ($maxY*($m[1]*0.01)), 3);
        }
        if (!$yTics) $yTics = 8;
        $yDiff = $yMax - $yMin;
        $yStep = floor($yDiff/$yTics);
        if ($yDiff <= 1) {
          $yStep = round($yDiff/$yTics, 3);
          if (!isset($settings['yFloatPrec'])) $yFloatPrec = 3;
        }
      }
      
      $img = sprintf('%s/%s.svg', $dir, $prefix);
      print_msg(sprintf('Generating line chart %s with %d data sets and %d points/set. X Label: %s; Y Label: %s; Title: %s; xMax: %s; xStep %s; yMax: %s; yStep: %s', basename($img), count($coords), $maxPoints, $xlabel, $ylabel, $title, $xMax, $xStep, $yMax, $yStep), $this->verbose, __FILE__, __LINE__);
      
      fwrite($fp, sprintf("#!%s\n", trim(shell_exec('which gnuplot'))));
      fwrite($fp, "reset\n");
      fwrite($fp, sprintf("set terminal svg dashed size 1024,%d fontfile 'font-svg.css' font 'rfont,%d'\n", isset($settings['height']) ? $settings['height'] : 600, $this->options['font_size']+4));
      // custom settings
      if (is_array($settings)) {
        foreach($settings as $key => $setting) {
          // special settings
          if (in_array($key, array('height', 'lines', 'nogrid', 'nokey', 'nolinespoints', 'xLogscale', 'xMin', 'xMax', 'xTics', 'xFloatPrec', 'yFloatPrec', 'yLogscale', 'yMin', 'yMax', 'yTics'))) continue;
          fwrite($fp, "${setting}\n");
        }
      }
      fwrite($fp, "set autoscale keepfix\n");
      fwrite($fp, "set decimal locale\n");
      fwrite($fp, "set format y \"%'10.${yFloatPrec}f\"\n");
      fwrite($fp, "set format x \"%'10.${xFloatPrec}f\"\n");
      if ($xlabel) fwrite($fp, sprintf("set xlabel \"%s\"\n", $xlabel));
      if (isset($settings['xLogscale'])) {
        if (!isset($settings['xMin'])) $xMin = IperfTest::adjustLogScale($xMin, TRUE);
        if (!isset($settings['xMax'])) $xMax = IperfTest::adjustLogScale($xMax);
      }
      if ($xMin != $xMax) fwrite($fp, sprintf("set xrange [%d:%s]\n", $xMin, $xMax));
      if (isset($settings['xLogscale'])) fwrite($fp, "set logscale x\n");
      else if ($xMin != $xMax && !$xFloatPrec) fwrite($fp, sprintf("set xtics %d, %s, %s\n", $xMin, $xStep, $xMax));
      if ($ylabel) fwrite($fp, sprintf("set ylabel \"%s\"\n", $ylabel));
      if (isset($yMin)) {
        if (isset($settings['yLogscale'])) {
          if (!isset($settings['yMin'])) $yMin = IperfTest::adjustLogScale($yMin, TRUE);
          if (!isset($settings['yMax'])) $yMax = IperfTest::adjustLogScale($yMax);
        }
        if ($yMin != $yMax) fwrite($fp, sprintf("set yrange [%d:%s]\n", $yMin, $yMax));
        if (isset($settings['yLogscale'])) fwrite($fp, "set logscale y\n");
        else if (!$yFloatPrec) fwrite($fp, sprintf("set ytics %d, %s, %s\n", $yMin, $yStep, $yMax));
      }
      if ($title) fwrite($fp, sprintf("set title \"%s\"\n", $title));
      if (!isset($settings['nokey'])) fwrite($fp, "set key outside center top horizontal reverse\n");
      fwrite($fp, "set grid\n");
      fwrite($fp, sprintf("set style data lines%s\n", !isset($settings['nolinespoints']) || !$settings['nolinespoints'] ? 'points' : ''));
      
      # line styles
      fwrite($fp, "set border linewidth 1.5\n");
      foreach(array_keys($coords) as $i => $key) {
        if (!isset($colors[$i])) break;
        if (isset($settings['lines'][$i+1])) fwrite($fp, sprintf("set style line %d %s\n", $i+1, $settings['lines'][$i+1]));
        else fwrite($fp, sprintf("set style line %d lc rgb '%s' lt 1 lw 3 pt -1\n", $i+1, $colors[$i]));
      }
      if ($type != 'line') {
        fwrite($fp, "set style fill solid noborder\n");
        fwrite($fp, sprintf("set boxwidth %s relative\n", $type == 'histogram' ? '1' : '0.9'));
        fwrite($fp, sprintf("set style histogram cluster gap %d\n", $type == 'histogram' ? 0 : 1));
        fwrite($fp, "set style data histogram\n");
      }
      
      fwrite($fp, "set grid noxtics\n");
      if (!isset($settings['nogrid'])) fwrite($fp, "set grid ytics lc rgb '#dddddd' lw 1 lt 0\n");
      else fwrite($fp, "set grid noytics\n");
      fwrite($fp, "set tic scale 0\n");
      // centering of labels doesn't work with current CentOS gnuplot package, so simulate for histogram
      fwrite($fp, sprintf("set xtics offset %d\n", $type == 'histogram' ? round(0.08*(480/count($coords))) : -1));
      fwrite($fp, sprintf("plot \"%s\"", basename($dfile)));
      $colorPtr = 1;
      if ($type != 'line') {
        for($i=0; $i<$maxPoints; $i++) {
          fwrite($fp, sprintf("%s u %d:xtic(1) ls %d notitle", $i > 0 ? ", \\\n\"\"" : '', $i+2, $colorPtr));
          $colorPtr++;
          if ($colorPtr > count($colors)) $colorPtr = 1;
        }
      }
      else {
        foreach(array_keys($coords) as $i => $key) {
          fwrite($fp, sprintf("%s every ::1 u %d:%d t \"%s\" ls %d", $i > 0 ? ", \\\n\"\"" : '', ($i*2)+1, ($i*2)+2, $key, $colorPtr));
          $colorPtr++;
          if ($colorPtr > count($colors)) $colorPtr = 1;
        }
      }
      
      fclose($fp);
      exec(sprintf('chmod +x %s', $script));
      $cmd = sprintf('cd %s; ./%s > %s 2>/dev/null; echo $?', $dir, basename($script), basename($img));
      $ecode = trim(exec($cmd));
      // exec('rm -f %s', $script);
      // exec('rm -f %s', $dfile);
      if ($ecode > 0) {
        // exec('rm -f %s', $img);
        // passthru(sprintf('cd %s; ./%s > %s', $dir, basename($script), basename($img)));
        // print_r($coords);
        // echo $cmd;
        // exit;
        print_msg(sprintf('Failed to generate line chart - exit code %d', $ecode), $this->verbose, __FILE__, __LINE__, TRUE);
      }
      else {
        print_msg(sprintf('Generated line chart %s successfully', $img), $this->verbose, __FILE__, __LINE__);
        // attempt to convert to PNG using wkhtmltoimage
        if (IperfTest::wkhtmltopdfInstalled()) {
          $cmd = sprintf('wkhtmltoimage %s %s >/dev/null 2>&1', $img, $png = str_replace('.svg', '.png', $img));
          $ecode = trim(exec($cmd));
          if ($ecode > 0 || !file_exists($png) || !filesize($png)) print_msg(sprintf('Unable to convert SVG image %s to PNG %s (exit code %d)', $img, $png, $ecode), $this->verbose, __FILE__, __LINE__, TRUE);
          else {
            exec(sprintf('rm -f %s', $img));
            print_msg(sprintf('SVG image %s converted to PNG successfully - PNG will be used in report', basename($img)), $this->verbose, __FILE__, __LINE__);
            $img = $png;
          }
        }
        // return full image tag
        if ($html) $chart = sprintf('<img alt="" class="plot" src="%s" />', basename($img));
        else $chart = basename($img);
      }
    }
    // error - invalid scripts or unable to open gnuplot files
    else {
      print_msg(sprintf('Failed to generate line chart - either coordinates are invalid or script/data files %s/%s could not be opened', basename($script), basename($dfile)), $this->verbose, __FILE__, __LINE__, TRUE);
      if ($fp) {
        fclose($fp);
        exec('rm -f %s', $script);
      }
    }
    return $chart;
  }
  
  /**
   * generates an HTML report. Returns TRUE on success, FALSE otherwise
   * @param string $dir optional directory where reports should be generated 
   * in. If not specified, --output will be used
   * @return boolean
   */
  public function generateReport($dir=NULL) {
    $generated = FALSE;
    $pageNum = 0;
    if (!$dir) $dir = $this->options['output'];
    
    if (is_dir($dir) && is_writable($dir) && ($fp = fopen($htmlFile = sprintf('%s/index.html', $dir), 'w'))) {
      print_msg(sprintf('Initiating report creation in directory %s', $dir), $this->verbose, __FILE__, __LINE__);
      
      $graphs = array();
      $reportsDir = dirname(dirname(__FILE__)) . '/reports';
      $fontSize = $this->options['font_size'];
      
      // add header
      $title = (isset($this->options['iperf_udp']) ? 'UDP' : 'TCP') . ' Iperf Test Report';
      
      ob_start();
      include(sprintf('%s/header.html', $reportsDir));
      fwrite($fp, ob_get_contents());
      ob_end_clean();
      
      // copy font files
      exec(sprintf('cp %s/font-svg.css %s/', $reportsDir, $dir));
      exec(sprintf('cp %s/font.css %s/', $reportsDir, $dir));
      exec(sprintf('cp %s/font.ttf %s/', $reportsDir, $dir));
      exec(sprintf('cp %s/logo.png %s/', $reportsDir, $dir));
      
      $pagesPerServer = isset($this->options['iperf_udp']) ? (isset($this->options['iperf_reverse']) && isset($this->options['skip_bandwidth_graphs']) ? 4 : 6) : 2;
      $testPages = count($this->servers) * $pagesPerServer;
      
      // multi-server graphs
      $earliest = NULL;
      $latest = NULL;
      $directions = array();
      $transfer = 0;
      $bcoords = array();
      $bandwidth = array();
      $jcoords = array();
      $jitter = array();
      $lcoords = array();
      $loss = array();
      if (count($this->servers) > 1) {
        $testPages += $pagesPerServer;
        foreach($this->results as $i => $result) {
          $pieces = explode(':', $result['iperf_server']);
          $server = $pieces[0];
          $offset = count($bandwidth)*$this->options['iperf_interval'] + ($i*$this->options['iperf_warmup']);
          if (count($result['bandwidth_values'])) {
            $bcoords[sprintf('%s - %s', $server, ucwords($result['bandwidth_direction']))] = $this->makeCoords($result['bandwidth_values'], FALSE, $offset);
          }
          if (isset($result['jitter_values']) && count($result['jitter_values']) > 1) {
            $jcoords[sprintf('%s', $server, ucwords($result['bandwidth_direction']))] = $this->makeCoords($result['jitter_values'], FALSE, $offset);
            foreach($result['jitter_values'] as $val) $jitter[] = $val;
          }
          if (isset($result['loss_values']) && count($result['loss_values']) > 1) {
            $lcoords[sprintf('%s', $server, ucwords($result['bandwidth_direction']))] = $this->makeCoords($result['loss_values'], FALSE, $offset);
            foreach($result['loss_values'] as $val) $loss[] = $val;
          }
          foreach($result['bandwidth_values'] as $val) $bandwidth[] = $val;
          if (!$earliest) $earliest = $result['test_started'];
          $latest = $result['test_stopped'];
          $directions[ucwords($result['bandwidth_direction'])] = TRUE;
          $transfer += $result['transfer'];
        }
        $settings = array();
        $settings['nogrid'] = TRUE;
        $settings['yMin'] = 0;
        
        $hsettings = array();
        $hsettings['nogrid'] = TRUE;
        $hsettings['yMin'] = 0;
        $hsettings['yFloatPrec'] = 0;
        $hsettings['yMax'] = '20%';
        
        if (!isset($this->options['skip_bandwidth_graphs'])) {
          if ($bcoords && ($graph = $this->generateGraph($dir, 'bandwidth', $bcoords, 'Time (secs)', 'Bandwidth (Mb/s)', NULL, $settings))) $graphs['Bandwidth'] = $graph;
          if ($bandwidth) {
            $coords = $this->makeCoords($bandwidth, TRUE);
            if ($graph = $this->generateGraph($dir, 'bandwidth-histogram', $coords, 'Bandwidth (Mb/s)', 'Samples', NULL, $hsettings, TRUE, 'histogram')) $graphs['Bandwidth Histogram - All Servers'] = $graph;
          }
        }
        if ($jcoords && ($graph = $this->generateGraph($dir, 'jitter', $jcoords, 'Time (secs)', 'Jitter (ms)', NULL, $settings))) $graphs['Jitter'] = $graph;
        if ($jitter) {
          $coords = $this->makeCoords($jitter, TRUE);
          if ($graph = $this->generateGraph($dir, 'jitter-histogram', $coords, 'Jitter (ms)', 'Samples', NULL, $hsettings, TRUE, 'histogram')) $graphs['Jitter Histogram - All Servers'] = $graph;
        }
        if ($lcoords && ($graph = $this->generateGraph($dir, 'loss', $lcoords, 'Time (secs)', 'Datagram Loss (%)', NULL, $settings))) $graphs['Datagram Loss'] = $graph;
        if ($loss) {
          $coords = $this->makeCoords($loss, TRUE);
          if ($graph = $this->generateGraph($dir, 'loss-histogram', $coords, 'Datagram Loss (%)', 'Samples', NULL, $hsettings, TRUE, 'histogram')) $graphs['Datagram Loss Histogram - All Servers'] = $graph;
        }
      }
      
      $gresults = array();
      foreach($this->results as $result) {
        $testPageNum = 0;
        if ($rgraphs = $this->generateGraphs($result, $dir, str_replace(':', '_', str_replace('.', '-', $result['iperf_server'])))) {
          print_msg(sprintf('Successfully generated report graphs for server %s', $result['iperf_server']), $this->verbose, __FILE__, __LINE__);
          $graphs = array_merge($graphs, $rgraphs);
          foreach(array_keys($rgraphs) as $label) $gresults[$label] = $result;
        }
        else {
          if (!isset($this->options['skip_bandwidth_graphs'])) print_msg(sprintf('Unable to generate report graphs for server %s, bandwidth values: [%s]', $result['iperf_server'], implode(', ', $result['bandwidth_values'])), $this->verbose, __FILE__, __LINE__, TRUE);
          continue;
        }
      }
      
      // render report graphs (1 per page)
      foreach($graphs as $label => $graph) {
        $result = isset($gresults[$label]) ? $gresults[$label] : NULL;
        $bwMean = $result ? $result['bandwidth_mean'] : get_mean($bandwidth);
        $bwMedian = $result ? $result['bandwidth_median'] : get_median($bandwidth);
        $bwStd = $result ? $result['bandwidth_stdev'] : get_std_dev($bandwidth);
        $tx = $result ? $result['transfer'] : $transfer;
        $jitterMean = $result ? (isset($result['jitter_mean']) ? $result['jitter_mean'] : NULL) : ($jitter ? get_mean($jitter) : NULL);
        $jitterMedian = $result ? (isset($result['jitter_median']) ? $result['jitter_median'] : NULL) : ($jitter ? get_median($jitter) : NULL);
        $lossMean = $result ? (isset($result['loss_mean']) ? $result['loss_mean'] : NULL) : ($loss ? get_mean($loss) : NULL);
        $lossMedian = $result ? (isset($result['loss_median']) ? $result['loss_median'] : NULL) : ($loss ? get_median($loss) : NULL);
        $bw = NULL;
        if (isset($this->options['iperf_udp'])) {
          if ($result) $bw = $this->bandwidth[$result['iperf_server']];
          else $bw = $this->options['iperf_bandwidth'];
          if (!$bw) $bw = '1M';
        }
        $params = array(
          'platform' => $this->getPlatformParameters(),
          'server' => $this->getPlatformParameters($result ? $result['iperf_server'] : NULL),
          'test' =>     array('Test Protocol' => isset($this->options['iperf_udp']) ? 'UDP' : 'TCP',
                              'Direction' => $result ? ucwords($result['bandwidth_direction']) : implode(', ', array_keys($directions)),
                              'Duration' => isset($this->options['iperf_num']) ? $this->options['iperf_num'] . ' Buffers' : $this->options['iperf_time'] . ' secs',
                              'Concurrency' => $result['iperf_concurrency'],
                              'Connections' => $this->options['iperf_parallel'],
                              'UDP Bandwidth' => isset($this->options['iperf_udp']) ? $bw : 'N/A',
                              'Started' => $result ? $result['test_started'] : $earliest,
                              'Ended' => $result ? $result['test_stopped'] : $latest),
          'result' =>   array('Mean Bandwidth' => round($bwMean > 1000 ? $bwMean/1000 : $bwMean, 2) . ($bwMean > 1000 ? ' Gb/s' : ' Mb/s'),
                              'Median Bandwidth' => round($bwMedian > 1000 ? $bwMedian/1000 : $bwMedian, 2) . ($bwMedian > 1000 ? ' Gb/s' : ' Mb/s'),
                              'Std Dev' => round($bwStd, 2) . ' Mb/s',
                              'Transfer' => round($tx > 1024 ? $tx/1024 : $tx, 2) . ($tx > 1024 ? ' GB' : ' MB'),
                              'Mean Jitter' => isset($jitterMean) ? round($jitterMean, 4) . ' ms' : 'N/A',
                              'Median Jitter' => isset($jitterMedian) ? round($jitterMedian, 4) . ' ms' : 'N/A',
                              'Mean Datagram Loss' => isset($lossMean) ? round($lossMean, 4) . '%' : 'N/A',
                              'Median Datagram Loss' => isset($lossMedian) ? round($lossMedian, 4) . '%' : 'N/A')
        );
        if (!$result) unset($params['server']);
        $headers = array();
        for ($i=0; $i<100; $i++) {
          $empty = TRUE;
          $cols = array();
          foreach($params as $type => $vals) {
            if (count($vals) >= ($i + 1)) {
              $empty = FALSE;
              $keys = array_keys($vals);
              $cols[] = array('class' => $type, 'label' => $keys[$i], 'value' => $vals[$keys[$i]]);
            }
            else $cols[] = array('class' => $type, 'label' => '', 'value' => '');
          }
          if (!$empty) $headers[] = $cols;
          else break;
        }
        
        $pageNum++;
        $testPageNum++;
        print_msg(sprintf('Successfully generated graphs for %s', $result ? $result['iperf_server'] : 'all servers'), $this->verbose, __FILE__, __LINE__);
        ob_start();
        include(sprintf('%s/test.html', $reportsDir));
        fwrite($fp, ob_get_contents());
        ob_end_clean(); 
        $generated = TRUE; 
      }
      
      // add footer
      ob_start();
      include(sprintf('%s/footer.html', $reportsDir));
      fwrite($fp, ob_get_contents());
      ob_end_clean();
      
      fclose($fp);
    }
    else print_msg(sprintf('Unable to generate report in directory %s - it either does not exist or is not writable', $dir), $this->verbose, __FILE__, __LINE__, TRUE);
    
    return $generated;
  }
  
  /**
   * returns an array containing the hex color codes to use for graphs (as 
   * defined in graph-colors.txt)
   * @return array
   */
  protected final function getGraphColors() {
    if (!count($this->graphColors)) {
      foreach(file(dirname(__FILE__) . '/graph-colors.txt') as $line) {
        if (substr($line, 0, 1) != '#' && preg_match('/([a-zA-Z0-9]{6})/', $line, $m)) $this->graphColors[] = '#' . $m[1];
      }
    }
    return $this->graphColors;
  }
  
  /**
   * returns the platform parameters for this test. These are displayed in the 
   * Test Platform columns
   * @param string $server if true, params will be for the $server designated
   * @return array
   */
  private function getPlatformParameters($server = FALSE) {
    if ($server && isset($this->servers[$server])) {
      $os = 
      $params = array(
        'Server' => $server,
        'Provider' => isset($this->servers[$server]['provider']) ? $this->servers[$server]['provider'] : (isset($this->servers[$server]['provider_id']) ? $this->servers[$server]['provider_id'] : ''),
        'Service' => isset($this->servers[$server]['service']) ? $this->servers[$server]['service'] : (isset($this->servers[$server]['service_id']) ? $this->servers[$server]['service_id'] : ''),
        'Region' => isset($this->servers[$server]['region']) ? $this->servers[$server]['region'] : '',
        'Instance Type' => isset($this->servers[$server]['instance_id']) ? $this->servers[$server]['instance_id'] : '',
        'Operating System' => isset($this->servers[$server]['os']) ? $this->servers[$server]['os'] : '',
      );
    }
    else {
      $params = array(
        'Provider' => isset($this->options['meta_provider']) ? $this->options['meta_provider'] : $this->options['meta_provider_id'],
        'Service' => isset($this->options['meta_compute_service']) ? $this->options['meta_compute_service'] : $this->options['meta_compute_service_id'],
        'Region' => isset($this->options['meta_region']) ? $this->options['meta_region'] : '',
        'Instance Type' => isset($this->options['meta_instance_id']) ? $this->options['meta_instance_id'] : '',
        'CPU' => isset($this->options['meta_cpu']) ? $this->options['meta_cpu'] : '',
        'Memory' => isset($this->options['meta_memory']) ? $this->options['meta_memory'] : '',
        'Operating System' => isset($this->options['meta_os']) ? $this->options['meta_os'] : '',
        'Test ID' => isset($this->options['meta_test_id']) ? $this->options['meta_test_id'] : '',
      );
    }
    return $params;
  }
  
  /**
   * returns results as an array of rows if testing was successful, NULL 
   * otherwise
   * @return array
   */
  public function getResults() {
    $rows = NULL;
    if (is_dir($this->dir) && self::getSerializedOptions($this->dir) && $this->getRunOptions() && isset($this->options['results'])) {
      $rows = array();
      $brow = array();
      foreach($this->options as $key => $val) {
        if (!is_array($this->options[$key])) $brow[$key] = $val;
      }
      foreach($this->options['results'] as $result) {
        $row = array_merge($brow, $result);
        if (!isset($this->options['iperf_udp']) && isset($row['iperf_bandwidth'])) unset($row['iperf_bandwidth']);
        $rows[] = $row;
      }
    }
    return $rows;
  }
  
  /**
   * returns run options represents as a hash
   * @return array
   */
  public function getRunOptions() {
    if (!isset($this->options)) {
      if ($this->dir) $this->options = self::getSerializedOptions($this->dir);
      else {
        // default run argument values
        $sysInfo = get_sys_info();
        $defaults = array(
          'collectd_rrd_dir' => '/var/lib/collectd/rrd',
          'drop_final' => 0,
          'font_size' => 9,
          'iperf_bandwidth' => '1M',
          'iperf_interval' => 1,
          'iperf_listen' => rand(49152, 65535),
          'iperf_parallel' => 1,
          'iperf_time' => 10,
          'iperf_warmup' => 0,
          'meta_compute_service' => '',
          'meta_cpu' => $sysInfo['cpu'],
          'meta_instance_id' => '',
          'meta_memory' => $sysInfo['memory_gb'] > 0 ? $sysInfo['memory_gb'] . ' GB' : $sysInfo['memory_mb'] . ' MB',
          'meta_os' => $sysInfo['os_info'],
          'meta_provider' => '',
          'output' => trim(shell_exec('pwd'))
        );
        $opts = array(
          'collectd_rrd',
          'collectd_rrd_dir:',
          'drop_final:',
          'font_size:',
          'ignore_uplink',
          'iperf_bandwidth:',
          'iperf_interval:',
          'iperf_len:',
          'iperf_listen:',
          'iperf_mss:',
          'iperf_nodelay',
          'iperf_num:',
          'iperf_parallel:',
          'iperf_server:',
          'iperf_server_instance_id:',
          'iperf_server_os:',
          'iperf_server_provider:',
          'iperf_server_provider_id:',
          'iperf_server_region:',
          'iperf_server_service:',
          'iperf_server_service_id:',
          'iperf_time:',
          'iperf_tos:',
          'iperf_reverse',
          'iperf_udp',
          'iperf_warmup:',
          'iperf_window:',
          'iperf_zerocopy',
          'meta_compute_service:',
          'meta_compute_service_id:',
          'meta_cpu:',
          'meta_instance_id:',
          'meta_memory:',
          'meta_os:',
          'meta_provider:',
          'meta_provider_id:',
          'meta_region:',
          'meta_resource_id:',
          'meta_run_id:',
          'meta_run_group_id:',
          'meta_test_id:',
          'nopdfreport',
          'noreport',
          'output:',
          'skip_bandwidth_graphs',
          'tcp_bw_file:',
          'v' => 'verbose'
        );
        $this->options = parse_args($opts, array('iperf_server', 
                                                 'iperf_server_instance_id', 
                                                 'iperf_server_os', 
                                                 'iperf_server_provider', 
                                                 'iperf_server_provider_id',
                                                 'iperf_server_region',
                                                 'iperf_server_service',
                                                 'iperf_server_service_id'));
        $this->verbose = isset($this->options['verbose']);
        
        // set default values
        foreach($defaults as $key => $val) {
          if (!isset($this->options[$key])) $this->options[$key] = $val;
        }
        
        // check for [cpus] in 
        $this->options['iperf_parallel'] = $this->evaluateExpression($this->options['iperf_parallel']);
        
        // servers
        foreach($this->options['iperf_server'] as $i => $hostname) {
          $server = array();
          $pieces = explode(':', trim($hostname));
          $server['hostname'] = $pieces[0];
          for($n=1; $n<count($pieces); $n++) {
            if (isset($pieces[$n]) && is_numeric($pieces[$n]) && $pieces[$n] > 0) {
              $port = $pieces[$n]*1;
              if (!isset($server['ports'])) $server['ports'] = array();
              if (!in_array($port, $server['ports'])) $server['ports'][] = $port;
            }
          }
          if (!isset($server['ports'])) $server['ports'] = array(0);
          foreach(array('iperf_server_instance_id', 'iperf_server_os', 'iperf_server_provider', 'iperf_server_provider_id', 'iperf_server_region', 'iperf_server_service', 'iperf_server_service_id') as $arg) {
            $attr = str_replace('iperf_server_', '', $arg);
            $val = NULL;
            if (isset($this->options[$arg][$i])) $val = $this->options[$arg][$i];
            else if (isset($this->options[$arg][0])) $val = $this->options[$arg][0];
            else if (isset($this->options[sprintf('meta_%s', $attr)])) $val = $this->options[sprintf('meta_%s', $attr)];
            else if (isset($this->options[sprintf('meta_compute_%s', $attr)])) $val = $this->options[sprintf('meta_compute_%s', $attr)];
            $server[$attr] = $val;
          }
          $this->servers[$hostname] = $server;
          print_msg(sprintf('Added server %s with ports %s to test endpoints', $server['hostname'], implode(', ', $server['ports'])), $this->verbose, __FILE__, __LINE__);
        }
      }
    }
    return $this->options;
  }
  
  /**
   * returns options from the serialized file where they are written when a 
   * test completes
   * @param string $dir the directory where results were written to
   * @return array
   */
  public static function getSerializedOptions($dir) {
    $ofile = sprintf('%s/%s', $dir, self::IPERF_TEST_OPTIONS_FILE_NAME);
    return file_exists($ofile) ? unserialize(file_get_contents($ofile)) : NULL;
  }
  
  /**
   * make coordinates tuples from a results array
   * @param array $vals results array (indexed by seconds)
   * @param boolean $histogram make coordinates for a histogram
   * @param int $offset operation start time offset
   * @return array
   */
  private function makeCoords($vals, $histogram=FALSE, $offset=0) {
    $coords = array();
    if ($histogram) {
      $vmin = NULL;
      $vmax = NULL;
      foreach($vals as $val) {
        if (!isset($vmin) || $val < $vmin) $vmin = $val;
        if (!isset($vmax) || $val > $vmax) $vmax = $val;        
      }
      if ($vmax > 1) {
        $decimal = FALSE;
        $min = floor($vmin/100)*100;
        $max = ceil($vmax/100)*100;
        $diff = $max - $min;
        $step = round($diff/8);
      }
      else {
        $decimal = TRUE;
        $min = floor($vmin);
        $max = round($vmax, 3);
        $diff = $max - $min;
        $step = round($diff/8, 3);
      }
      
      print_msg(sprintf('Generating histogram coords with vmax=%s; vmin=%s; min=%s; max=%s; step=%s', $vmax, $vmin, $min, $max, $step), $this->verbose, __FILE__, __LINE__);
      
      for($start=$min; $start<$max; $start+=$step) {
        $label = sprintf('%s', round($start, $decimal ? 3 : 0));
        $coords[$label] = 0;
        foreach($vals as $val) if ($val >= $start && $val < ($start + $step)) $coords[$label]++;
        print_msg(sprintf('Set count=%d for histogram label %s', $coords[$label], $label), $this->verbose, __FILE__, __LINE__);
        $coords[$label] = array($coords[$label]);
      }
    }
    else {
      foreach(array_keys($vals) as $i => $secs) {
        $time = $offset + $secs;
        if (isset($this->options['iperf_warmup']) && $this->options['iperf_warmup'] > 0) $time += $this->options['iperf_warmup'];
        $coords[] = array($time, $vals[$secs]);
      }
    }
    return $coords;
  }
  
  /**
   * initiates Iperf testing. returns the number of tests completed
   * @return int
   */
  public function test() {
    $success = 0;
    $this->getRunOptions();
    $rrdStarted = isset($this->options['collectd_rrd']) ? ch_collectd_rrd_start($this->options['collectd_rrd_dir'], $this->verbose) : FALSE;
    $iperf3 = FALSE;
    $version = trim(shell_exec(sprintf('%s --version 2>&1', $this->iperf)));
    print_msg(sprintf('Got iperf version string: %s', $version), $this->verbose, __FILE__, __LINE__);
    if (preg_match('/iperf.*\s([2-3][0-9\.]+)/m', $version, $m)) {
      $this->options['iperf_version'] = $m[1];
      $iperf3 = substr($m[1], 0, 1) == 3;
    }
    
    foreach($this->servers as $host => $server) {
      $results = array();
      $ofile = sprintf('%s/%s', $this->options['output'], rand());
      $bwv = isset($this->options['iperf_bandwidth']) ? explode('/', $this->options['iperf_bandwidth']) : array('1M');
      $bw = trim($bwv[0]);
      if (isset($this->options['iperf_udp']) && 
          preg_match('/^([0-9\.]+)%$/', $bw, $m)) {
        // check for prior TCP bandwidth results
        $bw = NULL;
        if (isset($this->options['tcp_bw_file']) && file_exists($this->options['tcp_bw_file'])) {
          $prior = array();
          $fp = fopen($this->options['tcp_bw_file'], 'r');
          while($line = fgets($fp)) {
            $pieces = explode('/', $line);
            if ($host == $pieces[0] && isset($pieces[2]) && $pieces[2] > 0) {
              if (!isset($prior[$pieces[1]])) $prior[$pieces[1]] = array();
              $prior[$pieces[1]][] = $pieces[2]*1;
            }
          }
          fclose($fp);
          if (isset($prior['down']) || isset($prior['up'])) {
            $prior = isset($prior['down']) ? $prior['down'] : $prior['up'];
            $bw = round(get_mean($prior)*($m[1]*0.01)) . 'M';
            print_msg(sprintf('Set UDP bandwidth dynamically to %s for server %s and --iperf_bandwidth %s from prior TCP bandwidth metrics [%s]', $bw, $host, $this->options['iperf_bandwidth'], implode(', ', $prior)), $this->verbose, __FILE__, __LINE__);
          }
          else {
            $bw = isset($bwv[1]) ? $bwv[1] : '1M';
            print_msg(sprintf('Unable to set UDP bandwidth dynamically for server %s because not prior TCP Iperf metrics are present in %s - using %s instead', $host, $this->options['tcp_bw_file'], $bw), $this->verbose, __FILE__, __LINE__);
          }
        }
      }
      
      if (!preg_match('/^[0-9\.]+[km]?$/i', $bw)) $bw = '1M';
      
      $this->bandwidth[$host] = $bw;
      
      $iperf = '';
      $ofiles = array();
      $script = $ofile . '.sh';
      $fp = fopen($script, 'w');
      fwrite($fp, "#!/bin/bash\n");
      foreach($server['ports'] as $port) {
        $ofiles[$port] = sprintf('%s%s', $ofile, $port ? '.' . $port : '');
        $cmd = sprintf("%s%s -c %s %s -i %d%s%s%s%s%s%s%s%s%s%s%s%s%s%s >%s 2>&1 &\n",
          !isset($this->options['iperf_num']) && !validate_dependencies(array('timeout' => 'timeout')) ? 'timeout -s 9 ' . (30 + ($this->options['iperf_time']*(isset($this->options['iperf_reverse']) && !$iperf3 ? 2 : 1))) . ' ' : '',
          $this->iperf,
          $server['hostname'],
          $iperf3 ? '-J' : '-y C',
          $this->options['iperf_interval'], 
          isset($this->options['iperf_udp']) && $bw && $bw != '1M' ? ' -b ' . $bw : '',
          isset($this->options['iperf_len']) ? ' -l ' . $this->options['iperf_len'] : '',
          isset($this->options['iperf_mss']) ? ' -M ' . $this->options['iperf_mss'] : '',
          isset($this->options['iperf_nodelay']) ? ' -N' : '',
          isset($this->options['iperf_num']) ? ' -n ' . $this->options['iperf_num'] : '',
          $port > 0 ? ' -p ' . $port : '',
          isset($this->options['iperf_parallel']) && $this->options['iperf_parallel'] > 1 ? ' -P ' . $this->options['iperf_parallel'] : '',
          !isset($this->options['iperf_num']) && $this->options['iperf_time'] != 10 ? ' -t ' . $this->options['iperf_time'] : '',
          isset($this->options['iperf_tos']) ? ' -S ' . $this->options['iperf_tos'] : '',
          isset($this->options['iperf_reverse']) ? ($iperf3 ? ' -R' : ' -r') : '',
          isset($this->options['iperf_udp']) ? ' -u' : '',
          isset($this->options['iperf_window']) ? ' -w ' . $this->options['iperf_window'] : '',
          isset($this->options['iperf_zerocopy']) && $iperf3 ? ' -Z' : '',
          isset($this->options['iperf_reverse']) && !$iperf3 ? ' -L ' . $this->options['iperf_listen'] : '',
          $ofiles[$port]);
        $pieces = explode('>', $cmd);
        $pieces = explode($this->perf . ' ', $pieces[0]);
        $iperf .= ($iperf ? ' && ' : '') . $this->perf . ' ' . trim($pieces[0]);
        fwrite($fp, $cmd);
      }
      fwrite($fp, "wait\n");
      fclose($fp);
      exec(sprintf('chmod 755 %s', $script));
      print_msg(sprintf('Testing server %s with %d concurrent clients using script %s', $server['hostname'], count($server['ports']), $script), $this->verbose, __FILE__, __LINE__);
      $started = date(self::IPERF_DB_DATE_FORMAT);
      passthru($script);
      $stopped = date(self::IPERF_DB_DATE_FORMAT);
      print_msg(sprintf('Iperf testing completed successfully for server %s', $server['hostname']), $this->verbose, __FILE__, __LINE__);
      exec(sprintf('rm -f %s', $script));
      
      foreach($ofiles as $port => $ofile) {
        if (!isset($results[$port])) $results[$port] = array();
        
        if (file_exists($ofile) && filesize($ofile)) {
          $direction = $iperf3 && isset($this->options['iperf_reverse']) ? 'down' : 'up';

          // Iperf3 JSON format => always just 1 result
          if ($iperf3) {
            if (($json = json_decode(file_get_contents($ofile), TRUE)) && isset($json['intervals']) && count($json['intervals'])) {
              $result = array();
              print_msg(sprintf('Successfully parsed JSON respone with %d intervals', count($json['intervals'])), $this->verbose, __FILE__, __LINE__);
              foreach($json['intervals'] as $interval) {
                if (isset($interval['sum']) && isset($interval['sum']['start']) && (!$this->options['iperf_warmup'] || $interval['sum']['start'] >= $this->options['iperf_warmup'])) {
                  if (!isset($result['bandwidth_direction'])) {
                    $result['bandwidth_direction'] = $direction;
                    $result['bandwidth_values'] = array();
                    $result['transfer'] = 0;
                    if (isset($this->options['iperf_udp'])) {
                      $result['jitter_values'] = array();
                      $result['loss_values'] = array();
                    }
                  }
                  $start = $interval['sum']['start'];
                  $bw = ($interval['sum']['bits_per_second']/1000)/1000;
                  $mb = ($interval['sum']['bytes']/1024)/1024;
                  $result['bandwidth_values'][$start] = $bw;
                  $result['transfer'] += $mb;
                  if (isset($this->options['iperf_udp'])) {
                    if (isset($interval['sum']['jitter_ms'])) $result['jitter_values'][$start] = $interval['sum']['jitter_ms'];
                    if (isset($interval['sum']['lost_percent'])) $result['loss_values'][$start] = $interval['sum']['lost_percent'];
                  }
                }
              }
              if (isset($result['bandwidth_direction'])) {
                // add jitter/loss from server
                if (isset($this->options['iperf_udp'])) {
                  if (!$result['jitter_values'] && isset($json['end']['sum']['jitter_ms'])) $result['jitter_values'][] = $json['end']['sum']['jitter_ms'];
                  if (!$result['loss_values'] && isset($json['end']['sum']['lost_percent'])) $result['loss_values'][] = $json['end']['sum']['lost_percent'];
                }
                if (isset($json['end']['cpu_utilization_percent']['host_total'])) $result['cpu_client'] = $json['end']['cpu_utilization_percent']['host_total'];
                if (isset($json['end']['cpu_utilization_percent']['remote_total'])) $result['cpu_server'] = $json['end']['cpu_utilization_percent']['remote_total'];
                $results[$port][] = $result;
              }
            }
            else print_msg(sprintf('Unable to parse JSON results in %s', $ofile), $this->verbose, __FILE__, __LINE__, TRUE);
          }
          // Iperf 2 CSV format
          else {
            $lstart = 0;
            $result = array();
            foreach(file($ofile) as $line) {
              $pieces = explode(',', trim($line));
              if (count($pieces) < 8) continue;

              $span = explode('-', $pieces[6]);
              $start = $span[0]*1;
              $stop = $span[1]*1;

              if (round($stop - $start) == $this->options['iperf_interval'] && $pieces[2] > 0) {
                // change to downlink
                if ($lstart && $start < $lstart && ($lstart - $start) > 5) {
                  $direction = 'down';
                  $results[$port][] = $result;
                  $result = array();
                }
                if (!$result) {
                  print_msg(sprintf('Starting new row for %s', $pieces[3]), $this->verbose, __FILE__, __LINE__);
                  $result['bandwidth_direction'] = $direction;
                  $result['bandwidth_values'] = array();
                  $result['transfer'] = 0;
                  if (isset($this->options['iperf_udp'])) {
                    $result['jitter_values'] = array();
                    $result['loss_values'] = array();
                  }
                }
                if (!$this->options['iperf_warmup'] || $start >= $this->options['iperf_warmup']) {
                  if (!isset($result['bandwidth_values'][$start])) $result['bandwidth_values'][$start] = 0;
                  $bw = ($pieces[8]/1000)/1000;
                  $mb = ($pieces[7]/1024)/1024;
                  // print_msg(sprintf('Adding bandwidth value %s Mb/s, transfer %s MB for interval %d-%d; line %s', $bw, $mb, $start, $stop, trim($line)), $this->verbose, __FILE__, __LINE__);
                  $result['bandwidth_values'][$start] += $bw;
                  $result['transfer'] += $mb;
                  if (isset($this->options['iperf_udp']) && isset($pieces[9])) {
                    if (!isset($result['jitter_values_arr'][$start])) $result['jitter_values_arr'][$start] = array();
                    $result['jitter_values_arr'][$start][] = $pieces[9];
                    $result['jitter_values'][$start] = get_mean($result['jitter_values_arr'][$start]);
                  }
                  if (isset($this->options['iperf_udp']) && isset($pieces[12])) {
                    if (!isset($result['loss_values_arr'][$start])) $result['loss_values_arr'][$start] = array();
                    $result['loss_values_arr'][$start][] = $pieces[12];
                    $result['loss_values'][$start] = get_mean($result['loss_values_arr'][$start]);
                  }
                }
                $lstart = $start;
              }
              // add server values for jitter/loss
              else if (isset($this->options['iperf_udp']) && !$result['jitter_values'] && isset($pieces[9])) {
                print_msg(sprintf('Adding server jitter/datagram loss values %s/%s', $pieces[9], $pieces[12]), $this->verbose, __FILE__, __LINE__);
                $result['jitter_values'][] = $pieces[9];
                $result['loss_values'][] = $pieces[12];
              }
              // else print_msg(sprintf('Skipping line %s', trim($line)), $this->verbose, __FILE__, __LINE__);
            }
            if ($result) $results[$port][] = $result; 
          }
          exec(sprintf('rm -f %s', $ofile));
        }
        else {
          print_msg(sprintf('Iperf testing failed for server %s', $server['hostname']), $this->verbose, __FILE__, __LINE__, TRUE);
          $this->results = array();
          $success = FALSE;
          exec(sprintf('rm -f %s', $ofile));
          break;
        }
      }
      
      // merge results from multiple ports
      $nresults = array();
      foreach($results as $port => $presults) {
        foreach($presults as $i => $result) {
          if (!isset($nresults[$i])) {
            $nresults[$i] = $result;
            $nresults[$i]['iperf_concurrency'] = 1;
          }
          else {
            $nresults[$i]['iperf_concurrency']++;
            $nresults[$i]['transfer'] += $result['transfer'];
            foreach(array('bandwidth', 'jitter', 'loss') as $attr) {
              $key = sprintf('%s_values', $attr);
              if (isset($nresults[$i][$key])) {
                foreach(array_keys($nresults[$i][$key]) as $n) {
                  if (isset($result[$key][$n])) $nresults[$i][$key][$n] += $result[$key][$n];
                }
              }
            }
          }
        }
      }
      $results = $nresults;
      
      // generate statistical values
      foreach(array_keys($results) as $i) {
        if (!isset($results[$i]['bandwidth_values']) || count($results[$i]['bandwidth_values']) < 5) {
          unset($results[$i]);
          continue;
        }
        foreach(array('bandwidth', 'jitter', 'loss') as $attr) {
          $key = sprintf('%s_values', $attr);
          if (isset($results[$i][$key]) && !count($results[$i][$key])) unset($results[$i][$key]);
          if (isset($results[$i][$key])) {
            $results[$i][$key] = array_values($results[$i][$key]);
            if (isset($this->options['drop_final']) && $this->options['drop_final'] > 0 && count($results[$i][$key]) > $this->options['drop_final']) {
              print_msg(sprintf('Removing final %d metrics from %s with %d metrics', $this->options['drop_final'], $attr, count($results[$i][$key])), $this->verbose, __FILE__, __LINE__);
              $results[$i][$key] = array_slice($results[$i][$key], 0, $this->options['drop_final']*-1);
            }
            $values = array();
            foreach($results[$i][$key] as $val) $values[] = $val;
            sort($values);
            $results[$i][sprintf('%s_max', $attr)] = $values[count($values) - 1];
            $results[$i][sprintf('%s_mean', $attr)] = get_mean($values);
            $results[$i][sprintf('%s_median', $attr)] = get_median($values);
            $results[$i][sprintf('%s_min', $attr)] = $values[0];
            $results[$i][sprintf('%s_p10', $attr)] = get_percentile($values, 10, $attr == 'jitter' || $attr == 'loss');
            $results[$i][sprintf('%s_p25', $attr)] = get_percentile($values, 25, $attr == 'jitter' || $attr == 'loss');
            $results[$i][sprintf('%s_p75', $attr)] = get_percentile($values, 75, $attr == 'jitter' || $attr == 'loss');
            $results[$i][sprintf('%s_p90', $attr)] = get_percentile($values, 90, $attr == 'jitter' || $attr == 'loss');
            $results[$i][sprintf('%s_stdev', $attr)] = get_std_dev($values);
          }
        }
        $results[$i]['iperf_server'] = $host;
        foreach($server as $attr => $val) $results[$i][sprintf('iperf_server_%s', $attr)] = $val;
        $results[$i]['same_provider'] = isset($server['provider_id']) && isset($this->options['meta_provider_id']) && $server['provider_id'] == $this->options['meta_provider_id'];
        $results[$i]['same_service'] = $results[$i]['same_provider'] && isset($server['service_id']) && isset($this->options['meta_compute_service_id']) && $server['service_id'] == $this->options['meta_compute_service_id'];
        $results[$i]['same_region'] = $results[$i]['same_service'] && isset($server['region']) && isset($this->options['meta_region']) && $server['region'] == $this->options['meta_region'];
        $results[$i]['same_instance_id'] = $results[$i]['same_service'] && isset($server['instance_id']) && isset($this->options['meta_instance_id']) && $server['instance_id'] == $this->options['meta_instance_id'];
        $results[$i]['same_os'] = isset($results[$i]['iperf_server_os']) && isset($this->options['meta_os']) && $results[$i]['iperf_server_os'] == $this->options['meta_os'];
        $results[$i]['test_started'] = $started;
        $results[$i]['test_stopped'] = $stopped;
        // add TCP bandwidth results
        if (isset($this->options['tcp_bw_file']) && !isset($this->options['iperf_udp']) && isset($results[$i]['bandwidth_median']) && $results[$i]['bandwidth_median'] > 0) exec(sprintf('echo "%s/%s/%s" >> %s', $results[$i]['iperf_server'], $results[$i]['bandwidth_direction'], $results[$i]['bandwidth_median'], $this->options['tcp_bw_file']));

        if (isset($this->options['ignore_uplink']) && $results[$i]['bandwidth_direction'] == 'up') print_msg(sprintf('Skipping uplink results for server %s because --ignore_uplink flag is set', $server['hostname']), $this->verbose, __FILE__, __LINE__);
        else {
          print_msg(sprintf('Adding result row for server %s with %d bandwidth values - median %s Mb/s', $server['hostname'], count($results[$i]['bandwidth_values']), $results[$i]['bandwidth_median']), $this->verbose, __FILE__, __LINE__);
          $results[$i]['iperf_cmd'] = $iperf;
          $this->results[] = $results[$i];
          $success = TRUE; 
        }
      }
    }
    
    if ($rrdStarted) ch_collectd_rrd_stop($this->options['collectd_rrd_dir'], $this->options['output'], $this->verbose);
    
    $this->endTest($success);
    
    return $success;
  }
  
  /**
   * validates test dependencies. returns an array containing the missing 
   * dependencies (array is empty if all dependencies are valid)
   * @return array
   */
  public function validateDependencies($options) {
    $dependencies = array('iperf' => 'iperf', 'iperf3' => 'iperf3', 'zip' => 'zip');
    // reporting dependencies
    if (!isset($options['noreport']) || !$options['noreport']) {
      $dependencies['gnuplot'] = 'gnuplot';
      if (!isset($options['nopdfreport']) || !$options['nopdfreport']) $dependencies['wkhtmltopdf'] = 'wkhtmltopdf';
    }
    $validated = validate_dependencies($dependencies);
    // iperf3
    if (!isset($validated['iperf3'])) {
      if (isset($validated['iperf'])) unset($validated['iperf']);
      $this->iperf = 'iperf3';
    }
    else if (!isset($validated['iperf'])) unset($validated['iperf3']);
    return $validated;
  }
  
  /**
   * validate run options. returns an array populated with error messages 
   * indexed by the argument name. If options are valid, the array returned
   * will be empty
   * @return array
   */
  public function validateRunOptions() {
    $options = $this->getRunOptions();
    $validate = array(
      'drop_final' => array('min' => 0),
      'font_size' => array('min' => 6, 'max' => 64),
      'output' => array('write' => TRUE),
      'iperf_bandwidth' => array('regex' => '/^[0-9\.]+[km%]\/?[0-9\.]*[km%]?$/i'),
      'iperf_interval' => array('min' => 1, 'max' => 60, 'required' => TRUE),
      'iperf_len' => array('regex' => '/^[0-9]+[km]$/i'),
      'iperf_listen' => array('min' => 1),
      'iperf_mss' => array('min' => 1),
      'iperf_num' => array('min' => 1),
      'iperf_parallel' => array('min' => 1, 'required' => TRUE),
      'iperf_time' => array('min' => 1),
      'iperf_warmup' => array('min' => 0),
      'iperf_window' => array('min' => 1),
      'tcp_bw_file' => array('writedir' => TRUE)
    );
    
    $validated = validate_options($options, $validate);
    if (!is_array($validated)) $validated = array();
    
    // validate collectd rrd options
    if (isset($options['collectd_rrd'])) {
      if (!ch_check_sudo()) $validated['collectd_rrd'] = 'sudo privilege is required to use this option';
      else if (!is_dir($options['collectd_rrd_dir'])) $validated['collectd_rrd_dir'] = sprintf('The directory %s does not exist', $options['collectd_rrd_dir']);
      else if ((shell_exec('ps aux | grep collectd | wc -l')*1 < 2)) $validated['collectd_rrd'] = 'collectd is not running';
      else if ((shell_exec(sprintf('find %s -maxdepth 1 -type d 2>/dev/null | wc -l', $options['collectd_rrd_dir']))*1 < 2)) $validated['collectd_rrd_dir'] = sprintf('The directory %s is empty', $options['collectd_rrd_dir']);
    }
    
    return $validated;
  }
  
  
  /**
   * returns TRUE if wkhtmltopdf is installed, FALSE otherwise
   * @return boolean
   */
  public final static function wkhtmltopdfInstalled() {
    $ecode = trim(exec('which wkhtmltopdf; echo $?'));
    return $ecode == 0;
  }
}
?>

