<?php

/**
 * @file classes/plugins/PubObjectsExportPlugin.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PubObjectsExportPlugin
 * @ingroup plugins
 *
 * @brief Basis class for XML metadata export plugins
 */

namespace APP\plugins;

use PKP\submission\PKPSubmission;
use PKP\core\JSONMessage;
use PKP\file\FileManager;
use PKP\plugins\ImportExportPlugin;
use PKP\db\DAORegistry;
use PKP\plugins\HookRegistry;
use PKP\linkAction\request\NullAction;
use PKP\linkAction\LinkAction;

use APP\core\Application;
use APP\template\TemplateManager;
use APP\i18n\AppLocale;
use APP\notification\NotificationManager;

// The statuses.
define('EXPORT_STATUS_ANY', '');
define('EXPORT_STATUS_NOT_DEPOSITED', 'notDeposited');
define('EXPORT_STATUS_MARKEDREGISTERED', 'markedRegistered');
define('EXPORT_STATUS_REGISTERED', 'registered');

// The actions.
define('EXPORT_ACTION_EXPORT', 'export');
define('EXPORT_ACTION_MARKREGISTERED', 'markRegistered');
define('EXPORT_ACTION_DEPOSIT', 'deposit');

// Configuration errors.
define('EXPORT_CONFIG_ERROR_SETTINGS', 0x02);

abstract class PubObjectsExportPlugin extends ImportExportPlugin
{
    /** @var PubObjectCache */
    public $_cache;

