<?php
namespace Yuav\RestEncoderBundle\Processor\Input;

use Yuav\RestEncoderBundle\Entity\Job;

class Downloader
{

    private $downloadedFiles = array();

    public function download($url, $progressCallback = false)
    {
        if (isset($this->downloadedFiles[$url]) && file_exists($this->downloadedFiles[$url])) {
            return $this->downloadedFiles[$url];
        }
        
        $filename = tempnam(sys_get_temp_dir(), 'RestEncoder');
        
        $fp = fopen($filename, 'w+');
        $ch = curl_init(str_replace(" ", "%20", $url));
        curl_setopt($ch, CURLOPT_TIMEOUT, 50);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        if ($progressCallback) {
            curl_setopt($ch, CURLOPT_BUFFERSIZE, 64*1024);
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, $progressCallback);
        }
        $res = curl_exec($ch);
        if (false === $res) {
            throw new \RuntimeException("Failed to download $url - " . curl_error($ch));
        }
        curl_close($ch);
        fclose($fp);
        
        // Cache
        $this->downloadedFiles[$url] = $filename;
        return $filename;
    }

    public function downloadFileAdvanced($url, $progressCallback = false)
    {
        if (empty($url)) {
            throw new \InvalidArgumentException('Expected non-empty string argument, got: ' . get_type($url));
        }
        if (isset($this->downloadedFiles[$url])) {
            return $this->downloadedFiles[$url];
        }
        
        $filename = basename($url);
        
        $tmpFile = tempnam(sys_get_temp_dir(), 'RestEncoder') . $filename;
        $bodyStream = fopen($tmpFile, 'w');
        $headerStream = fopen('php://temp', 'rw');
        
        $ch = curl_init(str_replace(" ", "%20", $url));
        curl_setopt($ch, CURLOPT_WRITEHEADER, $headerStream);
        curl_setopt($ch, CURLOPT_FILE, $bodyStream);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        if ($progressCallback) {
            curl_setopt($ch, CURLOPT_BUFFERSIZE, 64*1024);
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, $progressCallback);
        }
        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $ch);
        $headerProcessed = false;
        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        
        while ($active && $mrc == CURLM_OK) {
            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
            
            if (curl_errno($ch)) {
                throw new \RuntimeException(curl_error($ch) . "-" . curl_errno($ch));
            }
            
            // Process headers
            if (! $headerProcessed) {
                $currentPos = ftell($headerStream);
                rewind($headerStream);
                $header = stream_get_contents($headerStream);
                fseek($headerStream, $currentPos); // Is this really needed?
                if (strpos($header, "\r\n\r\n") !== false) {
                    // End of headers reached
                    $this->verifyHeader($header);
                    
                    $headerFilename = $this->findFilenameFromHeader($header);
                    if (false !== $headerFilename) {
                        $filename = $headerFilename;
                    }
                    
                    // Headers received, stop processing header
                    $headerProcessed = true;
                }
            }
        }
        
        curl_multi_remove_handle($mh, $ch);
        if ('' !== curl_error($ch)) {
            throw new \RuntimeException("Failed to download $url - " . curl_error($ch));
        }
        curl_close($ch);
        curl_multi_close($mh);
        fclose($bodyStream); // Not closing explicitly results in incomplete download for curl_multi
        
        $tmpDir = $this->tempdir();
        if (false !== $filename) {
            rename($tmpFile, $tmpDir . '/' . $filename);
        }
        
        // Cache
        $this->downloadedFiles[$url] = $tmpDir . '/' . $filename;
        
        return $tmpDir . '/' . $filename;
    }

    public function rmTmpFile($url)
    {
        if (empty($url)) {
            throw new \InvalidArgumentException('Url cannot be empty');
        }
        if (isset($this->downloadedFiles[$url])) {
            $file = $this->downloadedFiles[$url];
            if (file_exists($file)) {
                unlink($file);
                rmdir(dirname($file));
            }
            unset($this->downloadedFiles[$url]);
        }
    }

    protected function tempdir()
    {
        $tempfile = tempnam(sys_get_temp_dir(), '');
        if (file_exists($tempfile)) {
            unlink($tempfile);
        }
        mkdir($tempfile);
        if (is_dir($tempfile)) {
            return $tempfile;
        }
    }

    protected function findFilenameFromHeader($header)
    {
        $headerArray = explode("\r\n", $header);
        unset($headerArray[0]); // HTTP/1.1 200 OK
        foreach ($headerArray as $h) {
            @list ($headername, $headervalue) = explode(":", $h);
            if ($headername == 'Content-Disposition') {
                if (false !== preg_match('/filename="(.+?)"/', $headervalue, $match)) {
                    return trim($match[1]);
                }
            }
        }
        return false;
    }

    protected function verifyHeader($header)
    {
        /**
         * HTTP/1.1 is RFC2616.
         * In Section 6.1, it describes the Status-Line,
         * the first component of a Response, as:
         * Status-Line = HTTP-Version SP Status-Code SP Reason-Phrase CRLF
         */
        $data = explode(' ', $header);
        $code = $data[1];
        if ($code >= 400) {
            switch ($code) {
                case 400:
                    throw new \RuntimeException('Bad request');
                case 401:
                    throw new \RuntimeException('Not authenticated');
                case 402:
                    throw new \RuntimeException('Payment required');
                case 403:
                    throw new \RuntimeException('Forbidden');
                case 404:
                    throw new \RuntimeException('Resource not found.');
                case 405:
                    throw new \RuntimeException('Method not allowed');
                case 409:
                    throw new \RuntimeException('Conflict');
                case 412:
                    throw new \RuntimeException('Precondition failed');
                case 416:
                    throw new \RuntimeException('Requested Range Not Satisfiable');
                case 500:
                    throw new \RuntimeException('Internal server error');
                case 501:
                    throw new \RuntimeException('Not Implemented');
                default:
                    throw new \RuntimeException('HTTP error response. (errorcode ' . $code . ')');
            }
        }
    }
}