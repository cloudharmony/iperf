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
ini_set('memory_limit', '256m');
date_default_timezone_set('UTC');

class IperfTest {
  
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
   * optional results directory object was instantiated for
   */
  private $dir;
  
  /**
   * graph colors array
   */
  private $graphColors = array();
  
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
      
      exec(sprintf('rm -rf %s', $tdir));
    }
    else print_msg('Test report will not be generated because --noreport flag was set or not results exist', $this->verbose, __FILE__, __LINE__);
    
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
      if (isset($result['bandwidth_values'])) {
        // Bandwidth Timeline
        $timeline = $this->makeCoords($result['bandwidth_values']);
        $coords = array('' => $timeline,
                        'Median' => array(array($timeline[0][0], $result['bandwidth_median']), array($timeline[count($timeline) - 1][0], $result['bandwidth_median'])));
        $settings = array();
        $settings['lines'] = array(1 => "lt 1 lc rgb '#5DA5DA' lw 3 pt -1",
                                   2 => "lt 2 lc rgb '#4D4D4D' lw 3 pt -1");
        $settings['nogrid'] = TRUE;
        $settings['yMin'] = 0;
        if ($graph = $this->generateGraph($dir, $prefix . '-bandwidth', $coords, 'Time (secs)', 'Bandwidth (Mb/s)', NULL, $settings)) $graphs[sprintf('Bandwidth - %s', $result['iperf_server'])] = $graph;
        
        // Bandwidth Histogram
        $coords = $this->makeCoords($result['bandwidth_values'], TRUE);
        $settings = array();
        $settings['nogrid'] = TRUE;
        $settings['yMin'] = 0;
        $settings['yMax'] = '20%';
        if ($graph = $this->generateGraph($dir, $prefix . '-bandwidth-histogram', $coords, 'Bandwidth (Mb/s)', 'Samples', NULL, $settings, TRUE, 'histogram')) $graphs[sprintf('Bandwidth Histogram - %s', $result['iperf_server'])] = $graph;
      }
      if (isset($result['jitter_values'])) {
        // Jitter Timeline
        $coords = array('Median' => $this->makeCoords($result['jitter_median']),
                        'Min' => $this->makeCoords($result['jitter_min']),
                        'Max' => $this->makeCoords($result['jitter_max']));
        $settings['lines'] = array(1 => "lt 2 lc rgb '#4D4D4D' lw 4 pt -1",
                                   2 => "lt 1 lc rgb '#60BD68' lw 3 pt -1",
                                   3 => "lt 1 lc rgb '#F15854' lw 3 pt -1");
        $settings['nogrid'] = TRUE;
        $settings['yMin'] = 0;
        if ($graph = $this->generateGraph($dir, $prefix . '-jitter', $coords, 'Time (secs)', 'Jitter (ms)', NULL, $settings)) $graphs[sprintf('Jitter - %s', $result['iperf_server'])] = $graph;

        // Jitter Histogram
        $coords = $this->makeCoords($result['jitter_values'], TRUE);
        $settings = array();
        $settings['nogrid'] = TRUE;
        $settings['yMin'] = 0;
        $settings['yMax'] = '20%';
        if ($graph = $this->generateGraph($dir, $prefix . '-jitter-histogram', $coords, 'Jitter (ms)', 'Samples', NULL, $settings, TRUE, 'histogram')) $graphs[sprintf('Jitter Histogram - %s', $result['iperf_server'])] = $graph;
      }
      if (isset($result['loss_values'])) {
        // Datagram Loss Timeline
        $coords = array('Median' => $this->makeCoords($result['loss_median']),
                        'Min' => $this->makeCoords($result['loss_min']),
                        'Max' => $this->makeCoords($result['loss_max']));
        $settings['lines'] = array(1 => "lt 2 lc rgb '#4D4D4D' lw 4 pt -1",
                                   2 => "lt 1 lc rgb '#60BD68' lw 3 pt -1",
                                   3 => "lt 1 lc rgb '#F15854' lw 3 pt -1");
        $settings['nogrid'] = TRUE;
        $settings['yMin'] = 0;
        if ($graph = $this->generateGraph($dir, $prefix . '-loss', $coords, 'Time (secs)', 'Datagram Loss (%)', NULL, $settings)) $graphs[sprintf('Datagram Loss - %s', $result['iperf_server'])] = $graph;

        // Datagram Loss Histogram
        $coords = $this->makeCoords($result['loss_values'], TRUE);
        $settings = array();
        $settings['nogrid'] = TRUE;
        $settings['yMin'] = 0;
        $settings['yMax'] = '20%';
        if ($graph = $this->generateGraph($dir, $prefix . '-loss-histogram', $coords, 'Datagram Loss (%)', 'Samples', NULL, $settings, TRUE, 'histogram')) $graphs[sprintf('Datagram Loss Histogram - %s', $result['iperf_server'])] = $graph;
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
      if (isset($coords[0])) $coords = array('' => $coords);
      
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
      if (preg_match('/^([0-9\.]+)%$/', $xMax, $m)) $xMax = ceil($maxX + ($maxX*($m[1]*0.01)));
      if (!$xTics) $xTics = 8;
      $xDiff = $xMax - $xMin;
      $xStep = floor($xDiff/$xTics);
      if ($xStep < 1) $xStep = 1;
      
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
        if (preg_match('/^([0-9\.]+)%$/', $yMax, $m)) $yMax = ceil($maxY + ($maxY*($m[1]*0.01)));
        if (!$yTics) $yTics = 8;
        $yDiff = $yMax - $yMin;
        $yStep = floor($yDiff/$yTics);
        if ($yStep < 1) $yStep = 1;
      }
      
      $img = sprintf('%s/%s.svg', $dir, $prefix);
      print_msg(sprintf('Generating line chart %s with %d data sets and %d points/set. X Label: %s; Y Label: %s; Title: %s', basename($img), count($coords), $maxPoints, $xlabel, $ylabel, $title), $this->verbose, __FILE__, __LINE__);
      
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
      if ($xMin != $xMax) fwrite($fp, sprintf("set xrange [%d:%d]\n", $xMin, $xMax));
      if (isset($settings['xLogscale'])) fwrite($fp, "set logscale x\n");
      else if ($xMin != $xMax && !$xFloatPrec) fwrite($fp, sprintf("set xtics %d, %d, %d\n", $xMin, $xStep, $xMax));
      if ($ylabel) fwrite($fp, sprintf("set ylabel \"%s\"\n", $ylabel));
      if (isset($yMin)) {
        if (isset($settings['yLogscale'])) {
          if (!isset($settings['yMin'])) $yMin = IperfTest::adjustLogScale($yMin, TRUE);
          if (!isset($settings['yMax'])) $yMax = IperfTest::adjustLogScale($yMax);
        }
        if ($yMin != $yMax) fwrite($fp, sprintf("set yrange [%d:%d]\n", $yMin, $yMax));
        if (isset($settings['yLogscale'])) fwrite($fp, "set logscale y\n");
        else if (!$yFloatPrec) fwrite($fp, sprintf("set ytics %d, %d, %d\n", $yMin, $yStep, $yMax));
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
      
      $testPages = isset($this->options['iperf_udp']) ? 6 : 2;
      foreach($this->results as $server => $result) {
        $testPageNum = 0;
        if ($graphs = $this->generateGraphs($result, $dir, $server)) print_msg(sprintf('Successfully generated report graphs for server %s', $server), $this->verbose, __FILE__, __LINE__);
        else print_msg(sprintf('Unable to generate report graphs for server %s', $server), $this->verbose, __FILE__, __LINE__, TRUE);
        
        // render report graphs (1 per page)
        foreach($graphs as $label => $graph) {
          $params = array(
            'platform' => $this->getPlatformParameters(),
            'server' => $this->getPlatformParameters($server),
            'test' =>     array('Protocol' => isset($this->options['iperf_udp']) ? 'UDP' : 'TCP',
                                'Direction' => ucwords($result['bandwidth_direction']),
                                'Duration' => isset($this->options['iperf_num']) ? $this->options['iperf_num'] . ' Buffers' : $this->options['iperf_time'] . ' Secs',
                                'Warmup' => isset($this->options['iperf_warmup']) && $this->options['iperf_warmup'] > 0 ? $this->options['iperf_warmup'] . ' Secs' : 'None',
                                'Threads' => $this->options['iperf_parallel'],
                                'Bandwidth' => isset($this->options['iperf_udp']) ? $this->options['iperf_bandwidth'] . ' Mb/s' : 'N/A',
                                'Started' => date(IperfTest::IPERF_DATE_FORMAT, $result ? $result['test_started'] : $results[$fkey]['test_started']),
                                'Ended' => date(IperfTest::IPERF_DATE_FORMAT, $result ? $result['test_stopped'] : $results[$lkey]['test_stopped'])),
            'result' =>   array('Mean Bandwidth' => round($result['bandwidth_mean'], 2) . ' Mb/s',
                                'Median Bandwidth' => round($result['bandwidth_median'], 2) . ' Mb/s',
                                'Std Dev' => round($result['bandwidth_stdev'], 2),
                                'Mean Jitter' => isset($this->options['iperf_udp']) ? round($result['jitter_mean'], 2) . ' ms' : 'N/A',
                                'Median Jitter' => isset($this->options['iperf_udp']) ? round($result['jitter_median'], 2) . ' ms' : 'N/A',
                                'Std Dev ' => isset($this->options['iperf_udp']) ? round($result['jitter_stdev'], 2) : 'N/A',
                                'Mean Datagram Loss' => isset($this->options['iperf_udp']) ? round($result['loss_mean'], 2) . '%' : 'N/A',
                                'Median Datagram Loss' => isset($this->options['iperf_udp']) ? round($result['loss_median'], 2) . '%' : 'N/A',
                                'Std Dev ' => isset($this->options['iperf_udp']) ? round($result['loss_stdev'], 2) : 'N/A')
          );
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
          print_msg(sprintf('Successfully generated graphs for %s', $server), $this->verbose, __FILE__, __LINE__);
          ob_start();
          include(sprintf('%s/test.html', $reportsDir, $test));
          fwrite($fp, ob_get_contents());
          ob_end_clean(); 
          $generated = TRUE; 
        }
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
      $params = array(
        'Provider' => isset($this->servers[$server]['provider']) ? $this->servers[$server]['provider'] : $this->servers[$server]['provider_id'],
        'Service' => isset($this->servers[$server]['service']) ? $this->servers[$server]['service'] : $this->servers[$server]['service_id'],
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
          'font_size' => 9,
          'iperf_bandwidth' => '1M',
          'iperf_interval' => 1,
          'iperf_parallel' => 1,
          'iperf_time' => 10,
          'iperf_ttl' => 1,
          'iperf_warmup' => 0,
          'meta_compute_service' => 'Not Specified',
          'meta_cpu' => $sysInfo['cpu'],
          'meta_instance_id' => 'Not Specified',
          'meta_memory' => $sysInfo['memory_gb'] > 0 ? $sysInfo['memory_gb'] . ' GB' : $sysInfo['memory_mb'] . ' MB',
          'meta_os' => $sysInfo['os_info'],
          'meta_provider' => 'Not Specified',
          'output' => trim(shell_exec('pwd'))
        );
        $opts = array(
          'collectd_rrd',
          'collectd_rrd_dir:',
          'font_size:',
          'iperf_bandwidth:',
          'iperf_interval:',
          'iperf_len:',
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
          'iperf_tradeoff',
          'iperf_ttl:',
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
          'reportdebug',
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
        
        // set iperf_len
        if (!isset($this->options['iperf_len'])) $this->options['iperf_len'] = isset($this->options['iperf_udp']) ? 1470 : '8K';
        
        // check for [cpus] in 
        $this->options['iperf_parallel'] = $this->evaluateExpression($this->options['iperf_parallel']);
        
        // servers
        foreach($this->options['server'] as $i => $hostname) {
          $server = array();
          $pieces = explode(':', trim($hostname));
          $server['hostname'] = $pieces[0];
          if (isset($pieces[1]) && is_numeric($pieces[1]) && $pieces[1] > 0) $server['port'] = $pieces[1]*1;
          foreach(array('iperf_server_instance_id', 'iperf_server_os', 'iperf_server_provider', 'iperf_server_provider_id', 'iperf_server_region', 'iperf_server_service', 'iperf_server_service_id') as $arg) {
            $attr = str_replace('iperf_server_', '', $arg);
            $val = NULL;
            if (isset($this->options[$arg][$i])) $val = $this->options[$arg][$i];
            else if (isset($this->options[$arg][0])) $val = $this->options[$arg][0];
            else if (isset($this->options[sprintf('meta_%s', $attr)])) $val = $this->options[sprintf('meta_%s', $attr)];
            else if (isset($this->options[sprintf('meta_compute_%s', $attr)])) $val = $this->options[sprintf('meta_compute_%s', $attr)];
            if ($val) $server[$attr] = $val;
          }
          $this->servers[$hostname] = $server;
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
   * @param boolean $secsReset if TRUE, seconds will be explictly set to start 
   * at 0 and jump by iperf_interval
   * @return array
   */
  private function makeCoords($vals, $histogram=FALSE, $secsReset=FALSE) {
    $coords = array();
    if ($histogram) {
      $min = NULL;
      $max = NULL;
      foreach($vals as $val) {
        if (!isset($min) || $val < $min) $min = $val;
        if (!isset($max) || $val > $max) $max = $val;        
      }
      $min = floor($min/100)*100;
      $max = ceil($max/100)*100;
      $diff = $max - $min;
      $step = round($diff/8);
      for($start=$min; $start<$max; $start+=$step) {
        $label = sprintf('%d', $start);
        $coords[$label] = 0;
        foreach($vals as $val) if ($val >= $start && $val < ($start + $step)) $coords[$label]++;
        $coords[$label] = array($coords[$label]);
      }
    }
    else {
      foreach(array_keys($vals) as $i => $secs) $coords[] = array($secsReset ? $i*$this->options['iperf_interval'] : $secs, $vals[$secs]);
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
    if (preg_match('/\s([0-9\.]+)\s/', trim(shell_exec('iperf --version 2>&1')), $m)) {
      $this->options['iperf_version'] = $m[1];
      $iperf3 = substr($m[1], 0, 1) == 3;
    }
    
    foreach($this->servers as $host => $server) {
      $ofile = sprintf('%s/%s', $this->dir, rand());
      $iperf = sprintf('iperf -y C -o %s -c %s -i %d%s%s%s%s%s%s%s%s%s%s%s%s%s',
        $ofile,
        $server['hostname'],
        $this->options['iperf_interval'], 
        isset($this->options['iperf_bandwidth']) ? ' -b ' . $this->options['iperf_bandwidth'] : '',
        isset($this->options['iperf_len']) ? ' -l ' . $this->options['iperf_len'] : '',
        isset($this->options['iperf_mss']) ? ' -M ' . $this->options['iperf_mss'] : '',
        isset($this->options['iperf_nodelay']) ? ' -N' : '',
        isset($this->options['iperf_num']) ? ' -n ' . $this->options['iperf_num'] : '',
        isset($server['port']) ? ' -p ' . $server['port'] : '',
        isset($this->options['iperf_parallel']) ? ' -P ' . $this->options['iperf_parallel'] : '',
        !isset($this->options['iperf_len']) ? ' -t ' . $this->options['iperf_time'] : '',
        isset($this->options['iperf_tos']) ? ' -S ' . $this->options['iperf_tos'] : '',
        isset($this->options['iperf_tradeoff']) ? ' -r' : '',
        isset($this->options['iperf_ttl']) ? ' -T ' . $this->options['iperf_ttl'] : '',
        isset($this->options['iperf_udp']) ? ' -u' : '',
        isset($this->options['iperf_window']) ? ' -w ' . $this->options['iperf_window'] : '',
        isset($this->options['iperf_zerocopy']) && $iperf3 ? ' -Z' : '');
      print_msg(sprintf('Testing server %s using %s', $server['hostname'], $iperf), $this->verbose, __FILE__, __LINE__);
      $started = date(self::IPERF_DB_DATE_FORMAT);
      passthru($iperf);
      $stopped = date(self::IPERF_DB_DATE_FORMAT);
      if (file_exists($ofile) && filesize($ofile)) {
        print_msg(sprintf('Iperf testing completed successfully for server %s', $server['hostname']), $this->verbose, __FILE__, __LINE__);
        $ip = NULL;
        $direction = 'up';
        $result = array();
        foreach(file($ofile) as $line) {
          $pieces = explode(',', trim($line));
          if ($ip === NULL) $ip = $pieces[3];
          if ($ip == $pieces[3]) {
            if (!$result) {
              $result['bandwidth_direction'] = $direction;
              $result['bandwidth_values'] = array();
              $result['transfer'] = 0;
              if (isset($this->options['iperf_udp'])) {
                $result['jitter_values'] = array();
                $result['loss_values'] = array();
              }
            }
            $span = explode('-', $pieces[6]);
            $start = $span[0]*1;
            if (!$this->options['iperf_warmup'] || $start >= $this->options['iperf_warmup']) {
              $result['bandwidth_values'][] = ($pieces[8]/1000)/1000;
              $result['transfer'] += ($pieces[7]/1024)/1024;
              if (isset($this->options['iperf_udp']) && isset($pieces[9])) $result['jitter_values'][] = $pieces[9];
              if (isset($this->options['iperf_udp']) && isset($pieces[12])) $result['loss_values'][] = $pieces[12];
            }
          }
          else {
            $ip = $pieces[3];
            $direction = 'down';
            $results[] = $result;
            $result = array();
          }
        }
        if ($result) $results[] = $result;
        
        // generate statistical values
        foreach(array_keys($results) as $i) {
          foreach(array('bandwidth', 'jitter', 'loss') as $attr) {
            $key = sprintf('%s_values', $attr);
            if (isset($results[$i][$key]) && !count($results[$i][$key])) unset($results[$i][$key]);
            if (isset($results[$i][$key])) {
              sort($results[$i][$key]);
              $results[$i][sprintf('%s_max', $attr)] = $results[$i][$key][count($results[$i][$key]) - 1];
              $results[$i][sprintf('%s_mean', $attr)] = get_mean($results[$i][$key]);
              $results[$i][sprintf('%s_median', $attr)] = get_median($results[$i][$key]);
              $results[$i][sprintf('%s_min', $attr)] = $results[$i][$key][0];
              $results[$i][sprintf('%s_p10', $attr)] = get_percentile($results[$i][$key], 10, $attr == 'jitter' || $attr == 'loss');
              $results[$i][sprintf('%s_p25', $attr)] = get_percentile($results[$i][$key], 25, $attr == 'jitter' || $attr == 'loss');
              $results[$i][sprintf('%s_p75', $attr)] = get_percentile($results[$i][$key], 75, $attr == 'jitter' || $attr == 'loss');
              $results[$i][sprintf('%s_p90', $attr)] = get_percentile($results[$i][$key], 90, $attr == 'jitter' || $attr == 'loss');
              $results[$i][sprintf('%s_stdev', $attr)] = get_std_dev($results[$i][$key]);
            }
          }
          $results[$i]['iperf_server'] = $host;
          foreach($server as $attr => $val) $results[$i][sprintf('iperf_server_%s', $attr)] = $val;
          $results[$i]['same_provider'] = isset($server['provider_id']) && isset($this->options['meta_provider_id']) && $server['provider_id'] == $this->options['meta_provider_id'];
          $results[$i]['same_service'] = $results[$i]['same_provider'] && isset($server['service_id']) && isset($this->options['meta_compute_service_id']) && $server['service_id'] == $this->options['meta_compute_service_id'];
          $results[$i]['same_region'] = $results[$i]['same_service'] && isset($server['region']) && isset($this->options['meta_region']) && $server['region'] == $this->options['meta_region'];
          $results[$i]['same_instance_id'] = $results[$i]['same_service'] && isset($server['instance_id']) && isset($this->options['meta_instance_id']) && $server['instance_id'] == $this->options['meta_instance_id'];
          $results[$i]['same_os'] = isset($server['os']) && isset($this->options['meta_os']) && $server['os'] == $this->options['meta_os'];
          $results[$i]['test_started'] = $started;
          $results[$i]['test_stopped'] = $stopped;
          print_msg(sprintf('Adding result row for server %s: [%s][%s]', $server['hostname'], implode(', ', array_keys($results[$i])), implode(', ', $results[$i])), $this->verbose, __FILE__, __LINE__);
          $this->results[] = $results[$i];
        }
      }
      else print_msg(sprintf('Iperf testing failed for server %s', $server['hostname']), $this->verbose, __FILE__, __LINE__, TRUE);
      
      exec(sprintf('rm -f %s', $ofile));
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
  public static function validateDependencies($options) {
    $dependencies = array('iperf', 'iperf', 'zip' => 'zip');
    // reporting dependencies
    if (!isset($options['noreport']) || !$options['noreport']) {
      $dependencies['gnuplot'] = 'gnuplot';
      if (!isset($options['nopdfreport']) || !$options['nopdfreport']) $dependencies['wkhtmltopdf'] = 'wkhtmltopdf';
    }
    return validate_dependencies($dependencies);
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
      'font_size' => array('min' => 6, 'max' => 64),
      'output' => array('write' => TRUE),
      'iperf_bandwidth' => array('regex' => '/^[0-9]+[km]$/i'),
      'iperf_interval' => array('min' => 1, 'max' => 60, 'required' => TRUE),
      'iperf_len' => array('regex' => '/^[0-9]+[km]$/i'),
      'iperf_mss' => array('min' => 1),
      'iperf_num' => array('min' => 1),
      'iperf_parallel' => array('min' => 1, 'required' => TRUE),
      'iperf_time' => array('min' => 1),
      'iperf_ttl' => array('min' => 1),
      'iperf_warmup' => array('min' => 1),
      'iperf_window' => array('min' => 1)
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
