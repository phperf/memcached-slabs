#!/usr/bin/env php
<?php
srand(1);

class MemcachedTool {
    public $host;
    public $port;
    private $reportName;
    public function __construct($host, $port) {
        $this->host = $host;
        $this->port = $port;
        $this->reportName = $host . '_' . $port . '_' . date('YmdH');
        $this->topEntitiesPath = './' . $this->reportName . '.top.entities/';
    }

    public $keyStats = array();
    public $keySamples = array();
    public $keySize = array();
    public $keySlabs = array();
    public $emptyKeysBySlab = array();


    public $saveKeys;
    public $saveTopEntitiesCount = 10;
    public $topEntities = array();


    const MIN_ADDED_PAGES = 2;
    const BASIC_TOTAL_PERCENT = 0.2;
    const EV_SET_TOTAL_PERCENT_MUL = 1;
    private $evictionsReportCommand;
    private $evictionsReportTotalPages;
    private $evictionsReportTotalMemory;

    public function evictionsReport()
    {
        $preallocateCommand = '';
        $totalPages = 0;
        $totalPreallocatedMemory = 0;


        $slabs = $this->slabs();
        if (!$slabs) {
            return;
        }

        foreach ($slabs as $slab) {
            if (empty($slab['max'])) {
                continue;
            }
            $evSet = $slab['evicted'] / $slab['cmd_set'];
            if ($evSet >= 0.01) {
                $pageSize = $slab['chunk_size'] * $slab['chunks_per_page'];
                $total_number = $slab['total_chunks'];
                $percent = self::BASIC_TOTAL_PERCENT + self::EV_SET_TOTAL_PERCENT_MUL * $evSet * 100;

                $preallocateNumber = round($total_number * (1 + $percent / 100));
                $preallocatePages = ceil($preallocateNumber / $slab['chunks_per_page']);

                if ($preallocatePages < self::MIN_ADDED_PAGES + $slab['total_pages']) {
                    $preallocatePages = self::MIN_ADDED_PAGES + 1 * $slab['total_pages'];
                    $preallocateNumber = ceil($preallocatePages * $slab['chunks_per_page']);
                }

                $preallocateCommand .= ' -p ' . $slab['max'] . ':' . $preallocateNumber;
                $preallocatePages = ceil($preallocateNumber / $slab['chunks_per_page']);

                $totalPages += $preallocatePages;
                $totalPreallocatedMemory += $preallocatePages * $pageSize;
            }


            $this->evictionsReportCommand = 'php ' . basename(__FILE__) . $preallocateCommand;
            $this->evictionsReportTotalPages = $totalPages;
            $this->evictionsReportTotalMemory = $totalPreallocatedMemory;
        }
    }

    public function formatKeys(&$keysText, $prefix, &$slab) {
        $keysText = explode("\r\n", $keysText);

        $now = time();
        $slab['min'] = 10000000;
        $slab['max'] = 0;
        $slab['avg'] = 0;

        $count = 0;
        foreach ($keysText as $line) {
            $line = trim(substr($line, 5));
            if (!$line) {
                continue;
            }

            list($key, $params) = explode(' ', $line, 2);
            list($length, $timestamp) = explode(' b; ', substr($params, 1, -3));
            $length = (int)$length;
            $expired = $timestamp < $now;

            ++$count;
            $slab['min'] = min($slab['min'], $length);
            $slab['max'] = max($slab['max'], $length);
            $slab['avg'] = ($slab['avg'] * ($count - 1) / $count) + ($length / $count);


            $path = explode('_', $key);
            foreach ($path as &$item) {
                if (preg_match('/[0-9\.]+/', $item)) {
                    $item = '%';
                }
            }

            $mask = implode('_', $path);
            $total = &$this->keyStats[$mask];
            ++$total;

            $this->keySamples[$mask] = $key;

            $size = &$this->keySize[$mask];
            if (empty($size)) {
                $size = array('c' => 0, 't' => 0, 'm' => 100000000, 'M' => -1, 'Mk' => array(), 'e' => 0);
            }
            $size['c']++;

            $size['t'] += $length;
            $size['m'] = min($size['m'], $length);

            if ($size['M'] < $length) {
                $size['M'] = $length;
            }
            if (!$expired) {
                if (count($size['Mk']) < $this->saveTopEntitiesCount) {
                    $size['Mk'][$key] = $length;
                    arsort($size['Mk']);
                }
                else {
                    $last = end($size['Mk']);
                    if ($last < $length) {
                        $size['Mk'][$key] = $length;
                        arsort($size['Mk']);
                        array_pop($size['Mk']);
                    }
                }
            }
            else {
                ++$size['e'];
            }

            $total = &$this->keySlabs[$mask][$slab['id']];
            ++$total;
        }
        arsort($this->keyStats);
    }

