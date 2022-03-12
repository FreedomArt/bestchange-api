<?php

namespace BestChangeApi;

use BestChangeApi\Exception\BestChangeApiException;
use DateTime;
use ZipArchive;

class BestChangeApi
{
    private $version = '1.1.0';

    const API_URL = 'http://api.bestchange.ru/info.zip';

    const TIMEOUT = 25;
    const PREFIX_TMPFILE = 'art';

    private $cachePath;
    private $useCache;
    private $cacheTime;
    private $zip;

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
        return $this;
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