    /**
     * Get the plugin cache
     *
     * @return PubObjectCache
     */
    public function getCache()
    {
        if (!is_a($this->_cache, 'PubObjectCache')) {
            // Instantiate the cache.
            import('classes.plugins.PubObjectCache');
            $this->_cache = new PubObjectCache();
        }
        return $this->_cache;
    }

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }

        AppLocale::requireComponents(LOCALE_COMPONENT_APP_MANAGER);
        $this->addLocaleData();

        HookRegistry::register('AcronPlugin::parseCronTab', [$this, 'callbackParseCronTab']);
        foreach ($this->_getDAOs() as $dao) {
            if ($dao instanceof SchemaDAO) {
                HookRegistry::register('Schema::get::' . $dao->schemaName, [$this, 'addToSchema']);
            } else {
                HookRegistry::register(strtolower_codesafe(get_class($dao)) . '::getAdditionalFieldNames', [&$this, 'getAdditionalFieldNames']);
            }
        }
        return true;
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        $user = $request->getUser();
        $router = $request->getRouter();
        $context = $router->getContext($request);

        $form = $this->_instantiateSettingsForm($context);
        $notificationManager = new NotificationManager();
        switch ($request->getUserVar('verb')) {
            case 'save':
                $form->readInputData();
                if ($form->validate()) {
                    $form->execute();
                    $notificationManager->createTrivialNotification($user->getId(), NOTIFICATION_TYPE_SUCCESS);
                    return new JSONMessage(true);
                } else {
                    return new JSONMessage(true, $form->fetch($request));
                }
                // no break
            case 'index':
                $form->initData();
                return new JSONMessage(true, $form->fetch($request));
            case 'statusMessage':
                $statusMessage = $this->getStatusMessage($request);
                if ($statusMessage) {
                    $templateMgr = TemplateManager::getManager($request);
                    $templateMgr->assign([
                        'statusMessage' => htmlentities($statusMessage),
                    ]);
                    return new JSONMessage(true, $templateMgr->fetch($this->getTemplateResource('statusMessage.tpl')));
                }
        }
        return parent::manage($args, $request);
    }

    /**
     * @copydoc ImportExportPlugin::display()
     */
    public function display($args, $request)
    {
        parent::display($args, $request);

        $context = $request->getContext();
        switch (array_shift($args)) {
            case 'index':
            case '':
                // Check for configuration errors:
                $configurationErrors = [];
                // missing plugin settings
                $form = $this->_instantiateSettingsForm($context);
                foreach ($form->getFormFields() as $fieldName => $fieldType) {
                    if ($form->isOptional($fieldName)) {
                        continue;
                    }
                    $pluginSetting = $this->getSetting($context->getId(), $fieldName);
                    if (empty($pluginSetting)) {
                        $configurationErrors[] = EXPORT_CONFIG_ERROR_SETTINGS;
                        break;
                    }
                }

                // Add link actions
                $actions = $this->getExportActions($context);
                $actionNames = array_intersect_key($this->getExportActionNames(), array_flip($actions));
                $linkActions = [];
                foreach ($actionNames as $action => $actionName) {
                    $linkActions[] = new LinkAction($action, new NullAction(), $actionName);
                }
                $templateMgr = TemplateManager::getManager($request);
                $templateMgr->assign([
                    'plugin' => $this,
                    'actionNames' => $actionNames,
                    'configurationErrors' => $configurationErrors,
                ]);
                break;
            case 'exportSubmissions':
            case 'exportRepresentations':
                $selectedSubmissions = (array) $request->getUserVar('selectedSubmissions');
                $selectedRepresentations = (array) $request->getUserVar('selectedRepresentations');
                $tab = (string) $request->getUserVar('tab');
                $noValidation = $request->getUserVar('validation') ? false : true;

                if (empty($selectedSubmissions) && empty($selectedRepresentations)) {
                    fatalError(__('plugins.importexport.common.error.noObjectsSelected'));
                }
                if (!empty($selectedSubmissions)) {
                    $objects = $this->getPublishedSubmissions($selectedSubmissions, $context);
                    $filter = $this->getSubmissionFilter();
                    $objectsFileNamePart = 'preprints';
                } elseif (!empty($selectedRepresentations)) {
                    $objects = $this->getPreprintGalleys($selectedRepresentations);
                    $filter = $this->getRepresentationFilter();
                    $objectsFileNamePart = 'galleys';
                }

                // Execute export action
                $this->executeExportAction($request, $objects, $filter, $tab, $objectsFileNamePart, $noValidation);
        }
    }

    /**
     * Execute export action.
     *
     * @param $request Request
     * @param $objects array Array of objects to be exported
     * @param $filter string Filter to use
     * @param $tab string Tab to return to
     * @param $objectsFileNamePart string Export file name part for this kind of objects
     * @param $noValidation boolean If set to true no XML validation will be done
     */
    public function executeExportAction($request, $objects, $filter, $tab, $objectsFileNamePart, $noValidation = null)
    {
        $context = $request->getContext();
        $path = ['plugin', $this->getName()];
        if ($request->getUserVar(EXPORT_ACTION_EXPORT)) {
            assert($filter != null);
            // Get the XML
            $exportXml = $this->exportXML($objects, $filter, $context, $noValidation);
            $fileManager = new FileManager();
            $exportFileName = $this->getExportFileName($this->getExportPath(), $objectsFileNamePart, $context, '.xml');
            $fileManager->writeFile($exportFileName, $exportXml);
            $fileManager->downloadByPath($exportFileName);
            $fileManager->deleteByPath($exportFileName);
        } elseif ($request->getUserVar(EXPORT_ACTION_DEPOSIT)) {
            assert($filter != null);
            // Get the XML
            $exportXml = $this->exportXML($objects, $filter, $context, $noValidation);
            // Write the XML to a file.
            // export file name example: crossref-20160723-160036-preprints-1.xml
            $fileManager = new FileManager();
            $exportFileName = $this->getExportFileName($this->getExportPath(), $objectsFileNamePart, $context, '.xml');
            $fileManager->writeFile($exportFileName, $exportXml);
            // Deposit the XML file.
            $result = $this->depositXML($objects, $context, $exportFileName);
            // send notifications
            if ($result === true) {
                $this->_sendNotification(
                    $request->getUser(),
                    $this->getDepositSuccessNotificationMessageKey(),
                    NOTIFICATION_TYPE_SUCCESS
                );
            } else {
                if (is_array($result)) {
                    foreach ($result as $error) {
                        assert(is_array($error) && count($error) >= 1);
                        $this->_sendNotification(
                            $request->getUser(),
                            $error[0],
                            NOTIFICATION_TYPE_ERROR,
                            ($error[1] ?? null)
                        );
                    }
                }
            }
            // Remove all temporary files.
            $fileManager->deleteByPath($exportFileName);
            // redirect back to the right tab
            $request->redirect(null, null, null, $path, null, $tab);
        } elseif ($request->getUserVar(EXPORT_ACTION_MARKREGISTERED)) {
            $this->markRegistered($context, $objects);
            // redirect back to the right tab
            $request->redirect(null, null, null, $path, null, $tab);
        } else {
            $dispatcher = $request->getDispatcher();
            $dispatcher->handle404();
        }
    }

    /**
     * Get the locale key used in the notification for
     * the successful deposit.
     */
    public function getDepositSuccessNotificationMessageKey()
    {
        return 'plugins.importexport.common.register.success';
    }

    /**
     * Deposit XML document.
     * This must be implemented in the subclasses, if the action is supported.
     *
     * @param $objects mixed Array of or single published submission or galley
     * @param $context Context
     * @param $filename Export XML filename
     *
     * @return boolean Whether the XML document has been registered
     */
    abstract public function depositXML($objects, $context, $filename);

    /**
     * Get detailed message of the object status i.e. failure messages.
     * Parameters needed have to be in the request object.
     *
     * @param $request PKPRequest
     *
     * @return string Preformatted text that will be displayed in a div element in the modal
     */
    public function getStatusMessage($request)
    {
        return null;
    }

    /**
     * Get the submission filter.
     *
     * @return string|null
     */
    public function getSubmissionFilter()
    {
        return null;
    }

    /**
     * Get the representation filter.
     *
     * @return string|null
     */
    public function getRepresentationFilter()
    {
        return null;
    }

    /**
     * Get status names for the filter search option.
     *
     * @return array (string status => string text)
     */
    public function getStatusNames()
    {
        return [
            EXPORT_STATUS_ANY => __('plugins.importexport.common.status.any'),
            EXPORT_STATUS_NOT_DEPOSITED => __('plugins.importexport.common.status.notDeposited'),
            EXPORT_STATUS_MARKEDREGISTERED => __('plugins.importexport.common.status.markedRegistered'),
            EXPORT_STATUS_REGISTERED => __('plugins.importexport.common.status.registered'),
        ];
    }

    /**
     * Get status actions for the display to the user,
     * i.e. links to a web site with more information about the status.
     *
     * @param $pubObject
     *
     * @return array (string status => link)
     */
    public function getStatusActions($pubObject)
    {
        return [];
    }

    /**
     * Get actions.
     *
     * @param $context Context
     *
     * @return array
     */
    public function getExportActions($context)
    {
        $actions = [EXPORT_ACTION_EXPORT, EXPORT_ACTION_MARKREGISTERED];
        if ($this->getSetting($context->getId(), 'username') && $this->getSetting($context->getId(), 'password')) {
            array_unshift($actions, EXPORT_ACTION_DEPOSIT);
        }
        return $actions;
    }

    /**
     * Get action names.
     *
     * @return array (string action => string text)
     */
    public function getExportActionNames()
    {
        return [
            EXPORT_ACTION_DEPOSIT => __('plugins.importexport.common.action.register'),
            EXPORT_ACTION_EXPORT => __('plugins.importexport.common.action.export'),
            EXPORT_ACTION_MARKREGISTERED => __('plugins.importexport.common.action.markRegistered'),
        ];
    }

    /**
     * Return the name of the plugin's deployment class.
     *
     * @return string
     */
    abstract public function getExportDeploymentClassName();

    /**
     * Get the XML for selected objects.
     *
     * @param $objects mixed Array of or single published submission or galley
     * @param $filter string
     * @param $context Context
     * @param $noValidation boolean If set to true no XML validation will be done
     *
     * @return string XML document.
     */
    public function exportXML($objects, $filter, $context, $noValidation = null)
    {
        $filterDao = DAORegistry::getDAO('FilterDAO'); /* @var $filterDao FilterDAO */
        $exportFilters = $filterDao->getObjectsByGroup($filter);
        assert(count($exportFilters) == 1); // Assert only a single serialization filter
        $exportFilter = array_shift($exportFilters);
        $exportDeployment = $this->_instantiateExportDeployment($context);
        $exportFilter->setDeployment($exportDeployment);
        if ($noValidation) {
            $exportFilter->setNoValidation($noValidation);
        }
        libxml_use_internal_errors(true);
        $exportXml = $exportFilter->execute($objects, true);
        $xml = $exportXml->saveXml();
        $errors = array_filter(libxml_get_errors(), function ($a) {
            return $a->level == LIBXML_ERR_ERROR || $a->level == LIBXML_ERR_FATAL;
        });
        if (!empty($errors)) {
            $this->displayXMLValidationErrors($errors, $xml);
        }
        return $xml;
    }

    /**
     * Mark selected submissions as registered.
     *
     * @param $context Context
     * @param $objects array Array of published submissions or galleys
     */
    public function markRegistered($context, $objects)
    {
        foreach ($objects as $object) {
            $object->setData($this->getDepositStatusSettingName(), EXPORT_STATUS_MARKEDREGISTERED);
            $this->updateObject($object);
        }
    }

    /**
     * Update the given object.
     *
     * @param $object Submission|PreprintGalley
     */
    protected function updateObject($object)
    {
        // Register a hook for the required additional
        // object fields. We do this on a temporary
        // basis as the hook adds a performance overhead
        // and the field will "stealthily" survive even
        // when the DAO does not know about it.
        $dao = $object->getDAO();
        $dao->updateObject($object);
    }

    /**
     * Add properties for this type of public identifier to the entity's list for
     * storage in the database.
     * This is used for non-SchemaDAO-backed entities only.
     *
     * @see PubObjectsExportPlugin::addToSchema()
     *
     * @param $hookName string
     */
    public function getAdditionalFieldNames($hookName, $args)
    {
        assert(count($args) == 2);
        $additionalFields = & $args[1];
        assert(is_array($additionalFields));
        foreach ($this->_getObjectAdditionalSettings() as $fieldName) {
            $additionalFields[] = $fieldName;
        }

        return false;
    }

    /**
     * Add properties for this type of public identifier to the entity's list for
     * storage in the database.
     * This is used for SchemaDAO-backed entities only.
     *
     * @see PKPPubIdPlugin::getAdditionalFieldNames()
     *
     * @param $hookName string `Schema::get::publication`
     * @param $params array
     */
    public function addToSchema($hookName, $params)
    {
        $schema = & $params[0];
        foreach ($this->_getObjectAdditionalSettings() as $fieldName) {
            $schema->properties->{$fieldName} = (object) [
                'type' => 'string',
                'apiSummary' => true,
                'validation' => ['nullable'],
            ];
        }

        return false;
    }

    /**
     * Get a list of additional setting names that should be stored with the objects.
     *
     * @return array
     */
    protected function _getObjectAdditionalSettings()
    {
        return [$this->getDepositStatusSettingName()];
    }

    /**
     * @copydoc AcronPlugin::parseCronTab()
     */
    public function callbackParseCronTab($hookName, $args)
    {
        $taskFilesPath = & $args[0];
        $taskFilesPath[] = $this->getPluginPath() . DIRECTORY_SEPARATOR . 'scheduledTasks.xml';
        return false;
    }

    /**
     * Retrieve all unregistered preprints.
     *
     * @param $context Context
     *
     * @return array
     */
    public function getUnregisteredPreprints($context)
    {
        // Retrieve all published submissions that have not yet been registered.
        $submissionDao = DAORegistry::getDAO('SubmissionDAO'); /* @var $submissionDao SubmissionDAO */
        $preprints = $submissionDao->getExportable(
            $context->getId(),
            null,
            null,
            null,
            $this->getDepositStatusSettingName(),
            EXPORT_STATUS_NOT_DEPOSITED,
            null
        );
        return $preprints->toArray();
    }
    /**
     * Check whether we are in test mode.
     *
     * @param $context Context
     *
     * @return boolean
     */
    public function isTestMode($context)
    {
        return ($this->getSetting($context->getId(), 'testMode') == 1);
    }

    /**
     * Get deposit status setting name.
     *
     * @return string
     */
    public function getDepositStatusSettingName()
    {
        return $this->getPluginSettingsPrefix() . '::status';
    }



    /**
     * @copydoc PKPImportExportPlugin::usage
     */
    public function usage($scriptName)
    {
        echo __(
            'plugins.importexport.' . $this->getPluginSettingsPrefix() . '.cliUsage',
            [
                'scriptName' => $scriptName,
                'pluginName' => $this->getName()
            ]
        ) . "\n";
    }

    /**
     * @copydoc PKPImportExportPlugin::executeCLI()
     */
    public function executeCLI($scriptName, &$args)
    {
        AppLocale::requireComponents(LOCALE_COMPONENT_APP_MANAGER);

        $command = array_shift($args);
        if (!in_array($command, ['export', 'register'])) {
            $this->usage($scriptName);
            return;
        }

        $outputFile = $command == 'export' ? array_shift($args) : null;
        $contextPath = array_shift($args);
        $objectType = array_shift($args);

        $contextDao = DAORegistry::getDAO('ServerDAO');
        $context = $contextDao->getByPath($contextPath);
        if (!$context) {
            if ($contextPath != '') {
                echo __('plugins.importexport.common.cliError') . "\n";
                echo __('plugins.importexport.common.error.unknownServer', ['serverPath' => $contextPath]) . "\n\n";
            }
            $this->usage($scriptName);
            return;
        }

        if ($outputFile) {
            if ($this->isRelativePath($outputFile)) {
                $outputFile = PWD . '/' . $outputFile;
            }
            $outputDir = dirname($outputFile);
            if (!is_writable($outputDir) || (file_exists($outputFile) && !is_writable($outputFile))) {
                echo __('plugins.importexport.common.cliError') . "\n";
                echo __('plugins.importexport.common.export.error.outputFileNotWritable', ['param' => $outputFile]) . "\n\n";
                $this->usage($scriptName);
                return;
            }
        }

        switch ($objectType) {
            case 'preprints':
                $objects = $this->getPublishedSubmissions($args, $context);
                $filter = $this->getSubmissionFilter();
                $objectsFileNamePart = 'preprints';
                break;
            case 'galleys':
                $objects = $this->getPreprintGalleys($args);
                $filter = $this->getRepresentationFilter();
                $objectsFileNamePart = 'galleys';
                break;
            default:
                $this->usage($scriptName);
                return;

        }
        if (empty($objects)) {
            echo __('plugins.importexport.common.cliError') . "\n";
            echo __('plugins.importexport.common.error.unknownObjects') . "\n\n";
            $this->usage($scriptName);
            return;
        }
        if (!$filter) {
            $this->usage($scriptName);
            return;
        }

        $this->executeCLICommand($scriptName, $command, $context, $outputFile, $objects, $filter, $objectsFileNamePart);
        return;
    }

    /**
     * Execute the CLI command
     *
     * @param $scriptName The name of the command-line script (displayed as usage info)
     * @param $command string (export or register)
     * @param $context Context
     * @param $outputFile string Path to the file where the exported XML should be saved
     * @param $objects array Objects to be exported or registered
     * @param $filter string Filter to use
     * @param $objectsFileNamePart string Export file name part for this kind of objects
     */
    public function executeCLICommand($scriptName, $command, $context, $outputFile, $objects, $filter, $objectsFileNamePart)
    {
        $exportXml = $this->exportXML($objects, $filter, $context);
        if ($command == 'export' && $outputFile) {
            file_put_contents($outputFile, $exportXml);
        }

        if ($command == 'register') {
            $fileManager = new FileManager();
            $exportFileName = $this->getExportFileName($this->getExportPath(), $objectsFileNamePart, $context, '.xml');
            $fileManager->writeFile($exportFileName, $exportXml);
            $result = $this->depositXML($objects, $context, $exportFileName);
            if ($result === true) {
                echo __('plugins.importexport.common.register.success') . "\n";
            } else {
                echo __('plugins.importexport.common.cliError') . "\n";
                if (is_array($result)) {
                    foreach ($result as $error) {
                        assert(is_array($error) && count($error) >= 1);
                        $errorMessage = __($error[0], ['param' => ($error[1] ?? null)]);
                        echo "*** ${errorMessage}\n";
                    }
                    echo "\n";
                } else {
                    echo __('plugins.importexport.common.register.error.mdsError', ['param' => ' - ']) . "\n\n";
                }
                $this->usage($scriptName);
            }
            $fileManager->deleteByPath($exportFileName);
        }
    }

    /**
     * Get published submissions from submission IDs.
     *
     * @param $submissionIds array
     * @param $context Context
     *
     * @return array
     */
    public function getPublishedSubmissions($submissionIds, $context)
    {
        $submissions = array_map(function ($submissionId) {
            return Services::get('submission')->get($submissionId);
        }, $submissionIds);
        return array_filter($submissions, function ($submission) {
            return $submission->getData('status') === PKPSubmission::STATUS_PUBLISHED;
        });
    }

    /**
     * Get preprint galleys from gallley IDs.
     *
     * @param $galleyIds array
     *
     * @return array
     */
    public function getPreprintGalleys($galleyIds)
    {
        $galleys = [];
        $preprintGalleyDao = DAORegistry::getDAO('PreprintGalleyDAO');
        foreach ($galleyIds as $galleyId) {
            $preprintGalley = $preprintGalleyDao->getById($galleyId);
            if ($preprintGalley) {
                $galleys[] = $preprintGalley;
            }
        }
        return $galleys;
    }

    /**
     * Add a notification.
     *
     * @param $user User
     * @param $message string An i18n key.
     * @param $notificationType integer One of the NOTIFICATION_TYPE_* constants.
     * @param $param string An additional parameter for the message.
     */
    public function _sendNotification($user, $message, $notificationType, $param = null)
    {
        static $notificationManager = null;
        if (is_null($notificationManager)) {
            $notificationManager = new NotificationManager();
        }
        if (!is_null($param)) {
            $params = ['param' => $param];
        } else {
            $params = null;
        }
        $notificationManager->createTrivialNotification(
            $user->getId(),
            $notificationType,
            ['contents' => __($message, $params)]
        );
    }

    /**
     * Instantiate the export deployment.
     *
     * @param $context Context
     *
     * @return PKPImportExportDeployment
     */
    public function _instantiateExportDeployment($context)
    {
        $exportDeploymentClassName = $this->getExportDeploymentClassName();
        $this->import($exportDeploymentClassName);
        $exportDeployment = new $exportDeploymentClassName($context, $this);
        return $exportDeployment;
    }

    /**
     * Instantiate the settings form.
     *
     * @param $context Context
     *
     * @return CrossRefSettingsForm
     */
    public function _instantiateSettingsForm($context)
    {
        $settingsFormClassName = $this->getSettingsFormClassName();
        $this->import('classes.form.' . $settingsFormClassName);
        $settingsForm = new $settingsFormClassName($this, $context->getId());
        return $settingsForm;
    }

    /**
     * Get the DAOs for objects that need to be augmented with additional settings.
     *
     * @return array
     */
    protected function _getDAOs()
    {
        return [
            DAORegistry::getDAO('PublicationDAO'),
            DAORegistry::getDAO('SubmissionDAO'),
            Application::getRepresentationDAO(),
            DAORegistry::getDAO('SubmissionFileDAO'),
        ];
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\PubObjectsExportPlugin', '\PubObjectExportsPlugin');
}
