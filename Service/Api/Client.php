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
    public const BASE_URL_LIVE = 'https://NOT_A_REAL_ADDRESS_FOR_TESTING_PURPOSES/ispws';
    //public const BASE_URL_LIVE = 'https://report.cybertip.org/ispws';
    
    // API Endpoints
    public const ENDPOINT_STATUS = '/status';
    public const ENDPOINT_XSD = '/xsd';
    public const ENDPOINT_SUBMIT = '/submit';
    public const ENDPOINT_UPLOAD = '/upload';
    public const ENDPOINT_FILEINFO = '/fileinfo';
    public const ENDPOINT_FINISH = '/finish';
    public const ENDPOINT_RETRACT = '/retract';

    /**
     * Canonical incident type labels as defined by the CyberTipline Reporting API
     * for 18 U.S. Code § 2258A reporting obligations.
     *
     * Keys are internal enum tokens, values are the literal strings expected by NCMEC.
     * /report/incidentSummary/incidentType
     *
     *  @see https://exttest.cybertip.org/ispws/documentation/index.html#incident-summary
     */
    public const INCIDENT_TYPE_VALUES = [
        'child_pornography' => 'Child Pornography (possession, manufacture, and distribution)',
        'child_sex_trafficking' => 'Child Sex Trafficking',
        'child_sex_tourism' => 'Child Sex Tourism',
        'child_sexual_molestation' => 'Child Sexual Molestation',
        'misleading_domain_name' => 'Misleading Domain Name',
        'misleading_words_or_digital_images_on_the_internet' => 'Misleading Words or Digital Images on the Internet',
        'online_enticement_of_children_for_sexual_acts' => 'Online Enticement of Children for Sexual Acts',
        'unsolicited_obscene_material_sent_to_a_child' => 'Unsolicited Obscene Material Sent to a Child',
    ];

    /**
     * Report annotation tags as defined by the CyberTipline Reporting API.
     * These are optional tags to describe the report.
     * /report/incidentSummary/reportAnnotations
     *
     * Keys are internal identifiers, values are the XML element names expected by NCMEC.
     * 
     * @see https://exttest.cybertip.org/ispws/documentation/index.html#report-annotations
     */
    public const REPORT_ANNOTATION_VALUES = [
        'sextortion' => 'sextortion',
        'csam_solicitation' => 'csamSolicitation',
        'minor_to_minor_interaction' => 'minorToMinorInteraction',
        'spam' => 'spam',
        'sadistic_online_exploitation' => 'sadisticOnlineExploitation',
    ];

    /**
     * Human-readable labels for report annotation tags.
     */
    public const REPORT_ANNOTATION_LABELS = [
        'sextortion' => 'Sextortion',
        'csam_solicitation' => 'CSAM Solicitation',
        'minor_to_minor_interaction' => 'Minor-to-Minor Interaction',
        'spam' => 'Spam',
        'sadistic_online_exploitation' => 'Sadistic Online Exploitation',
    ];
    
    /** @var string */
    protected $username;
    
    /** @var string */
    protected $password;
    
    /** @var string */
    protected $environment;
    
    /** @var string */
    protected $baseUrl;
    
    /** @var int|null */
    protected $reportId;

    /** @var array|null */
    protected $lastRequestLogData;
    
    public function __construct(\XF\App $app, string $username, string $password, string $environment = self::ENVIRONMENT_TEST)
    {
        parent::__construct($app);
        
        $this->username = $username;
        $this->password = $password;
        $this->environment = $environment;
        $this->baseUrl = $this->determineBaseUrl($environment);
        $this->reportId = null;
        $this->lastRequestLogData = null;
    }
    
    /**
     * Set the internal report ID for logging context
     */
    public function setReportId(?int $reportId): void
    {
        $this->reportId = $reportId;
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
     * @param bool $logSuccess Whether to log successful status checks (default false)
     * @return bool True if connection successful
     */
    public function testConnection(&$error = null, bool $logSuccess = false): bool
    {
        try
        {
            $response = $this->get(self::ENDPOINT_STATUS, false);
            $xml = $this->parseXml($response);
            
            if (!$xml)
            {
                $error = \XF::phrase('usips_ncmec_invalid_response_from_server');

                $lastRequestData = $this->getLastRequestLogData() ?? [];
                $responseBody = $lastRequestData['response_data'] ?? '';

                $this->logFromLastRequest(false, [
                    'request_data' => ['context' => 'status_check', 'error_type' => 'invalid_xml'],
                    'response_data' => 'Invalid XML response: ' . $responseBody
                ]);
                return false;
            }
            
            $responseCode = (string)$xml->responseCode;
            
            if ($responseCode !== '0')
            {
                $error = \XF::phrase('usips_ncmec_api_error_x', [
                    'error' => (string)$xml->responseDescription
                ]);

                $lastRequestData = $this->getLastRequestLogData() ?? [];
                $responseBody = $lastRequestData['response_data'] ?? '';

                $this->logFromLastRequest(false, [
                    'request_data' => ['context' => 'status_check', 'error_type' => 'api_error', 'ncmec_code' => $responseCode],
                    'response_code' => 200,
                    'response_data' => 'NCMEC error: ' . (string)$xml->responseDescription . ($responseBody !== '' ? " | Body: " . $responseBody : '')
                ]);
                return false;
            }
            
            if ($logSuccess)
            {
                $this->logFromLastRequest(true, [
                    'request_data' => ['context' => 'status_check']
                ]);
            }

            return true;
        }
        catch (\Exception $e)
        {
            $error = \XF::phrase('usips_ncmec_connection_error_x', [
                'error' => $e->getMessage()
            ]);

            $lastRequestData = $this->getLastRequestLogData() ?? [];
            $responseBody = $lastRequestData['response_data'] ?? '';
            $responsePayload = $responseBody !== ''
                ? 'HTTP exception: ' . $e->getMessage() . " | Body: " . $responseBody
                : $e->getMessage();

            $this->logFromLastRequest(false, [
                'request_data' => ['context' => 'status_check', 'error_type' => 'exception'],
                'response_data' => $responsePayload
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
     * @param bool $shouldLog Whether to log this request (default true, false for successful /status)
     * @return string Response body
     * @throws \Exception
     */
    protected function get(string $endpoint, bool $shouldLog = true): string
    {
        $this->resetLastRequestLogData();
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
        
        $success = empty($error) && $httpCode === 200;

        $responseData = $response !== false ? $response : ($error ?: '');

        $this->storeLastRequestLogData('GET', $endpoint, [], $httpCode, $responseData, $success);
        
        if ($shouldLog)
        {
            $this->logFromLastRequest($success);
        }
        
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
        $this->resetLastRequestLogData();
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
        
        $success = empty($error) && $httpCode === 200;

        // Store full XML body for debugging, not just length
        $requestData = ['xml' => $xmlBody];
        $responseData = $response !== false ? $response : ($error ?: '');

        $this->storeLastRequestLogData('POST', $endpoint, $requestData, $httpCode, $responseData, $success);

        $this->logFromLastRequest($success);
        
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
        $this->resetLastRequestLogData();
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
        
        $success = empty($error) && $httpCode === 200;

        $logData = [];
        foreach ($fields as $key => $value)
        {
            if ($value instanceof \CURLFile)
            {
                $logData[$key] = [
                    'filename' => basename($value->getFilename()),
                    'mime' => $value->getMimeType(),
                ];
            }
            else
            {
                $logData[$key] = $value;
            }
        }

        $responseData = $response !== false ? $response : ($error ?: '');

        $this->storeLastRequestLogData('POST', $endpoint, $logData, $httpCode, $responseData, $success);

        $this->logFromLastRequest($success);
        
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
    
    protected function resetLastRequestLogData(): void
    {
        $this->lastRequestLogData = null;
    }

    protected function storeLastRequestLogData(
        string $method,
        string $endpoint,
        array $requestData,
        ?int $responseCode,
        string $responseData,
        bool $success
    ): void
    {
        $this->lastRequestLogData = [
            'method' => $method,
            'endpoint' => $endpoint,
            'request_data' => $requestData,
            'response_code' => $responseCode,
            'response_data' => $responseData,
            'success' => $success,
        ];
    }

    protected function getLastRequestLogData(): ?array
    {
        return $this->lastRequestLogData;
    }

    protected function logFromLastRequest(bool $success, array $override = []): void
    {
        $data = $this->lastRequestLogData ?? [
            'method' => 'GET',
            'endpoint' => self::ENDPOINT_STATUS,
            'request_data' => [],
            'response_code' => null,
            'response_data' => '',
        ];

        $method = $override['method'] ?? $data['method'];
        $endpoint = $override['endpoint'] ?? $data['endpoint'];

        $requestData = $data['request_data'] ?? [];
        if (isset($override['request_data']))
        {
            if (is_array($override['request_data']))
            {
                $requestData = array_merge($requestData, $override['request_data']);
            }
            else
            {
                $requestData = $override['request_data'];
            }
        }

        $responseCode = $override['response_code'] ?? $data['response_code'];
        $responseData = $override['response_data'] ?? $data['response_data'];

        $this->logRequest(
            $method,
            $endpoint,
            $requestData,
            $responseCode,
            $responseData,
            $success
        );
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
    
    /**
     * Log an API request to the database
     * 
     * @param string $method HTTP method (GET, POST)
     * @param string $endpoint Endpoint path (e.g., /submit, /status)
     * @param array $requestData Request data (NEVER include file data)
     * @param int|null $responseCode HTTP response code
     * @param string $responseData Response body or error message
     * @param bool $success Whether the request succeeded
     */
    protected function logRequest(
        string $method,
        string $endpoint,
        array $requestData,
        ?int $responseCode,
        string $responseData,
        bool $success
    ): void
    {
        $visitor = \XF::visitor();
        
        /** @var \USIPS\NCMEC\Entity\ApiLog $log */
        $log = $this->em()->create('USIPS\NCMEC:ApiLog');
        $log->bulkSet([
            'report_id' => $this->reportId,
            'user_id' => $visitor->user_id,
            'request_date' => \XF::$time,
            'request_method' => $method,
            'request_url' => $this->baseUrl . $endpoint,
            'request_endpoint' => $endpoint,
            'request_data' => $requestData,
            'response_code' => $responseCode,
            'response_data' => $responseData,
            'environment' => $this->environment,
            'success' => $success,
        ]);
        $log->save();
    }
}
