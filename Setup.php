<?php

namespace USIPS\NCMEC;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\Db\Schema\Alter;
use XF\Db\Schema\Create;
use USIPS\NCMEC\Service\Api\Client;

class Setup extends AbstractSetup
{
    // Dispatches the upgrade{VersionId}Step{N}() methods below. Before 1.3.1
    // upgrade() was an empty override, so versioned steps never ran.
    use StepRunnerUpgradeTrait;

    public function install(array $stepParams = [])
    {
        $this->schemaManager()->createTable('xf_usips_ncmec_case', function(Create $table)
        {
            $table->addColumn('case_id', 'int')->autoIncrement();
            $table->addColumn('title', 'varchar', 255);
            $table->addColumn('additional_info', 'text');
            $table->addColumn('created_date', 'int');
            $table->addColumn('last_update_date', 'int');
            $table->addColumn('user_id', 'int');
            $table->addColumn('username', 'varchar', 50);
            $table->addColumn('incident_type', 'varchar', 100)->setDefault('');
            $table->addColumn('report_annotations', 'mediumtext')->nullable();
            $table->addColumn('incident_date_time_desc', 'varchar', 3000)->setDefault('');
            $table->addColumn('reporter_person_id', 'int')->nullable()->setDefault(0);
            $table->addColumn('reported_person_id', 'int')->nullable()->setDefault(0);
            $table->addColumn('reported_additional_info', 'mediumtext')->nullable();
            $table->addColumn('finalized_on', 'int')->nullable()->setDefault(null);
            $table->addColumn('submitted_on', 'int')->nullable()->setDefault(null);
            $table->addPrimaryKey('case_id');
            $table->addKey(['reporter_person_id']);
            $table->addKey(['reported_person_id']);
            $table->addKey(['finalized_on']);
            $table->addKey(['submitted_on']);
        });

        $this->schemaManager()->createTable('xf_usips_ncmec_person', function(Create $table)
        {
            $table->addColumn('person_id', 'int')->autoIncrement();
            $table->addColumn('created_date', 'int');
            $table->addColumn('last_update_date', 'int');
            $table->addColumn('user_id', 'int')->setDefault(0);
            $table->addColumn('username', 'varchar', 50)->setDefault('');
            $table->addColumn('created_by_user_id', 'int')->setDefault(0);
            $table->addColumn('created_by_username', 'varchar', 50)->setDefault('');
            $table->addColumn('title', 'varchar', 255)->nullable()->setDefault(null);
            $table->addColumn('first_name', 'varchar', 100)->nullable();
            $table->addColumn('last_name', 'varchar', 100)->nullable();
            $table->addColumn('phones', 'mediumtext')->nullable();
            $table->addColumn('emails', 'mediumtext')->nullable();
            $table->addColumn('addresses', 'mediumtext')->nullable();
            $table->addColumn('age', 'smallint')->unsigned()->nullable();
            $table->addColumn('date_of_birth', 'date')->nullable();
            $table->addPrimaryKey('person_id');
            $table->addKey(['user_id']);
        });

        $this->schemaManager()->createTable('xf_usips_ncmec_incident', function(Create $table)
        {
            $table->addColumn('incident_id', 'int')->autoIncrement();
            $table->addColumn('case_id', 'int')->nullable()->setDefault(null);
            $table->addColumn('title', 'varchar', 255);
            $table->addColumn('additional_info', 'text');
            $table->addColumn('created_date', 'int');
            $table->addColumn('last_update_date', 'int');
            $table->addColumn('user_id', 'int');
            $table->addColumn('username', 'varchar', 50);
            $table->addColumn('finalized_on', 'int')->nullable()->setDefault(null);
            $table->addColumn('submitted_on', 'int')->nullable()->setDefault(null);
            $table->addPrimaryKey('incident_id');
            $table->addKey(['case_id']);
            $table->addKey(['finalized_on']);
            $table->addKey(['submitted_on']);
        });

        $this->schemaManager()->createTable('xf_usips_ncmec_incident_attachment_data', function(Create $table)
        {
            $table->addColumn('incident_id', 'int');
            $table->addColumn('data_id', 'int');
            $table->addColumn('user_id', 'int');
            $table->addColumn('username', 'varchar', 50);
            $table->addPrimaryKey(['incident_id', 'data_id']);
            $table->addKey('user_id');
        });

        $this->schemaManager()->createTable('xf_usips_ncmec_incident_content', function(Create $table)
        {
            $table->addColumn('incident_id', 'int');
            $table->addColumn('content_type', 'varchar', 25);
            $table->addColumn('content_id', 'int');
            $table->addColumn('user_id', 'int');
            $table->addColumn('username', 'varchar', 50);
            $table->addPrimaryKey(['incident_id', 'content_type', 'content_id']);
            $table->addKey(['content_type', 'content_id'], 'content_type_id');
            $table->addKey('user_id');
        });

        $this->schemaManager()->createTable('xf_usips_ncmec_incident_user', function(Create $table)
        {
            $table->addColumn('incident_id', 'int');
            $table->addColumn('user_id', 'int');
            $table->addColumn('username', 'varchar', 50);
            $table->addPrimaryKey(['incident_id', 'user_id']);
        });

        $this->schemaManager()->createTable('xf_usips_ncmec_report', function(Create $table)
        {
            $table->addColumn('report_id', 'int')->autoIncrement();
            $table->addColumn('ncmec_report_id', 'int')->nullable();
            $table->addColumn('case_id', 'int');
            $table->addColumn('created_date', 'int');
            $table->addColumn('last_update_date', 'int');
            $table->addColumn('user_id', 'int');
            $table->addColumn('username', 'varchar', 50);
            $table->addColumn('subject_user_id', 'int');
            $table->addColumn('subject_username', 'varchar', 50);
            $table->addColumn('submitted_on', 'int')->nullable()->setDefault(null);
            $table->addPrimaryKey('report_id');
            $table->addUniqueKey(['ncmec_report_id']);
            $table->addKey(['case_id']);
            $table->addKey(['user_id']);
            $table->addKey(['submitted_on']);
            $table->addKey(['subject_user_id']);
        });

        $this->schemaManager()->createTable('xf_usips_ncmec_report_file', function(Create $table)
        {
            $table->addColumn('file_id', 'int')->autoIncrement();
            $table->addColumn('report_id', 'int');
            $table->addColumn('case_id', 'int');
            $table->addColumn('ncmec_report_id', 'int');
            $table->addColumn('ncmec_file_id', 'varchar', 100)->nullable();
            $table->addColumn('original_file_name', 'varchar', 255)->setDefault('');
            $table->addColumn('location_of_file', 'varchar', 2048)->setDefault('');
            $table->addColumn('publicly_available', 'tinyint', 1)->setDefault(0);
            $table->addColumn('ip_capture_event', 'varbinary', 16)->setDefault('');
            $table->addColumn('data_id', 'int')->unsigned()->nullable()->setDefault(null);
            $table->addColumn('supplemental_key', 'varchar', 100)->nullable()->setDefault(null);
            $table->addColumn('file_details_submitted', 'tinyint', 1)->setDefault(0);
            $table->addPrimaryKey('file_id');
            $table->addKey(['report_id', 'data_id'], 'report_data_id');
            $table->addKey(['report_id', 'supplemental_key'], 'report_supplemental_key');
            $table->addKey(['report_id']);
            $table->addKey(['case_id']);
            $table->addKey(['ncmec_report_id']);
        });

        $this->schemaManager()->createTable('xf_usips_ncmec_api_log', function(Create $table)
        {
            $table->addColumn('log_id', 'int')->autoIncrement();
            $table->addColumn('report_id', 'int')->nullable();
            $table->addColumn('file_id', 'int')->nullable();
            $table->addColumn('user_id', 'int')->setDefault(0);
            $table->addColumn('request_date', 'int')->setDefault(0);
            $table->addColumn('request_method', 'varchar', 10);  // GET, POST
            $table->addColumn('request_url', 'varchar', 500);  // Full URL including base
            $table->addColumn('request_endpoint', 'varchar', 100);  // Endpoint path only
            $table->addColumn('request_data', 'mediumblob');  // JSON serialized request data (NO file data)
            $table->addColumn('response_code', 'int')->nullable();  // HTTP response code
            $table->addColumn('response_data', 'mediumblob');  // XML/text response from NCMEC
            $table->addColumn('environment', 'enum', ['test', 'production'])->setDefault('test');
            $table->addColumn('success', 'tinyint', 1)->setDefault(0);  // Whether the API call succeeded
            $table->addPrimaryKey('log_id');
            $table->addKey(['report_id']);
            $table->addKey(['file_id']);
            $table->addKey(['user_id']);
            $table->addKey(['request_date']);
            $table->addKey(['environment', 'request_date']);
        });

        // Add denormalized column to track incident relationships
        $this->schemaManager()->alterTable('xf_attachment_data', function(\XF\Db\Schema\Alter $table)
        {
            $table->addColumn('usips_ncmec_incident_count', 'int')->setDefault(0);
        });

        $this->createUserField();
    }

