<?php

namespace USIPS\NCMEC\Service\Report;

use USIPS\NCMEC\Entity\CaseFile;
use USIPS\NCMEC\Entity\Report;
use USIPS\NCMEC\Entity\ReportFile;
use USIPS\NCMEC\Service\Api\Client;
use XF\Entity\User;
use XF\Service\AbstractService;

class Submitter extends AbstractService
{
    /** @var CaseFile */
    protected $case;

    /** @var User */
    protected $subject;

    /** @var Client */
    protected $apiClient;

    /** @var Report|null */
    protected $report;

    public function __construct(\XF\App $app, CaseFile $case, User $subject)
    {
        parent::__construct($app);
        $this->case = $case;
        $this->subject = $subject;
        $options = $this->app->options()->usipsNcmecApi;
        $this->apiClient = $this->service('USIPS\NCMEC:Api\Client', 
            $options['username'] ?? '', 
            $options['password'] ?? '',
            $options['environment'] ?? 'test'
        );
    }

    public function banUser()
    {
        if (!$this->subject->is_banned)
        {
            /** @var \XF\Repository\Banning $banRepo */
            $banRepo = $this->app->repository('XF:Banning');
            $banRepo->banUser($this->subject, 0, 'NCMEC Report', 'permanent');
        }
    }

    public function ensureReportOpened()
    {
        // 1. Ensure Report Entity Exists
        $this->report = $this->getOrCreateReport();
        $this->apiClient->setReportId($this->report->report_id);

        // 2. Open Report with NCMEC if not already opened
        if (!$this->report->ncmec_report_id)
        {
            $xml = $this->buildReportXml();
            $response = $this->apiClient->submitReport($xml);
            
            if ($response && (string)$response->responseCode === '0')
            {
                $this->report->ncmec_report_id = (int)$response->reportId;
                $this->report->save();
            }
            else
            {
                $error = $response ? (string)$response->responseDescription : 'Unknown error';
                throw new \Exception("Failed to submit report: " . $error);
            }
        }
        
        return $this->report;
    }

    public function processAttachment(\XF\Entity\AttachmentData $attachmentData)
    {
        $this->report = $this->getOrCreateReport();
        $this->apiClient->setReportId($this->report->report_id);

        if (!$this->report->ncmec_report_id)
        {
            throw new \Exception("Report must be opened before processing attachments.");
        }

        // Check if already processed for this report
        $reportFile = $this->finder('USIPS\NCMEC:ReportFile')
            ->where('report_id', $this->report->report_id)
            ->where('original_file_name', $attachmentData->filename)
            ->fetchOne();

        if (!$reportFile)
        {
            $reportFile = $this->em()->create('USIPS\NCMEC:ReportFile');
            $reportFile->report_id = $this->report->report_id;
            $reportFile->case_id = $this->case->case_id;
            $reportFile->ncmec_report_id = $this->report->ncmec_report_id;
            $reportFile->original_file_name = $attachmentData->filename;
            
            // Try to find an IP for this attachment
            $attachment = $this->finder('XF:Attachment')
                ->where('data_id', $attachmentData->data_id)
                ->fetchOne();
            
            if ($attachment)
            {
                $ip = $this->findIpForContent($attachment->content_type, $attachment->content_id);
                if ($ip)
                {
                    $reportFile->ip_capture_event = $ip;
                }
                
                if ($attachment->Container && method_exists($attachment->Container, 'getContentUrl'))
                {
                        $reportFile->location_of_file = $attachment->Container->getContentUrl(true);
                }
            }
            
            $reportFile->save();
        }

        if (!$reportFile->ncmec_file_id)
        {
            // Prepare file for upload
            $filePath = $attachmentData->getAbstractedDataPath();
            $tempFile = \XF\Util\File::getTempFile();
            
            try 
            {
                $fs = \XF::app()->fs();
                $stream = $fs->readStream($filePath);
                if ($stream)
                {
                    file_put_contents($tempFile, stream_get_contents($stream));
                    fclose($stream);
                }
                else
                {
                    if (file_exists($filePath))
                    {
                        copy($filePath, $tempFile);
                    }
                    else
                    {
                        // If file is missing, we can't upload it. 
                        // We should probably delete the data record and move on.
                        $attachmentData->delete();
                        return;
                    }
                }

                $response = $this->apiClient->uploadFile($this->report->ncmec_report_id, $tempFile);
                
                if ($response && (string)$response->responseCode === '0')
                {
                    $reportFile->ncmec_file_id = (string)$response->fileId;
                    $reportFile->save();

                    // Submit File Details
                    $detailsXml = $this->buildFileDetailsXml($reportFile);
                    $this->apiClient->submitFileDetails($detailsXml);

                    // Delete Data - This is the critical step requested
                    // Deleting AttachmentData triggers XF's cleanup process which removes the file from storage
                    $attachmentData->delete();
                }
                else
                {
                    $error = $response ? (string)$response->responseDescription : 'Unknown upload error';
                    \XF::logError("NCMEC Upload Failed for data_id {$attachmentData->data_id}: $error");
                    // Throwing exception to retry or handle in job
                    throw new \Exception("Upload failed: $error");
                }
            }
            finally
            {
                if (file_exists($tempFile))
                {
                    @unlink($tempFile);
                }
            }
        }
        else
        {
            // Already uploaded, ensure data is deleted
            $attachmentData->delete();
        }
    }