    public $analyzeKeys = true;
    private $usedSlabs = array();
    public function getKeys() {
        $slabs = $this->getSlabs();
        if (!$slabs) {
            return array();
        }

        foreach ($slabs as $id => $slab) {
            $prefix = $id . ':' . $slab['chunk_size'];
            echo "\n", $this->reportName;
            echo "\n$prefix\n";
            //print_r($slab);
            $start = microtime(1);
            if ($this->analyzeKeys && !empty($slab['number'])) {
                echo "Getting keys...\n";
                $keys = $this->mcQuery('stats cachedump ' . $id . ' 100000000');

                if ($this->saveKeys) {
                    file_put_contents($this->reportName . '_' . $id . '.keys', $keys);
                }
            }
            $slab['prefix'] = $prefix;

            $slab['cachedump_time'] = 'skipped';
            if ($this->analyzeKeys && !empty($slab['number'])) {
                $slab['cachedump_time'] = round(microtime(1) - $start, 4);
                $this->formatKeys($keys, $prefix, $slab);
            }

            $this->usedSlabs []= $slab;
        }

        if ($this->saveTopEntitiesCount) {
            if (!file_exists($this->topEntitiesPath)) {
                mkdir($this->topEntitiesPath);
            }
            foreach ($this->keySize as $mask => $size) {
                if ($this->saveTopEntitiesCount) {
                    foreach ($size['Mk'] as $key => $length) {
                        //$val = $this->getValue($key);
                        $val = print_r($this->getPhpValue($key), 1);
                        //echo $key, "\n", $val;
                        $filename = $this->topEntitiesPath . '/' . str_replace(array(':','/','\\'), '', $key);
                        file_put_contents($filename, $val);
                        $this->topEntities[$key] = $filename;
                    }
                }

            }
        }

        return $this->keyStats;
    }


    public $evictedOnly = false;


    public function getSlabs() {
        if ($this->evictedOnly) {
            $evicted = array();
            $slabs = $this->slabs();
            foreach ($slabs as $id => $slab) {
                if (!empty($slab['evicted'])) {
                    $evicted [$id]= $slab;
                }
            }
            return $evicted;
        }
        else {
            return $this->slabs();
        }

    }


    private $slabs;
    public function slabs() {
        if (null !== $this->slabs) {
            return $this->slabs;
        }

        echo "Getting slabs stats...\n";

        $this->slabs = array();

        $slabParamNames = array();

        $res = $this->mcQuery("stats slabs");
        //echo $res;
        foreach (explode("\r\n", $res) as $line) {
            $line = substr($line, 5);
            $data = explode(':', $line);
            if (count($data) == 2) {
                $slabId = $data[0];
                $slabParam = explode(' ', $data[1], 2);
                $this->slabs[$slabId]['id'] = $slabId;
                $this->slabs[$slabId][$slabParam[0]] = $slabParam[1];
                $slabParamNames[$slabParam[0]] = $slabParam[0];
            }
        }

        $res = $this->mcQuery('stats items');
        foreach (explode("\r\n", $res) as $line) {
            $line = substr($line, 11);
            $data = explode(':', $line);
            if (count($data) == 2) {
                $slabId = $data[0];
                $slabParam = explode(' ', $data[1], 2);
                $this->slabs[$slabId]['id'] = $slabId;
                $this->slabs[$slabId][$slabParam[0]] = $slabParam[1];
                $slabParamNames[$slabParam[0]] = $slabParam[0];
            }
        }

        if ($this->slabs) {
            foreach ($this->slabs as &$slab) {
                foreach ($slabParamNames as $paramName) {
                    if (!isset($slab[$paramName])) {
                        $slab[$paramName] = 0;
                    }
                }
            }
        }

        //print_r($this->slabs);


        return $this->slabs;
    }

    private function getTotalStats() {
        $res = explode("\r\n", $this->mcQuery('stats'));
        $stats = array();
        foreach ($res as $line) {
            $stat = explode(' ', substr($line, 5), 2);
            if (count($stat) == 2) {
                $stats[$stat[0]] = $stat[1];
            }
        }
        return $stats;
    }

    public function saveReport() {
        $slabs = array();
        foreach ($this->usedSlabs as $slab) {
            $slabs[$slab['id']] = $slab;
        }
        $jsonReport = array(
            'host' => $this->host,
            'port' => $this->port,
            'stats' => $this->getTotalStats(),
            'slabs' => $slabs,
            'keySize' => $this->keySize,
            'keySamples' => $this->keySamples,
            'keySlabs' => $this->keySlabs,
            'maxKeys' => $this->topEntities,
        );
        $jsonData = json_encode($jsonReport);
        /*
        if ($this->maxKeysCount) {
            file_put_contents($this->reportName . '.top-entities.json', json_encode($this->maxKeys));
        }
        */
        file_put_contents($this->reportName . '.json', $jsonData);
        file_put_contents($this->reportName . '.html', $this->getHtmlReport($jsonData));

        $this->evictionsReport();
        if ($this->evictionsReportCommand) {
            echo "Many evictions found, recommend to pre-allocate slabs after memcached restart\n";
            echo $this->evictionsReportCommand, "\n";
            echo "Total pages to pre-allocate: " . $this->evictionsReportTotalPages, "\n";
        }

        return;
    }

