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
        
        $logFinder = $this->finder('USIPS\\NCMEC:ApiLog')
            ->with(['Report', 'Report.Case', 'User'])
            ->order('request_date', 'DESC')
            ->limitByPage($page, $perPage);
        
        $logs = $logFinder->fetch();
        $total = $logFinder->total();
        
        $viewParams = [
            'logs' => $logs,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
        ];
        
        return $this->view('USIPS\\NCMEC:ApiLog\\Index', 'usips_ncmec_api_log_list', $viewParams);
    }

    public function actionView(ParameterBag $params)
    {
        $log = $this->assertApiLogExists($params->log_id, ['Report', 'Report.Case', 'User']);

        $viewParams = [
            'log' => $log,
            'requestBody' => $this->prepareRequestBody($log->request_data),
            'responseBody' => $this->prepareResponseBody($log->response_data),
        ];

        return $this->view('USIPS\\NCMEC:ApiLog\\View', 'usips_ncmec_api_log_view', $viewParams);
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
            return (string) $data['xml'];
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

        return trim((string) $data);
    }
}
