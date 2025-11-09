<?php

namespace USIPS\NCMEC\Service;

use XF\Service\AbstractService;

class Configurer extends AbstractService
{
    public const ENVIRONMENT_TEST = 'test';
    public const ENVIRONMENT_LIVE = 'live';

    public const BASE_URL_TEST = 'https://exttest.cybertip.org/ispws';
    public const BASE_URL_LIVE = 'https://report.cybertip.org/ispws';

    protected $config;

    public function __construct(\XF\App $app, array $config = null)
    {
        parent::__construct($app);
        
        if ($config === null)
        {
            $config = $this->app->options()->usipsNcmecApi;
        }
        
        $this->config = $config;
    }

    public function getConfig()
    {
        return $this->config;
    }

    public function hasActiveConfig()
    {
        return !empty($this->config['username']) && !empty($this->config['password']);
    }

    public function getBaseUrl()
    {
        $environment = $this->config['environment'] ?? self::ENVIRONMENT_TEST;

        if ($environment === self::ENVIRONMENT_LIVE)
        {
            return self::BASE_URL_LIVE;
        }
        else
        {
            return self::BASE_URL_TEST;
        }
    }

    public function test(&$error = null)
    {
        if (!$this->hasActiveConfig())
        {
            $error = \XF::phrase('usips_ncmec_api_not_configured');
            return false;
        }

        try
        {
            $response = $this->makeRequest('/status');
            
            // Parse XML response
            $xml = $this->parseXml($response);
            
            if (!$xml)
            {
                $error = \XF::phrase('usips_ncmec_invalid_response_from_server');
                return false;
            }

            $responseCode = (string)$xml->responseCode;
            
            if ($responseCode !== '0')
            {
                $error = \XF::phrase('usips_ncmec_api_error_x', [
                    'error' => (string)$xml->responseDescription
                ]);
                return false;
            }

            return true;
        }
        catch (\Exception $e)
        {
            $error = \XF::phrase('usips_ncmec_connection_error_x', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function makeRequest($endpoint, $method = 'GET', $body = null)
    {
        $url = $this->getBaseUrl() . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $this->config['username'] . ':' . $this->config['password']);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        
        if ($method === 'POST')
        {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body)
            {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/xml',
                    'Content-Length: ' . strlen($body)
                ]);
            }
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error)
        {
            throw new \Exception($error);
        }
        
        if ($httpCode !== 200)
        {
            throw new \Exception("HTTP Error: {$httpCode}");
        }
        
        return $response;
    }

    public function parseXml($xmlString)
    {
        if (empty($xmlString))
        {
            return null;
        }

        // Suppress XML parsing errors and handle them manually
        libxml_use_internal_errors(true);
        
        try
        {
            $xml = simplexml_load_string($xmlString);
            
            if ($xml === false)
            {
                $errors = libxml_get_errors();
                libxml_clear_errors();
                return null;
            }
            
            return $xml;
        }
        finally
        {
            libxml_use_internal_errors(false);
        }
    }

    public function saveConfig()
    {
        /** @var \XF\Repository\Option $optionRepo */
        $optionRepo = $this->repository('XF:Option');
        $optionRepo->updateOption('usipsNcmecApi', $this->config);
    }
}