    public function deleteContent()
    {
        // Delete Incident Content (Posts, Threads, etc)
        $incidentContents = $this->finder('USIPS\NCMEC:IncidentContent')
            ->where('user_id', $this->subject->user_id)
            ->with('Incident', true)
            ->where('Incident.case_id', $this->case->case_id)
            ->fetch();

        foreach ($incidentContents as $content)
        {
            $entity = $content->getContent();
            if ($entity)
            {
                // Hard delete the content
                $entity->delete();
            }
            // Delete the incident content record
            $content->delete();
        }
    }

    public function finishReport()
    {
        $this->report = $this->getOrCreateReport();
        $this->apiClient->setReportId($this->report->report_id);

        // 4. Finish Report
        if (!$this->report->is_finished && $this->report->ncmec_report_id)
        {
            $response = $this->apiClient->finishReport($this->report->ncmec_report_id);
            
            if ($response && (string)$response->responseCode === '0')
            {
                $this->report->is_finished = true;
                $this->report->save();
            }
            else
            {
                $error = $response ? (string)$response->responseDescription : 'Unknown error';
                throw new \Exception("Failed to finish report: " . $error);
            }
        }
    }

    protected function getOrCreateReport(): Report
    {
        $report = $this->finder('USIPS\NCMEC:Report')
            ->where('case_id', $this->case->case_id)
            ->where('subject_user_id', $this->subject->user_id)
            ->fetchOne();

        if (!$report)
        {
            $report = $this->em()->create('USIPS\NCMEC:Report');
            $report->case_id = $this->case->case_id;
            $report->user_id = \XF::visitor()->user_id;
            $report->username = \XF::visitor()->username;
            $report->subject_user_id = $this->subject->user_id;
            $report->subject_username = $this->subject->username;
            $report->save();
        }

        return $report;
    }

    protected function findIpForContent($contentType, $contentId)
    {
        $ipLog = $this->finder('XF:Ip')
            ->where('content_type', $contentType)
            ->where('content_id', $contentId)
            ->order('log_date', 'DESC')
            ->fetchOne();
        
        return $ipLog ? $ipLog->ip : null;
    }

