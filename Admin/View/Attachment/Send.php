<?php

namespace USIPS\NCMEC\Admin\View\Attachment;

use League\Flysystem\FileNotFoundException;

use function pathinfo;

class Send extends \XF\Mvc\View
{
    public function renderRaw()
    {
        if (!empty($this->params['return304']))
        {
            $this->response
                ->httpCode(304)
                ->removeHeader('last-modified');

            return '';
        }

        $filename = $this->params['filename'];
        $extension = $this->params['extension'] ?? pathinfo($filename, PATHINFO_EXTENSION);

        $this->response->setAttachmentFileParams($filename, $extension);

        if (!empty($this->params['etag']))
        {
            $this->response->header('ETag', '"' . $this->params['etag'] . '"');
        }

        if (!empty($this->params['sendfileHeader']))
        {
            [$header, $value] = $this->params['sendfileHeader'];
            $this->response->header($header, $value);

            if (!empty($this->params['fileSize']))
            {
                $this->response->header('Content-Length', (string) $this->params['fileSize']);
            }

            return '';
        }

        $abstractedPath = $this->params['abstractedPath'];
        $fileSize = $this->params['fileSize'];

        try
        {
            $stream = \XF::fs()->readStream($abstractedPath);
        }
        catch (FileNotFoundException $e)
        {
            $this->response->httpCode(404);
            return '';
        }

        return $this->response->responseStream($stream, $fileSize);
    }
}
