<?php

namespace USIPS\NCMEC\Admin\Controller;

use USIPS\NCMEC\Entity\Person;
use XF\Admin\Controller\AbstractController;
use XF\Mvc\FormAction;
use XF\Mvc\ParameterBag;

class PersonController extends AbstractController
{
    protected function preDispatchController($action, ParameterBag $params)
    {
        $this->assertAdminPermission('usips_ncmec');
    }

    public function actionIndex(ParameterBag $params)
    {
        $page = $this->filterPage();
        $perPage = 50;

        $finder = $this->finder(Person::class)
            ->with('User')
            ->order('last_update_date', 'DESC')
            ->limitByPage($page, $perPage);

        $total = $finder->total();
        $this->assertValidPage($page, $perPage, $total, 'ncmec-people');

        $viewParams = [
            'persons' => $finder->fetch(),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
        ];

        return $this->view('USIPS\NCMEC:Person\List', 'usips_ncmec_person_list', $viewParams);
    }

    public function actionCreate(ParameterBag $params)
    {
        /** @var Person $person */
        $person = $this->em()->create(Person::class);

        if ($this->isPost())
        {
            $this->personSaveProcess($person)->run();

            return $this->redirect($this->buildLink('ncmec-people/view', $person));
        }

        return $this->personAddEdit($person);
    }

    public function actionView(ParameterBag $params)
    {
        $person = $this->assertPersonExists($params->person_id, 'User');

        $viewParams = [
            'person' => $person,
            'ageSelectorValue' => $person->date_of_birth ? 'date_of_birth' : ($person->age !== null ? 'age' : ''),
        ];

        return $this->view('USIPS\NCMEC:Person\View', 'usips_ncmec_person_view', $viewParams);
    }

    public function actionUpdate(ParameterBag $params)
    {
        $this->assertPostOnly();

        $person = $this->assertPersonExists($params->person_id, 'User');

        $this->personSaveProcess($person)->run();

        return $this->redirect($this->buildLink('ncmec-people/view', $person));
    }

    protected function personAddEdit(Person $person)
    {
        $viewParams = [
            'person' => $person,
        ];

        return $this->view('USIPS\NCMEC:Person\Edit', 'usips_ncmec_person_edit', $viewParams);
    }

    protected function personSaveProcess(Person $person): FormAction
    {
        $input = $this->filter([
            'first_name' => 'str',
            'last_name' => 'str',
            'age_selector' => 'str',
            'date_of_birth' => 'str',
            'age' => 'str',
        ]);

        foreach (['first_name', 'last_name'] as $key)
        {
            $value = trim($input[$key]);
            $input[$key] = ($value === '') ? null : $value;
        }

        $ageSelector = trim($input['age_selector']);

        if ($ageSelector === 'date_of_birth')
        {
            $dob = trim($input['date_of_birth']);
            $input['date_of_birth'] = ($dob === '') ? null : $dob;
            $input['age'] = null;
        }
        else if ($ageSelector === 'age')
        {
            $ageRaw = trim($input['age']);
            if ($ageRaw === '')
            {
                $input['age'] = null;
            }
            else
            {
                $age = (int) $ageRaw;
                if ($age < 0)
                {
                    $age = 0;
                }
                $input['age'] = $age;
            }
            $input['date_of_birth'] = null;
        }
        else
        {
            $input['age'] = null;
            $input['date_of_birth'] = null;
        }

        unset($input['age_selector']);

        $form = $this->formAction();
        $form->basicEntitySave($person, $input);

        $form->apply(function() use ($person)
        {
            $time = \XF::$time;
            if ($person->isInsert() && !$person->created_date)
            {
                $person->created_date = $time;
            }

            $person->last_update_date = $time;

            if ($person->isInsert())
            {
                $visitor = \XF::visitor();
                $person->user_id = $visitor->user_id;
                $person->username = $visitor->username;
            }
        });

        return $form;
    }

    protected function assertPersonExists($id, $with = null)
    {
        return $this->assertRecordExists(Person::class, $id, $with);
    }
}
