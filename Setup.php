<?php

namespace USIPS\NCMEC;

use XF\AddOn\AbstractSetup;
use XF\Db\Schema\Alter;
use XF\Db\Schema\Create;

class Setup extends AbstractSetup
{
    public function install(array $stepParams = [])
    {
        $this->schemaManager()->createTable('xf_usips_ncmec_incident', function(Create $table)
        {
            $table->addColumn('incident_id', 'int')->autoIncrement();
            $table->addColumn('title', 'varchar', 255);
            $table->addColumn('additional_info', 'text');
            $table->addColumn('created_date', 'int');
            $table->addColumn('last_update_date', 'int');
            $table->addColumn('user_id', 'int');
            $table->addColumn('username', 'varchar', 50);
            $table->addColumn('report_id', 'int')->nullable();
            $table->addColumn('is_finalized', 'tinyint', 1)->setDefault(0);
            $table->addPrimaryKey('incident_id');
            $table->addKey(['report_id']);
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
            $table->addColumn('report_id', 'int');
            $table->addColumn('activity_summary', 'mediumtext')->nullable();
            $table->addColumn('created_date', 'int');
            $table->addColumn('last_update_date', 'int');
            $table->addColumn('user_id', 'int');
            $table->addColumn('username', 'varchar', 50);
            $table->addColumn('is_finished', 'tinyint', 1)->setDefault(0);
            $table->addPrimaryKey('report_id');
            $table->addKey(['user_id']);
        });

        $this->schemaManager()->createTable('xf_usips_ncmec_api_log', function(Create $table)
        {
            $table->addColumn('log_id', 'int')->autoIncrement();
            $table->addColumn('report_id', 'int')->nullable();
            $table->addColumn('user_id', 'int')->setDefault(0);
            $table->addColumn('request_date', 'int')->setDefault(0);
            $table->addColumn('request_method', 'varchar', 10);  // GET, POST
            $table->addColumn('request_url', 'varchar', 500);  // Full URL including base
            $table->addColumn('request_endpoint', 'varchar', 100);  // Endpoint path only
            $table->addColumn('request_data', 'mediumblob');  // JSON serialized request data (NO file data)
            $table->addColumn('response_code', 'int')->nullable();  // HTTP response code
            $table->addColumn('response_data', 'mediumblob');  // XML/text response from NCMEC
            $table->addColumn('environment', 'varchar', 20);  // test, live
            $table->addColumn('success', 'tinyint', 1)->setDefault(0);  // Whether the API call succeeded
            $table->addPrimaryKey('log_id');
            $table->addKey(['report_id']);
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
    }

    public function upgrade(array $stepParams = [])
    {
        return $stepParams;
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
        $sm->dropTable('xf_usips_ncmec_report');
        $sm->dropTable('xf_usips_ncmec_incident');

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