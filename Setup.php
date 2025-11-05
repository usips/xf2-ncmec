<?php

namespace USIPS\NCMEC;

use XF\AddOn\AbstractSetup;
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
            $table->addColumn('is_finalized', 'tinyint', 1)->setDefault(0);
            $table->addPrimaryKey('incident_id');
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
            $table->addColumn('report_id', 'int')->autoIncrement();
            $table->addColumn('incident_id', 'int');
            $table->addColumn('created_date', 'int');
            $table->addColumn('last_update_date', 'int');
            $table->addColumn('user_id', 'int');
            $table->addColumn('username', 'varchar', 50);
            $table->addColumn('ncmec_report_id', 'varchar', 255);
            $table->addColumn('is_finished', 'tinyint', 1)->setDefault(0);
            $table->addPrimaryKey('report_id');
            $table->addKey(['incident_id']);
            $table->addKey(['user_id']);
        });

        $this->schemaManager()->createTable('xf_usips_ncmec_report_log', function(Create $table)
        {
            $table->addColumn('log_id', 'int')->autoIncrement();
            $table->addColumn('report_id', 'int');
            $table->addColumn('user_id', 'int');
            $table->addColumn('username', 'varchar', 50);
            $table->addColumn('created_date', 'int');
            $table->addColumn('last_update_date', 'int');
            $table->addColumn('api_method', 'varchar', 10);
            $table->addColumn('api_endpoint', 'varchar', 500);
            $table->addColumn('api_data', 'blob');
            $table->addColumn('response_code', 'int');
            $table->addColumn('response_data', 'blob');
            $table->addPrimaryKey('log_id');
            $table->addKey(['report_id']);
            $table->addKey(['user_id']);
        });

        // Add denormalized column to track incident relationships
        $this->schemaManager()->alterTable('xf_attachment_data', function(\XF\Db\Schema\Alter $table)
        {
            $table->addColumn('usips_ncmec_incident_count', 'int')->setDefault(0);
        });
    }

    public function upgrade(array $stepParams = [])
    {
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
        $sm->dropTable('xf_usips_ncmec_report_log');
        $sm->dropTable('xf_usips_ncmec_report');
        $sm->dropTable('xf_usips_ncmec_incident');
    }
}