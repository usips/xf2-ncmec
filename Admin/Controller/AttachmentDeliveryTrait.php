<?php

namespace USIPS\NCMEC\Admin\Controller;

use XF\Entity\Attachment;
use XF\Entity\AttachmentData;

use function explode;
use function file_exists;
use function function_exists;
use function in_array;
use function is_dir;
use function ltrim;
use function pathinfo;
use function realpath;
use function strpos;
use function str_replace;
use function strtolower;
use function trim;

trait AttachmentDeliveryTrait
{
    protected function assertAttachmentDataExists($id, $with = null)
    {
        return $this->assertRecordExists('XF:AttachmentData', $id, $with);
    }

    protected function prepareAttachmentSendReply(
        AttachmentData $data,
        string $filename,
        ?int $eTagValue = null,
        ?Attachment $attachment = null
    ) {
        if (!$data->isDataAvailable())
        {
            return $this->error(\XF::phrase('attachment_cannot_be_shown_at_this_time'));
        }

        $this->setResponseType('raw');

        $abstractedPath = $data->getAbstractedDataPath();
        $extension = pathinfo($filename, PATHINFO_EXTENSION) ?: '';
        $return304 = $this->shouldReturnNotModified($eTagValue);
        $sendfileHeader = $this->getSendfileHeaderForPath($abstractedPath);

        return $this->view('USIPS\\NCMEC:Attachment\\Send', '', [
            'attachment' => $attachment,
            'data' => $data,
            'filename' => $filename,
            'extension' => $extension,
            'fileSize' => $data->file_size,
            'abstractedPath' => $abstractedPath,
            'etag' => $eTagValue,
            'return304' => $return304,
            'sendfileHeader' => $sendfileHeader,
        ]);
    }

    protected function shouldReturnNotModified(?int $eTagValue): bool
    {
        if ($eTagValue === null)
        {
            return false;
        }

        $incoming = $this->request->getServer('HTTP_IF_NONE_MATCH');
        if (!$incoming)
        {
            return false;
        }

        $expected = '"' . $eTagValue . '"';
        foreach (explode(',', $incoming) as $candidate)
        {
            if (trim($candidate) === $expected)
            {
                return true;
            }
        }

        return false;
    }

    protected function getSendfileHeaderForPath(string $abstractedPath): ?array
    {
        $parts = explode('://', $abstractedPath, 2);
        if (count($parts) !== 2)
        {
            return null;
        }

        [$scheme, $path] = $parts;
        $path = ltrim($path, '/');

        switch ($scheme)
        {
            case 'internal-data':
                $baseDir = 'internal_data';
                break;

            case 'data':
                $baseDir = 'data';
                break;

            default:
                return null;
        }

        $root = \XF::getRootDirectory();
        $absolute = $root . '/' . $baseDir . '/' . $path;
        if (!file_exists($absolute))
        {
            return null;
        }

        $hiddenBase = '.' . str_replace('_', '-', $baseDir);
        $publicBase = is_dir($root . '/' . $hiddenBase) ? $hiddenBase : $baseDir;
        $publicPath = '/' . $publicBase . '/' . $path;

        if (function_exists('apache_get_modules')
            && in_array('mod_xsendfile', apache_get_modules(), true)
        ) {
            $realPath = realpath($absolute) ?: $absolute;
            return ['X-Sendfile', $realPath];
        }

        $server = strtolower($_SERVER['SERVER_SOFTWARE'] ?? '');
        if (strpos($server, 'nginx') !== false)
        {
            return ['X-Accel-Redirect', $publicPath];
        }

        if (strpos($server, 'lighttpd') !== false)
        {
            $realPath = realpath($absolute) ?: $absolute;
            return ['X-LIGHTTPD-send-file', $realPath];
        }

        return null;
    }
}