    protected function createUserField()
    {
        $userField = \XF::em()->create('XF:UserField');
        $userField->bulkSet([
            'field_id' => 'usips_ncmec_in_incident',
            'field_type' => 'radio',
            'field_choices' => ['0' => 'No', '1' => 'Yes'],
            'match_type' => 'none',
            'max_length' => 0,
            'required' => 0,
            'user_editable' => 'never',
            'moderator_editable' => 0,
            'viewable_profile' => 0,
            'viewable_message' => 0,
            'show_registration' => 0,
        ]);
        $userField->save();

        $title = $userField->getMasterPhrase(true);
        $title->phrase_text = 'In NCMEC Incident';
        $title->save();

        $description = $userField->getMasterPhrase(false);
        $description->phrase_text = 'User is currently involved in an active NCMEC incident report.';
        $description->save();

        // Create UserFieldValue records for all existing users, defaulting to FALSE (not in incident)
        $this->db()->query("
            INSERT INTO xf_user_field_value (field_id, user_id, field_value)
            SELECT 'usips_ncmec_in_incident', user_id, '0'
            FROM xf_user
            WHERE user_id NOT IN (
                SELECT user_id FROM xf_user_field_value WHERE field_id = 'usips_ncmec_in_incident'
            )
        ");

        // Update the custom_fields JSON cache for all users to include the new field
        // Use JSON_MERGE_PATCH which is more forgiving with data types
        $this->db()->query("
            UPDATE xf_user_profile
            SET custom_fields = JSON_MERGE_PATCH(
                COALESCE(NULLIF(CAST(custom_fields AS CHAR CHARACTER SET utf8mb4), ''), '{}'), 
                '{\"usips_ncmec_in_incident\":\"0\"}'
            )
        ");

        \XF::repository('XF:UserField')->rebuildFieldCache();

        $sm = $this->schemaManager();
        
        // Add incident count column to existing installations
        if (!$sm->columnExists('xf_attachment_data', 'usips_ncmec_incident_count'))
        {
            $sm->alterTable('xf_attachment_data', function(\XF\Db\Schema\Alter $table)
            {
                $table->addColumn('usips_ncmec_incident_count', 'int')->setDefault(0);
            });
        }
        
        // Populate the count for existing data
        $this->db()->query("
            UPDATE xf_attachment_data 
            SET usips_ncmec_incident_count = (
                SELECT COUNT(*) 
                FROM xf_usips_ncmec_incident_attachment_data 
                WHERE xf_usips_ncmec_incident_attachment_data.data_id = xf_attachment_data.data_id
            )
        ");

        // Add emergency_report column + index to xf_report (needed for NCMEC emergency profiling)
        if (!$this->schemaManager()->columnExists('xf_report', 'emergency_report'))
        {
            $this->schemaManager()->alterTable('xf_report', function(Alter $table)
            {
                $table->addColumn('emergency_report', 'tinyint')
                    ->unsigned()
                    ->setDefault(0)
                    ->after('report_state');
                $table->addKey('emergency_user_report', ['emergency_report', 'content_type', 'content_id', 'report_state']);
            });
        }
    }

    public function upgrade1010200Step1()
    {
        // Add emergency_report column to XenForo's xf_report table
        $sm = $this->schemaManager();

        if (!$sm->columnExists('xf_report', 'emergency_report'))
        {
            $sm->alterTable('xf_report', function(Alter $table)
            {
                $table->addColumn('emergency_report', 'tinyint')->unsigned()->setDefault(0)->after('report_state');
                $table->addKey('emergency_user_report', ['emergency_report', 'content_type', 'content_id', 'report_state']);
            });
        }
    }

    public function upgrade1010201Step1()
    {
        // Add covering index for content visibility filtering queries
        $indexes = $this->db()->fetchAllKeyed("SHOW INDEX FROM xf_usips_ncmec_incident_content", 'Key_name');
        if (isset($indexes['content_type_id']))
        {
            return;
        }

        $this->schemaManager()->alterTable('xf_usips_ncmec_incident_content', function(Alter $table)
        {
            $table->addKey(['content_type', 'content_id'], 'content_type_id');
        });
    }

    /**
     * 1.3.1: key report files by their source instead of by filename.
     *
     * Report files used to be de-duplicated on (report_id, original_file_name),
     * so a second evidence file that shared a name (e.g. "image.png") was taken
     * as already uploaded and its attachment data deleted unsent. Attachment
     * files are now keyed by data_id and supplemental exports by
     * supplemental_key. Existing rows keep NULL in both, so they never match a
     * new lookup (at worst a file is uploaded twice, which NCMEC allows).
     *
     * file_details_submitted records whether /fileinfo succeeded, so a re-run
     * re-sends it instead of deleting the local copy. Existing uploaded rows
     * are backfilled to 1, which is what the old code assumed.
     */
    public function upgrade1030100Step1()
    {
        $sm = $this->schemaManager();

        $sm->alterTable('xf_usips_ncmec_report_file', function(Alter $table) use ($sm)
        {
            if (!$sm->columnExists('xf_usips_ncmec_report_file', 'data_id'))
            {
                $table->addColumn('data_id', 'int')->unsigned()->nullable()->setDefault(null);
            }
            if (!$sm->columnExists('xf_usips_ncmec_report_file', 'supplemental_key'))
            {
                $table->addColumn('supplemental_key', 'varchar', 100)->nullable()->setDefault(null);
            }
            if (!$sm->columnExists('xf_usips_ncmec_report_file', 'file_details_submitted'))
            {
                $table->addColumn('file_details_submitted', 'tinyint', 1)->setDefault(0);
            }
        });
    }

    public function upgrade1030100Step2()
    {
        $sm = $this->schemaManager();
        $indexes = $this->db()->fetchAllKeyed("SHOW INDEX FROM xf_usips_ncmec_report_file", 'Key_name');

        $sm->alterTable('xf_usips_ncmec_report_file', function(Alter $table) use ($indexes)
        {
            if (!isset($indexes['report_data_id']))
            {
                $table->addKey(['report_id', 'data_id'], 'report_data_id');
            }
            if (!isset($indexes['report_supplemental_key']))
            {
                $table->addKey(['report_id', 'supplemental_key'], 'report_supplemental_key');
            }
        });
    }

    public function upgrade1030100Step4()
    {
        // The 1.2.1 covering index never reached existing installs: upgrade()
        // was a no-op, and the step passed addKey() its arguments swapped.
        $this->upgrade1010201Step1();
    }

    public function upgrade1030100Step3()
    {
        // Idempotent: only touches rows that were uploaded under the old code
        // and have not been marked yet.
        $this->db()->query("
            UPDATE xf_usips_ncmec_report_file
            SET file_details_submitted = 1
            WHERE ncmec_file_id IS NOT NULL
                AND ncmec_file_id <> ''
                AND file_details_submitted = 0
                AND data_id IS NULL
                AND supplemental_key IS NULL
        ");
    }

    public function uninstall(array $stepParams = [])
    {
        $sm = $this->schemaManager();
        
        // Remove the denormalized column
        if ($sm->columnExists('xf_attachment_data', 'usips_ncmec_incident_count'))
        {
            $sm->alterTable('xf_attachment_data', function(\XF\Db\Schema\Alter $table)
            {
                $table->dropColumns('usips_ncmec_incident_count');
            });
        }
        
        $sm->dropTable('xf_usips_ncmec_incident_attachment_data');
        $sm->dropTable('xf_usips_ncmec_incident_content');
        $sm->dropTable('xf_usips_ncmec_incident_user');
        $sm->dropTable('xf_usips_ncmec_api_log');
        $sm->dropTable('xf_usips_ncmec_report_file');
        $sm->dropTable('xf_usips_ncmec_report');
        $sm->dropTable('xf_usips_ncmec_incident');
        $sm->dropTable('xf_usips_ncmec_case');
        $sm->dropTable('xf_usips_ncmec_person');

        $this->deleteUserField();
    }

    protected function deleteUserField()
    {
        $userField = \XF::em()->find('XF:UserField', 'usips_ncmec_in_incident');
        if ($userField)
        {
            $userField->delete();
            \XF::repository('XF:UserField')->rebuildFieldCache();
        }
    }
}