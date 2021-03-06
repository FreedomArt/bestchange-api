<?php

namespace BestChangeApi;

use BestChangeApi\Exception\BestChangeApiException;
use DateTime;
use ZipArchive;

class BestChangeApi
{
    private $version = '';
    private $lastUpdate;

    const API_URL         = 'http://api.bestchange.ru/info.zip';
    const FILE_CURRENCIES = 'bm_cy.dat';
    const FILE_EXCHANGERS = 'bm_exch.dat';
    const FILE_RATES      = 'bm_rates.dat';
    const TIMEOUT         = 25;
    const PREFIX_TMPFILE  = 'art';

    private $cachePath;
    private $useCache;
    private $cacheTime;
    private $zip;
    private $currencies;
    private $exchangers;
    private $rates;

    private $dataExchangers = [];
    private $dataCurrencies = [];
    private $dataRates      = [];

    public function __construct($cachePath = '', $cacheTime = 3600)
    {
        $this->zip = new \ZipArchive();
        if ($cachePath) {
            $this->cacheTime = $cacheTime;
            $this->useCache  = true;
            $this->cachePath = $cachePath;
        } else {
            $this->useCache  = false;
            $this->cachePath = tempnam(sys_get_temp_dir(), self::PREFIX_TMPFILE);
        }
        $this->initLoad();
    }

    public function getVersion()
    {
        return $this->version;
    }

    private function initLoad()
    {
        $this->getFile()->unzip()->init();
        $this->currencies = $this->setCurrencies($this->zip->getFromName(self::FILE_CURRENCIES));
        $this->exchangers = $this->setExchangers($this->zip->getFromName(self::FILE_EXCHANGERS));
        $this->rates      = $this->setRates($this->zip->getFromName(self::FILE_RATES));
        return $this;
    }

    /**
     * [getByID description]
     * @param  [type] $id   [description]
     * @param  string $type currencies  exchangers  rates
     * @return [type]       [description]
     */
    public function getByID($id, $type = "currencies")
    {
        return empty($this->{$type}[$id]) ? false : $this->{$type}[$id];
    }

    public function getCurrencies()
    {
        return $this->currencies;
    }

    public function getExchangers()
    {
        return $this->exchangers;
    }

    public function getRates()
    {
        return $this->rates;
    }

    public function getLastUpdate()
    {
        return $this->lastUpdate;
    }

    private function setCurrencies($data)
    {
        $data = explode("\n", $data);
        foreach ($data as $row) {
            $row = iconv('CP1251', 'UTF-8', $row);
            $data = explode(';', $row);
            $this->dataCurrencies[$data[0]] = [
                'id' => (int)$data[0],
                'name' => $data[2],
            ];
        }
        return $this->dataCurrencies;
    }

    private function setExchangers($data)
    {
        $data = explode("\n", $data);
        foreach ($data as $row) {
            $row  = iconv('CP1251', 'UTF-8', $row);
            $data = explode(';', $row);
            $this->dataExchangers[$data[0]] = $data[1];
        }
        ksort($this->dataExchangers);
        return $this->dataExchangers;
    }

    private function setRates($data)
    {
        $data = explode("\n", $data);
        foreach ($data as $row) {
            $row = iconv('CP1251', 'UTF-8', $row);
            $data = explode(';', $row);
            if (count($data) < 5) {
                continue;
            }
            $rateGive    = (float)$data[3];
            $rateReceive = (float)$data[4];
            if (!$rateGive || !$rateReceive) {
                continue;
            }
            $rate = $rateReceive ? $rateGive / $rateReceive : 0;
            $this->dataRates[$data[0]][$data[1]][$data[2]] = [
                'exchanger_id' => (int)$data[2],
                'rate_give'    => $rateGive,
                'rate_receive' => $rateReceive,
                'rate'         => $rate,
                'reserve'      => $data[5],
            ];
        }
        return $this->dataRates;
    }

    private function init()
    {
        $file = explode("\n", $this->zip->getFromName('bm_info.dat'));
        foreach ($file as $row) {
            $row = iconv('CP1251', 'UTF-8', $row);
            $data = array_map('trim', explode('=', $row));
            if (count($data) < 2) {
                continue;
            }
            switch ($data[0]) {
                case 'last_update':
                    $this->lastUpdate = $this->getDate($data[1]);
                break;
                case 'current_version':
                    $this->version = $data[1];
                break;
            }
        }
        return $this;
    }

    private function getFile()
    {
        if ($this->checkCacheFile()) {
            return $this;
        }

        $file = $this->loadFile(self::API_URL);
        if ($file) {
            $fp = fopen($this->cachePath, 'wb+');
            fputs($fp, $file);
            fclose($fp);
            return $this;
        }
        throw new BestChangeApiException('File on bestchange.ru not available!');
    }

    private function checkCacheFile()
    {
        clearstatcache(true, $this->cachePath);
        return ( $this->useCache && file_exists($this->cachePath) && filemtime($this->cachePath) > (time() - $this->cacheTime) );
    }

    private function unzip()
    {
        if (!$this->zip->open($this->cachePath)) {
            throw new BestChangeApiException('Incorrect file received from bestchange.ru!');
        }
        return $this;
    }


    private function getDate($date)
    {
        $arMonth = [
            '????????????' => 'January',
            '??????????????' => 'February',
            '??????????' => 'March',
            '????????????' => 'April',
            '??????' => 'May',
            '????????' => 'June',
            '????????' => 'July',
            '??????????????' => 'August',
            '????????????????' => 'September',
            '??????????????' => 'October',
            '????????????' => 'November',
            '??????????????' => 'December',
        ];
        foreach ($arMonth as $ru => $en) {
            $date = preg_replace('/' . $ru . '/sui', $en, $date);
        }
        return new \DateTime($date);
    }

    private function loadFile($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $data = curl_exec($ch);
        curl_close($ch);
        return $data;
    }

}