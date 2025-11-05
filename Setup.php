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
    }

    public function upgrade(array $stepParams = [])
    {
        // Future upgrades can be handled here
    }

    public function uninstall(array $stepParams = [])
    {
        $this->schemaManager()->dropTable('xf_usips_ncmec_incident_attachment_data');
        $this->schemaManager()->dropTable('xf_usips_ncmec_incident_content');
        $this->schemaManager()->dropTable('xf_usips_ncmec_incident_user');
        $this->schemaManager()->dropTable('xf_usips_ncmec_report_log');
        $this->schemaManager()->dropTable('xf_usips_ncmec_report');
        $this->schemaManager()->dropTable('xf_usips_ncmec_incident');
    }
}