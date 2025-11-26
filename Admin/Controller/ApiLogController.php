<?php

namespace USIPS\NCMEC\Admin\Controller;

use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;

class ApiLogController extends AbstractController
{
    protected function preDispatchController($action, ParameterBag $params)
    {
        $this->assertAdminPermission('usips_ncmec');
    }

    protected function assertApiLogExists($id, $with = null)
    {
        return $this->assertRecordExists('USIPS\\NCMEC:ApiLog', $id, $with);
    }

    public function actionIndex()
    {
        $page = $this->filterPage();
        $perPage = 100;
        $state = $this->filter('state', 'str');
        if (!$state)
        {
            $state = 'all';
        }
        
        $logFinder = $this->finder('USIPS\\NCMEC:ApiLog')
            ->with(['Report', 'Report.Case', 'User'])
            ->order('request_date', 'DESC')
            ->order('log_id', 'DESC')
            ->limitByPage($page, $perPage);

        if ($state === 'receipts')
        {
            $logFinder->where('request_endpoint', '/finish');
        }
        
        $logs = $logFinder->fetch();
        $total = $logFinder->total();
        
        $viewParams = [
            'logs' => $logs,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'state' => $state,
            'tabs' => [
                'all' => \XF::phrase('all'),
                'receipts' => \XF::phrase('usips_ncmec_receipts')
            ]
        ];
        
        return $this->view('USIPS\\NCMEC:ApiLog\\Index', 'usips_ncmec_api_log_list', $viewParams);
    }

    public function actionView(ParameterBag $params)
    {
        $log = $this->assertApiLogExists($params->log_id, ['Report', 'Report.Case', 'User']);

        $requestBody = $this->prepareRequestBody($log->request_data);
        $responseBody = $this->prepareResponseBody($log->response_data);

        $viewParams = [
            'log' => $log,
            'requestBody' => $requestBody,
            'responseBody' => $responseBody,
            'requestMode' => $this->detectMode($requestBody),
            'responseMode' => $this->detectMode($responseBody),
        ];

        return $this->view('USIPS\\NCMEC:ApiLog\\View', 'usips_ncmec_api_log_view', $viewParams);
    }

    protected function detectMode($content)
    {
        $content = trim($content);
        if (strpos($content, '<') === 0)
        {
            return 'xml';
        }
        if (strpos($content, '{') === 0 || strpos($content, '[') === 0)
        {
            return 'json';
        }
        return 'text';
    }

    protected function prepareRequestBody($data)
    {
        if (!$data)
        {
            return '';
        }

        if (!is_array($data))
        {
            return (string) $data;
        }

        // If the data contains an 'xml' key, return the raw XML
        if (isset($data['xml']))
        {
            return $this->formatXml((string) $data['xml']);
        }

        // Otherwise, return formatted JSON
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false)
        {
            return '';
        }

        return $json;
    }

    protected function prepareResponseBody($data)
    {
        if ($data === null)
        {
            return '';
        }

        $data = trim((string) $data);

        if (strpos($data, '<') === 0)
        {
            return $this->formatXml($data);
        }

        return $data;
    }

    protected function formatXml($xml)
    {
        if (!$xml)
        {
            return '';
        }

        $dom = new \DOMDocument('1.0');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        // Suppress errors for invalid XML
        libxml_use_internal_errors(true);
        if ($dom->loadXML($xml))
        {
            return $dom->saveXML();
        }
        libxml_clear_errors();

        return $xml;
    }
}
