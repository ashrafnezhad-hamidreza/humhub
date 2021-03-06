<?php

/**
 * @link https://www.humhub.org/
 * @copyright Copyright (c) 2017 HumHub GmbH & Co. KG
 * @license https://www.humhub.com/licences
 */

namespace humhub\modules\admin\controllers;

use humhub\compat\HForm;
use humhub\components\export\DateTimeColumn;
use humhub\components\export\SpreadsheetExport;
use humhub\modules\admin\components\Controller;
use humhub\modules\admin\models\forms\UserEditForm;
use humhub\modules\admin\models\UserSearch;
use humhub\modules\admin\permissions\ManageGroups;
use humhub\modules\admin\permissions\ManageSettings;
use humhub\modules\admin\permissions\ManageUsers;
use humhub\modules\space\models\Membership;
use humhub\modules\user\models\forms\Registration;
use humhub\modules\user\models\ProfileField;
use humhub\modules\user\models\User;
use Yii;
use yii\helpers\Url;
use yii\web\HttpException;

/**
 * User management
 *
 * @since 0.5
 */
class UserController extends Controller
{

    /**
     * @inheritdoc
     */
    public $adminOnly = false;

    public function init()
    {
        $this->appendPageTitle(Yii::t('AdminModule.base', 'Users'));
        $this->subLayout = '@admin/views/layouts/user';

        return parent::init();
    }

    /**
     * @inheritdoc
     */
    public function getAccessRules()
    {
        return [
            [
                'permissions' => [
                    ManageUsers::class,
                    ManageGroups::class,
                ]
            ],
            [
                'permissions' => [ManageSettings::class],
                'actions' => ['index']
            ]
        ];
    }

    /**
     * Returns a List of Users
     */
    public function actionIndex()
    {
        if (Yii::$app->user->can([new ManageUsers(), new ManageGroups()])) {
            $searchModel = new UserSearch();
            $dataProvider = $searchModel->search(Yii::$app->request->queryParams);
            return $this->render('index', [
                'dataProvider' => $dataProvider,
                'searchModel' => $searchModel
            ]);
        } else {
            if (Yii::$app->user->can(ManageSettings::class)) {
                $this->redirect(['/admin/authentication']);
            } else {
                $this->forbidden();
            }
        }
    }

    /**
     * Edits a user
     *
     * @return type
     */
    public function actionEdit()
    {
        $user = UserEditForm::findOne(['id' => Yii::$app->request->get('id')]);
        $user->initGroupSelection();

        if ($user == null) {
            throw new HttpException(404, Yii::t('AdminModule.controllers_UserController', 'User not found!'));
        }

        $user->scenario = 'editAdmin';
        $user->profile->scenario = 'editAdmin';
        $profile = $user->profile;

        // Build Form Definition
        $definition = [];
        $definition['elements'] = [];
        // Add User Form
        $definition['elements']['User'] = [
            'type' => 'form',
            'title' => 'Account',
            'elements' => [
                'username' => [
                    'type' => 'text',
                    'class' => 'form-control',
                    'maxlength' => 25,
                ],
                'email' => [
                    'type' => 'text',
                    'class' => 'form-control',
                    'maxlength' => 100,
                ],
                'groupSelection' => [
                    'id' => 'user_edit_groups',
                    'type' => 'multiselectdropdown',
                    'items' => UserEditForm::getGroupItems(),
                    'options' => [
                        'data-placeholder' => Yii::t('AdminModule.controllers_UserController', 'Select Groups'),
                        'data-placeholder-more' => Yii::t('AdminModule.controllers_UserController', 'Add Groups...')
                    ],
                    'isVisible' => Yii::$app->user->can(new ManageGroups())
                ],
                'status' => [
                    'type' => 'dropdownlist',
                    'class' => 'form-control',
                    'items' => [
                        User::STATUS_ENABLED => Yii::t('AdminModule.controllers_UserController', 'Enabled'),
                        User::STATUS_DISABLED => Yii::t('AdminModule.controllers_UserController', 'Disabled'),
                        User::STATUS_NEED_APPROVAL => Yii::t('AdminModule.controllers_UserController', 'Unapproved'),
                    ],
                ]
            ],
        ];

        // Add Profile Form
        $definition['elements']['Profile'] = array_merge(['type' => 'form'], $profile->getFormDefinition());

        // Get Form Definition
        $definition['buttons'] = [
            'save' => [
                'type' => 'submit',
                'label' => Yii::t('AdminModule.controllers_UserController', 'Save'),
                'class' => 'btn btn-primary',
            ],
            'become' => [
                'type' => 'submit',
                'label' => Yii::t('AdminModule.controllers_UserController', 'Become this user'),
                'class' => 'btn btn-danger',
                'isVisible' => $this->canBecomeUser($user)
            ],
            'delete' => [
                'type' => 'submit',
                'label' => Yii::t('AdminModule.controllers_UserController', 'Delete'),
                'class' => 'btn btn-danger',
            ],
        ];

        $form = new HForm($definition);
        $form->models['User'] = $user;
        $form->models['Profile'] = $profile;

        if ($form->submitted('save') && $form->validate()) {
            if ($form->save()) {
                $this->view->saved();
                return $this->redirect(['/admin/user']);
            }
        }

        // This feature is used primary for testing, maybe remove this in future
        if ($form->submitted('become') && $this->canBecomeUser($user)) {

            Yii::$app->user->switchIdentity($form->models['User']);
            return $this->redirect(Url::home());
        }

        if ($form->submitted('delete')) {
            return $this->redirect(['/admin/user/delete', 'id' => $user->id]);
        }

        return $this->render('edit', [
            'hForm' => $form,
            'user' => $user
        ]);
    }