    protected function buildReportXml(): string
    {
        $incidentType = Client::INCIDENT_TYPE_VALUES[$this->case->incident_type] ?? $this->case->incident_type;
        
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><report xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="https://report.cybertip.org/ispws/xsd"></report>');
        
        // Incident Summary
        $summary = $xml->addChild('incidentSummary');
        $summary->addChild('incidentType', htmlspecialchars($incidentType));
        $summary->addChild('incidentDateTime', gmdate('c', $this->case->created_date));

        // Annotations
        if ($this->case->report_annotations)
        {
            $annotations = $summary->addChild('reportAnnotations');
            foreach ($this->case->report_annotations as $annotation)
            {
                $apiValue = Client::REPORT_ANNOTATION_VALUES[$annotation] ?? $annotation;
                $annotations->addChild($apiValue);
            }
        }

        // Reporter
        $reporter = $xml->addChild('reporter');
        $reportingPerson = $reporter->addChild('reportingPerson');
        
        $reporterPerson = $this->case->Reporter;
        if ($reporterPerson)
        {
            if ($reporterPerson->first_name) $reportingPerson->addChild('firstName', htmlspecialchars($reporterPerson->first_name));
            if ($reporterPerson->last_name) $reportingPerson->addChild('lastName', htmlspecialchars($reporterPerson->last_name));
            if ($reporterPerson->emails) $reportingPerson->addChild('email', htmlspecialchars($reporterPerson->emails));
        }
        else
        {
            $reportingPerson->addChild('email', htmlspecialchars($this->app->options()->contactEmailAddress));
        }

        // Person Or User Reported
        $personReported = $xml->addChild('personOrUserReported');
        $personReported->addChild('screenName', htmlspecialchars($this->subject->username));
        if ($this->subject->email)
        {
            $personReported->addChild('email', htmlspecialchars($this->subject->email));
        }
        
        // IP Capture for User (Registration IP)
        $regIp = $this->finder('XF:Ip')
            ->where('user_id', $this->subject->user_id)
            ->where('action', 'register')
            ->fetchOne();
            
        if ($regIp)
        {
            $ipEvent = $personReported->addChild('ipCaptureEvent');
            $ipEvent->addChild('ipAddress', \XF\Util\Ip::binaryToString($regIp->ip));
            $ipEvent->addChild('eventName', 'Registration');
            $ipEvent->addChild('dateTime', gmdate('c', $regIp->log_date));
        }

        // Internet Details (URLs)
        $internetDetails = $xml->addChild('internetDetails');
        
        $contentUrls = $this->getIncidentContentUrls();
        foreach ($contentUrls as $url)
        {
            $webPage = $internetDetails->addChild('webPageIncident');
            $webPage->addChild('url', htmlspecialchars($url));
        }

        return $xml->asXML();
    }

    protected function buildFileDetailsXml(ReportFile $reportFile): string
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><fileDetails xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="https://report.cybertip.org/ispws/xsd"></fileDetails>');
        
        $xml->addChild('reportId', $reportFile->ncmec_report_id);
        $xml->addChild('fileId', $reportFile->ncmec_file_id);
        $xml->addChild('originalFileName', htmlspecialchars($reportFile->original_file_name));
        
        if ($reportFile->ip_capture_event)
        {
            $ipStr = \XF\Util\Ip::binaryToString($reportFile->ip_capture_event);
            if ($ipStr)
            {
                $ipEvent = $xml->addChild('ipCaptureEvent');
                $ipEvent->addChild('ipAddress', $ipStr);
                $ipEvent->addChild('eventName', 'Upload');
            }
        }

        return $xml->asXML();
    }

    protected function getIncidentContentUrls(): array
    {
        $urls = [];
        
        $incidentContents = $this->finder('USIPS\NCMEC:IncidentContent')
            ->where('user_id', $this->subject->user_id)
            ->with('Incident', true)
            ->where('Incident.case_id', $this->case->case_id)
            ->fetch();
            
        foreach ($incidentContents as $content)
        {
            $entity = $content->getContent();
            if ($entity && method_exists($entity, 'getContentUrl'))
            {
                $urls[] = $entity->getContentUrl(true);
            }
        }
        
        return array_unique($urls);
    }
}
