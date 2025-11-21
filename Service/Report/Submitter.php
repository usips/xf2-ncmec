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

    /** @var \XF\Mvc\Entity\AbstractCollection|array */
    protected $subjects;

    /** @var Client */
    protected $apiClient;

    /** @var Report|null */
    protected $report;

    public function __construct(\XF\App $app, CaseFile $case, $subjects)
    {
        parent::__construct($app);
        $this->case = $case;

        if ($subjects instanceof User)
        {
            $subjects = [$subjects];
        }
        elseif ($subjects instanceof \XF\Mvc\Entity\AbstractCollection)
        {
            $subjects = $subjects->toArray();
        }
        
        $this->subjects = $subjects;
        $this->subject = reset($subjects) ?: null;

        if (!$this->subject)
        {
            throw new \InvalidArgumentException("Submitter requires at least one subject user.");
        }
        
        // Ensure required relations are loaded
        $this->case->hydrateRelation('Reporter', $this->case->Reporter);
        $this->case->hydrateRelation('ReportedPerson', $this->case->ReportedPerson);

        foreach ($this->subjects as $subject)
        {
            $subject->hydrateRelation('Profile', $subject->Profile);
            $subject->hydrateRelation('ConnectedAccounts', $subject->ConnectedAccounts);
        }
        
        $options = $this->app->options()->usipsNcmecApi;
        $this->apiClient = $this->service('USIPS\NCMEC:Api\Client', 
            $options['username'] ?? '', 
            $options['password'] ?? '',
            !empty($options['environment']) ? $options['environment'] : 'test'
        );
    }

    public function banUser()
    {
        /** @var \XF\Repository\Banning $banRepo */
        $banRepo = $this->app->repository('XF:Banning');

        foreach ($this->subjects as $subject)
        {
            if (!$subject->is_banned)
            {
                $error = null;
                $banRepo->banUser($subject, 0, 'NCMEC Report', $error);
            }
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
        // Entity deletion methods handle all cleanup including attachments
        
        $userIds = [];
        foreach ($this->subjects as $subject)
        {
            $userIds[] = $subject->user_id;
        }

        $incidentContents = $this->finder('USIPS\NCMEC:IncidentContent')
            ->where('user_id', $userIds)
            ->with('Incident', true)
            ->where('Incident.case_id', $this->case->case_id)
            ->fetch();

        foreach ($incidentContents as $content)
        {
            $entity = $content->getContent();
            if ($entity)
            {
                try
                {
                    // Direct entity deletion:
                    // - Posts: _postDelete() removes attachments, updates thread
                    // - Threads: _postDelete() removes all posts and their attachments
                    // - ProfilePosts: _postDelete() removes attachments
                    // First posts automatically trigger thread deletion via isFirstPost() check
                    if ($entity instanceof \XF\Entity\Post && $entity->isFirstPost() && $entity->Thread)
                    {
                        $entity->Thread->delete();
                    }
                    else
                    {
                        $entity->delete();
                    }
                }
                catch (\Exception $e)
                {
                    // Log error but continue processing other content
                    \XF::logException($e, false, "Failed to delete content entity {$entity->getEntityContentType()}:{$entity->getEntityId()}: ");
                }
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
        if (!$this->report->finished_on && $this->report->ncmec_report_id)
        {
            $response = $this->apiClient->finishReport($this->report->ncmec_report_id);
            
            if ($response && (string)$response->responseCode === '0')
            {
                $this->report->finished_on = \XF::$time;
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
        $finder = $this->finder('USIPS\NCMEC:Report')
            ->where('case_id', $this->case->case_id);

        // If we have a reported person ID, we are doing a single report for the case
        // Otherwise, we are doing a report for the specific subject
        if (!$this->case->reported_person_id)
        {
            $finder->where('subject_user_id', $this->subject->user_id);
        }

        $report = $finder->fetchOne();

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

    /**
     * Generate the XML for preview purposes
     * 
     * @return string
     */
    public function getPreviewXml(): string
    {
        return $this->buildReportXml();
    }

    protected function buildReportXml(): string
    {
        $incidentType = Client::INCIDENT_TYPE_VALUES[$this->case->incident_type] ?? $this->case->incident_type;
        
        // Validate incident type is not empty
        if (empty($incidentType))
        {
            throw new \Exception("Case incident_type is required but was empty. Case ID: {$this->case->case_id}");
        }
        
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><report xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="https://report.cybertip.org/ispws/xsd"></report>');
        
        // ===== INCIDENT SUMMARY (required, first) =====
        $this->buildIncidentSummary($xml);

        // ===== INTERNET DETAILS (optional, after incidentSummary, before reporter per XSD) =====
        $this->buildInternetDetails($xml);

        // ===== REPORTER (required) =====
        $this->buildReporter($xml);

        // ===== PERSON OR USER REPORTED (optional, after reporter) =====
        $this->buildPersonOrUserReported($xml);

        // ===== ADDITIONAL INFO (optional, last) =====
        if (!empty($this->case->additional_info))
        {
            $xml->addChild('additionalInfo', htmlspecialchars($this->case->additional_info));
        }

        $xmlString = $xml->asXML();
        
        // Validate XML against XSD if available
        $this->validateXml($xmlString);
        
        return $xmlString;
    }
    
    /**
     * Build incidentSummary section
     */
    protected function buildIncidentSummary(\SimpleXMLElement $xml): void
    {
        $incidentType = Client::INCIDENT_TYPE_VALUES[$this->case->incident_type] ?? $this->case->incident_type;
        
        $summary = $xml->addChild('incidentSummary');
        $summary->addChild('incidentType', htmlspecialchars($incidentType));
        
        // Optional: platform
        $options = $this->app->options();
        if (!empty($options->boardTitle))
        {
            $summary->addChild('platform', htmlspecialchars($options->boardTitle));
        }

        // Report annotations (optional)
        if ($this->case->report_annotations && !empty($this->case->report_annotations))
        {
            $annotations = $summary->addChild('reportAnnotations');
            foreach ($this->case->report_annotations as $annotation)
            {
                $apiValue = Client::REPORT_ANNOTATION_VALUES[$annotation] ?? $annotation;
                $annotations->addChild($apiValue);
            }
        }

        // Incident date/time (required)
        $summary->addChild('incidentDateTime', gmdate('c', $this->case->created_date));
        
        // Optional description
        if (!empty($this->case->incident_date_time_desc))
        {
            $summary->addChild('incidentDateTimeDescription', htmlspecialchars($this->case->incident_date_time_desc));
        }
    }
    
    /**
     * Build internetDetails section with all incident-related URLs
     */
    protected function buildInternetDetails(\SimpleXMLElement $xml): void
    {
        // Collect all web page incidents from case incidents
        $incidents = $this->finder('USIPS\NCMEC:Incident')
            ->where('case_id', $this->case->case_id)
            ->fetch();
            
        foreach ($incidents as $incident)
        {
            // Get content URLs
            $contentItems = $this->finder('USIPS\NCMEC:IncidentContent')
                ->where('incident_id', $incident->incident_id)
                ->fetch();
                
            foreach ($contentItems as $contentItem)
            {
                $entity = $contentItem->getContent();
                if ($entity && method_exists($entity, 'getContentUrl'))
                {
                    $internetDetails = $xml->addChild('internetDetails');
                    $webPage = $internetDetails->addChild('webPageIncident');
                    $webPage->addChild('url', htmlspecialchars($entity->getContentUrl(true)));
                    
                    // Note: uploadDateTime is not allowed in webPageIncident per XSD
                }
            }
            
            // Get user profile URLs
            $userItems = $this->finder('USIPS\NCMEC:IncidentUser')
                ->where('incident_id', $incident->incident_id)
                ->with('User', true)
                ->fetch();
                
            foreach ($userItems as $userItem)
            {
                if ($userItem->User)
                {
                    $internetDetails = $xml->addChild('internetDetails');
                    $webPage = $internetDetails->addChild('webPageIncident');
                    
                    $router = $this->app->router('public');
                    $profileUrl = $router->buildLink('canonical:members', $userItem->User);
                    $webPage->addChild('url', htmlspecialchars($profileUrl));
                }
            }
        }
    }
    
    /**
     * Build reporter section
     */
    protected function buildReporter(\SimpleXMLElement $xml): void
    {
        $reporter = $xml->addChild('reporter');
        
        // Reporting person (required)
        $reportingPerson = $reporter->addChild('reportingPerson');
        
        $options = $this->app->options();
        $reporterPersonId = $options->usipsNcmecReporterContactPerson ?? 0;
        $reporterPerson = $reporterPersonId ? $this->em()->find('USIPS\NCMEC:Person', $reporterPersonId) : null;
        
        if (!$reporterPerson)
        {
            $reporterPerson = $this->case->Reporter;
        }
        
        if ($reporterPerson)
        {
            $this->addPersonData($reportingPerson, $reporterPerson, 'person');
        }
        else
        {
            // Fallback: use contact email
            $email = $reportingPerson->addChild('email');
            $email[0] = htmlspecialchars($options->contactEmailAddress ?? 'noreply@example.com');
        }
        
        // Contact person (optional, different from reporting person)
        $contactPersonId = $options->usipsNcmecReporterContactPerson ?? 0;
        if ($contactPersonId && (!$reporterPerson || $reporterPerson->person_id != $contactPersonId))
        {
            $contactPersonEntity = $this->em()->find('USIPS\NCMEC:Person', $contactPersonId);
            if ($contactPersonEntity)
            {
                $contactPerson = $reporter->addChild('contactPerson');
                $this->addPersonData($contactPerson, $contactPersonEntity, 'contactPerson');
            }
        }
        
        // Company template (optional)
        if (!empty($options->usipsNcmecReporterCompanyTemplate))
        {
            $reporter->addChild('companyTemplate', htmlspecialchars($options->usipsNcmecReporterCompanyTemplate));
        }
        
        // Terms of Service URL (optional)
        $router = $this->app->router('public');
        $tosUrl = $router->buildLink('canonical:help/terms');
        if ($tosUrl)
        {
            $reporter->addChild('termsOfService', htmlspecialchars($tosUrl));
        }
    }
    
    /**
     * Build personOrUserReported section
     */
    protected function buildPersonOrUserReported(\SimpleXMLElement $xml): void
    {
        $personReported = $xml->addChild('personOrUserReported');
        
        // Person info if available from case
        if ($this->case->reported_person_id && $this->case->ReportedPerson)
        {
            $personElement = $personReported->addChild('personOrUserReportedPerson');
            $this->addPersonData($personElement, $this->case->ReportedPerson, 'person');

            // If we are collapsing multiple users, add their emails to the person data
            if (count($this->subjects) > 1)
            {
                $addedEmails = [];
                // Pre-fill with existing emails from person record to avoid dupes
                if (!empty($this->case->ReportedPerson->emails))
                {
                    $existing = is_array($this->case->ReportedPerson->emails) 
                        ? $this->case->ReportedPerson->emails 
                        : explode(',', $this->case->ReportedPerson->emails);
                    foreach ($existing as $e) $addedEmails[trim($e)] = true;
                }

                foreach ($this->subjects as $subject)
                {
                    if ($subject->email && !isset($addedEmails[$subject->email]))
                    {
                        $email = $personElement->addChild('email');
                        $email[0] = htmlspecialchars($subject->email);
                        $addedEmails[$subject->email] = true;
                    }
                }
            }
        }
        
        // ESP Identifier (user ID)
        $personReported->addChild('espIdentifier', (string)$this->subject->user_id);
        
        // ESP Service
        $options = $this->app->options();
        if (!empty($options->boardTitle))
        {
            $personReported->addChild('espService', htmlspecialchars($options->boardTitle));
        }
        
        // Screen name (username)
        $screenName = $personReported->addChild('screenName');
        $screenName[0] = htmlspecialchars($this->subject->username);
        
        // Display name (if different from username)
        if ($this->subject->Profile && !empty($this->subject->Profile->custom_title))
        {
            $personReported->addChild('displayName', htmlspecialchars($this->subject->Profile->custom_title));
        }

        // If collapsing multiple users, add their usernames as display names
        if (count($this->subjects) > 1)
        {
            foreach ($this->subjects as $subject)
            {
                if ($subject->user_id !== $this->subject->user_id)
                {
                    $personReported->addChild('displayName', htmlspecialchars($subject->username));
                }
            }
        }
        
        // Profile URL
        $router = $this->app->router('public');
        $profileUrl = $router->buildLink('canonical:members', $this->subject);
        $personReported->addChild('profileUrl', htmlspecialchars($profileUrl));

        // If collapsing multiple users, add their profile URLs
        if (count($this->subjects) > 1)
        {
            foreach ($this->subjects as $subject)
            {
                if ($subject->user_id !== $this->subject->user_id)
                {
                    $url = $router->buildLink('canonical:members', $subject);
                    $personReported->addChild('profileUrl', htmlspecialchars($url));
                }
            }
        }
        
        // Profile Bio (about/signature)
        // Find the first user with bio info if the main subject doesn't have it, or just use main subject
        $bioSubject = $this->subject;
        if (empty($bioSubject->Profile->about) && empty($bioSubject->Profile->signature))
        {
            foreach ($this->subjects as $subject)
            {
                if (!empty($subject->Profile->about) || !empty($subject->Profile->signature))
                {
                    $bioSubject = $subject;
                    break;
                }
            }
        }

        if ($bioSubject->Profile)
        {
            $bio = '';
            if (!empty($bioSubject->Profile->about))
            {
                $bio .= "About:\n" . $bioSubject->Profile->about;
            }
            if (!empty($bioSubject->Profile->signature))
            {
                if ($bio) $bio .= "\n\n";
                $bio .= "Signature:\n" . $bioSubject->Profile->signature;
            }
            if ($bio)
            {
                $personReported->addChild('profileBio', htmlspecialchars($bio));
            }
        }
        
        // IP Capture Events - all IPs associated with ALL users if collapsing
        if ($this->case->reported_person_id)
        {
            foreach ($this->subjects as $subject)
            {
                $this->addIpCaptureEvents($personReported, $subject->user_id);
            }
        }
        else
        {
            $this->addIpCaptureEvents($personReported, $this->subject->user_id);
        }
        
        // Account disabled status
        $this->addBanStatus($personReported);
        
        // Associated accounts (connected accounts)
        foreach ($this->subjects as $subject)
        {
            if ($subject->ConnectedAccounts && $subject->ConnectedAccounts->count() > 0)
            {
                foreach ($subject->ConnectedAccounts as $connectedAccount)
                {
                    $associatedAccount = $personReported->addChild('associatedAccount');
                    $associatedAccount->addChild('accountType', 'Other');
                    $associatedAccount->addChild('accountDisplayName', htmlspecialchars($connectedAccount->provider));
                    if (!empty($connectedAccount->provider_key))
                    {
                        $associatedAccount->addChild('accountIdentifier', htmlspecialchars($connectedAccount->provider_key));
                    }
                }
            }
        }
        
        // Additional info
        if (!empty($this->case->reported_additional_info))
        {
            $personReported->addChild('additionalInfo', htmlspecialchars($this->case->reported_additional_info));
        }
    }
    
    /**
     * Add ban status to personOrUserReported
     * 
     * @param \SimpleXMLElement $element Parent XML element
     */
    protected function addBanStatus(\SimpleXMLElement $element): void
    {
        if (!$this->subject->is_banned)
        {
            return;
        }

        $ban = $this->em()->find('XF:UserBan', $this->subject->user_id);
        if (!$ban)
        {
            return;
        }

        $tagName = ($ban->end_date == 0) ? 'accountPermanentlyDisabled' : 'accountTemporarilyDisabled';
        $banElement = $element->addChild($tagName, 'true');
        
        $banDate = gmdate('c', $ban->ban_date);
        
        $banElement->addAttribute('disabledDate', $banDate);
        $banElement->addAttribute('userNotified', 'true');
        $banElement->addAttribute('userNotifiedDate', $banDate);
    }
    
    /**
     * Add person data to XML element
     * 
     * @param \SimpleXMLElement $element Parent XML element
     * @param \USIPS\NCMEC\Entity\Person $person Person entity
     * @param string $type 'person' or 'contactPerson' (contactPerson excludes age/DOB)
     */
    protected function addPersonData(\SimpleXMLElement $element, $person, string $type = 'person'): void
    {
        if (!empty($person->first_name))
        {
            $element->addChild('firstName', htmlspecialchars($person->first_name));
        }
        
        if (!empty($person->last_name))
        {
            $element->addChild('lastName', htmlspecialchars($person->last_name));
        }
        
        // Phones (can be multiple, comma-separated or JSON)
        if (!empty($person->phones))
        {
            $phones = is_array($person->phones) ? $person->phones : explode(',', $person->phones);
            foreach ($phones as $phoneStr)
            {
                $phoneStr = trim($phoneStr);
                if ($phoneStr)
                {
                    $phone = $element->addChild('phone');
                    $phone->addChild('number', htmlspecialchars($phoneStr));
                }
            }
        }
        
        // Emails (can be multiple, comma-separated or JSON)
        if (!empty($person->emails))
        {
            $emails = is_array($person->emails) ? $person->emails : explode(',', $person->emails);
            foreach ($emails as $emailStr)
            {
                $emailStr = trim($emailStr);
                if ($emailStr)
                {
                    $email = $element->addChild('email');
                    $email[0] = htmlspecialchars($emailStr);
                }
            }
        }
        
        // Addresses (if available)
        if (!empty($person->addresses))
        {
            // Parse address data if it's JSON or structured
            $addresses = is_array($person->addresses) ? $person->addresses : [$person->addresses];
            foreach ($addresses as $addressData)
            {
                if (is_string($addressData))
                {
                    $address = $element->addChild('address');
                    $address->addChild('address', htmlspecialchars($addressData));
                }
            }
        }
        
        // Age and DOB only for 'person' type, not 'contactPerson'
        if ($type === 'person')
        {
            if ($person->age !== null)
            {
                $element->addChild('age', (string)$person->age);
            }
            
            if (!empty($person->date_of_birth))
            {
                $element->addChild('dateOfBirth', htmlspecialchars($person->date_of_birth));
            }
        }
    }
    
    /**
     * Add IP capture events for a user
     * 
     * @param \SimpleXMLElement $element Parent XML element
     * @param int $userId User ID
     */
    protected function addIpCaptureEvents(\SimpleXMLElement $element, int $userId): void
    {
        $page = 1;
        $perPage = 500;
        $addedIps = []; // Track IPs we've already added to avoid duplicates

        while (true)
        {
            // Get IPs for this user, grouped by action/context
            $ipLogs = $this->finder('XF:Ip')
                ->where('user_id', $userId)
                ->order('log_date', 'DESC')
                ->limitByPage($page, $perPage)
                ->fetch();

            if (!$ipLogs->count())
            {
                break;
            }
            
            foreach ($ipLogs as $ipLog)
            {
                $ipString = \XF\Util\Ip::binaryToString($ipLog->ip);
                $key = $ipString . '_' . $ipLog->action;
                
                // Avoid duplicate IP/action combinations
                if (isset($addedIps[$key]))
                {
                    continue;
                }
                $addedIps[$key] = true;
                
                $ipEvent = $element->addChild('ipCaptureEvent');
                $ipEvent->addChild('ipAddress', $ipString);
                
                // Map XenForo actions to descriptive event names
                $eventName = $this->getIpEventName($ipLog->action, $ipLog->content_type);
                $ipEvent->addChild('eventName', htmlspecialchars($eventName));
                $ipEvent->addChild('dateTime', gmdate('c', $ipLog->log_date));
            }

            $page++;
        }
    }
    
    /**
     * Get human-readable event name for IP log
     * Must return one of: 'Login', 'Registration', 'Purchase', 'Upload', 'Other', 'Unknown'
     * 
     * @param string $action XenForo action
     * @param string|null $contentType Content type if applicable
     * @return string Event name from allowed enumeration
     */
    protected function getIpEventName(string $action, ?string $contentType): string
    {
        $map = [
            'register' => 'Registration',
            'login' => 'Login',
            'cookie_login' => 'Login',
            'post' => 'Upload',
            'thread' => 'Upload',
            'profile_post' => 'Upload',
            'conversation_message' => 'Upload',
            'insert' => 'Upload',
        ];
        
        // Check action directly
        $lowerAction = strtolower($action);
        if (isset($map[$lowerAction]))
        {
            return $map[$lowerAction];
        }
        
        // Default to 'Other' for unmapped actions
        return 'Other';
    }
    
    /**
     * Validate XML against NCMEC XSD schema
     * 
     * @param string $xmlString XML to validate
     * @throws \Exception if validation fails
     */
    protected function validateXml(string $xmlString): void
    {
        // Get cached XSD or download if needed
        $xsdContent = $this->getCachedXsd();
        
        if (!$xsdContent)
        {
            // XSD not available, skip validation (will rely on API validation)
            \XF::logError("NCMEC: XSD not available for validation, skipping local validation for Case #{$this->case->case_id}");
            return;
        }
        
        // Create DOMDocument for validation
        $dom = new \DOMDocument();
        $dom->loadXML($xmlString);
        
        // Create temporary file for XSD
        $xsdFile = \XF\Util\File::getTempFile();
        file_put_contents($xsdFile, $xsdContent);
        
        try
        {
            // Enable user error handling for detailed validation messages
            libxml_use_internal_errors(true);
            libxml_clear_errors();
            
            if (!$dom->schemaValidate($xsdFile))
            {
                $errors = libxml_get_errors();
                $errorMessages = [];
                
                foreach ($errors as $error)
                {
                    $errorMessages[] = sprintf(
                        "Line %d: %s",
                        $error->line,
                        trim($error->message)
                    );
                }
                
                libxml_clear_errors();
                
                // Log the XML that failed validation
                \XF::logError(sprintf(
                    "NCMEC XML Validation Failed for Case #%d, User #%d:\n\nErrors:\n%s\n\nXML:\n%s",
                    $this->case->case_id,
                    $this->subject->user_id,
                    implode("\n", $errorMessages),
                    $xmlString
                ));
                
                throw new \Exception(
                    "XML validation failed:\n" . implode("\n", $errorMessages)
                );
            }
            
            libxml_clear_errors();
        }
        finally
        {
            @unlink($xsdFile);
        }
    }
    
    /**
     * Get cached XSD or download if needed
     * Uses both temp file storage (persistent) and cache (if available)
     * 
     * @return string|null XSD content or null if unavailable
     */
    protected function getCachedXsd(): ?string
    {
        $environment = $this->apiClient->getEnvironment();
        $cacheKey = 'usipsNcmecXsd_' . $environment;
        
        // Try temp file first (persistent across requests)
        $tempDir = \XF\Util\File::getTempDir();
        $xsdTempFile = $tempDir . '/ncmec_xsd_' . $environment . '.xsd';
        
        // Check if temp file exists and is fresh (less than 24 hours old)
        if (file_exists($xsdTempFile) && (time() - filemtime($xsdTempFile)) < 86400)
        {
            return file_get_contents($xsdTempFile);
        }
        
        // Try cache next
        $cache = $this->app->cache();
        if ($cache)
        {
            $xsdContent = $cache->fetch($cacheKey);
            if ($xsdContent !== false)
            {
                // Store in temp file for persistence
                file_put_contents($xsdTempFile, $xsdContent);
                return $xsdContent;
            }
        }
        
        // Download XSD as last resort
        $xsdContent = $this->apiClient->downloadXsd();
        
        if ($xsdContent)
        {
            // Store in both temp file and cache
            file_put_contents($xsdTempFile, $xsdContent);
            if ($cache)
            {
                $cache->save($cacheKey, $xsdContent, 86400);
            }
        }
        
        return $xsdContent ?: null;
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

        $xmlString = $xml->asXML();
        
        // Validate XML against XSD if available
        $this->validateXml($xmlString);
        
        return $xmlString;
    }
}
