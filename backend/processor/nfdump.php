<?php

namespace nfsen_ng\processor;

use nfsen_ng\common\{Debug, Config};

class NfDump implements Processor {
    private $cfg = [
        'env' => [],
        'option' => [],
        'format' => null,
        'filter' => []
    ];
    private $clean;
    private $d;
    public static $_instance;

    function __construct() {
        $this->d = Debug::getInstance();
        $this->clean = $this->cfg;
        $this->reset();
    }

    public static function getInstance() {
        if (!(self::$_instance instanceof self)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Sets an option's value
     *
     * @param $option
     * @param $value
     */
    public function setOption($option, $value) {
        switch ($option) {
            case '-M': // set sources

                // only sources specified in settings allowed
                $queried_sources = explode(':', $value);
                foreach ($queried_sources as $s) {
                    if (!in_array($s, Config::$cfg['general']['sources'])) continue;
                    $this->cfg['env']['sources'][] = $s;
                }

                // cancel if no sources remain
                if (empty($this->cfg['env']['sources'])) break;

                // set sources path
                $this->cfg['option'][$option] = implode(DIRECTORY_SEPARATOR, [
                    $this->cfg['env']['profiles-data'],
                    $this->cfg['env']['profile'],
                    implode(':', $this->cfg['env']['sources'])
                ]);

                break;
            case '-R': // set path
                $this->cfg['option'][$option] = $this->convert_date_to_path($value[0], $value[1]);
                break;
            case '-o': // set output format
                $this->cfg['format'] = $value;
                break;
            default:
                $this->cfg['option'][$option] = $value;
                $this->cfg['option']['-o'] = 'csv'; // always get parsable data todo user-selectable? calculations bps/bpp/pps not in csv
                break;
        }
    }

    /**
     * Sets a filter's value
     *
     * @param $filter
     */
    public function setFilter($filter) {
        $this->cfg['filter'] = $filter;
    }

    /**
     * Executes the nfdump command, tries to throw an exception based on the return code
     * @return array
     * @throws \Exception
     */
    public function execute() {
        $output = [];
        $processes = [];
        $return = "";
        $filter = (empty($this->cfg['filter'])) ? "" : " " . escapeshellarg($this->cfg['filter']);
        $command = $this->cfg['env']['bin'] . " " . $this->flatten($this->cfg['option']) . $filter . ' 2>&1';
        $this->d->log('Trying to execute ' . $command, LOG_DEBUG);

        // check for already running nfdump processes
        exec('ps -eo user,pid,args | grep -v grep | grep `whoami` | grep "' . $this->cfg['env']['bin'] . '"', $processes);
        if (count($processes) / 2 > intVal(Config::$cfg['nfdump']['max-processes'])) throw new \Exception("There already are " . count($processes) / 2 . " processes of NfDump running!");

        // execute nfdump
        exec($command, $output, $return);

        // prevent logging the command usage description
        if (isset($output[0]) && preg_match('/^usage/i', $output[0])) $output = [];

        switch ($return) {
            case 127:
                throw new \Exception("NfDump: Failed to start process. Is nfdump installed? " . implode(' ', $output));
                break;
            case 255:
                throw new \Exception("NfDump: Initialization failed. " . $command);
                break;
            case 254:
                throw new \Exception("NfDump: Error in filter syntax. " . implode(' ', $output));
                break;
            case 250:
                throw new \Exception("NfDump: Internal error. " . implode(' ', $output));
                break;
        }

        // add command to output
        array_unshift($output, $command);

        // if last element contains a colon, it's not a csv - todo better check?
        if (preg_match('/:/', $output[count($output) - 1])) {
            return $output; // return output if it is a flows/packets/bytes dump
        }

        // remove the 3 summary lines at the end of the csv output
        $output = array_slice($output, 0, -3);

        // slice csv (only return the fields actually wanted)
        $field_ids_active = [];
        $parsed_header = false;
        $format = false;
        if (isset($this->cfg['format']))
            $format = $this->get_output_format($this->cfg['format']);

        foreach ($output as $i => &$line) {

            if ($i === 0) continue; // skip nfdump command
            $line = str_getcsv($line, ',');
            $temp_line = [];

            if (preg_match('/limit/', $line[0])) continue;
            if (preg_match('/error/', $line[0])) continue;
            if (!is_array($format)) $format = $line; // set first valid line as header if not already defined

            foreach ($line as $field_id => $field) {

                // heading has the field identifiers. fill $fields_active with all active fields
                if ($parsed_header === false) {
                    if (in_array($field, $format)) {
                        $field_ids_active[array_search($field, $format)] = $field_id;
                    }
                }

                // remove field if not in $fields_active
                if (in_array($field_id, $field_ids_active)) {
                    $temp_line[array_search($field_id, $field_ids_active, true)] = $field;
                }
            }

            $parsed_header = true;
            ksort($temp_line);
            $line = array_values($temp_line);
        }
        return $output;
    }

    /**
     * Concatenates key and value of supplied array
     *
     * @param $array
     *
     * @return bool|string
     */
    private function flatten($array) {
        if (!is_array($array)) return false;
        $output = "";

        foreach ($array as $key => $value) {
            if (is_null($value)) {
                $output .= $key . ' ';
            } else {
                $output .= is_int($key) ?: $key . ' ' . escapeshellarg($value) . ' ';
            }
        }
        return $output;
    }

    /**
     * Reset config
     */
    public function reset() {
        $this->clean['env'] = [
            'bin' => Config::$cfg['nfdump']['binary'],
            'profiles-data' => Config::$cfg['nfdump']['profiles-data'],
            'profile' => Config::$cfg['nfdump']['profile'],
            'sources' => [],
        ];
        $this->cfg = $this->clean;
    }

    /**
     * Converts a time range to a nfcapd file range
     * Ensures that files actually exist
     *
     * @param int $datestart
     * @param int $dateend
     *
     * @return string
     * @throws \Exception
     */
    public function convert_date_to_path(int $datestart, int $dateend) {
        $start = new \DateTime();
        $end = new \DateTime();
        $start->setTimestamp((int)$datestart - ($datestart % 300));
        $end->setTimestamp((int)$dateend - ($dateend % 300));
        $filestart = $fileend = "-";
        $filestartexists = false;
        $fileendexists = false;
        $sourcepath = $this->cfg['env']['profiles-data'] . DIRECTORY_SEPARATOR . $this->cfg['env']['profile'] . DIRECTORY_SEPARATOR;

        // if start file does not exist, increment by 5 minutes and try again
        while ($filestartexists === false) {
            if ($start >= $end) break;

            foreach ($this->cfg['env']['sources'] as $source) {
                if (file_exists($sourcepath . $source . DIRECTORY_SEPARATOR . $filestart)) $filestartexists = true;
            }

            $pathstart = $start->format('Y/m/d') . DIRECTORY_SEPARATOR;
            $filestart = $pathstart . 'nfcapd.' . $start->format('YmdHi');
            $start->add(new \DateInterval('PT5M'));
        }

        // if end file does not exist, subtract by 5 minutes and try again
        while ($fileendexists === false) {
            if ($end == $start) break; // strict comparison won't work

            foreach ($this->cfg['env']['sources'] as $source) {
                if (file_exists($sourcepath . $source . DIRECTORY_SEPARATOR . $fileend)) $fileendexists = true;
            }

            $pathend = $end->format('Y/m/d') . DIRECTORY_SEPARATOR;
            $fileend = $pathend . 'nfcapd.' . $end->format('YmdHi');
            $end->sub(new \DateInterval('PT5M'));
        }

        return $filestart . PATH_SEPARATOR . $fileend;
    }

    /**
     * @param $format
     *
     * @return array|string
     */
    public function get_output_format($format) {
        // todo calculations like bps/pps? flows? concatenate sa/sp to sap?
        switch ($format) {
            // nfdump format: %ts %td %pr %sap %dap %pkt %byt %fl
            // csv output: ts,te,td,sa,da,sp,dp,pr,flg,fwd,stos,ipkt,ibyt,opkt,obyt,in,out,sas,das,smk,dmk,dtos,dir,nh,nhb,svln,dvln,ismc,odmc,idmc,osmc,mpls1,mpls2,mpls3,mpls4,mpls5,mpls6,mpls7,mpls8,mpls9,mpls10,cl,sl,al,ra,eng,exid,tr
            case 'line':
                return ['ts', 'td', 'pr', 'sa', 'sp', 'da', 'dp', 'ipkt', 'ibyt', 'fl'];
                // nfdump format: %ts %td %pr %sap %dap %flg %tos %pkt %byt %fl
            case 'long':
                return ['ts', 'td', 'pr', 'sa', 'sp', 'da', 'dp', 'flg', 'stos', 'dtos', 'ipkt', 'ibyt', 'fl'];
                // nfdump format: %ts %td %pr %sap %dap %pkt %byt %pps %bps %bpp %fl
            case 'extended':
                return ['ts', 'td', 'pr', 'sa', 'sp', 'da', 'dp', 'ipkt', 'ibyt', 'ibps', 'ipps', 'ibpp'];

            default:
                return explode(' ', str_replace(['fmt:', '%'], '', $format));
        }
    }
}