    private $memcachedSock;
    private function initMemcached() {
        if (!is_resource($this->memcachedSock)) {
            $this->memcachedSock = fsockopen($this->host, $this->port);
            if (!$this->memcachedSock) {
                die('Could not connect to memcached');
            }
        }
    }


    private static $mcEnd = array("END\r\n", "ERROR\r\n", "STORED\r\n", "NOT_STORED\r\n", "EXISTS\r\n", "NOT_FOUND\r\n", "DELETED\r\n");
    private $lastEnd;
    private function mcQuery($command) {
        $this->initMemcached();

        $response = '';
        $this->totalWrite += strlen($command) + 2;
        fwrite($this->memcachedSock, $command . "\r\n");

        while (!feof($this->memcachedSock)) {
            $chunk = fread($this->memcachedSock, 8192);

            $response .= $chunk;
            foreach (self::$mcEnd as $end) {
                if (substr($chunk, -strlen($end)) == $end) {
                    $this->totalRead += strlen($response);
                    $response = substr($response, 0, -strlen($end));
                    $this->lastEnd = $end;
                    break 2;
                }
            }
        }

        return $response;
    }



    private $memcached;
    public function getPhpValue($key) {
        if (null === $this->memcached) {
            $this->memcached = new Memcached();
            $this->memcached->addServer($this->host,$this->port);
        }
        return $this->memcached->get($key);
    }

    public function getValue($key) {
        $command = 'get ' . $key;
        $response = $this->mcQuery($command);
        $p = strpos($response, "\r\n");
        $head = substr($response, 0, $p);
        //return substr($response, $p + 2);
        return $response;
    }





    public function __destruct() {
        if (is_resource($this->memcachedSock)) {
            fclose($this->memcachedSock);
        }
    }


