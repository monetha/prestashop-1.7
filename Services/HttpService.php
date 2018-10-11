<?php

namespace Monetha\Services;

class HttpService
{
    public static function callApi($uri, $method, $body = null, $headers)
    {
        $chSign = curl_init();

        $options = [
            CURLOPT_URL => $uri,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER =>  array_merge($headers, array(
                "Cache-Control: no-cache",
                "Content-Type: application/json"
            )),
        ];

        if ($method !== 'GET' && $body) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_NUMERIC_CHECK);
            $options[CURLOPT_CUSTOMREQUEST] = $method;
        }

        curl_setopt_array($chSign, $options);

        $res = curl_exec($chSign);
        $error = curl_error($chSign);

        if ($error) {
            throw new \Exception($error);
        }

        $resStatus = curl_getinfo($chSign, CURLINFO_HTTP_CODE);
        if ($resStatus < 200 || $resStatus >= 300) {
            throw new \Exception($res);
        }

        $resJson = json_decode($res);

        curl_close($chSign);

        return $resJson;
    }
}
