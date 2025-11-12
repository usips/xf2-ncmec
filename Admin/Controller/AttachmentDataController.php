<?php

namespace USIPS\NCMEC\Admin\Controller;

use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;

class AttachmentDataController extends AbstractController
{
    use AttachmentDeliveryTrait;

    protected function preDispatchController($action, ParameterBag $params)
    {
        $this->assertAdminPermission('usips_ncmec');
        $this->assertAdminPermission('attachment');
    }

    public function actionView(ParameterBag $params)
    {
        $data = $this->assertAttachmentDataExists($params->data_id);

        return $this->prepareAttachmentSendReply(
            $data,
            $data->filename,
            $data->upload_date
        );
    }
}