    private function getHtmlReport($jsonData) {
        ob_start();
        ?>
        <html>
        <head>
            <style type="text/css">body,input,pre,select,textarea{font-family:monospace}td,th{border:1px solid #aaa;white-space:pre-wrap;background:#fff;font-weight:400}td:hover{border:1px solid #faa}th{background:#afa}table{border-collapse:separate;border:0}</style>


            <script src="https://code.jquery.com/jquery-1.11.2.min.js"></script>
            <script src="http://www.kryogenix.org/code/browser/sorttable/sorttable.js"></script>
            <!--script src="http://www.chartjs.org/assets/Chart.min.js" charset="utf-8"></script-->
            <script type="text/javascript" src="http://code.highcharts.com/highcharts.js"></script>
            <script type="text/javascript" src="http://code.highcharts.com/highcharts-more.js"></script>


        </head>
        <body>


        <script type="text/javascript">

            (function(){
                var instance = {}, data;
                window.mcReportViewer = instance;

                $(function(){
                    data = <?=$jsonData?>;
                    makeReport(data);
                });

                instance.getKey = function(key){
                    //console.log(data);
                    //console.log(data.maxKeys[key]);
                };

                function renderTable(keys, rows) {
                    var html = '<table class="sortable"><tr>', k, i, row;

                    if (!keys) {
                        keys = [];
                        for (i in rows) {
                            if (rows.hasOwnProperty(i)) {
                                row = rows[i];

                                for (k in row) {
                                    if (row.hasOwnProperty(k)) {
                                        keys.push(k);
                                    }
                                }

                                break;
                            }
                        }
                    }


                    for (k = 0; k < keys.length; ++k) {
                        html += '<th>' + keys[k] + '</th>';
                    }
                    html += '</tr>';

                    //for (i = 0; i < rows.length; ++i) {
                    for (i in rows) {
                        if (rows.hasOwnProperty(i)) {
                            row = rows[i];
                            html += '<tr' + (row['RowClass'] ? ' class="' + row['RowClass'] + '"' : '') + '>';
                            for (k = 0; k < keys.length; ++k) {
                                html += '<td>' + row[keys[k]] + '</td>';
                            }

                            html += '</tr>';
                        }
                    }

                    return html;
                }




                var filteredSlab = 0;
                instance.filterSlab = function(id) {
                    if (filteredSlab == id) {
                        $('#report .slab').show();
                        filteredSlab = 0;
                    }
                    else {
                        $('#report .slab').hide();
                        $('#report .slab.slab-' + id).show();
                        filteredSlab = id;
                    }
                };

                function keysReportHtml(data) {
                    //console.log(data);
                    var reportKeys = ['Mask', 'Count', 'Expired', 'Total Size', 'Average Size', 'Min Size', 'Max Size', 'Affected Slabs', 'Sample Key', 'Top Entities'],
                        reportData = [],
                        item = {},
                        mask = '',
                        slabId,
                        slab,
                        slabCount,
                        size = {};

                    for (mask in data.keySize) {
                        if (data.keySize.hasOwnProperty(mask)) {
                            size = data.keySize[mask];
                            item['RowClass'] = 'slab ';
                            item['Mask'] = mask;
                            item['Count'] = Math.round(size.c);
                            item['Expired'] = size.e;
                            item['Total Size'] = size.t;
                            item['Average Size'] = Math.round(size.t / size.c);
                            item['Min Size'] = size.m;
                            item['Max Size'] = size.M;
                            item['Affected Slabs'] = '';
                            for (slabId in data.keySlabs[mask]) {
                                if (data.keySlabs[mask].hasOwnProperty(slabId)) {
                                    slab = data.slabs[slabId];

                                    slabCount = data.keySlabs[mask][slabId];
                                    item['RowClass'] += 'slab-' + slabId + ' ';
                                    item['Affected Slabs'] += '<a href="#" onclick="mcReportViewer.filterSlab(' + slabId + ');return false;">' + slab.prefix + '</a>(' + slabCount + '), ';
                                }
                            }
                            if (item['Affected Slabs']) {
                                item['Affected Slabs'] = item['Affected Slabs'].substring(0, item['Affected Slabs'].length - 1);
                            }

                            item['Sample Key'] = data.keySamples[mask];
                            item['Top Entities'] = '';
                            for (var i in size['Mk']) {
                                if (size['Mk'].hasOwnProperty(i)) {
                                    item['Top Entities'] += '<a href="#" onclick="mcReportViewer.getKey(\'' + i + '\');return false;">'
                                    + i + '</a>' + ':' + size['Mk'][i] + ' ';
                                }
                            }

                            reportData.push($.extend({}, item));
                        }
                    }
                    return renderTable(reportKeys, reportData);
                }

                function countBytes(size) {
                    var kb = 1024, mb = 1024*kb, gb = 1024*mb;
                    if (size > gb) {
                        return (Math.round(100 * size / gb) / 100) + 'G';
                    }
                    if (size > mb) {
                        return (Math.round(100 * size / mb) / 100) + 'M';
                    }
                    if (size > kb) {
                        return (Math.round(100 * size / kb) / 100) + 'K';
                    }
                    return size;
                }

                function slabReport(data) {
                    var slab,
                        slabData = [],
                        slabKeys = [
                            'id',
                            'chunk_size',
                            //'avg',
                            'number',
                            //'hit_ratio',
                            //'get_hits',
                            'cmd_set',
                            //'delete_hits',
                            'evicted',
                            'prefix',
                            'mem_usage',
                            'total_memory',
                            'ev/set',
                            'filled_%',

                            //'chunks_per_page',
                            //'outofmemory',
                            //'used_chunks',
                            //'total_chunks',
                            //'total_pages',

                            'cachedump_time'
                            /*
                             'age',
                             'crawler_reclaimed',
                             'evicted_nonzero',
                             'evicted_time',
                             'evicted_unfetched',
                             'expired_unfetched',
                             'free_chunks',
                             'free_chunks_end',
                             'incr_hits',
                             'decr_hits',
                             'lrutail_reflocked',
                             'mem_requested',
                             'chunks_per_page',
                             'outofmemory',
                             'reclaimed',
                             'tailrepairs',
                             'total_chunks',
                             'total_pages',
                             'touch_hits',
                             'used_chunks',
                             'cas_badval',
                             'cas_hits'
                             */
                        ];
                    for (slabId in data.slabs) {
                        if (data.slabs.hasOwnProperty(slabId)) {
                            slab = data.slabs[slabId];
                            slab['avg'] = Math.floor(slab['avg']);
                            slab['hit_ratio'] = slab['get_hits'] / slab['cmd_set'];
                            slab['used_memory'] = slab['used_chunks'] * slab['chunk_size'];
                            slab['total_memory'] = slab['total_chunks'] * slab['chunk_size'];
                            slab['ev/set'] = Math.round(100 * slab['evicted'] / slab['cmd_set']);
                            slab['filled_%'] = Math.round(100 * slab['used_chunks'] / slab['total_chunks']);
                            slab['mem_usage'] = countBytes(slab['used_chunks'] * slab['chunk_size'])
                            + ' / ' + countBytes(slab['total_chunks'] * slab['chunk_size']);
                            slabData.push(
                                $.extend({}, slab, {
                                    prefix: '<a href="#" onclick="mcReportViewer.filterSlab(\'' + slabId + '\');return false;">' + slab.prefix + '</a>'
                                })
                            );
                        }
                    }
                    return renderTable(slabKeys, slabData);
                }

                function statsReportHtml(data) {
                    var param, value, reportData = [];
                    for (param in data.stats) {
                        if (data.stats.hasOwnProperty(param)) {
                            value = data.stats[param];
                            reportData.push({'Param': param, 'Value': value});
                        }
                    }
                    return renderTable(['Param', 'Value'], reportData);
                }

                function appendHChart(records, xAxis, yAxis, options) {
                    var record, data = [];
                    for (var id in records) {
                        if (records.hasOwnProperty(id)) {
                            record = records[id];

                            data.push([parseInt(record[xAxis]), parseFloat(record[yAxis])]);
                        }
                    }

                    //console.log(data);
                    var canvas = $('<div style="width: 500px;height: 200px"></div>');

                    $(function () {
                        canvas.highcharts({
                            title: null,
                            legend: {enabled: false},
                            yAxis: {title: null},
                            credits:{enabled:false},
                            series: [{
                                type: "areaspline",
                                name: yAxis,
                                data: data
                            }]
                        });
                    });
                    return canvas;
                }

                function evictionsReport(data) {
                    var minAddedPages = 2,
                        basicTotalPercent = 0.2,
                        evSetTotalPercentMul = 1;


                    var slab,
                        preallocateCommand = '',
                        total_number,
                        preallocateNumber,
                        preallocatePages,
                        percent,
                        evSet,
                        pageSize,
                        totalPages = 0,
                        totalPreallocatedMemory = 0;

                    for (var i in data.slabs) {
                        if (data.slabs.hasOwnProperty(i)) {
                            slab = data.slabs[i];

                            if (typeof slab['max'] == 'undefined') {
                                return '';
                            }

                            evSet = slab['evicted'] / slab['cmd_set'];

                            //console.log(slab);

                            if (evSet >= 0.01) {
                                pageSize = parseInt(slab['chunk_size']) * parseInt(slab['chunks_per_page']);
                                total_number = slab['total_chunks'];
                                percent = basicTotalPercent + evSetTotalPercentMul * evSet * 100;

                                preallocateNumber = Math.round(total_number * (1 + percent / 100));
                                preallocatePages = Math.ceil(preallocateNumber / slab['chunks_per_page']);

                                //console.log(slab['chunk_size'], preallocateNumber, preallocatePages);

                                if (preallocatePages < minAddedPages + 1*slab['total_pages']) {
                                    preallocatePages = minAddedPages + 1*slab['total_pages'];
                                    preallocateNumber = Math.ceil(preallocatePages * slab['chunks_per_page']);
                                    //console.log(slab['chunk_size'], preallocateNumber, preallocatePages);
                                }

                                preallocateCommand += ' -p ' + slab['max'] + ':' + preallocateNumber;
                                preallocatePages = Math.ceil(preallocateNumber / slab['chunks_per_page']);

                                totalPages += preallocatePages;
                                totalPreallocatedMemory += preallocatePages * pageSize;
                            }
                        }
                    }


                    if (!totalPages) {
                        return '';
                    }

                    return '<h2>Many evictions found</h2>'
                        + '<div>recommend to preallocate memory after restart</div>'
                        + '<pre>php memcached-slabs.php' + preallocateCommand + ' ' + data.host + ':' + data.port + '</pre>'
                        + '<pre>Total memory to pre-allocate: ' + countBytes(totalPreallocatedMemory)
                        + ', total pages: ' + totalPages + '</pre>'
                        ;

                }

                function makeReport(data) {
                    var memUsed = 0, memTotal = 0, slab;
                    for (var i in data.slabs) {
                        if (data.slabs.hasOwnProperty(i)) {
                            slab = data.slabs[i];
                            memUsed += slab['chunk_size']  * slab['used_chunks'];
                            memTotal += slab['chunk_size']  * slab['total_chunks']
                        }
                    }

                    var report = $('#report');
                    report
                        .html('')
                        .append('<h2>Memory Used ' + countBytes(memUsed) + ' of ' + countBytes(memTotal) + '</h2>')
                        .append(evictionsReport(data))
                        .append('<div style="float:left"><h2>Slabs</h2>' + slabReport(data) + '</div>')

                        .append(
                        $('<div id="report-cont" style="float:left"></div>')
                            .append('<div>Evicted</div>').append(appendHChart(data.slabs, 'id', 'evicted', {scaleShowLabels : false}))
                            .append('<div>Used Memory</div>').append(appendHChart(data.slabs, 'id', 'used_memory', {scaleShowLabels : false}))
                            .append('<div>Count</div>').append(appendHChart(data.slabs, 'id', 'number', {scaleShowLabels : false}))
                            .append('<div>Hit Ratio</div>').append(appendHChart(data.slabs, 'id', 'hit_ratio', {scaleShowLabels : false}))
                            .append('<div>Hits</div>').append(appendHChart(data.slabs, 'id', 'get_hits', {scaleShowLabels : false}))
                            .append('<div>Sets</div>').append(appendHChart(data.slabs, 'id', 'cmd_set', {scaleShowLabels : false}))
                    )
                        .append('<h2 style="clear:left">Key Groups</h2>' + keysReportHtml(data))
                        .append('<h2>Stats</h2>' + statsReportHtml(data))
                        .append('<h2>Slabs Stat</h2>' + renderTable(false, data.slabs))
                    ;


                    report.find('table.sortable').each(function(){
                        sorttable.makeSortable(this);
                    });
                }

            })();

        </script>

        <h1 id="title"><?=$this->reportName?></h1>
        <div id="report"></div>

        </body>
        </html>
        <?php
        $html = ob_get_clean();
        return $html;
    }


    public function store($key, $value, $ttl) {
        $this->mcQuery("set $key 0 $ttl " . strlen($value) . "\r\n" . $value);
    }

    public function delete($key) {
        $this->mcQuery("delete $key");
    }


    public $totalWrite = 0;
    public $totalRead = 0;
    public function fillData($count, $minLength, $maxLength, $minTtl, $maxTtl, $prefix) {
        $k = 0;
        for ($i = 0; $i < $count; ++$i) {
            $key = $prefix . $i;
            $value = str_pad('', rand($minLength, $maxLength), '.');
            $this->store($key, $value, rand($minTtl, $maxTtl));
            $result = $this->lastEnd;
            if ($result != "STORED\r\n") {
                echo $this->lastEnd;
            }
            if (++$k > $this->dotSize) {
                echo '.';
                $k = 0;
            }
        }
        echo "\n";
    }

    public $dotSize = 100;

    public function deleteData($count, $prefix) {
        $k = 0;
        $dot = '.';
        for ($i = 0; $i < $count; ++$i) {
            $key = $prefix . $i;
            $this->delete($key);
            $result = $this->lastEnd;
            if ($result != "DELETED\r\n") {
                //echo $this->lastEnd;
                $dot = 'e';
            }
            if (++$k > $this->dotSize) {
                echo $dot;
                $dot = '.';
                $k = 0;
            }
        }
        echo "\n";
    }

    public function getData($count, $prefix) {
        $k = 0;
        $dot = '.';
        for ($i = 0; $i < $count; ++$i) {
            $key = $prefix . $i;
            $result = $this->getValue($key);
            if (empty($result)) {
                //echo $this->lastEnd;
                $dot = 'e';
            }
            if (++$k > $this->dotSize) {
                echo $dot;
                $dot = '.';
                $k = 0;
            }
        }
        echo "\n";
    }



    public $topEntitiesPath = false;
}
if (!ini_get('date.timezone')) {
    date_default_timezone_set('UTC');
}

/*$msc = new MemcacheSlabsCheck('localhost', '11211');
$msc->store('test', 'test', 0);

return;
*/

/*
$msc = new MemcacheSlabsCheck('localhost', '11211');
$msc->sampleFactor = 1;
print_r($msc->getKeys());
$msc->saveReport();
*/

if ($_SERVER['argc'] == 1) {
    ?>
Usage:
    php <?=basename(__FILE__)?> [options] host1:port1 [host2:port2 ...]
Options:
    -r generate html report
    -p <size:count> preallocate slabs by setting/deleting
    -ps <size:count> preallocate slabs by setting (no deleting)
    -e  process only slabs with evictions-e
    -k  save dumped keys
    -f <count:min_length:max_length:min_ttl:max_ttl:key_prefix> fill memcached with random data
    -d <count:key_prefix> delete random data from memcached
    -s  skip analyzing keys
    -ste <count> save top size entities per key group count (print_r'ed), default 10
    -g <key> get key value (raw protocol)
    -gp <key> print_r key value (Memcached extension required)

Examples:

# preallocate space for 200k of 160 bytes records and 100k of 350 bytes records at myhost:11211 and myhost2:11011
php <?=basename(__FILE__)?> -p 160:200000 -p 350:100000 myhost:11211 myhost2:11011

# fetch raw values of MY_KEY at myhost:11211 and myhost2:11011
php <?=basename(__FILE__)?> -g MY_KEY myhost:11211 myhost2:11011

# view print_r values of MY_KEY and MY_KEY2 at myhost:11211 retrieved via Memcached ext
php <?=basename(__FILE__)?> -gp MY_KEY -gp MY_KEY2 myhost:11211 myhost2:11011

# generate slabs allocation html reports for myhost:11211 and myhost2:11011 (faster, without keys statistics)
php <?=basename(__FILE__)?> -r -s myhost:11211 myhost2:11011

# generate slabs allocation html reports for myhost:11211 and myhost2:11011 (slower, with keys statistics)
php <?=basename(__FILE__)?> -r myhost:11211 myhost2:11011

# benchmark 192.168.59.105:11211 with 50000 record with random size between 50 and 100,
# zero ttl and names SMALL_0 to SMALL_49999
# in benchmark mode records are set, then read, then deleted
php <?=basename(__FILE__)?> -b 5000:50:10000:0:0:SMALL_ 192.168.59.105:11211

# fill 192.168.59.105:11211 with 50000 record with random size between 50 and 100,
# zero ttl and names SMALL_0 to SMALL_49999
php <?=basename(__FILE__)?> -f 50000:50:100:0:0:SMALL_ 192.168.59.105:11211

# delete items with names SMALL_0 to SMALL_49999 from 192.168.59.105:11211
php <?=basename(__FILE__)?> -d 50000:SMALL_ 192.168.59.105:11211

<?php
}


$evictedOnly = false;
$saveKeys = false;
$analyzeKeys = true;
$fillData = array();
$benchData = array();
$deleteData = array();

$instances = array();
$arguments = $_SERVER['argv'];
$getValues = array();
$getPhpValues = array();
$preallocate = array();
$preallocateSet = array();
$makeReport = false;
$pte = false;
$saveTopEntitiesCount = 0;
$threads = false;
$jsonOutput = false;

for ($i = 1; $i < count($arguments); ++$i) {
    $arg = $arguments[$i];
    switch ($arg) {
        case '-r': $makeReport = true;break;
        case '-e': $evictedOnly = true;break;
        case '-s': $analyzeKeys = false;break;
        case '-k': $saveKeys = true;break;
        case '-p': {
            $preallocate []= explode(':', $arguments[$i+1]);
            $i++;
            break;
        }
        case '-ps': {
            $preallocateSet []= explode(':', $arguments[$i+1]);
            $i++;
            break;
        }
        case '-t': {
            $threads = $arguments[$i+1];
            $i++;
            break;
        }
        case '-j': {
            $jsonOutput = $arguments[$i+1];
            $i++;
            break;
        }
        case '-b': {
            $benchData []= explode(':', $arguments[$i+1]);
            $i++;
            break;
        }
        case '-f': {
            $fillData []= explode(':', $arguments[$i+1]);
            $i++;
            break;
        }
        case '-d': {
            $deleteData []= explode(':', $arguments[$i+1]);
            $i++;
            break;
        }
        case '-g': {
            $getValues []= $arguments[$i+1];
            $i++;
            break;
        }
        case '-gp': {
            $getPhpValues []= $arguments[$i+1];
            $i++;
            break;
        }
        case '-ste': {
            $saveTopEntitiesCount = $arguments[$i + 1];
            $i++;
            break;
        }
        default: {
            if (strpos($arg, ':') === false) {
                die("Bad argument: " . $arg);
            }
            else {
                $instances []= explode(':', $arg, 2);
            }
            break;
        }
    }


}
set_time_limit(0);
foreach ($instances as $instance) {
    $msc = new MemcachedTool($instance[0], $instance[1]);
    $msc->evictedOnly = $evictedOnly;
    $msc->saveKeys = $saveKeys;
    $msc->analyzeKeys = $analyzeKeys;
    $msc->saveTopEntitiesCount = $saveTopEntitiesCount;

    if ($benchData && $threads) {
        $jsonReports = array();

        for ($i = 0; $i < $threads; ++$i) {
            $jsonOutput = sys_get_temp_dir() . '/memcached_slabs_' . time() . '_' . $i;
            $jsonReports [$i]= $jsonOutput;
            $command = 'php ' . __FILE__ . ' -j ' . $jsonOutput;
            foreach ($benchData as $benchDataSet) {
                $benchDataSet[0] = round($benchDataSet[0] / $threads);
                $benchDataSet[5] .= $i . '_';
                $command .= ' -b ' . implode(':', $benchDataSet);
            }
            foreach ($instances as $instance) {
                $command .= ' ' . implode(':', $instance);
            }
            //echo $command, PHP_EOL;
            exec($command . '>/dev/null &');
        }

        $stats = array();
        while ($jsonReports) {
            foreach ($jsonReports as $i => $jsonOutput) {
                if (file_exists($jsonOutput)) {
                    $stats []= json_decode(file_get_contents($jsonOutput), true);
                    unset($jsonReports[$i]);
                    unlink($jsonOutput);
                    echo '*';
                }
            }
            echo '.';
            sleep(1);
        }

        $total = array();
        foreach ($stats as $statData) {
            foreach ($statData as $stat) {
                foreach ($stat as $test => $results) {
                    foreach ($results as $key => $value) {
                        if (!isset($total[$test][$key])) {
                            $total[$test][$key] = 0;
                        }
                        $total[$test][$key] += $value;
                    }

                }
            }
        }

        ini_set('precision', 3);
        echo PHP_EOL;
        foreach ($total as $test => $result) {
            echo $test, ': ',$result['count'] . ' items in ' . $result['time']
                . ' s. ('.number_format($result['count'] / $result['time'], 2, '.', '').' items/s), '
                . $result['read'] . ' ('.number_format($result['read'] / $result['time'], 2, '.', '').' B/s) bytes read, '
                . $result['write'] . ' ('.number_format($result['write'] / $result['time'], 2, '.', '').' B/s) bytes written'
                . PHP_EOL;
        }

        exit;
    }

    if ($benchData) {
        if (!$jsonOutput) {
            echo "Benchmarking\n";
        }
        $stats = array();
        foreach ($benchData as $benchDataSet) {
            $count = $benchDataSet[0];
            $prefix = $benchDataSet[5];
            $msc->dotSize = max(1, round($benchDataSet[0] / 100));


            echo "Filling data\n";
            $start = microtime(1);
            $msc->totalRead = 0;
            $msc->totalWrite = 0;
            $msc->fillData($count, $benchDataSet[1], $benchDataSet[2], $benchDataSet[3], $benchDataSet[4], $prefix);
            $stat['set']['count'] = $count;
            $stat['set']['time'] = microtime(1) - $start;
            $stat['set']['read'] = $msc->totalRead;
            $stat['set']['write'] = $msc->totalWrite;
            echo 'Done in ' . $stat['set']['time'] . " s\n";

            echo "Getting data\n";
            $start = microtime(1);
            $msc->totalRead = 0;
            $msc->totalWrite = 0;
            $msc->getData($count, $prefix);
            $stat['get']['count'] = $count;
            $stat['get']['time'] = microtime(1) - $start;
            $stat['get']['read'] = $msc->totalRead;
            $stat['get']['write'] = $msc->totalWrite;
            echo 'Done in ' . $stat['get']['time'] . " s\n";

            echo "Deleting data\n";
            $start = microtime(1);
            $msc->totalRead = 0;
            $msc->totalWrite = 0;
            $msc->deleteData($count, $prefix);
            $stat['delete']['count'] = $count;
            $stat['delete']['time'] = microtime(1) - $start;
            $stat['delete']['read'] = $msc->totalRead;
            $stat['delete']['write'] = $msc->totalWrite;
            echo 'Done in ' . $stat['delete']['time'] . " s\n";
            $stats []= $stat;
        }
        if ($jsonOutput) {
            file_put_contents($jsonOutput, json_encode($stats));
        }
    }

    if ($fillData) {
        echo "Filling data\n";
        foreach ($fillData as $fillDataSet) {
            $msc->dotSize = max(1, round($fillDataSet[0] / 100));
            $msc->fillData($fillDataSet[0], $fillDataSet[1], $fillDataSet[2], $fillDataSet[3], $fillDataSet[4], $fillDataSet[5]);
        }
    }

    if ($deleteData) {
        echo "Deleting data\n";
        foreach ($deleteData as $deleteDataSet){
            $msc->dotSize = max(1, round($deleteDataSet[0] / 100));
            $msc->deleteData($deleteDataSet[0], $deleteDataSet[1]);
        }
    }

    if ($getValues) {
        echo "Getting values\n";
        foreach ($getValues as $key) {
            print_r($msc->getValue($key));
        }
    }

    if ($getPhpValues) {
        echo "(print_r)ing values\n";
        foreach ($getValues as $key) {
            print_r($msc->getPhpValue($key));
        }
    }

    if ($preallocate || $preallocateSet) {
        echo "Pre-allocating slabs\n";
        foreach ($preallocate as $item) {
            $size = $item[0];
            $count = $item[1];
            $msc->dotSize = max(1, round($count / 100));
            echo "\nSetting $count items of size $size (. = $msc->dotSize items)\n";
            $msc->fillData($count, $size, $size, 0, 0, 'temp_' . $size . '_');
        }

        foreach ($preallocateSet as $item) {
            $size = $item[0];
            $count = $item[1];
            $msc->dotSize = max(1, round($count / 100));
            echo "\nSetting $count items of size $size (. = $msc->dotSize items)\n";
            $msc->fillData($count, $size, $size, 0, 0, 'temp_' . $size . '_');
        }


        foreach ($preallocate as $item) {
            $size = $item[0];
            $count = $item[1];
            $msc->dotSize = max(1, round($count / 100));
            echo "\nDeleting $count items of size $size (. = $msc->dotSize items)\n";
            $msc->deleteData($count, 'temp_' . $size . '_');
        }

    }


    if ($makeReport) {
        $msc->getKeys();
        $msc->saveReport();
    }
}

