<?php

namespace USIPS\NCMEC\Service\Api;

use XF\Service\AbstractService;

/**
 * NCMEC CyberTipline Reporting API Client
 * 
 * Handles all direct communication with the NCMEC API endpoints.
 * This service is responsible for:
 * - Making HTTP requests to NCMEC servers
 * - Authentication via Basic Auth
 * - XML parsing of responses
 * - Error handling
 * 
 * @see https://exttest.cybertip.org/ispws/documentation/
 * @see https://report.cybertip.org/ispws/documentation/
 */
class Client extends AbstractService
{
    // Environment constants
    public const ENVIRONMENT_TEST = 'test';
    public const ENVIRONMENT_LIVE = 'live';
    
    // Base URLs for different environments
    public const BASE_URL_TEST = 'https://exttest.cybertip.org/ispws';
    public const BASE_URL_LIVE = 'https://report.cybertip.org/ispws';
    
    // API Endpoints
    public const ENDPOINT_STATUS = '/status';
    public const ENDPOINT_XSD = '/xsd';
    public const ENDPOINT_SUBMIT = '/submit';
    public const ENDPOINT_UPLOAD = '/upload';
    public const ENDPOINT_FILEINFO = '/fileinfo';
    public const ENDPOINT_FINISH = '/finish';
    public const ENDPOINT_RETRACT = '/retract';
    
    /** @var string */
    protected $username;
    
    /** @var string */
    protected $password;
    
    /** @var string */
    protected $environment;
    
    /** @var string */
    protected $baseUrl;
    
    public function __construct(\XF\App $app, string $username, string $password, string $environment = self::ENVIRONMENT_TEST)
    {
        parent::__construct($app);
        
        $this->username = $username;
        $this->password = $password;
        $this->environment = $environment;
        $this->baseUrl = $this->determineBaseUrl($environment);
    }
    
    /**
     * Determine the base URL based on the environment
     */
    protected function determineBaseUrl(string $environment): string
    {
        if ($environment === self::ENVIRONMENT_LIVE || $environment === 'production')
        {
            return self::BASE_URL_LIVE;
        }
        
        return self::BASE_URL_TEST;
    }
    
    /**
     * Get the current base URL
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
    
    /**
     * Get the current environment
     */
    public function getEnvironment(): string
    {
        return $this->environment;
    }
    
    /**
     * Test connection and authentication with NCMEC API
     * 
     * @param string|null $error Error message if connection fails
     * @return bool True if connection successful
     */
    public function testConnection(&$error = null): bool
    {
        try
        {
            $response = $this->get(self::ENDPOINT_STATUS);
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
    
    /**
     * Download the XML Schema Definition
     * 
     * @return string|null XSD content or null on failure
     */
    public function downloadXsd(): ?string
    {
        try
        {
            return $this->get(self::ENDPOINT_XSD);
        }
        catch (\Exception $e)
        {
            return null;
        }
    }
    
    /**
     * Submit a report to open a new incident
     * 
     * @param string $xmlDocument XML report document
     * @return \SimpleXMLElement|null Response XML or null on failure
     * @throws \Exception
     */
    public function submitReport(string $xmlDocument): ?\SimpleXMLElement
    {
        $response = $this->post(self::ENDPOINT_SUBMIT, $xmlDocument);
        return $this->parseXml($response);
    }
    
    /**
     * Upload a file to an existing report
     * 
     * @param int $reportId Report ID
     * @param string $filePath Path to file to upload
     * @return \SimpleXMLElement|null Response XML or null on failure
     * @throws \Exception
     */
    public function uploadFile(int $reportId, string $filePath): ?\SimpleXMLElement
    {
        $response = $this->postMultipart(self::ENDPOINT_UPLOAD, [
            'id' => $reportId,
            'file' => new \CURLFile($filePath)
        ]);
        
        return $this->parseXml($response);
    }
    
    /**
     * Submit file details for an uploaded file
     * 
     * @param string $xmlDocument XML file details document
     * @return \SimpleXMLElement|null Response XML or null on failure
     * @throws \Exception
     */
    public function submitFileDetails(string $xmlDocument): ?\SimpleXMLElement
    {
        $response = $this->post(self::ENDPOINT_FILEINFO, $xmlDocument);
        return $this->parseXml($response);
    }
    
    /**
     * Finish a report submission
     * 
     * @param int $reportId Report ID
     * @return \SimpleXMLElement|null Response XML or null on failure
     * @throws \Exception
     */
    public function finishReport(int $reportId): ?\SimpleXMLElement
    {
        $response = $this->postMultipart(self::ENDPOINT_FINISH, [
            'id' => $reportId
        ]);
        
        return $this->parseXml($response);
    }
    
    /**
     * Retract/cancel a report submission
     * 
     * @param int $reportId Report ID
     * @return \SimpleXMLElement|null Response XML or null on failure
     * @throws \Exception
     */
    public function retractReport(int $reportId): ?\SimpleXMLElement
    {
        $response = $this->postMultipart(self::ENDPOINT_RETRACT, [
            'id' => $reportId
        ]);
        
        return $this->parseXml($response);
    }
    
    /**
     * Make a GET request to the API
     * 
     * @param string $endpoint API endpoint (relative to base URL)
     * @return string Response body
     * @throws \Exception
     */
    protected function get(string $endpoint): string
    {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        
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
    
    /**
     * Make a POST request with XML body
     * 
     * @param string $endpoint API endpoint (relative to base URL)
     * @param string $xmlBody XML document to send
     * @return string Response body
     * @throws \Exception
     */
    protected function post(string $endpoint, string $xmlBody): string
    {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlBody);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: text/xml; charset=utf-8',
            'Content-Length: ' . strlen($xmlBody)
        ]);
        
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
    
    /**
     * Make a POST request with multipart form data
     * 
     * @param string $endpoint API endpoint (relative to base URL)
     * @param array $fields Form fields
     * @return string Response body
     * @throws \Exception
     */
    protected function postMultipart(string $endpoint, array $fields): string
    {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        
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
    
    /**
     * Parse XML string into SimpleXMLElement
     * 
     * @param string $xmlString XML string to parse
     * @return \SimpleXMLElement|null Parsed XML or null on failure
     */
    protected function parseXml(string $xmlString): ?\SimpleXMLElement
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
}
