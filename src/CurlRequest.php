<?php

namespace Aslamhus\GoogleDrive;

use Aslamhus\GoogleDrive\Exceptions\CurlException;

/**
 * Curl Request
 *
 * A simple class to execute curl requests in GoogleDriveUploader
 */
class CurlRequest
{
    /**
     * Execute a curl request
     *
     * @param array $options
     * @return CurlResponse  - CurlResponse object which holds the  the HTTP status code, and response output body
     */
    public static function exec(array $options)
    {
        $cH = curl_init();
        // set the options
        curl_setopt_array($cH, $options);
        // execute
        $output = curl_exec($cH);
        // handle errors
        if($output === false) {
            $error = curl_error($cH);
            throw new CurlException($error);
        }
        // get the status
        $status = curl_getinfo($cH, CURLINFO_HTTP_CODE);
        curl_close($cH);
        return new CurlResponse($output, $status);
    }


}