    public function canBecomeUser($user)
    {
        return Yii::$app->user->isAdmin() && $user->id != Yii::$app->user->getIdentity()->id;
    }

    public function actionAdd()
    {
        $registration = new Registration();
        $registration->enableEmailField = true;
        $registration->enableUserApproval = false;
        if ($registration->submitted('save') && $registration->validate() && $registration->register()) {
            return $this->redirect(['edit', 'id' => $registration->getUser()->id]);
        }

        return $this->render('add', ['hForm' => $registration]);
    }

    /**
     * Deletes a user permanently
     * @throws HttpException
     */
    public function actionDelete()
    {
        $id = (int)Yii::$app->request->get('id');
        $doit = (int)Yii::$app->request->get('doit');

        $user = User::findOne(['id' => $id]);

        if ($user == null) {
            throw new HttpException(
                404,
                Yii::t('AdminModule.controllers_UserController', 'User not found!')
            );
        } elseif (Yii::$app->user->id == $id) {
            throw new HttpException(
                400,
                Yii::t('AdminModule.controllers_UserController', 'You cannot delete yourself!')
            );
        }

        if ($doit == 2) {
            $this->forcePostRequest();

            foreach (Membership::GetUserSpaces($user->id) as $space) {
                if ($space->isSpaceOwner($user->id)) {
                    $space->addMember(Yii::$app->user->id);
                    $space->setSpaceOwner(Yii::$app->user->id);
                }
            }
            $user->delete();
            return $this->redirect(['/admin/user']);
        }

        return $this->render('delete', ['model' => $user]);
    }

    /**
     * Export user list as csv or xlsx
     * @param string $format supported format by phpspreadsheet
     * @return \yii\web\Response
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function actionExport($format)
    {
        $searchModel = new UserSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);
        $dataProvider->pagination = false;

        $exporter = new SpreadsheetExport([
            'dataProvider' => $dataProvider,
            'columns' => $this->collectExportColumns(),
            'resultConfig' => [
                'fileBaseName' => 'humhub_user',
                'writerType' => $format,
            ],
        ]);

        return $exporter->export()->send();
    }

    /**
     * Return array with columns for data export
     * @return array
     */
    private function collectExportColumns()
    {
        $userColumns = [
            'id',
            'guid',
            'status',
            'username',
            'email',
            'auth_mode',
            'tags',
            'language',
            'time_zone',
            [
                'class' => DateTimeColumn::className(),
                'attribute' => 'created_at',
            ],
            'created_by',
            [
                'class' => DateTimeColumn::className(),
                'attribute' => 'updated_at',
            ],
            'updated_by',
            [
                'class' => DateTimeColumn::className(),
                'attribute' => 'last_login',
            ],
            'authclient_id',
            'visibility',
        ];

        $profileColumns = (new \yii\db\Query())
            ->select(['CONCAT(\'profile.\', internal_name)'])
            ->from(ProfileField::tableName())
            ->orderBy(['profile_field_category_id' => SORT_ASC, 'sort_order' => SORT_ASC])
            ->column();

        return array_merge($userColumns, $profileColumns);
    }
}
