<?php

namespace USIPS\NCMEC\Service;

use XF\Service\AbstractService;
use USIPS\NCMEC\Service\Api\Client as ApiClient;

/**
 * NCMEC API Configuration Service
 * 
 * Handles configuration management for NCMEC API credentials.
 * For actual API calls, use the Api\Client service.
 */
class Configurer extends AbstractService
{
    public const ENVIRONMENT_TEST = 'test';
    public const ENVIRONMENT_LIVE = 'live';

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

    /**
     * Get current configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Check if credentials are configured
     */
    public function hasActiveConfig(): bool
    {
        return !empty($this->config['username']) && !empty($this->config['password']);
    }

    /**
     * Get the environment from config
     */
    public function getEnvironment(): string
    {
        return $this->config['environment'] ?? self::ENVIRONMENT_TEST;
    }

    /**
     * Get an API client instance with current configuration
     * 
     * @return ApiClient|null API client or null if not configured
     */
    public function getApiClient(): ?ApiClient
    {
        if (!$this->hasActiveConfig())
        {
            return null;
        }

        return $this->service('USIPS\NCMEC:Api\Client',
            $this->config['username'],
            $this->config['password'],
            $this->getEnvironment()
        );
    }

    /**
     * Test connection and authentication with NCMEC API
     * 
     * @param string|null $error Error message if connection fails
     * @param bool $logSuccess Whether to log successful status checks (default false)
     * @return bool True if connection successful
     */
    public function test(&$error = null, bool $logSuccess = false): bool
    {
        if (!$this->hasActiveConfig())
        {
            $error = \XF::phrase('usips_ncmec_api_not_configured');
            return false;
        }

        $client = $this->getApiClient();
        if (!$client)
        {
            $error = \XF::phrase('usips_ncmec_api_not_configured');
            return false;
        }

        return $client->testConnection($error, $logSuccess);
    }

    /**
     * Save configuration to options
     */
    public function saveConfig(): void
    {
        /** @var \XF\Repository\Option $optionRepo */
        $optionRepo = $this->repository('XF:Option');
        $optionRepo->updateOption('usipsNcmecApi', $this->config);
    }
}
